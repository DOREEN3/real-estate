<?php

require_once __DIR__ . '/../models/PropertyVerificationModel.php';
require_once __DIR__ . '/../models/PropertyModel.php';
require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../security/Middleware.php';

class PropertyVerificationController
{
    private const STATUSES = [
        'pending',
        'documents_submitted',
        'under_review',
        'visit_required',
        'verified',
        'rejected',
    ];
    private const MAX_DOCUMENT_BYTES = 10485760;
    private const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    private PropertyVerificationModel $verificationModel;
    private PropertyModel $propertyModel;
    private UserModel $userModel;

    public function __construct()
    {
        $this->verificationModel = new PropertyVerificationModel();
        $this->propertyModel = new PropertyModel();
        $this->userModel = new UserModel();
    }

    public function requestVerification(string $id): void
    {
        $user = Middleware::requireRole(['owner', 'agent', 'admin']);
        $propertyId = $this->positiveId($id, 'property ID');
        $property = $this->propertyModel->getPropertyById($propertyId);
        if (!$property) {
            sendError('Property not found', 404);
        }
        $this->assertPropertyAccess($property, $user);

        $verificationId = $this->verificationModel->requestVerification($propertyId);
        if ($verificationId <= 0) {
            sendError('Unable to request property verification', 500);
        }

        sendSuccess('Property verification request submitted', [
            'verification' => $this->publicVerification(
                $this->verificationModel->getById($verificationId)
            ),
        ], 201);
    }

    public function listPropertyVerifications(string $id): void
    {
        $user = Middleware::requireAuth();
        $propertyId = $this->positiveId($id, 'property ID');
        $property = $this->propertyModel->getPropertyById($propertyId);
        if (!$property) {
            sendError('Property not found', 404);
        }
        $this->assertPropertyAccess($property, $user);

        sendSuccess('Property verification history retrieved', [
            'data' => array_map(
                [$this, 'publicVerification'],
                $this->verificationModel->listForProperty($propertyId)
            ),
        ]);
    }

    public function listAdminVerifications(): void
    {
        Middleware::requireRole(['admin']);
        [$page, $limit] = $this->pagination();
        $status = $_GET['status'] ?? null;
        if ($status !== null && (!is_string($status) || !in_array($status, self::STATUSES, true))) {
            sendError('Invalid verification status filter', 400);
        }
        $rows = $this->verificationModel->listAll($status, $page, $limit);
        $total = $this->verificationModel->countAll($status);

        sendSuccess('Property verifications retrieved', [
            'data' => array_map([$this, 'publicVerification'], $rows),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    public function uploadDocument(string $id): void
    {
        $user = Middleware::requireRole(['owner', 'agent', 'admin']);
        $verificationId = $this->positiveId($id, 'verification ID');
        $verification = $this->verificationModel->getById($verificationId);
        if (!$verification) {
            sendError('Property verification not found', 404);
        }
        $this->assertVerificationAccess($verification, $user);
        if ($verification['document_path'] !== null) {
            sendError('A document is already attached; contact an administrator to replace it', 409);
        }
        if (!isset($_FILES['document']) || !is_array($_FILES['document'])) {
            sendError('Upload a document using the document multipart field', 400);
        }

        $file = $_FILES['document'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $message = match ((int) $file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Document exceeds the upload size limit',
                UPLOAD_ERR_PARTIAL => 'Document upload was incomplete',
                UPLOAD_ERR_NO_FILE => 'A document is required',
                default => 'Document upload failed',
            };
            sendError($message, 400);
        }
        if (
            !isset($file['tmp_name'], $file['size'])
            || !is_string($file['tmp_name'])
            || !is_uploaded_file($file['tmp_name'])
            || (int) $file['size'] <= 0
            || (int) $file['size'] > self::MAX_DOCUMENT_BYTES
        ) {
            sendError('Document must be a non-empty upload no larger than 10 MB', 400);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!is_string($mimeType) || !isset(self::MIME_EXTENSIONS[$mimeType])) {
            sendError('Only PDF, JPEG, and PNG documents are accepted', 400);
        }

        $storageRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . 'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR
            . 'property-verifications' . DIRECTORY_SEPARATOR . $verificationId;
        if (!is_dir($storageRoot) && !mkdir($storageRoot, 0700, true) && !is_dir($storageRoot)) {
            error_log('Unable to create private property verification document directory.');
            sendError('Document storage is unavailable', 500);
        }

        $filename = bin2hex(random_bytes(24)) . '.' . self::MIME_EXTENSIONS[$mimeType];
        $absolutePath = $storageRoot . DIRECTORY_SEPARATOR . $filename;
        $relativePath = 'private/property-verifications/' . $verificationId . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
            error_log('Unable to move uploaded property verification document into private storage.');
            sendError('Unable to save document', 500);
        }
        @chmod($absolutePath, 0600);

        if (!$this->verificationModel->attachDocument($verificationId, $relativePath)) {
            error_log(
                'Uploaded verification document was saved but could not be attached; '
                . 'private file retained at ' . $relativePath
            );
            sendError('Verification changed during upload; contact an administrator before retrying', 409);
        }

        sendSuccess('Verification document submitted for review', [
            'verification' => $this->publicVerification(
                $this->verificationModel->getById($verificationId)
            ),
        ], 201);
    }

    public function downloadDocument(string $id): void
    {
        $user = Middleware::requireAuth();
        $verificationId = $this->positiveId($id, 'verification ID');
        $verification = $this->verificationModel->getById($verificationId);
        if (!$verification) {
            sendError('Property verification not found', 404);
        }
        $this->assertVerificationAccess($verification, $user);

        $relativePath = $verification['document_path'];
        if (!is_string($relativePath) || !preg_match(
            '#^private/property-verifications/[1-9][0-9]*/[a-f0-9]{48}\.(pdf|jpg|png)$#',
            $relativePath
        )) {
            sendError('No valid verification document is available', 404);
        }

        $storageRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';
        $absolutePath = $storageRoot . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            error_log('Referenced property verification document is unavailable: ' . $relativePath);
            sendError('Verification document is unavailable', 404);
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $contentTypes = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
        ];
        header('Content-Type: ' . $contentTypes[$extension]);
        header('Content-Length: ' . (string) filesize($absolutePath));
        header('Content-Disposition: attachment; filename="verification-' . $verificationId . '.' . $extension . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($absolutePath);
        exit;
    }

    public function reviewVerification(string $id): void
    {
        $user = Middleware::requireRole(['admin']);
        $verificationId = $this->positiveId($id, 'verification ID');
        $data = getJsonBody();
        $allowedFields = ['verification_status', 'review_notes'];
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowedFields, true)) {
                sendError("Unknown verification review field: {$key}", 400);
            }
        }

        $status = $data['verification_status'] ?? null;
        if (
            !is_string($status)
            || !in_array($status, ['under_review', 'verified', 'rejected', 'visit_required'], true)
        ) {
            sendError(
                'verification_status must be under_review, verified, rejected, or visit_required',
                400
            );
        }
        $notes = $data['review_notes'] ?? null;
        if ($notes !== null && !is_string($notes)) {
            sendError('review_notes must be a string or null', 400);
        }
        if (is_string($notes)) {
            $notes = trim($notes);
            if (strlen($notes) > 5000) {
                sendError('review_notes must not exceed 5000 characters', 400);
            }
        }

        $verification = $this->verificationModel->getById($verificationId);
        if (!$verification) {
            sendError('Property verification not found', 404);
        }
        if ($verification['document_path'] === null) {
            sendError('Submit a verification document before reviewing it', 409);
        }
        if (!$this->verificationModel->review(
            $verificationId,
            (int) $user['sub'],
            $status,
            $notes
        )) {
            sendError('Verification was already finalized or changed; refresh and try again', 409);
        }

        sendSuccess('Property verification reviewed', [
            'verification' => $this->publicVerification(
                $this->verificationModel->getById($verificationId)
            ),
        ]);
    }

    public function scheduleVisit(string $id): void
    {
        Middleware::requireRole(['admin']);
        $verificationId = $this->positiveId($id, 'verification ID');
        $data = getJsonBody();
        $allowedFields = ['assigned_to', 'scheduled_at', 'visit_notes'];
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowedFields, true)) {
                sendError("Unknown visit field: {$key}", 400);
            }
        }

        $assignedTo = filter_var($data['assigned_to'] ?? null, FILTER_VALIDATE_INT);
        if ($assignedTo === false || $assignedTo <= 0) {
            sendError('assigned_to must be a positive user ID', 400);
        }
        $assignee = $this->userModel->getUserById($assignedTo);
        if (
            !$assignee
            || $assignee['account_status'] !== 'approved'
            || !in_array($assignee['role'], ['agent', 'admin'], true)
        ) {
            sendError('Visits can only be assigned to approved agents or administrators', 400);
        }

        $scheduledAt = $data['scheduled_at'] ?? null;
        if ($scheduledAt !== null) {
            if (!is_string($scheduledAt)) {
                sendError('scheduled_at must be a datetime string or null', 400);
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $scheduledAt);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (
                !$date
                || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))
                || $date->format('Y-m-d H:i:s') !== $scheduledAt
                || $date->getTimestamp() <= time()
            ) {
                sendError('scheduled_at must be a future datetime in YYYY-MM-DD HH:MM:SS format', 400);
            }
        }

        $notes = $data['visit_notes'] ?? null;
        if ($notes !== null && !is_string($notes)) {
            sendError('visit_notes must be a string or null', 400);
        }
        if (is_string($notes)) {
            $notes = trim($notes);
            if (strlen($notes) > 5000) {
                sendError('visit_notes must not exceed 5000 characters', 400);
            }
        }

        if (!$this->verificationModel->getById($verificationId)) {
            sendError('Property verification not found', 404);
        }
        $visitId = $this->verificationModel->createVisit(
            $verificationId,
            $assignedTo,
            $scheduledAt,
            $notes
        );
        if ($visitId <= 0) {
            sendError('Only a verification with submitted documents can be scheduled', 409);
        }

        sendSuccess('Property verification visit scheduled', [
            'visit' => $this->verificationModel->getVisitById($visitId),
        ], 201);
    }

    public function listMyVisits(): void
    {
        $user = Middleware::requireRole(['agent', 'admin']);
        [$page, $limit] = $this->pagination();
        $isAdmin = $user['role'] === 'admin';
        $visits = $this->verificationModel->listVisitsForUser(
            (int) $user['sub'],
            $isAdmin,
            $page,
            $limit
        );

        sendSuccess('Property verification visits retrieved', [
            'data' => $visits,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $this->verificationModel->countVisitsForUser(
                    (int) $user['sub'],
                    $isAdmin
                ),
            ],
        ]);
    }

    public function recordVisitOutcome(string $id): void
    {
        $user = Middleware::requireRole(['agent', 'admin']);
        $visitId = $this->positiveId($id, 'visit ID');
        $visit = $this->verificationModel->getVisitById($visitId);
        if (!$visit) {
            sendError('Property verification visit not found', 404);
        }
        if (
            $user['role'] !== 'admin'
            && (int) $visit['assigned_to'] !== (int) $user['sub']
        ) {
            sendError('Only the assigned agent or an administrator can record this visit outcome', 403);
        }

        $data = getJsonBody();
        $allowedFields = ['visit_status', 'visit_notes'];
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowedFields, true)) {
                sendError("Unknown visit outcome field: {$key}", 400);
            }
        }
        $status = $data['visit_status'] ?? null;
        if (!is_string($status) || !in_array($status, ['completed', 'cancelled'], true)) {
            sendError('visit_status must be completed or cancelled', 400);
        }
        $notes = $data['visit_notes'] ?? '';
        if (!is_string($notes) || strlen(trim($notes)) > 5000) {
            sendError('visit_notes must be a string of at most 5000 characters', 400);
        }
        $notes = trim($notes);
        if ($status === 'completed' && $notes === '') {
            sendError('visit_notes are required when completing a visit', 400);
        }

        if (!$this->verificationModel->recordVisitOutcome(
            $visitId,
            (int) $visit['assigned_to'],
            (int) $user['sub'],
            $status,
            $notes
        )) {
            sendError('Only a scheduled visit can receive an outcome', 409);
        }

        sendSuccess('Property verification visit updated', [
            'visit' => $this->verificationModel->getVisitById($visitId),
        ]);
    }

    private function assertPropertyAccess(array $property, array $user): void
    {
        if (
            (int) $property['owner_id'] !== (int) $user['sub']
            && ($user['role'] ?? '') !== 'admin'
        ) {
            sendError('You can only access verification for your own properties', 403);
        }
    }

    private function publicVerification(?array $verification): ?array
    {
        if ($verification === null) {
            return null;
        }

        $verification['document_available'] = $verification['document_path'] !== null;
        unset($verification['document_path']);

        return $verification;
    }

    private function assertVerificationAccess(array $verification, array $user): void
    {
        if ($user['role'] === 'admin') {
            return;
        }
        if ((int) $verification['owner_id'] === (int) $user['sub']) {
            return;
        }
        if (
            $user['role'] === 'agent'
            && $this->verificationModel->hasAssignedVisit(
                (int) $verification['id'],
                (int) $user['sub']
            )
        ) {
            return;
        }
        sendError('You do not have access to this verification document', 403);
    }

    private function positiveId(string $value, string $label): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            sendError("Invalid {$label}", 400);
        }

        return $id;
    }

    private function pagination(): array
    {
        $page = $this->queryInt('page', 1);
        $limit = $this->queryInt('limit', 20);
        if ($page < 1 || $limit < 1 || $limit > 100) {
            sendError('page must be positive and limit must be between 1 and 100', 400);
        }

        return [$page, $limit];
    }

    private function queryInt(string $key, int $default): int
    {
        if (!isset($_GET[$key])) {
            return $default;
        }
        $value = filter_var($_GET[$key], FILTER_VALIDATE_INT);
        if ($value === false) {
            sendError("{$key} must be an integer", 400);
        }

        return $value;
    }
}
