<?php

declare(strict_types=1);

if (!isset($pdo)) {
    require_once __DIR__ . '/../../config/database.php';
}

class NotificationLogModel
{
    private const LINKED_RECORD_TABLES = [
        'enquiry_id' => 'enquiries',
        'property_id' => 'properties',
        'sale_id' => 'sales',
        'installment_id' => 'installments',
    ];

    public function create(array $data): int
    {
        global $pdo;

        $columns = [
            'recipient_name',
            'recipient_contact',
            'channel',
            'notification_type',
            'message',
            'enquiry_id',
            'property_id',
            'sale_id',
            'installment_id',
        ];
        $names = implode(', ', $columns);
        $placeholders = implode(', ', array_map(
            static fn (string $column): string => ':' . $column,
            $columns
        ));
        $values = [];
        foreach ($columns as $column) {
            $values[':' . $column] = $data[$column] ?? null;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO notification_logs ({$names}) VALUES ({$placeholders})"
        );
        $stmt->execute($values);
        $id = (int) $pdo->lastInsertId();
        if ($id <= 0) {
            throw new RuntimeException('Unable to retrieve the created notification log ID.');
        }

        return $id;
    }

    public function getById(int $id): ?array
    {
        global $pdo;

        $stmt = $pdo->prepare('SELECT * FROM notification_logs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $notification = $stmt->fetch();

        return $notification ?: null;
    }

    public function list(array $filters, int $page, int $limit): array
    {
        global $pdo;

        [$where, $params] = $this->buildFilters($filters);
        $stmt = $pdo->prepare(
            "SELECT * FROM notification_logs {$where}
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function count(array $filters): int
    {
        global $pdo;

        [$where, $params] = $this->buildFilters($filters);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notification_logs {$where}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function linkedRecordExists(string $column, int $id): bool
    {
        global $pdo;

        if (!isset(self::LINKED_RECORD_TABLES[$column])) {
            throw new InvalidArgumentException('Unsupported notification relationship.');
        }
        $table = self::LINKED_RECORD_TABLES[$column];
        $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);

        return $stmt->fetchColumn() !== false;
    }

    public function transitionStatus(
        int $id,
        string $expectedStatus,
        string $newStatus,
        array $data
    ): bool {
        global $pdo;

        $stmt = $pdo->prepare(
            "UPDATE notification_logs
             SET delivery_status = :new_status,
                 provider = COALESCE(:provider, provider),
                 provider_reference = COALESCE(:provider_reference, provider_reference),
                 error_message = CASE WHEN :error_status = 'failed' THEN :error_message ELSE NULL END,
                 retry_count = retry_count + CASE WHEN :retry_status = 'pending' THEN 1 ELSE 0 END,
                 sent_at = CASE
                     WHEN :sent_status IN ('sent', 'delivered') THEN COALESCE(sent_at, CURRENT_TIMESTAMP)
                     ELSE sent_at
                 END,
                 delivered_at = CASE
                     WHEN :delivered_status = 'delivered' THEN COALESCE(delivered_at, CURRENT_TIMESTAMP)
                     ELSE delivered_at
                 END
             WHERE id = :id AND delivery_status = :expected_status"
        );
        $stmt->execute([
            ':new_status' => $newStatus,
            ':provider' => $data['provider'],
            ':provider_reference' => $data['provider_reference'],
            ':error_status' => $newStatus,
            ':error_message' => $data['error_message'],
            ':retry_status' => $newStatus,
            ':sent_status' => $newStatus,
            ':delivered_status' => $newStatus,
            ':id' => $id,
            ':expected_status' => $expectedStatus,
        ]);

        return $stmt->rowCount() === 1;
    }

    private function buildFilters(array $filters): array
    {
        $conditions = [];
        $params = [];
        $filterColumns = [
            'delivery_status',
            'channel',
            'notification_type',
            'enquiry_id',
            'property_id',
            'sale_id',
            'installment_id',
        ];

        foreach ($filterColumns as $column) {
            if (!array_key_exists($column, $filters) || $filters[$column] === null) {
                continue;
            }
            $conditions[] = "{$column} = :{$column}";
            $params[':' . $column] = $filters[$column];
        }

        return [$conditions ? 'WHERE ' . implode(' AND ', $conditions) : '', $params];
    }
}
