<?php

declare(strict_types=1);

/**
 * Property Availability Synchronization
 * Synchronizes property operational summary status across Tenancies and BnB Bookings.
 * 
 * Rules:
 * - Active Long-term Tenancy -> 'rented'
 * - Guest checked in -> 'occupied'
 * - Confirmed reservation active today -> 'reserved'
 * - Guest checked out / lease ended, no other active booking -> 'available'
 * - 'sold' and manual 'unavailable' are preserved
 */
class PropertyAvailabilityHelper
{
    public static function syncStatus(int $propertyId): string
    {
        global $pdo;

        $stmt = $pdo->prepare('SELECT availability_status FROM properties WHERE id = :id');
        $stmt->execute([':id' => $propertyId]);
        $current = $stmt->fetchColumn();

        if ($current === false) {
            return 'unavailable';
        }

        if (in_array($current, ['sold', 'unavailable'], true)) {
            return (string) $current;
        }

        $today = date('Y-m-d');

        // 1. Long-Term Tenancy Check
        $tStmt = $pdo->prepare(
            "SELECT id FROM tenancies 
             WHERE property_id = :id 
               AND tenancy_status = 'active'
               AND (end_date IS NULL OR end_date >= :today)
             LIMIT 1"
        );
        $tStmt->execute([':id' => $propertyId, ':today' => $today]);
        if ($tStmt->fetchColumn() !== false) {
            self::updateStatus($propertyId, 'rented');
            return 'rented';
        }

        // 2. BnB Bookings Check
        // 2a. Guest physically checked in
        $bOccupied = $pdo->prepare(
            "SELECT id FROM bookings 
             WHERE property_id = :id 
               AND booking_status = 'checked_in' 
             LIMIT 1"
        );
        $bOccupied->execute([':id' => $propertyId]);
        if ($bOccupied->fetchColumn() !== false) {
            self::updateStatus($propertyId, 'occupied');
            return 'occupied';
        }

        // 2b. Confirmed reservation upcoming or ongoing (check_out_date >= today)
        $bReserved = $pdo->prepare(
            "SELECT id FROM bookings 
             WHERE property_id = :id 
               AND booking_status = 'confirmed' 
               AND check_out_date >= :today 
             ORDER BY check_in_date ASC
             LIMIT 1"
        );
        $bReserved->execute([':id' => $propertyId, ':today' => $today]);
        if ($bReserved->fetchColumn() !== false) {
            self::updateStatus($propertyId, 'reserved');
            return 'reserved';
        }

        // 2c. No active occupancy
        self::updateStatus($propertyId, 'available');
        return 'available';
    }

    private static function updateStatus(int $propertyId, string $status): void
    {
        global $pdo;
        $stmt = $pdo->prepare('UPDATE properties SET availability_status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $propertyId]);
    }
}
