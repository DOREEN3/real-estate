<?php

declare(strict_types=1);

date_default_timezone_set('Africa/Nairobi');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(
    "CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        full_name TEXT NOT NULL
    );
    CREATE TABLE properties (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        owner_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        property_type TEXT NOT NULL,
        transaction_type TEXT NOT NULL,
        price NUMERIC NOT NULL,
        currency TEXT NOT NULL,
        address TEXT NULL,
        city TEXT NULL,
        country TEXT NULL,
        property_status TEXT NOT NULL,
        availability_status TEXT NOT NULL
    );
    CREATE TABLE enquiries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        lead_name TEXT NOT NULL,
        phone TEXT NULL,
        email TEXT NULL,
        enquiry_source TEXT NULL,
        preferred_contact TEXT NULL,
        listing_type TEXT NULL,
        property_type TEXT NULL,
        country TEXT NULL,
        city TEXT NULL,
        area TEXT NULL,
        budget_currency TEXT NULL,
        min_budget NUMERIC NULL,
        max_budget NUMERIC NULL,
        message TEXT NULL,
        enquiry_status TEXT NOT NULL DEFAULT 'NEW',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE enquiry_follow_ups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        enquiry_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        follow_up_type TEXT NOT NULL,
        follow_up_status TEXT NULL,
        notes TEXT NULL,
        next_follow_up_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (enquiry_id) REFERENCES enquiries(id),
        FOREIGN KEY (user_id) REFERENCES users(id)
    );
    CREATE TABLE property_matches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        enquiry_id INTEGER NOT NULL,
        property_id INTEGER NOT NULL,
        match_status TEXT NOT NULL DEFAULT 'NEW',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (enquiry_id, property_id),
        FOREIGN KEY (enquiry_id) REFERENCES enquiries(id),
        FOREIGN KEY (property_id) REFERENCES properties(id)
    );
    CREATE TABLE inquiries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        property_id INTEGER NOT NULL,
        user_id INTEGER NULL,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        phone TEXT NULL,
        message TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'new',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (property_id) REFERENCES properties(id),
        FOREIGN KEY (user_id) REFERENCES users(id)
    );
    INSERT INTO users (id, full_name) VALUES (1, 'Admin User'), (2, 'Property Owner');
    INSERT INTO properties
        (id, owner_id, title, property_type, transaction_type, price, currency, address, city, country, property_status, availability_status)
    VALUES
        (1, 2, 'Westlands Apartment', 'apartment', 'sale', 2000000, 'KSH', 'Westlands', 'Nairobi', 'Kenya', 'approved', 'available'),
        (2, 2, 'Over Budget Apartment', 'apartment', 'sale', 4500000, 'KSH', 'Westlands', 'Nairobi', 'Kenya', 'approved', 'available'),
        (3, 2, 'Pending Apartment', 'apartment', 'sale', 1800000, 'KSH', 'Westlands', 'Nairobi', 'Kenya', 'pending', 'available'),
        (4, 2, 'Westlands Rental', 'apartment', 'rent', 45000, 'KSH', 'Westlands', 'Nairobi', 'Kenya', 'approved', 'available');"
);

require_once __DIR__ . '/../app/models/EnquiryModel.php';
require_once __DIR__ . '/../app/controllers/EnquiryController.php';
require_once __DIR__ . '/../app/security/Middleware.php';
require_once __DIR__ . '/../routes/EnquiryRoutes.php';

$model = new EnquiryModel();
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};

$enquiryId = $model->createEnquiry([
    'lead_name' => 'Grace Wanjiku',
    'phone' => '+254712345678',
    'email' => 'grace@example.com',
    'enquiry_source' => 'WhatsApp',
    'preferred_contact' => 'Phone',
    'listing_type' => 'For Sale',
    'property_type' => 'apartment',
    'country' => 'Kenya',
    'city' => 'Nairobi',
    'area' => 'Westlands',
    'budget_currency' => 'KSH',
    'min_budget' => '1000000.00',
    'max_budget' => '3000000.00',
    'message' => 'Looking for an apartment near work.',
]);
$enquiry = $model->getEnquiry($enquiryId);
$assert($enquiryId > 0 && $enquiry['enquiry_status'] === 'NEW', 'New enquiries should use the schema default status.');
$assert(
    $model->countEnquiries('NEW', 'WhatsApp') === 1
        && count($model->listEnquiries('NEW', 'WhatsApp', 1, 10)) === 1,
    'Enquiry status and source filters should compose with pagination.'
);
$assert(
    array_map('intval', array_column($model->refreshMatches($enquiryId), 'property_id')) === [1],
    'Matching should use listing type, type, location, currency, budget, approval, and availability: '
        . json_encode($model->listMatches($enquiryId))
);
$assert(
    count($model->refreshMatches($enquiryId)) === 1
        && (int) $pdo->query('SELECT COUNT(*) FROM property_matches')->fetchColumn() === 1,
    'Refreshing matches should not duplicate the unique enquiry/property pair.'
);
$matchCount = (int) $pdo->query('SELECT COUNT(*) FROM property_matches')->fetchColumn();
$model->listMatches($enquiryId);
$assert(
    (int) $pdo->query('SELECT COUNT(*) FROM property_matches')->fetchColumn() === $matchCount,
    'Reading saved matches should not create database rows.'
);
$matchId = (int) $pdo->query('SELECT id FROM property_matches LIMIT 1')->fetchColumn();
$assert($model->updateMatchStatus($matchId, 'INTERESTED'), 'An existing property match status should update.');
$assert(
    $model->listMatches($enquiryId)[0]['match_status'] === 'INTERESTED',
    'The selected match status should be returned by the match workflow.'
);

$followUpId = $model->addFollowUp($enquiryId, 1, [
    'follow_up_type' => 'Call',
    'follow_up_status' => 'CONTACTED',
    'notes' => 'Buyer requested a viewing.',
    'next_follow_up_at' => '2026-11-06 09:00:00',
]);
$followUps = $model->listFollowUps($enquiryId);
$assert(
    $followUpId > 0 && $followUps[0]['user_name'] === 'Admin User'
        && $followUps[0]['next_follow_up_at'] === '2026-11-06 09:00:00',
    'Follow-up history should retain the acting user, outcome, notes, and next contact time.'
);
$assert($model->updateStatus($enquiryId, 'FOLLOW_UP'), 'Enquiry status should update without deleting its history.');
$assert($model->getEnquiry($enquiryId)['enquiry_status'] === 'FOLLOW_UP', 'The updated enquiry status should persist.');

$propertyInquiryId = $model->createInquiry(1, null, [
    'name' => 'Daniel Kimani',
    'email' => 'daniel@example.com',
    'phone' => '+254722000000',
    'message' => 'Is this apartment still available?',
]);
$propertyInquiries = $model->listInquiries('new', 1, 1, 10);
$assert(
    $propertyInquiryId > 0 && $propertyInquiries[0]['property_title'] === 'Westlands Apartment',
    'Property inquiries should remain separate and retain their property relationship.'
);
$assert($model->updateInquiryStatus($propertyInquiryId, 'replied'), 'A property inquiry status should be updateable.');
$assert($model->getInquiry($propertyInquiryId)['status'] === 'replied', 'Property inquiry status should persist.');

$assert(
    Middleware::requiredRolesForRoute('GET /api/v1/admin/enquiries') === ['admin']
        && Middleware::requiredRolesForRoute('GET /api/v1/enquiries/{id}/follow-ups') === ['admin'],
    'Buyer lead and follow-up history routes must be admin-only until assignment exists in the schema.'
);
$assert(
    Middleware::requiredRolesForRoute('POST /api/v1/enquiries/{id}/matches/refresh') === ['admin'],
    'Refreshing property matches must be admin-only.'
);
$assert(
    Middleware::requiredRolesForRoute('GET /api/v1/properties/{id}/inquiries') === ['admin', 'owner'],
    'Property inquiry history must be restricted to admins and property owners.'
);
$assert(
    Middleware::isPublicRoute('POST', '/api/v1/enquiries')
        && Middleware::isPublicRoute('POST', '/api/v1/properties/1/inquiries'),
    'Buyer enquiry intake and public property inquiry submission should be public routes.'
);
$controller = new EnquiryController();
$validateEnquiry = new ReflectionMethod($controller, 'validatedEnquiry');
$validateEnquiry->setAccessible(true);
$validateEnquiryStatus = new ReflectionMethod($controller, 'validatedEnquiryStatus');
$validateEnquiryStatus->setAccessible(true);
$assert(
    $validateEnquiryStatus->invoke($controller, ['status' => 'Follow Up']) === 'FOLLOW_UP'
        && $validateEnquiryStatus->invoke($controller, ['enquiry_status' => 'CONTACTED']) === 'CONTACTED',
    'Status updates should accept the API-friendly status field and the database-column alias.'
);
$normalizedEnquiry = $validateEnquiry->invoke($controller, [
    'lead_name' => 'Grace Wanjiku',
    'enquiry_source' => 'whatsapp',
    'budget_currency' => 'ksh',
    'min_budget' => 1000,
    'max_budget' => '2500.5',
]);
$assert(
    $normalizedEnquiry['enquiry_source'] === 'WhatsApp'
        && $normalizedEnquiry['budget_currency'] === 'KSH'
        && $normalizedEnquiry['min_budget'] === '1000.00'
        && $normalizedEnquiry['max_budget'] === '2500.50',
    'Enquiry input should be canonicalized and budgets normalized to the database decimal scale.'
);
$normalizeDateTime = new ReflectionMethod($controller, 'optionalDateTime');
$normalizeDateTime->setAccessible(true);
$assert(
    $normalizeDateTime->invoke($controller, ['next_follow_up_at' => '2026-11-06T06:00:00Z'], 'next_follow_up_at')
        === '2026-11-06 09:00:00',
    'An offset-aware follow-up time should be normalized to the database timezone before storage.'
);
$assert(
    isset($enquiryRoutes['POST /api/v1/enquiries'])
        && isset($enquiryRoutes['POST /api/v1/properties/{id}/inquiries'])
        && !isset($enquiryRoutes['DELETE /api/v1/enquiries/{id}']),
    'The endpoint set should expose the distinct create workflows and no destructive lead deletion.'
);

fwrite(STDOUT, "Enquiry API checks passed ({$assertions} assertions).\n");
