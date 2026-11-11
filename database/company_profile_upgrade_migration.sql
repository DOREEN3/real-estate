-- ==============================================================================
-- GoldERP Real Estate: Company Profile Upgrade Migration
-- Adds physical address, city, phone, WhatsApp, email, and KRA PIN fields
-- ==============================================================================

ALTER TABLE company_profile
    ADD COLUMN IF NOT EXISTS physical_address VARCHAR(255) NULL AFTER logo_url,
    ADD COLUMN IF NOT EXISTS city VARCHAR(100) NULL AFTER physical_address,
    ADD COLUMN IF NOT EXISTS phone_number VARCHAR(50) NULL AFTER city,
    ADD COLUMN IF NOT EXISTS whatsapp_number VARCHAR(50) NULL AFTER phone_number,
    ADD COLUMN IF NOT EXISTS email VARCHAR(150) NULL AFTER whatsapp_number,
    ADD COLUMN IF NOT EXISTS kra_pin VARCHAR(50) NULL AFTER email;
