-- Migration: Rentals & BnB Bookings Support with Property Availability Synchronization

-- 1. Add security deposit to tenancies
ALTER TABLE tenancies 
    ADD COLUMN IF NOT EXISTS security_deposit DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER monthly_rent;

-- 2. Extend property availability statuses to include 'reserved' and 'occupied'
ALTER TABLE properties 
    MODIFY COLUMN availability_status 
    ENUM('available', 'sold', 'rented', 'reserved', 'occupied', 'unavailable') 
    NOT NULL DEFAULT 'available';

-- 3. Create BnB bookings table
CREATE TABLE IF NOT EXISTS bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    guest_name VARCHAR(150) NOT NULL,
    guest_email VARCHAR(150) NOT NULL,
    guest_phone VARCHAR(30) NOT NULL,
    check_in_date DATE NOT NULL,
    check_out_date DATE NOT NULL,
    nightly_rate DECIMAL(15,2) NOT NULL,
    number_of_nights INT NOT NULL,
    accommodation_total DECIMAL(15,2) NOT NULL,
    required_booking_deposit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    required_damage_deposit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(10) NOT NULL DEFAULT 'KES',
    booking_status ENUM('confirmed', 'checked_in', 'checked_out', 'cancelled') NOT NULL DEFAULT 'confirmed',
    special_requests TEXT DEFAULT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE RESTRICT,
    INDEX idx_booking_property_dates (property_id, check_in_date, check_out_date),
    INDEX idx_booking_status (booking_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Extend unified payments table to support tenancy and booking references
ALTER TABLE payments 
    MODIFY COLUMN sale_id INT NULL,
    ADD COLUMN IF NOT EXISTS tenancy_id INT NULL AFTER sale_id,
    ADD COLUMN IF NOT EXISTS booking_id INT NULL AFTER tenancy_id,
    ADD INDEX IF NOT EXISTS idx_pay_tenancy (tenancy_id),
    ADD INDEX IF NOT EXISTS idx_pay_booking (booking_id);
