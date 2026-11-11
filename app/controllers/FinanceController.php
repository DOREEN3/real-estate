<?php

declare(strict_types=1);

require_once __DIR__ . '/../models/ExpenseModel.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../security/Middleware.php';
require_once __DIR__ . '/../security/Validator.php';

class FinanceController
{
    private const CATEGORIES = [
        'operations',
        'maintenance',
        'marketing',
        'utilities',
        'taxes',
        'staff',
        'other',
    ];

    private ExpenseModel $expenseModel;

    public function __construct()
    {
        $this->expenseModel = new ExpenseModel();
    }

    public function listExpenses(): void
    {
        Middleware::requireRole(['admin']);
        $year = $this->queryYear();
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
        $limit = filter_var($_GET['limit'] ?? 100, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $limit === false || $limit < 1 || $limit > 500) {
            sendError('Invalid expense pagination parameters', 400);
        }
        $total = $this->expenseModel->countForYear($year);
        sendSuccess('Expenses retrieved', [
            'data' => $this->expenseModel->listForYear($year, $page, $limit),
            'year' => $year,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    public function createExpense(): void
    {
        $user = Middleware::requireRole(['admin']);
        $body = getJsonBody();
        $allowed = ['expense_date', 'category', 'vendor', 'description', 'amount', 'currency'];
        if (array_diff(array_keys($body), $allowed)) {
            sendError('Unsupported expense fields', 400);
        }

        $validator = new Validator();
        $date = $body['expense_date'] ?? '';
        $category = $body['category'] ?? '';
        $vendor = Validator::sanitizeInput($body['vendor'] ?? '');
        $description = Validator::sanitizeInput($body['description'] ?? '');
        $amount = $body['amount'] ?? '';
        $currency = strtoupper(trim((string) ($body['currency'] ?? '')));

        if (!is_string($date) || !$validator->date($date, 'expense_date')) {
            sendError('Enter a valid expense date', 400);
        }
        if (!is_string($category) || !$validator->inArray($category, self::CATEGORIES, 'category')) {
            sendError('Choose a valid expense category', 400, ['allowed' => self::CATEGORIES]);
        }
        if (!is_string($description) || mb_strlen($description) < 2 || mb_strlen($description) > 500) {
            sendError('Expense description must be between 2 and 500 characters', 400);
        }
        if (!is_string($vendor) || mb_strlen($vendor) > 150) {
            sendError('Vendor name cannot exceed 150 characters', 400);
        }
        if (!is_string($amount) && !is_numeric($amount)) {
            sendError('Enter a valid expense amount', 400);
        }
        $amount = (string) $amount;
        if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $amount) || (float) $amount <= 0) {
            sendError('Expense amount must be positive with no more than two decimal places', 400);
        }
        if (!preg_match('/^[A-Z]{3,10}$/', $currency)) {
            sendError('Currency must be a 3 to 10 character currency code', 400);
        }

        $id = $this->expenseModel->create([
            'expense_date' => $date,
            'category' => $category,
            'vendor' => $vendor !== '' ? $vendor : null,
            'description' => $description,
            'amount' => $amount,
            'currency' => $currency,
            'recorded_by' => (int) $user['sub'],
        ]);

        Middleware::auditLog((int) $user['sub'], 'expense_created', 'expenses', $id, [
            'amount' => $amount,
            'currency' => $currency,
            'category' => $category,
        ]);
        sendSuccess('Expense recorded', ['expense_id' => $id], 201);
    }

    public function profitLoss(): void
    {
        Middleware::requireRole(['admin']);
        $year = $this->queryYear();
        sendSuccess('Annual profit and loss report generated', [
            'year' => $year,
            'basis' => 'completed payments less recorded expenses, grouped by currency',
            'data' => $this->expenseModel->annualProfitLoss($year),
        ]);
    }

    private function queryYear(): int
    {
        $year = filter_var($_GET['year'] ?? date('Y'), FILTER_VALIDATE_INT);
        if ($year === false || $year < 2000 || $year > (int) date('Y') + 1) {
            sendError('Year must be between 2000 and next year', 400);
        }

        return $year;
    }
}
