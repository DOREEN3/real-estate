-- Run once on existing installations after confirming both tables exist.
-- These ALTERs preserve existing rows and only enable generated IDs and indexed lookups.
ALTER TABLE property_verifications
    MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT,
    ADD INDEX idx_property_verifications_status_created (verification_status, created_at);

ALTER TABLE property_verification_visits
    MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT,
    ADD INDEX idx_verification_visits_assignee_schedule (assigned_to, scheduled_at);
