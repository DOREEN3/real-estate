<?php

declare(strict_types=1);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(
    "CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL,
        role TEXT NOT NULL,
        account_status TEXT NOT NULL
    );
    CREATE TABLE properties (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        owner_id INTEGER NOT NULL,
        managed_by INTEGER NULL,
        title TEXT NOT NULL,
        description TEXT NULL,
        property_type TEXT NOT NULL,
        transaction_type TEXT NOT NULL,
        price NUMERIC NOT NULL,
        currency TEXT DEFAULT 'USD',
        address TEXT NULL,
        city TEXT NULL,
        state TEXT NULL,
        country TEXT NULL,
        postal_code TEXT NULL,
        latitude NUMERIC NULL,
        longitude NUMERIC NULL,
        bedrooms INTEGER DEFAULT 0,
        bathrooms INTEGER DEFAULT 0,
        square_feet INTEGER DEFAULT 0,
        property_status TEXT NOT NULL DEFAULT 'pending',
        availability_status TEXT NOT NULL DEFAULT 'available',
        featured INTEGER DEFAULT 0,
        views_count INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (owner_id) REFERENCES users(id)
    );
    CREATE TABLE property_images (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        property_id INTEGER NOT NULL,
        image_url TEXT NOT NULL,
        alt_text TEXT NULL,
        is_primary INTEGER DEFAULT 0,
        sort_order INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (property_id) REFERENCES properties(id)
    );
    CREATE TABLE property_verifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        property_id INTEGER NOT NULL,
        verification_status TEXT NOT NULL,
        FOREIGN KEY (property_id) REFERENCES properties(id)
    );"
);
$pdo->exec(
    "INSERT INTO users (id, full_name, email, role, account_status) VALUES
        (1, 'Owner One', 'owner1@example.com', 'owner', 'approved'),
        (2, 'Agent Two', 'agent2@example.com', 'agent', 'approved'),
        (3, 'Admin', 'admin@example.com', 'admin', 'approved');
    INSERT INTO properties
        (id, owner_id, title, description, property_type, transaction_type, price, currency,
         city, country, latitude, longitude, bedrooms, bathrooms, square_feet,
         property_status, availability_status, featured)
    VALUES
        (1, 1, 'Miami Villa', 'Near the beach', 'villa', 'sale', 500000, 'USD',
         'Miami', 'USA', 25.7617, -80.1918, 4, 3, 2400, 'approved', 'available', 1),
        (2, 1, 'Pending House', NULL, 'house', 'sale', 100000, 'USD',
         'Miami', 'USA', NULL, NULL, 3, 2, 1200, 'pending', 'available', 0),
        (3, 2, 'Unavailable Apartment', NULL, 'apartment', 'rent', 1200, 'KES',
         'Nairobi', 'Kenya', NULL, NULL, 2, 1, 800, 'approved', 'unavailable', 0),
        (4, 2, 'Nairobi Apartment', NULL, 'apartment', 'rent', 1500, 'KES',
         'Nairobi', 'Kenya', -1.2864, 36.8172, 2, 2, 950, 'approved', 'available', 0);"
);

require_once __DIR__ . '/../app/models/PropertyModel.php';
require_once __DIR__ . '/../app/security/Middleware.php';
require_once __DIR__ . '/../routes/PropertyRoutes.php';

$model = new PropertyModel();
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};

$publicProperties = $model->listPublicProperties([], 1, 12, 'created_at', 'DESC');
$assert(
    array_map('intval', array_column($publicProperties, 'id')) === [1, 4],
    'Public results must contain only approved, available properties: '
        . json_encode(array_column($publicProperties, 'id'))
);
$assert($model->countPublicProperties([]) === 2, 'Public result count must match visibility rules.');
$assert($publicProperties[0]['owner_name'] === 'Owner One', 'Property results should join the owner display name.');
$assert(
    $model->countPublicProperties([
        'city' => '%Nairobi%',
        'property_type' => 'apartment',
        'transaction_type' => 'rent',
        'currency' => 'KES',
        'min_price' => '1000',
        'max_price' => '2000',
        'bedrooms_min' => 2,
    ]) === 1,
    'Property search filters should compose against schema fields.'
);
$assert(
    $model->countAdminProperties(['property_status' => 'pending']) === 1,
    'Admin filters should include pending properties.'
);
$assert(
    $model->countPropertiesByOwner(1) === 2
        && count($model->listPropertiesByOwner(1, 1, 10)) === 2,
    'Owner listings should include all of that owner’s property statuses.'
);
$assert($model->getPublicPropertyById(2) === null, 'Pending properties must not be public.');
$assert($model->getPropertyById(2)['property_status'] === 'pending', 'Admin/owner lookup should see pending records.');

$assert($model->incrementViews(1), 'Property views should update.');
$assert((int) $model->getPublicPropertyById(1)['views_count'] === 1, 'View count should be persisted.');

$imageOne = $model->addPropertyImage(1, 'https://images.example.com/one.jpg', 'Front view', true, 0);
$imageTwo = $model->addPropertyImage(1, 'https://images.example.com/two.jpg', 'Garden', true, 1);
$images = $model->getPropertyImages(1);
$assert($imageOne > 0 && $imageTwo > $imageOne, 'Property image records should be created.');
$assert(
    count(array_filter($images, static function (array $image): bool {
        return (int) $image['is_primary'] === 1;
    })) === 1,
    'Adding a new primary image should unset the previous primary.'
);
$assert(
    $model->getPublicPropertyById(1)['primary_image_url'] === 'https://images.example.com/two.jpg',
    'The primary property image should be included in listing results.'
);

$assert(
    $model->updateProperty(1, [
        'price' => '525000.00',
        'city' => 'Fort Lauderdale',
        'latitude' => '26.1224',
        'longitude' => '-80.1373',
        'bedrooms' => 5,
    ]),
    'Schema-backed editable property fields should update.'
);
$updated = $model->getPropertyById(1);
$assert($updated['city'] === 'Fort Lauderdale' && (int) $updated['bedrooms'] === 5, 'Property updates should persist.');

$assert(
    !$model->setReviewStatus(2, 'approved'),
    'A property without a verified verification record must not be approved.'
);
$pdo->exec(
    "INSERT INTO property_verifications (property_id, verification_status)
     VALUES (2, 'pending')"
);
$assert(
    !$model->setReviewStatus(2, 'approved'),
    'A property with pending verification must not be approved.'
);
$pdo->exec(
    "UPDATE property_verifications SET verification_status = 'rejected' WHERE property_id = 2"
);
$assert(
    !$model->setReviewStatus(2, 'approved'),
    'A property with rejected verification must not be approved.'
);
$pdo->exec(
    "INSERT INTO property_verifications (property_id, verification_status)
     VALUES (2, 'pending')"
);
$pdo->exec(
    "UPDATE property_verifications
     SET verification_status = 'verified'
     WHERE property_id = 2 AND id = 1"
);
$assert(
    !$model->setReviewStatus(2, 'approved'),
    'An older verified record must not override a newer pending verification.'
);
$pdo->exec(
    "UPDATE property_verifications
     SET verification_status = 'verified'
     WHERE property_id = 2 AND id = 2"
);
$assert($model->setReviewStatus(2, 'approved'), 'An admin can approve a verified pending property.');
$assert(!$model->setReviewStatus(2, 'rejected'), 'A reviewed property cannot be transitioned from pending a second time.');
$assert($model->getPublicPropertyById(2) !== null, 'Approved and available properties should become public.');

$assert($model->archiveProperty(4), 'Archiving should update availability.');
$assert($model->getPublicPropertyById(4) === null, 'Archived properties should disappear from public results.');
$assert($model->getPropertyById(4)['availability_status'] === 'unavailable', 'Archiving must retain the property row.');
$assert((int) $pdo->query('SELECT COUNT(*) FROM properties')->fetchColumn() === 4, 'No property rows should be deleted.');

$assert(
    Middleware::requiredRolesForRoute('GET /api/v1/admin/properties') === ['admin'],
    'The all-status admin listing route must require the admin role.'
);
$assert(
    isset($propertyRoutes['PATCH /api/v1/properties/{id}'])
        && $propertyRoutes['DELETE /api/v1/properties/{id}'][1] === 'archiveProperty',
    'Property update and non-destructive archive routes should be registered.'
);
$assert(
    !isset($propertyRoutes['POST /api/v1/properties/{id}/images/{image_id}']),
    'The route set should not expose an unimplemented image mutation endpoint.'
);

fwrite(STDOUT, "Property API checks passed ({$assertions} assertions).\n");
