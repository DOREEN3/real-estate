<?php

declare(strict_types=1);

if (!isset($pdo)) {
    require_once __DIR__ . '/../../config/database.php';
}

class ExpenseModel
{
    public function create(array $expense): int
    {
        global $pdo;

        $stmt = $pdo->prepare(
            'INSERT INTO expenses
                (expense_date, category, vendor, description, amount, currency, recorded_by)
             VALUES
                (:expense_date, :category, :vendor, :description, :amount, :currency, :recorded_by)'
        );
        $stmt->execute([
            ':expense_date' => $expense['expense_date'],
            ':category' => $expense['category'],
            ':vendor' => $expense['vendor'],
            ':description' => $expense['description'],
            ':amount' => $expense['amount'],
            ':currency' => $expense['currency'],
            ':recorded_by' => $expense['recorded_by'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function listForYear(int $year, int $page = 1, int $limit = 100): array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            'SELECT id, expense_date, category, vendor, description, amount, currency,
                    recorded_by, created_at
             FROM expenses
             WHERE expense_date >= :start_date AND expense_date < :end_date
             ORDER BY expense_date DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':start_date', sprintf('%04d-01-01', $year));
        $stmt->bindValue(':end_date', sprintf('%04d-01-01', $year + 1));
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countForYear(int $year): int
    {
        global $pdo;

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM expenses
             WHERE expense_date >= :start_date AND expense_date < :end_date'
        );
        $stmt->execute([
            ':start_date' => sprintf('%04d-01-01', $year),
            ':end_date' => sprintf('%04d-01-01', $year + 1),
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function annualProfitLoss(int $year): array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT currency,
                    COALESCE(SUM(revenue_amount), 0) AS revenue,
                    COALESCE(SUM(expense_amount), 0) AS expenses,
                    COALESCE(SUM(revenue_amount), 0) - COALESCE(SUM(expense_amount), 0) AS net_result
             FROM (
                SELECT COALESCE(s.currency, t.currency, b.currency, 'KES') AS currency,
                       SUM(p.amount) AS revenue_amount,
                       0 AS expense_amount
                FROM payments p
                LEFT JOIN sales s ON s.id = p.sale_id
                LEFT JOIN tenancies t ON t.id = p.tenancy_id
                LEFT JOIN bookings b ON b.id = p.booking_id
                WHERE p.payment_status = 'completed'
                  AND p.payment_date >= :revenue_start
                  AND p.payment_date < :revenue_end
                GROUP BY COALESCE(s.currency, t.currency, b.currency, 'KES')

                UNION ALL

                SELECT e.currency, 0 AS revenue_amount, SUM(e.amount) AS expense_amount
                FROM expenses e
                WHERE e.expense_date >= :expense_start
                  AND e.expense_date < :expense_end
                GROUP BY e.currency
             ) AS annual_totals
             GROUP BY currency
             ORDER BY currency"
        );
        $startDate = sprintf('%04d-01-01', $year);
        $endDate = sprintf('%04d-01-01', $year + 1);
        $stmt->execute([
            ':revenue_start' => $startDate,
            ':revenue_end' => $endDate,
            ':expense_start' => $startDate,
            ':expense_end' => $endDate,
        ]);

        return $stmt->fetchAll();
    }
}
