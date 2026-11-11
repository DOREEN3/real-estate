<?php

require_once __DIR__ . "/../models/PropertyModel.php";
require_once __DIR__ . "/../models/UserModel.php";
require_once __DIR__ . "/../helpers/response.php";
require_once __DIR__ . "/../security/Middleware.php";
require_once __DIR__ . "/../security/Validator.php";
require_once __DIR__ . "/../models/PropertyVerificationModel.php";
require_once __DIR__ . "/../services/NotificationDispatcher.php";

class PropertyController
{
    private const PROPERTY_TYPES = [
        'apartment',
        'house',
        'villa',
        'condo',
        'townhouse',
        'commercial',
        'land',
    ];

    private const TRANSACTION_TYPES = ['sale', 'rent', 'bnb'];
    private const PROPERTY_STATUSES = ['pending', 'approved', 'rejected'];
    private const AVAILABILITY_STATUSES = ['available', 'sold', 'rented', 'reserved', 'occupied', 'unavailable'];

    private PropertyModel $propertyModel;
    private UserModel $userModel;

    private PropertyVerificationModel $verificationModel;

    public function __construct()
    {
        $this->propertyModel = new PropertyModel();
        $this->userModel = new UserModel();
        $this->verificationModel = new PropertyVerificationModel();
    }

    public function getProperties(): void
    {
        [$page, $limit] = $this->pagination();
        $filters = $this->queryFilters();
        [$sortBy, $sortOrder] = $this->sortOptions(false);
        $properties = $this->propertyModel->listPublicProperties(
            $filters,
            $page,
            $limit,
            $sortBy,
            $sortOrder
        );
        $total = $this->propertyModel->countPublicProperties($filters);

        $this->sendPropertyList($properties, $page, $limit, $total);
    }

    public function getAdminProperties(): void
    {
        Middleware::requireRole(['admin']);
        [$page, $limit] = $this->pagination();
        $filters = $this->queryFilters(true);
        [$sortBy, $sortOrder] = $this->sortOptions(true);
        $properties = $this->propertyModel->listAdminProperties(
            $filters,
            $page,
            $limit,
            $sortBy,
            $sortOrder
        );
        $total = $this->propertyModel->countAdminProperties($filters);

        $this->sendPropertyList($properties, $page, $limit, $total);
    }

    public function getPropertyById(string $id): void
    {
        $propertyId = $this->propertyId($id);
        $property = $this->propertyModel->getPublicPropertyById($propertyId);
        $isPublic = $property !== null;

        if (!$property) {
            $viewer = Middleware::authenticate();
            if ($viewer && $viewer['role'] === 'admin') {
                $property = $this->propertyModel->getPropertyById($propertyId);
            } elseif ($viewer) {
                $property = $this->propertyModel->getPropertyByOwner(
                    $propertyId,
                    (int) $viewer['sub']
                );
            }
        }

        if (!$property) {
            sendError('Property not found', 404);
        }

        if ($isPublic) {
            if (!$this->propertyModel->incrementViews($propertyId)) {
                sendError('Unable to update property views', 500);
            }
            $property = $this->propertyModel->getPublicPropertyById($propertyId);
        }

        sendSuccess('Property retrieved', [
            'property' => $property,
            'images' => $this->propertyModel->getPropertyImages($propertyId),
        ]);
    }

    public function createProperty(): void
    {
        $user = Middleware::requireRole(['agent', 'owner', 'admin']);
        $data = getJsonBody();
        $isAdmin = $user['role'] === 'admin';
        $ownerId = (int) $user['sub'];
        $managerId = $user['role'] === 'agent' ? (int) $user['sub'] : null;

        if (array_key_exists('owner_id', $data)) {
            if (!$isAdmin) {
                sendError('Only administrators can assign a property to another account', 403);
            }
            $ownerId = filter_var($data['owner_id'], FILTER_VALIDATE_INT);
            if ($ownerId === false || $ownerId <= 0) {
                sendError('owner_id must be a positive integer', 400);
            }
            $owner = $this->userModel->getUserById($ownerId);
            if (
                !$owner
                || $owner['account_status'] !== 'approved'
                || !in_array($owner['role'], ['agent', 'owner'], true)
            ) {
                sendError('Properties can only be assigned to an approved owner or agent', 400);
            }
            unset($data['owner_id']);
        }

        if (array_key_exists('manager_id', $data)) {
            if (!$isAdmin) {
                sendError('Only administrators can assign a property manager', 403);
            }
            if ($data['manager_id'] === null || $data['manager_id'] === '') {
                $managerId = null;
            } else {
                $managerId = filter_var($data['manager_id'], FILTER_VALIDATE_INT);
                if ($managerId === false || $managerId <= 0) {
                    sendError('manager_id must be a positive integer', 400);
                }
                $manager = $this->userModel->getUserById($managerId);
                if (
                    !$manager
                    || $manager['account_status'] !== 'approved'
                    || $manager['role'] !== 'agent'
                ) {
                    sendError('Properties can only be assigned to an approved agent as manager', 400);
                }
            }
            unset($data['manager_id']);
        }

        if (array_key_exists('property_status', $data)) {
            sendError('New properties are submitted with pending status for review', 400);
        }

        $propertyData = $this->validatedPropertyData($data, true, $isAdmin);
        $propertyData['property_status'] = $isAdmin ? 'approved' : 'pending';
        $propertyData['availability_status'] = $propertyData['availability_status'] ?? 'available';
        $propertyData['currency'] = $propertyData['currency'] ?? 'KES';
        $propertyData['bedrooms'] = $propertyData['bedrooms'] ?? 0;
        $propertyData['bathrooms'] = $propertyData['bathrooms'] ?? 0;
        $propertyData['square_feet'] = $propertyData['square_feet'] ?? 0;

        $this->assertCoordinatePair($propertyData);
        $propertyId = $this->propertyModel->createProperty($ownerId, $propertyData, $managerId);
        if ($propertyId <= 0) {
            sendError('Failed to create property', 500);
        }

        if (!$isAdmin) {
            $this->verificationModel->requestVerification($propertyId);
        }

        $savedProperty = $this->propertyModel->getPropertyById($propertyId);

        // Requirement 4: Automated action - all agents notified when new property is saved
        try {
            $dispatcher = new NotificationDispatcher();
            $dispatcher->dispatchNewPropertyAlert($savedProperty);
        } catch (Throwable $e) {
            error_log('Automated agent property alert failed: ' . $e->getMessage());
        }

        sendSuccess('Property created and submitted for review', [
            'property' => $savedProperty,
            'images' => [],
        ], 201);
    }

    public function updateProperty(string $id): void
    {
        $user = Middleware::requireAuth();
        $propertyId = $this->propertyId($id);
        $property = $this->propertyModel->getPropertyById($propertyId);
        if (!$property) {
            sendError('Property not found', 404);
        }
        $this->assertCanManage($property, $user);

        $data = getJsonBody();
        if (!$data) {
            sendError('Provide at least one property field to update', 400);
        }
        if (array_key_exists('owner_id', $data) || array_key_exists('views_count', $data)) {
            sendError('owner_id and views_count cannot be changed through this endpoint', 400);
        }

        $propertyData = $this->validatedPropertyData(
            $data,
            false,
            $user['role'] === 'admin'
        );
        $merged = array_merge($property, $propertyData);
        $this->assertCoordinatePair($merged);
        if (!$this->propertyModel->updateProperty(
            $propertyId,
            $propertyData,
            $user['role'] !== 'admin'
        )) {
            sendError('Unable to update property', 500);
        }

        sendSuccess('Property updated', [
            'property' => $this->propertyModel->getPropertyById($propertyId),
        ]);
    }

    public function archiveProperty(string $id): void
{
    $user = Middleware::requireAuth();
    $propertyId = $this->propertyId($id);

    $property = $this->propertyModel->getPropertyById($propertyId);

    if (!$property) {
        sendError('Property not found', 404);
    }

    $this->assertCanManage($property, $user);

    if ($property['availability_status'] === 'unavailable') {
        sendError('Property is already archived', 409);
    }

    if (!$this->propertyModel->archiveProperty($propertyId)) {
        sendError('Unable to archive property; its status may have changed', 409);
    }

    sendSuccess('Property archived successfully; its database record and history were retained', [
        'property' => $this->propertyModel->getPropertyById($propertyId),
    ]);
}
    public function getMyProperties(): void
    {
        $user = Middleware::requireAuth();
        [$page, $limit] = $this->pagination();
        $ownerId = (int) $user['sub'];
        $properties = $this->propertyModel->listPropertiesByOwner($ownerId, $page, $limit);
        $total = $this->propertyModel->countPropertiesByOwner($ownerId);

        $this->sendPropertyList($properties, $page, $limit, $total);
    }

    public function addPropertyImage(string $id): void
    {
        $user = Middleware::requireAuth();
        $propertyId = $this->propertyId($id);
        $property = $this->propertyModel->getPropertyById($propertyId);
        if (!$property) {
            sendError('Property not found', 404);
        }
        $this->assertCanManage($property, $user);

        $data = getJsonBody();
        $allowedFields = ['image_url', 'alt_text', 'is_primary', 'sort_order'];
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowedFields, true)) {
                sendError("Unknown image field: {$key}", 400);
            }
        }
        $errors = [];
        $rawImageUrl = $data['image_url'] ?? null;
        if (!is_string($rawImageUrl)) {
            $errors['image_url'] = 'image_url must be a URL string';
            $rawImageUrl = '';
        }

        $imageUrl = '';
        if (preg_match('#^data:image/(webp|png|jpe?g|gif);base64,([A-Za-z0-9+/=\r\n]+)$#i', trim($rawImageUrl), $matches)) {
            $ext = strtolower($matches[1]);
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }
            $binary = base64_decode($matches[2], true);
            if ($binary === false || strlen($binary) === 0 || strlen($binary) > 10 * 1024 * 1024) {
                $errors['image_url'] = 'Invalid or oversized base64 image payload';
            } else {
                $uploadDir = dirname(__DIR__, 2) . '/public/uploads/properties';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0775, true);
                }
                $filename = sprintf('prop_%d_%s.%s', $propertyId, bin2hex(random_bytes(8)), $ext);
                $filePath = $uploadDir . '/' . $filename;
                if (file_put_contents($filePath, $binary) === false) {
                    sendError('Unable to save uploaded property image', 500);
                }
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $imageUrl = "{$scheme}://{$host}/real-estate-backend/uploads/properties/{$filename}";
            }
        } else {
            $imageUrl = Validator::sanitizeInput($rawImageUrl);
            $urlParts = $imageUrl === '' ? false : parse_url($imageUrl);
            if ($imageUrl === '') {
                $errors['image_url'] = 'image_url is required';
            } elseif (
                strlen($imageUrl) > 500
                || !filter_var($imageUrl, FILTER_VALIDATE_URL)
                || !is_array($urlParts)
                || !in_array(strtolower($urlParts['scheme'] ?? ''), ['http', 'https'], true)
                || empty($urlParts['host'])
                || isset($urlParts['user'])
                || isset($urlParts['pass'])
            ) {
                $errors['image_url'] = 'image_url must be an HTTP(S) URL without embedded credentials and at most 500 characters';
            }
        }

        $altText = $data['alt_text'] ?? null;
        if ($altText !== null && !is_string($altText)) {
            $errors['alt_text'] = 'alt_text must be a string or null';
        } elseif (is_string($altText)) {
            $altText = Validator::sanitizeInput($altText);
            if (strlen($altText) > 255) {
                $errors['alt_text'] = 'alt_text must not exceed 255 characters';
            }
        }

        $isPrimary = $data['is_primary'] ?? false;
        if (!in_array($isPrimary, [true, false, 0, 1, '0', '1'], true)) {
            $errors['is_primary'] = 'is_primary must be a boolean';
        }
        $isPrimary = in_array($isPrimary, [true, 1, '1'], true);

        $sortOrder = $data['sort_order'] ?? 0;
        $parsedSortOrder = filter_var($sortOrder, FILTER_VALIDATE_INT);
        if ($parsedSortOrder === false || $parsedSortOrder < 0 || $parsedSortOrder > 2147483647) {
            $errors['sort_order'] = 'sort_order must be a non-negative 32-bit integer';
        } else {
            $sortOrder = $parsedSortOrder;
        }

        if ($errors) {
            sendError('Validation failed', 400, ['errors' => $errors]);
        }

        $imageId = $this->propertyModel->addPropertyImage(
            $propertyId,
            $imageUrl,
            $altText,
            $isPrimary,
            $sortOrder,
            $user['role'] !== 'admin'
        );
        if ($imageId <= 0) {
            sendError('Unable to add image to property', 500);
        }

        sendSuccess('Property image added', [
            'images' => $this->propertyModel->getPropertyImages($propertyId),
        ], 201);
    }

    public function approveProperty(string $id): void
    {
        $this->reviewProperty($id, 'approved');
    }

    public function rejectProperty(string $id): void
    {
        $this->reviewProperty($id, 'rejected');
    }

    private function reviewProperty(string $id, string $status): void
    {
        Middleware::requireRole(['admin']);
        $propertyId = $this->propertyId($id);
        $property = $this->propertyModel->getPropertyById($propertyId);
        if (!$property) {
            sendError('Property not found', 404);
        }
        if ($property['property_status'] !== 'pending') {
            sendError('Only pending properties can be reviewed', 409);
        }
        if (
            $status === 'approved'
            && !$this->verificationModel->isPropertyVerified($propertyId)
        ) {
            sendError(
            'Property cannot be approved until its latest verification has been completed successfully',
            409
         );
        }
        if (!$this->propertyModel->setReviewStatus($propertyId, $status)) {
            sendError('Property status changed before review could be saved', 409);
        }

        sendSuccess('Property ' . $status, [
            'property' => $this->propertyModel->getPropertyById($propertyId),
        ]);
    }

    private function assertCanManage(array $property, array $user): void
    {
        if (
            (int) $property['owner_id'] !== (int) $user['sub']
            && ($user['role'] ?? '') !== 'admin'
        ) {
            sendError('You can only manage your own properties', 403);
        }
    }

    private function propertyId(string $id): int
    {
        $propertyId = filter_var($id, FILTER_VALIDATE_INT);
        if ($propertyId === false || $propertyId <= 0) {
            sendError('Invalid property ID', 400);
        }

        return $propertyId;
    }

    private function pagination(): array
    {
        $page = $this->queryInteger('page', 1);
        $limit = $this->queryInteger('limit', 12);
        if ($page < 1 || $limit < 1 || $limit > 100) {
            sendError('page must be positive and limit must be between 1 and 100', 400);
        }

        return [$page, $limit];
    }

    private function queryInteger(string $key, int $default): int
    {
        if (!array_key_exists($key, $_GET)) {
            return $default;
        }
        $value = filter_var($_GET[$key], FILTER_VALIDATE_INT);
        if ($value === false) {
            sendError("{$key} must be an integer", 400);
        }

        return $value;
    }

    private function queryFilters(bool $admin = false): array
    {
        $filters = [];
        foreach (['city', 'state', 'country'] as $key) {
            if (isset($_GET[$key])) {
                if (!is_string($_GET[$key])) {
                    sendError("{$key} must be a string", 400);
                }
                $filters[$key] = Validator::sanitizeInput($_GET[$key]);
            }
        }

        foreach (['property_type' => self::PROPERTY_TYPES, 'transaction_type' => self::TRANSACTION_TYPES] as $key => $allowed) {
            if (isset($_GET[$key])) {
                if (!is_string($_GET[$key]) || !in_array($_GET[$key], $allowed, true)) {
                    sendError("Invalid {$key} filter", 400);
                }
                $filters[$key] = $_GET[$key];
            }
        }

        if (isset($_GET['currency'])) {
            if (!is_string($_GET['currency']) || !preg_match('/^[A-Za-z]{3,10}$/', $_GET['currency'])) {
                sendError('Invalid currency filter', 400);
            }
            $filters['currency'] = strtoupper($_GET['currency']);
        }

        foreach (['min_price', 'max_price'] as $key) {
            if (isset($_GET[$key])) {
                if (!is_numeric($_GET[$key]) || !is_finite((float) $_GET[$key]) || (float) $_GET[$key] < 0) {
                    sendError("{$key} must be a non-negative number", 400);
                }
                $filters[$key] = (string) $_GET[$key];
            }
        }
        if (
            isset($filters['min_price'], $filters['max_price'])
            && (float) $filters['min_price'] > (float) $filters['max_price']
        ) {
            sendError('min_price cannot exceed max_price', 400);
        }

        foreach (['bedrooms_min', 'bathrooms_min'] as $key) {
            if (isset($_GET[$key])) {
                $value = filter_var($_GET[$key], FILTER_VALIDATE_INT);
                if ($value === false || $value < 0) {
                    sendError("{$key} must be a non-negative integer", 400);
                }
                $filters[$key] = $value;
            }
        }

        if ($admin) {
            foreach ([
                'property_status' => self::PROPERTY_STATUSES,
                'availability_status' => self::AVAILABILITY_STATUSES,
            ] as $key => $allowed) {
                if (isset($_GET[$key])) {
                    if (!is_string($_GET[$key]) || !in_array($_GET[$key], $allowed, true)) {
                        sendError("Invalid {$key} filter", 400);
                    }
                    $filters[$key] = $_GET[$key];
                }
            }
        }

        return $filters;
    }

    private function sortOptions(bool $admin): array
    {
        $allowed = $admin
            ? ['created_at', 'updated_at', 'price', 'views_count']
            : ['created_at', 'price', 'bedrooms', 'views_count'];
        $sortBy = $_GET['sort_by'] ?? 'created_at';
        $sortOrder = $_GET['sort_order'] ?? 'DESC';
        if (!is_string($sortBy) || !in_array($sortBy, $allowed, true)) {
            sendError('Invalid sort_by value', 400);
        }
        if (!is_string($sortOrder)) {
            sendError('sort_order must be ASC or DESC', 400);
        }
        $sortOrder = strtoupper($sortOrder);
        if (!in_array($sortOrder, ['ASC', 'DESC'], true)) {
            sendError('sort_order must be ASC or DESC', 400);
        }

        return [$sortBy, $sortOrder];
    }

    private function validatedPropertyData(array $data, bool $isCreate, bool $isAdmin): array
    {
        $editable = [
            'title',
            'description',
            'property_type',
            'transaction_type',
            'price',
            'currency',
            'address',
            'city',
            'state',
            'country',
            'postal_code',
            'latitude',
            'longitude',
            'bedrooms',
            'bathrooms',
            'square_feet',
            'availability_status',
            'property_status',
            'featured',
        ];
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $editable, true)) {
                sendError("Unknown property field: {$key}", 400);
            }
        }

        $validator = new Validator();
        $result = [];
        if ($isCreate) {
            foreach (['title', 'property_type', 'transaction_type', 'price'] as $required) {
                if (!array_key_exists($required, $data)) {
                    $validator->required(null, $required);
                }
            }
        }

        if (array_key_exists('title', $data)) {
            if (!is_string($data['title'])) {
                $validator->length('', 3, 255, 'title');
            } else {
                $value = Validator::sanitizeInput($data['title']);
                $validator->length($value, 3, 255, 'title');
                $result['title'] = $value;
            }
        }

        if (array_key_exists('description', $data)) {
            $value = $data['description'];
            if ($value !== null && !is_string($value)) {
                $validator->length('', 0, 65535, 'description');
            } else {
                $value = $value === null ? null : Validator::sanitizeInput($value);
                if (is_string($value) && strlen($value) > 60000) {
                    $validator->length($value, 0, 0, 'description');
                }
                $result['description'] = $value;
            }
        }

        foreach ([
            'property_type' => self::PROPERTY_TYPES,
            'transaction_type' => self::TRANSACTION_TYPES,
        ] as $key => $allowed) {
            if (array_key_exists($key, $data)) {
                if (!is_string($data[$key])) {
                    $validator->inArray('', $allowed, $key);
                } else {
                    $validator->inArray($data[$key], $allowed, $key);
                    $result[$key] = $data[$key];
                }
            }
        }

        if (array_key_exists('price', $data)) {
            if (
                (!is_string($data['price']) && !is_int($data['price']) && !is_float($data['price']))
                || !preg_match('/^\d+(?:\.\d{1,2})?$/D', (string) $data['price'])
                || !is_finite((float) $data['price'])
                || (float) $data['price'] <= 0
                || (float) $data['price'] > 9999999999999.99
            ) {
                $validator->numericRange(0, 0.01, 9999999999999.99, 'price');
            } else {
                $result['price'] = (string) $data['price'];
            }
        }

        if (array_key_exists('currency', $data)) {
            if (!is_string($data['currency']) || !preg_match('/^[A-Za-z]{3,10}$/', $data['currency'])) {
                $validator->length('', 3, 10, 'currency');
            } else {
                $result['currency'] = strtoupper($data['currency']);
            }
        }

        foreach ([
            'address' => 10000,
            'city' => 100,
            'state' => 100,
            'country' => 100,
            'postal_code' => 20,
        ] as $key => $maxLength) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                if ($value !== null && !is_string($value)) {
                    $validator->length('', 0, $maxLength, $key);
                } else {
                    $value = $value === null ? null : Validator::sanitizeInput($value);
                    if (is_string($value) && strlen($value) > $maxLength) {
                        $validator->length($value, 0, $maxLength, $key);
                    }
                    $result[$key] = $value;
                }
            }
        }

        foreach (['latitude' => [-90, 90], 'longitude' => [-180, 180]] as $key => $range) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                if ($value === null || $value === '') {
                    $result[$key] = null;
                } elseif (
                    (!is_string($value) && !is_int($value) && !is_float($value))
                    || !preg_match('/^-?\d+(?:\.\d{1,8})?$/D', (string) $value)
                    || !is_finite((float) $value)
                    || (float) $value < $range[0]
                    || (float) $value > $range[1]
                ) {
                    $validator->numericRange(0, $range[0], $range[1], $key);
                } else {
                    $result[$key] = (string) $value;
                }
            }
        }

        foreach (['bedrooms', 'bathrooms', 'square_feet'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = filter_var($data[$key], FILTER_VALIDATE_INT);
                if ($value === false || $value < 0) {
                    $validator->numericRange(-1, 0, 2147483647, $key);
                } else {
                    $result[$key] = $value;
                }
            }
        }

        if (array_key_exists('availability_status', $data)) {
            if (!is_string($data['availability_status'])) {
                $validator->inArray('', self::AVAILABILITY_STATUSES, 'availability_status');
            } else {
                $validator->inArray($data['availability_status'], self::AVAILABILITY_STATUSES, 'availability_status');
                $result['availability_status'] = $data['availability_status'];
            }
        }

        if (array_key_exists('property_status', $data)) {
            if (!$isAdmin) {
                sendError('Only administrators can change property approval status', 403);
            }
            if ($isCreate) {
                sendError('New properties are submitted with pending status for review', 400);
            }
            if (!is_string($data['property_status'])) {
                $validator->inArray('', self::PROPERTY_STATUSES, 'property_status');
            } else {
                $validator->inArray($data['property_status'], self::PROPERTY_STATUSES, 'property_status');
                $result['property_status'] = $data['property_status'];
            }
        }

        if (array_key_exists('featured', $data)) {
            if (!$isAdmin) {
                sendError('Only administrators can feature properties', 403);
            }
            if (!in_array($data['featured'], [true, false, 0, 1, '0', '1'], true)) {
                $validator->inArray('', ['true', 'false', '0', '1'], 'featured');
            } else {
                $result['featured'] = in_array($data['featured'], [true, 1, '1'], true) ? 1 : 0;
            }
        }

        if ($validator->hasErrors()) {
            sendError('Validation failed', 400, ['errors' => $validator->getErrors()]);
        }

        return $result;
    }

    private function assertCoordinatePair(array $property): void
    {
        $hasLatitude = isset($property['latitude']) && $property['latitude'] !== '';
        $hasLongitude = isset($property['longitude']) && $property['longitude'] !== '';
        if ($hasLatitude !== $hasLongitude) {
            sendError('latitude and longitude must both be provided or both be null', 400);
        }
    }

    private function sendPropertyList(array $properties, int $page, int $limit, int $total): void
    {
        foreach ($properties as &$property) {
            if (isset($property['id'])) {
                $property['images'] = $this->propertyModel->getPropertyImages((int) $property['id']);
            }
        }
        unset($property);

        sendSuccess('Properties retrieved', [
            'data' => $properties,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ]);
    }
}
