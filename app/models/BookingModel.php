<?php

declare(strict_types=1);

if (!isset($pdo)) {
    require_once __DIR__ . '/../../config/database.php';
}
require_once __DIR__ . '/../helpers/PropertyAvailabilityHelper.php';

class BookingModel
{
    public function createBooking(int $userId, array $data): int
    {
        global $pdo;

        $pdo->beginTransaction();
        try {
            $propertyId = (int) $data['property_id'];
            $property = $this->fetchOne(
                'SELECT id, title, price, currency, transaction_type, property_status, availability_status
                 FROM properties WHERE id = :id',
                [':id' => $propertyId],
                true
            );

            if ($property === null) {
                throw new DomainException('property_not_found');
            }
            if ($property['transaction_type'] !== 'bnb' || $property['property_status'] !== 'approved') {
                throw new DomainException('property_not_available_for_bnb');
            }
            if (in_array($property['availability_status'], ['sold', 'unavailable'], true)) {
                throw new DomainException('property_is_unavailable');
            }

            $checkIn = new DateTimeImmutable($data['check_in_date']);
            $checkOut = new DateTimeImmutable($data['check_out_date']);
            if ($checkOut <= $checkIn) {
                throw new DomainException('checkout_must_be_after_checkin');
            }

            $nights = (int) $checkIn->diff($checkOut)->days;
            if ($nights < 1) {
                throw new DomainException('invalid_booking_duration');
            }

            if ($this->hasOverlappingBooking($propertyId, $data['check_in_date'], $data['check_out_date'])) {
                throw new DomainException('booking_date_collision');
            }

            $nightlyRate = isset($data['nightly_rate']) && (float) $data['nightly_rate'] > 0
                ? (float) $data['nightly_rate']
                : (float) $property['price'];

            $accommodationTotal = bcmul((string) $nightlyRate, (string) $nights, 2);
            $requiredBookingDeposit = isset($data['required_booking_deposit'])
                ? (string) $data['required_booking_deposit']
                : '0.00';
            $requiredDamageDeposit = isset($data['required_damage_deposit'])
                ? (string) $data['required_damage_deposit']
                : '0.00';
            $currency = $data['currency'] ?? $property['currency'] ?? 'KES';

            $stmt = $pdo->prepare(
                'INSERT INTO bookings
                    (property_id, guest_name, guest_email, guest_phone,
                     check_in_date, check_out_date, nightly_rate, number_of_nights,
                     accommodation_total, required_booking_deposit, required_damage_deposit,
                     currency, booking_status, special_requests, created_by)
                 VALUES
                    (:property_id, :guest_name, :guest_email, :guest_phone,
                     :check_in_date, :check_out_date, :nightly_rate, :number_of_nights,
                     :accommodation_total, :required_booking_deposit, :required_damage_deposit,
                     :currency, :booking_status, :special_requests, :created_by)'
            );

            $stmt->execute([
                ':property_id' => $propertyId,
                ':guest_name' => $data['guest_name'],
                ':guest_email' => $data['guest_email'],
                ':guest_phone' => $data['guest_phone'],
                ':check_in_date' => $data['check_in_date'],
                ':check_out_date' => $data['check_out_date'],
                ':nightly_rate' => $nightlyRate,
                ':number_of_nights' => $nights,
                ':accommodation_total' => $accommodationTotal,
                ':required_booking_deposit' => $requiredBookingDeposit,
                ':required_damage_deposit' => $requiredDamageDeposit,
                ':currency' => $currency,
                ':booking_status' => 'confirmed',
                ':special_requests' => $data['special_requests'] ?? null,
                ':created_by' => $userId,
            ]);

            $bookingId = (int) $pdo->lastInsertId();
            if ($bookingId <= 0) {
                throw new RuntimeException('Unable to retrieve created booking ID');
            }

            // Sync property summary status
            PropertyAvailabilityHelper::syncStatus($propertyId);

            $pdo->commit();

            return $bookingId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function hasOverlappingBooking(
        int $propertyId,
        string $checkIn,
        string $checkOut,
        ?int $excludeBookingId = null
    ): bool {
        global $pdo;

        $excludeSql = $excludeBookingId !== null ? ' AND id <> :exclude_id' : '';
        $stmt = $pdo->prepare(
            "SELECT id FROM bookings
             WHERE property_id = :property_id
               AND booking_status IN ('confirmed', 'checked_in')
               AND check_in_date < :check_out
               AND check_out_date > :check_in
               {$excludeSql}
             LIMIT 1"
        );

        $params = [
            ':property_id' => $propertyId,
            ':check_in' => $checkIn,
            ':check_out' => $checkOut,
        ];
        if ($excludeBookingId !== null) {
            $params[':exclude_id'] = $excludeBookingId;
        }

        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    public function getBooking(int $bookingId): ?array
    {
        global $pdo;

        $booking = $this->fetchOne(
            'SELECT b.*,
                    p.title AS property_title,
                    p.address AS property_address,
                    p.city AS property_city,
                    p.availability_status AS property_availability_status,
                    u.full_name AS created_by_name
             FROM bookings b
             JOIN properties p ON p.id = b.property_id
             LEFT JOIN users u ON u.id = b.created_by
             WHERE b.id = :id',
            [':id' => $bookingId]
        );

        if ($booking === null) {
            return null;
        }

        // Financial calculations from unified payments
        $stmt = $pdo->prepare(
            "SELECT 
                COALESCE(SUM(CASE WHEN payment_type IN ('accommodation', 'deposit') THEN amount ELSE 0 END), 0) AS accommodation_paid,
                COALESCE(SUM(CASE WHEN payment_type = 'damage_deposit' THEN amount ELSE 0 END), 0) AS damage_deposit_paid,
                COALESCE(SUM(amount), 0) AS total_paid
             FROM payments
             WHERE booking_id = :id AND payment_status = 'completed'"
        );
        $stmt->execute([':id' => $bookingId]);
        $finances = $stmt->fetch() ?: [
            'accommodation_paid' => '0.00',
            'damage_deposit_paid' => '0.00',
            'total_paid' => '0.00',
        ];

        $accommodationTotal = (float) $booking['accommodation_total'];
        $accommodationPaid = (float) $finances['accommodation_paid'];
        $damageRequired = (float) $booking['required_damage_deposit'];
        $damagePaid = (float) $finances['damage_deposit_paid'];

        $booking['accommodation_paid'] = number_format($accommodationPaid, 2, '.', '');
        $booking['accommodation_balance'] = number_format(max(0, $accommodationTotal - $accommodationPaid), 2, '.', '');
        $booking['damage_deposit_paid'] = number_format($damagePaid, 2, '.', '');
        $booking['damage_deposit_balance'] = number_format(max(0, $damageRequired - $damagePaid), 2, '.', '');
        $booking['total_paid'] = number_format((float) $finances['total_paid'], 2, '.', '');

        return $booking;
    }

    public function listBookings(array $filters, int $page, int $limit): array
    {
        [$where, $params] = $this->bookingFilters($filters);

        return $this->fetchPage(
            "SELECT b.*,
                    p.title AS property_title,
                    p.city AS property_city,
                    (
                        SELECT pi.image_url
                        FROM property_images pi
                        WHERE pi.property_id = p.id
                        ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC
                        LIMIT 1
                    ) AS property_image_url,
                    COALESCE((
                        SELECT SUM(amount)
                        FROM payments pmt
                        WHERE pmt.booking_id = b.id
                          AND pmt.payment_status = 'completed'
                          AND pmt.payment_type IN ('accommodation', 'deposit')
                    ), 0) AS accommodation_paid,
                    b.accommodation_total - COALESCE((
                        SELECT SUM(amount)
                        FROM payments pmt
                        WHERE pmt.booking_id = b.id
                          AND pmt.payment_status = 'completed'
                          AND pmt.payment_type IN ('accommodation', 'deposit')
                    ), 0) AS accommodation_balance,
                    COALESCE((
                        SELECT SUM(amount)
                        FROM payments pmt
                        WHERE pmt.booking_id = b.id
                          AND pmt.payment_status = 'completed'
                          AND pmt.payment_type = 'damage_deposit'
                    ), 0) AS damage_deposit_paid
             FROM bookings b
             JOIN properties p ON p.id = b.property_id
             {$where}
             ORDER BY b.created_at DESC, b.id DESC",
            $params,
            $page,
            $limit
        );
    }

    public function countBookings(array $filters): int
    {
        [$where, $params] = $this->bookingFilters($filters);

        return $this->countRows(
            "SELECT COUNT(*) FROM bookings b JOIN properties p ON p.id = b.property_id {$where}",
            $params
        );
    }

    public function updateBookingStatus(int $bookingId, string $newStatus): array
    {
        global $pdo;

        $allowedStatuses = ['confirmed', 'checked_in', 'checked_out', 'cancelled'];
        if (!in_array($newStatus, $allowedStatuses, true)) {
            throw new DomainException('invalid_booking_status');
        }

        $pdo->beginTransaction();
        try {
            $booking = $this->fetchOne(
                'SELECT id, property_id, booking_status, check_in_date, check_out_date
                 FROM bookings WHERE id = :id',
                [':id' => $bookingId],
                true
            );

            if ($booking === null) {
                throw new DomainException('booking_not_found');
            }

            $current = $booking['booking_status'];

            // Validate status transitions
            $valid = false;
            if ($current === 'confirmed' && in_array($newStatus, ['checked_in', 'cancelled'], true)) {
                $valid = true;
            } elseif ($current === 'checked_in' && $newStatus === 'checked_out') {
                $valid = true;
            } elseif ($current === $newStatus) {
                $valid = true;
            }

            if (!$valid) {
                throw new DomainException("Cannot transition booking from '{$current}' to '{$newStatus}'");
            }

            $stmt = $pdo->prepare('UPDATE bookings SET booking_status = :status WHERE id = :id');
            $stmt->execute([':status' => $newStatus, ':id' => $bookingId]);

            // Synchronize property availability
            PropertyAvailabilityHelper::syncStatus((int) $booking['property_id']);

            $pdo->commit();

            return $this->getBooking($bookingId);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function checkAvailability(int $propertyId, string $checkIn, string $checkOut): array
    {
        global $pdo;

        $checkInDate = new DateTimeImmutable($checkIn);
        $checkOutDate = new DateTimeImmutable($checkOut);
        if ($checkOutDate <= $checkInDate) {
            throw new DomainException('checkout_must_be_after_checkin');
        }

        $nights = (int) $checkInDate->diff($checkOutDate)->days;

        $stmt = $pdo->prepare(
            "SELECT id, guest_name, check_in_date, check_out_date, booking_status
             FROM bookings
             WHERE property_id = :property_id
               AND booking_status IN ('confirmed', 'checked_in')
               AND check_in_date < :check_out
               AND check_out_date > :check_in
             ORDER BY check_in_date ASC"
        );
        $stmt->execute([
            ':property_id' => $propertyId,
            ':check_in' => $checkIn,
            ':check_out' => $checkOut,
        ]);
        $conflicts = $stmt->fetchAll();

        return [
            'available' => empty($conflicts),
            'nights' => $nights,
            'conflicts' => $conflicts,
        ];
    }

    private function bookingFilters(array $filters): array
    {
        $conditions = [];
        $params = [];

        if (!empty($filters['property_id'])) {
            $conditions[] = 'b.property_id = :property_id';
            $params[':property_id'] = $filters['property_id'];
        }
        if (!empty($filters['booking_status'])) {
            $conditions[] = 'b.booking_status = :booking_status';
            $params[':booking_status'] = $filters['booking_status'];
        }
        if (!empty($filters['guest_email'])) {
            $conditions[] = 'b.guest_email LIKE :guest_email';
            $params[':guest_email'] = '%' . $filters['guest_email'] . '%';
        }
        if (!empty($filters['guest_name'])) {
            $conditions[] = 'b.guest_name LIKE :guest_name';
            $params[':guest_name'] = '%' . $filters['guest_name'] . '%';
        }
        if (!empty($filters['from_date'])) {
            $conditions[] = 'b.check_in_date >= :from_date';
            $params[':from_date'] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $conditions[] = 'b.check_out_date <= :to_date';
            $params[':to_date'] = $filters['to_date'];
        }

        return [$conditions ? 'WHERE ' . implode(' AND ', $conditions) : '', $params];
    }

    private function fetchOne(string $sql, array $params, bool $forUpdate = false): ?array
    {
        global $pdo;

        $lock = $forUpdate && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ' FOR UPDATE'
            : '';
        $stmt = $pdo->prepare($sql . ' LIMIT 1' . $lock);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function fetchPage(string $sql, array $params, int $page, int $limit): array
    {
        global $pdo;

        $stmt = $pdo->prepare($sql . ' LIMIT :limit OFFSET :offset');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function countRows(string $sql, array $params): int
    {
        global $pdo;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
