<?php

declare(strict_types=1);

/**
 * Production-ready Automated Bank Payment Service Skeleton
 * Modeled after MpesaDarajaService.php
 * Handles automated bank callback verification, Pesalink / Open Banking reconciliation,
 * signature checks, and automatic payment updates.
 */
class BankPaymentService
{
    private string $provider;
    private string $webhookSecret;
    private string $appEnv;

    public function __construct()
    {
        $this->appEnv = strtolower((string) (getenv('APP_ENV') ?: 'development'));
        $this->provider = strtolower((string) (getenv('BANK_PROVIDER') ?: 'pesalink'));
        $this->webhookSecret = (string) (getenv('BANK_WEBHOOK_SECRET') ?: '');
    }

    /**
     * Validate environment configuration for production automated bank payments
     */
    public function assertConfigured(): void
    {
        if ($this->appEnv === 'production') {
            if ($this->webhookSecret === '' || strlen($this->webhookSecret) < 16) {
                throw new RuntimeException('BANK_WEBHOOK_SECRET must be configured with at least 16 characters.');
            }
        }
    }

    /**
     * Verify incoming webhook signature (HMAC-SHA256)
     */
    public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader): bool
    {
        // In development with no secret set, allow for local testing
        if ($this->appEnv === 'development' && empty($this->webhookSecret)) {
            return true;
        }

        if (empty($signatureHeader) || empty($this->webhookSecret)) {
            return false;
        }

        // Clean signature (remove sha256= prefix if present)
        $cleanSignature = str_replace(['sha256=', 'HMAC-SHA256 '], '', trim($signatureHeader));
        $expectedSignature = hash_hmac('sha256', $rawPayload, $this->webhookSecret);

        return hash_equals($expectedSignature, $cleanSignature);
    }

    /**
     * Parse and normalize bank callback payload into standard structure
     *
     * @return array{
     *     transaction_reference: string,
     *     account_reference: string,
     *     amount: float,
     *     currency: string,
     *     bank_name: string,
     *     payer_name: string,
     *     status: string,
     *     payment_date: string
     * }
     */
    public function parseCallback(array $payload): array
    {
        // Support standard formats:
        // Format 1: Direct JSON { transaction_reference, account_reference, amount, status }
        // Format 2: Jenga / Pesalink { reference, customerReference, amount, status }
        // Format 3: Co-op { TransactionReference, AccountReference, TransactionAmount }
        $txRef = (string) (
            $payload['transaction_reference']
            ?? $payload['reference']
            ?? $payload['TransactionReference']
            ?? $payload['ft_reference']
            ?? ''
        );

        $accRef = (string) (
            $payload['account_reference']
            ?? $payload['customerReference']
            ?? $payload['AccountReference']
            ?? $payload['bill_reference']
            ?? ''
        );

        $amountRaw = $payload['amount']
            ?? $payload['TransactionAmount']
            ?? $payload['transaction_amount']
            ?? 0;

        $amount = (float) $amountRaw;
        $currency = strtoupper((string) ($payload['currency'] ?? $payload['Currency'] ?? 'KES'));
        $bankName = (string) ($payload['bank_name'] ?? $payload['bank'] ?? 'Bank Transfer');
        $payerName = (string) ($payload['payer_name'] ?? $payload['customer_name'] ?? $payload['CustomerName'] ?? 'Bank Customer');
        $statusRaw = strtolower((string) ($payload['status'] ?? $payload['TransactionStatus'] ?? 'completed'));
        $status = in_array($statusRaw, ['completed', 'success', '00', 'paid'], true) ? 'completed' : 'failed';
        $paymentDate = (string) ($payload['payment_date'] ?? $payload['date'] ?? date('Y-m-d H:i:s'));

        if (trim($txRef) === '') {
            throw new InvalidArgumentException('Missing transaction_reference in bank payload.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Invalid bank payment amount.');
        }

        return [
            'transaction_reference' => trim($txRef),
            'account_reference' => trim($accRef),
            'amount' => $amount,
            'currency' => $currency,
            'bank_name' => trim($bankName),
            'payer_name' => trim($payerName),
            'status' => $status,
            'payment_date' => $paymentDate,
        ];
    }

    /**
     * Resolve target Sale ID from account reference
     * Handles formats: "SALE-15", "SALE15", "15"
     */
    public function resolveSaleId(string $accountReference): ?int
    {
        $cleaned = trim($accountReference);

        if (preg_match('/^(?:SALE[-_]?)?(\d+)$/i', $cleaned, $matches)) {
            $id = (int) $matches[1];
            return $id > 0 ? $id : null;
        }

        return null;
    }

    /**
     * Resolve target Installment ID from reference
     * Handles formats: "INST-25", "INST25"
     */
    public function resolveInstallmentId(string $accountReference): ?int
    {
        $cleaned = trim($accountReference);

        if (preg_match('/^INST[-_]?(\d+)$/i', $cleaned, $matches)) {
            $id = (int) $matches[1];
            return $id > 0 ? $id : null;
        }

        return null;
    }

    /**
     * Resolve target Tenancy ID from reference
     * Handles formats: "TEN-12", "RENT-12"
     */
    public function resolveTenancyId(string $accountReference): ?int
    {
        $cleaned = trim($accountReference);

        if (preg_match('/^(?:TEN|RENT)[-_]?(\d+)$/i', $cleaned, $matches)) {
            $id = (int) $matches[1];
            return $id > 0 ? $id : null;
        }

        return null;
    }

    /**
     * Resolve target BnB Booking ID from reference
     * Handles formats: "BNB-5", "BOOK-5"
     */
    public function resolveBookingId(string $accountReference): ?int
    {
        $cleaned = trim($accountReference);

        if (preg_match('/^(?:BNB|BOOK)[-_]?(\d+)$/i', $cleaned, $matches)) {
            $id = (int) $matches[1];
            return $id > 0 ? $id : null;
        }

        return null;
    }
}
