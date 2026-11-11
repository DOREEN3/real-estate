ALTER TABLE properties
    ADD COLUMN managed_by INT NULL AFTER owner_id,
    ADD INDEX idx_properties_managed_by (managed_by),
    ADD CONSTRAINT fk_properties_managed_by
        FOREIGN KEY (managed_by) REFERENCES users(id) ON DELETE SET NULL;
