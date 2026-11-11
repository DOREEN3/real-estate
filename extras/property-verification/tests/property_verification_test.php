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
        title TEXT NOT NULL,
        address TEXT NULL,
        city TEXT NULL,
        country TEXT NULL,
        property_status TEXT NOT NULL,
        availability_status TEXT NOT NULL,
        FOREIGN KEY (owner_id) REFERENCES users(id)
    );
    CREATE TABLE property_verifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        property_id INTEGER NOT NULL,
        reviewed_by INTEGER NULL,
        verification_status TEXT NOT NULL DEFAULT 'pending',
        document_path TEXT NULL,
        review_notes TEXT NULL,
        reviewed_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (property_id) REFERENCES properties(id)
    );
    CREATE TABLE property_verification_visits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        verification_id INTEGER NOT NULL,
        assigned_to INTEGER NOT NULL,
        scheduled_at TEXT NULL,
        visit_status TEXT NOT NULL,
        visit_notes TEXT NULL,
        outcome_recorded_by INTEGER NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (verification_id) REFERENCES property_verifications(id),
        FOREIGN KEY (assigned_to) REFERENCES users(id),
        FOREIGN KEY (outcome_recorded_by) REFERENCES users(id)
    );"
);
$pdo->exec(
    "INSERT INTO users (id, full_name, email, role, account_status) VALUES
        (1, 'Owner One', 'owner@example.com', 'owner', 'approved'),
        (2, 'Approved Agent', 'agent@example.com', 'agent', 'approved'),
        (3, 'Pending Agent', 'pending@example.com', 'agent', 'pending'),
        (4, 'Admin', 'admin@example.com', 'admin', 'approved');
    INSERT INTO properties
        (id, owner_id, title, address, city, country, property_status, availability_status)
    VALUES (1, 1, 'Westlands Apartment', 'Westlands', 'Nairobi', 'Kenya', 'pending', 'available'),
           (2, 2, 'Approved Listing', NULL, 'Nairobi', 'Kenya', 'approved', 'available');"
);

require_once __DIR__ . '/../app/models/PropertyVerificationModel.php';
require_once __DIR__ . '/../app/security/Middleware.php';
require_once __DIR__ . '/../routes/PropertyRoutes.php';

$model = new PropertyVerificationModel();
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};

$verificationId = $model->requestVerification(1);
$assert($verificationId > 0, 'A verification request should be created.');
$assert(
    $model->requestVerification(1) === $verificationId,
    'Repeated requests should reuse the active verification rather than duplicate it.'
);
$assert(
    count($model->listForProperty(1)) === 1,
    'Verification history should expose the active property request.'
);
$assert(
    !$model->review($verificationId, 4, 'verified', 'missing document'),
    'A verification without a document cannot be reviewed.'
);
$assert(
    $model->attachDocument($verificationId, 'private/property-verifications/1/' . str_repeat('a', 48) . '.pdf'),
    'The property owner can attach a document to a pending verification.'
);
$assert(
    $model->getById($verificationId)['verification_status'] === 'documents_submitted',
    'Attaching a document should advance the request to documents_submitted.'
);
$assert(
    !$model->attachDocument($verificationId, 'private/property-verifications/1/' . str_repeat('b', 48) . '.pdf'),
    'An attached document cannot silently be replaced.'
);

$verification = $model->getById($verificationId);
$assert(
    (int) $verification['owner_id'] === 1
        && $verification['document_path'] === 'private/property-verifications/1/' . str_repeat('a', 48) . '.pdf',
    'Verification lookups should include property owner and the private stored path.'
);
$assert(
    $model->review($verificationId, 4, 'under_review', 'Document is legible'),
    'An administrator should be able to start document review.'
);
$assert(
    $model->review($verificationId, 4, 'visit_required', 'Confirm the title deed on site'),
    'An administrator may require an on-site visit.'
);

$visitId = $model->createVisit(
    $verificationId,
    2,
    '2099-01-02 10:00:00',
    'Inspect original ownership documents'
);
$assert($visitId > 0, 'An admin should be able to assign a visit to an approved agent.');
$visit = $model->getVisitById($visitId);
$assert(
    (int) $visit['assigned_to'] === 2
        && $visit['outcome_recorded_by'] === null
        && $visit['visit_status'] === 'scheduled',
    'A scheduled visit should have an assignee but no outcome recorder yet.'
);
$assert($model->hasAssignedVisit($verificationId, 2), 'Assigned agents should be able to access verification documents.');
$assert(!$model->hasAssignedVisit($verificationId, 3), 'Unassigned agents must not access verification documents.');
$assert(
    count($model->listVisitsForUser(2, false, 1, 10)) === 1
        && count($model->listVisitsForUser(3, false, 1, 10)) === 0,
    'Agents should only see their own assigned visits.'
);
$assert(
    count($model->listVisitsForUser(4, true, 1, 10)) === 1,
    'Administrators should be able to see all visits.'
);
$scheduledVisits = $model->listVisitsForUser(2, false, 1, 10);
$assert(
    count($scheduledVisits) === 1
        && $scheduledVisits[0]['outcome_recorded_by'] === null
        && $scheduledVisits[0]['outcome_recorded_by_name'] === null,
    'Scheduled visits without an outcome recorder should still appear in visit listings.'
);
$assert(
    !$model->recordVisitOutcome($visitId, 3, 3, 'completed', 'Visited'),
    'An unassigned agent cannot update a site visit.'
);
$assert(
    $model->recordVisitOutcome($visitId, 2, 2, 'completed', 'Original title deed verified'),
    'The assigned agent should be able to record the visit outcome.'
);
$completedVisit = $model->getVisitById($visitId);
$assert(
    (int) $completedVisit['outcome_recorded_by'] === 2
        && $model->getById($verificationId)['verification_status'] === 'under_review',
    'Completing a visit should record its agent and return verification to admin review.'
);
$completedVisits = $model->listVisitsForUser(2, false, 1, 10);
$assert(
    count($completedVisits) === 1
        && $completedVisits[0]['outcome_recorded_by_name'] === 'Approved Agent',
    'Visit listings should include the recorder name when an outcome has been submitted.'
);
$assert(
    !$model->recordVisitOutcome($visitId, 2, 2, 'cancelled', 'Too late'),
    'A completed visit cannot be overwritten.'
);
$assert(
    $model->review($verificationId, 4, 'verified', 'Document and in-person inspection passed'),
    'Admin should be able to finalize a reviewed verification.'
);
$assert(
    !$model->review($verificationId, 4, 'rejected', 'Overwrite'),
    'A finalized verification cannot be overwritten.'
);
$assert(
    $model->countAll('verified') === 1 && $model->countAll('pending') === 0,
    'Admin verification filters should count statuses correctly.'
);
$nextVerificationId = $model->requestVerification(1);
$assert(
    $nextVerificationId !== $verificationId
        && $model->countAll('pending') === 1,
    'A new request can be opened after the prior verification is finalized.'
);
$assert(
    Middleware::requiredRolesForRoute('GET /api/v1/admin/property-verifications') === ['admin']
        && Middleware::requiredRolesForRoute('PATCH /api/v1/admin/property-verifications/{id}/review') === ['admin']
        && Middleware::requiredRolesForRoute('POST /api/v1/admin/property-verifications/{id}/visits') === ['admin'],
    'Admin verification and visit-management routes must require explicit admin roles.'
);
$assert(
    isset($propertyRoutes['POST /api/v1/properties/{id}/verification'])
        && isset($propertyRoutes['GET /api/v1/property-verifications/{id}/document'])
        && isset($propertyRoutes['PATCH /api/v1/property-verification-visits/{id}']),
    'Verification request, private document, and visit outcome endpoints must be registered.'
);

fwrite(STDOUT, "Property verification checks passed ({$assertions} assertions).\n");
