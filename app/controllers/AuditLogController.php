<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../security/Middleware.php';

class AuditLogController
{
    public function list(): void
    {
        global $pdo;

        Middleware::requireRole(['admin']);

        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
        $limit = filter_var($_GET['limit'] ?? 20, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1) {
            $page = 1;
        }
        if ($limit === false || $limit < 1 || $limit > 100) {
            $limit = 20;
        }
        $offset = ($page - 1) * $limit;

        $conditions = [];
        $params = [];

        if (!empty($_GET['resource_type'])) {
            $conditions[] = 'a.resource_type = :resource_type';
            $params[':resource_type'] = trim((string) $_GET['resource_type']);
        }

        if (!empty($_GET['resource_id'])) {
            $resourceId = filter_var($_GET['resource_id'], FILTER_VALIDATE_INT);
            if ($resourceId !== false) {
                $conditions[] = 'a.resource_id = :resource_id';
                $params[':resource_id'] = $resourceId;
            }
        }

        if (!empty($_GET['action'])) {
            $conditions[] = 'a.action = :action';
            $params[':action'] = trim((string) $_GET['action']);
        }

        if (!empty($_GET['user_id'])) {
            $userId = filter_var($_GET['user_id'], FILTER_VALIDATE_INT);
            if ($userId !== false) {
                $conditions[] = 'a.user_id = :user_id';
                $params[':user_id'] = $userId;
            }
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs a {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT a.id, a.user_id, a.action, a.resource_type, a.resource_id,
                       a.ip_address, a.user_agent, a.details, a.created_at,
                       u.full_name AS administrator_name, u.email AS administrator_email
                FROM audit_logs a
                LEFT JOIN users u ON u.id = a.user_id
                {$whereClause}
                ORDER BY a.created_at DESC, a.id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $paramKey => $paramVal) {
            $stmt->bindValue($paramKey, $paramVal);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        $formatted = array_map(static function (array $row): array {
            if (isset($row['details']) && is_string($row['details'])) {
                $decoded = json_decode($row['details'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row['details'] = $decoded;
                }
            }
            return $row;
        }, $rows);

        sendSuccess('Audit logs retrieved successfully', [
            'data' => $formatted,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ]);
    }
}
