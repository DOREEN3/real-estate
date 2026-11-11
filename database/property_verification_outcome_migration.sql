-- Run once on existing installations. Scheduled visits have no outcome recorder yet.
ALTER TABLE property_verification_visits
    MODIFY COLUMN outcome_recorded_by INT(11) NULL DEFAULT NULL;
