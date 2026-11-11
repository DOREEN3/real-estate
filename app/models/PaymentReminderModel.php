<?php

declare(strict_types=1);

if (!isset($pdo)) {
    require_once __DIR__ . '/../../config/database.php';
}

class PaymentReminderModel
{
    public function activeTenancyIds(): array
    {
        global $pdo;

        $stmt = $pdo->query("SELECT id FROM tenancies WHERE tenancy_status = 'active' ORDER BY id");
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function installmentBalances(string $throughDate): array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT 'installment' AS target_type, i.id AS target_id, i.due_date,
                    i.amount_due,
                    i.amount_due - COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN p.amount ELSE 0 END), 0) AS outstanding_balance,
                    s.currency, s.buyer_name AS recipient_name, s.buyer_email AS recipient_email,
                    NULL AS recipient_phone, pr.title AS property_title,
                    i.installment_number, s.id AS sale_id, NULL AS rent_charge_id, 0 AS grace_period_days
             FROM installments i
             JOIN sales s ON s.id = i.sale_id
             JOIN properties pr ON pr.id = s.property_id
             LEFT JOIN payments p ON p.installment_id = i.id
             WHERE i.due_date <= :through_date
             GROUP BY i.id, i.due_date, i.amount_due, s.currency, s.buyer_name, s.buyer_email,
                      pr.title, i.installment_number, s.id
             HAVING outstanding_balance > 0
             ORDER BY i.due_date, i.id"
        );
        $stmt->execute([':through_date' => $throughDate]);
        return $stmt->fetchAll();
    }

    public function rentChargeBalances(string $throughDate): array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT 'rent_charge' AS target_type, rc.id AS target_id, rc.due_date,
                    rc.amount_due,
                    rc.amount_due - (
                        COALESCE((
                            SELECT SUM(p.amount)
                            FROM payments p
                            WHERE p.rent_charge_id = rc.id AND p.payment_status = 'completed'
                        ), 0) + COALESCE((
                            SELECT SUM(rp.amount_paid)
                            FROM rent_payments rp
                            WHERE rp.rent_charge_id = rc.id
                              AND rp.payment_id IS NULL
                        ), 0)
                    ) AS outstanding_balance,
                    rc.currency, tn.full_name AS recipient_name, tn.email AS recipient_email,
                    tn.phone AS recipient_phone, pr.title AS property_title,
                    NULL AS installment_number, NULL AS sale_id, rc.id AS rent_charge_id,
                    t.grace_period_days
             FROM rent_charges rc
             JOIN tenancies t ON t.id = rc.tenancy_id AND t.tenancy_status = 'active'
             JOIN tenants tn ON tn.id = t.tenant_id
             JOIN properties pr ON pr.id = t.property_id
             WHERE rc.due_date <= :through_date
             GROUP BY rc.id, rc.due_date, rc.amount_due, rc.currency, tn.full_name, tn.email,
                      tn.phone, pr.title, t.grace_period_days
             HAVING outstanding_balance > 0
             ORDER BY rc.due_date, rc.id"
        );
        $stmt->execute([':through_date' => $throughDate]);
        return $stmt->fetchAll();
    }

    public function getOrCreateInvoice(array $target, string $invoiceDate): array
    {
        global $pdo;

        $targetType = $target['target_type'];
        if (!in_array($targetType, ['installment', 'rent_charge'], true)) {
            throw new InvalidArgumentException('Unsupported invoice target.');
        }
        $targetColumn = $targetType === 'installment' ? 'installment_id' : 'rent_charge_id';
        $targetId = (int) $target['target_id'];
        $find = $pdo->prepare("SELECT * FROM payment_invoices WHERE {$targetColumn} = :target_id LIMIT 1");
        $find->execute([':target_id' => $targetId]);
        $invoice = $find->fetch();
        if ($invoice) {
            return $invoice;
        }

        $number = sprintf(
            'INV-%s-%d-%s',
            $targetType === 'installment' ? 'SALE' : 'RENT',
            $targetId,
            strtoupper(bin2hex(random_bytes(3)))
        );
        $insert = $pdo->prepare(
            "INSERT INTO payment_invoices
                (invoice_number, {$targetColumn}, invoice_date, due_date, amount_due, currency)
             VALUES
                (:invoice_number, :target_id, :invoice_date, :due_date, :amount_due, :currency)"
        );
        try {
            $insert->execute([
                ':invoice_number' => $number,
                ':target_id' => $targetId,
                ':invoice_date' => $invoiceDate,
                ':due_date' => $target['due_date'],
                ':amount_due' => $target['outstanding_balance'],
                ':currency' => $target['currency'],
            ]);
        } catch (PDOException $exception) {
            $find->execute([':target_id' => $targetId]);
            $invoice = $find->fetch();
            if ($invoice) {
                return $invoice;
            }
            throw $exception;
        }

        $find->execute([':target_id' => $targetId]);
        $invoice = $find->fetch();
        if (!$invoice) {
            throw new RuntimeException('Unable to retrieve the created invoice.');
        }
        return $invoice;
    }

    public function ensureDispatch(array $target, string $event, string $eventDate): array
    {
        global $pdo;

        $values = [
            ':target_type' => $target['target_type'],
            ':target_id' => (int) $target['target_id'],
            ':event_key' => $event,
            ':event_date' => $eventDate,
        ];
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $sql = 'INSERT INTO payment_reminder_dispatches (target_type, target_id, event_key, event_date)
                    VALUES (:target_type, :target_id, :event_key, :event_date)
                    ON CONFLICT(target_type, target_id, event_key) DO NOTHING';
        } else {
            $sql = 'INSERT IGNORE INTO payment_reminder_dispatches (target_type, target_id, event_key, event_date)
                    VALUES (:target_type, :target_id, :event_key, :event_date)';
        }
        $pdo->prepare($sql)->execute($values);

        $stmt = $pdo->prepare(
            'SELECT * FROM payment_reminder_dispatches
             WHERE target_type = :target_type AND target_id = :target_id AND event_key = :event_key'
        );
        $stmt->execute([
            ':target_type' => $target['target_type'],
            ':target_id' => (int) $target['target_id'],
            ':event_key' => $event,
        ]);
        $dispatch = $stmt->fetch();
        if (!$dispatch) {
            throw new RuntimeException('Unable to retrieve reminder dispatch record.');
        }
        return $dispatch;
    }

    public function retryEventsForTarget(array $target): array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT event_key FROM payment_reminder_dispatches
             WHERE target_type = :target_type
               AND target_id = :target_id
               AND (email_status IN ('pending', 'failed') OR sms_status IN ('pending', 'failed'))
             ORDER BY event_date, id"
        );
        $stmt->execute([
            ':target_type' => $target['target_type'],
            ':target_id' => (int) $target['target_id'],
        ]);
        return array_values(array_unique($stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function claimChannel(int $dispatchId, string $channel): bool
    {
        global $pdo;

        if (!in_array($channel, ['email', 'sms'], true)) {
            throw new InvalidArgumentException('Unsupported reminder channel.');
        }
        $column = $channel . '_status';
        $staleSending = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "({$column} = 'sending' AND updated_at < datetime('now', '-15 minutes'))"
            : "({$column} = 'sending' AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE))";
        $stmt = $pdo->prepare(
            "UPDATE payment_reminder_dispatches
             SET {$column} = 'sending'
             WHERE id = :id AND ({$column} IN ('pending', 'failed') OR {$staleSending})"
        );
        $stmt->execute([':id' => $dispatchId]);
        return $stmt->rowCount() === 1;
    }

    public function finishChannel(int $dispatchId, string $channel, string $status, ?string $error = null): void
    {
        global $pdo;

        if (!in_array($channel, ['email', 'sms'], true)
            || !in_array($status, ['sent', 'failed', 'skipped'], true)
        ) {
            throw new InvalidArgumentException('Invalid reminder channel result.');
        }
        $column = $channel . '_status';
        $stmt = $pdo->prepare(
            "UPDATE payment_reminder_dispatches
             SET {$column} = :status, last_error = :error
             WHERE id = :id"
        );
        $stmt->execute([':status' => $status, ':error' => $error, ':id' => $dispatchId]);
    }
}
