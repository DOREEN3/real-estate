<?php

declare(strict_types=1);

/**
 * Production-ready SMS Service Skeleton & Placeholder
 * Supports Africa's Talking, Twilio, and Sandbox simulation
 * Formats messages for property alerts, match notifications, receipts, and installment reminders
 */
class SmsService
{
    private string $provider;
    private string $senderId;
    private string $appEnv;

    public function __construct()
    {
        $this->appEnv = strtolower((string) (getenv('APP_ENV') ?: 'development'));
        $this->provider = strtolower((string) (getenv('SMS_PROVIDER') ?: 'sandbox'));
        $this->senderId = (string) (getenv('SMS_SENDER_ID') ?: 'REALESTATE');
    }

    /**
     * Validate environment configuration for production SMS dispatch
     */
    public function assertConfigured(): void
    {
        if ($this->provider === 'africastalking') {
            foreach (['AFRICASTALKING_USERNAME', 'AFRICASTALKING_API_KEY'] as $var) {
                $val = getenv($var);
                if (!is_string($val) || trim($val) === '') {
                    throw new RuntimeException("Africa's Talking SMS configuration missing: {$var}");
                }
            }
        } elseif ($this->provider === 'twilio') {
            foreach (['TWILIO_ACCOUNT_SID', 'TWILIO_AUTH_TOKEN', 'TWILIO_PHONE_NUMBER'] as $var) {
                $val = getenv($var);
                if (!is_string($val) || trim($val) === '') {
                    throw new RuntimeException("Twilio SMS configuration missing: {$var}");
                }
            }
        }
    }

    /**
     * Send generic SMS message
     */
    public function send(string $phoneNumber, string $message): array
    {
        $normalizedPhone = $this->normalizePhoneNumber($phoneNumber);

        // In development or sandbox mode without live credentials, simulate and log
        if ($this->appEnv === 'development' || $this->provider === 'sandbox') {
            $hasAt = getenv('AFRICASTALKING_USERNAME') && getenv('AFRICASTALKING_API_KEY');
            $hasTwilio = getenv('TWILIO_ACCOUNT_SID') && getenv('TWILIO_AUTH_TOKEN');
            if (!$hasAt && !$hasTwilio) {
                error_log("[SmsService::SIMULATED] To: {$normalizedPhone} | Message: {$message}");
                return [
                    'success' => true,
                    'status' => 'simulated',
                    'message_id' => 'sms-sim-' . bin2hex(random_bytes(6)),
                    'recipient' => $normalizedPhone,
                    'cost' => '0.00',
                ];
            }
        }

        if ($this->provider === 'africastalking') {
            return $this->sendViaAfricasTalking($normalizedPhone, $message);
        } elseif ($this->provider === 'twilio') {
            return $this->sendViaTwilio($normalizedPhone, $message);
        }

        error_log("[SmsService::SANDBOX] To: {$normalizedPhone} | Content: {$message}");
        return [
            'success' => true,
            'status' => 'sandbox_logged',
            'message_id' => 'sb-' . bin2hex(random_bytes(6)),
            'recipient' => $normalizedPhone,
        ];
    }

    /**
     * Send New Property Alert SMS to Agent (Requirement 4)
     */
    public function sendNewPropertyAlert(string $phoneNumber, array $property): array
    {
        $title = substr($property['title'] ?? 'New Property', 0, 30);
        $price = number_format((float) ($property['price'] ?? 0));
        $currency = $property['currency'] ?? 'KES';
        $location = $property['city'] ?? $property['address'] ?? 'Kenya';

        $message = "NEW PROPERTY ALERT: {$title} in {$location}. Price: {$currency} {$price}. Log into GoldERP to share with clients.";
        return $this->send($phoneNumber, $message);
    }

    /**
     * Send Match Alert SMS (Requirement 4: Lead, Seller, Introducing Agent, Office)
     */
    public function sendMatchAlert(string $phoneNumber, array $property, array $enquiry, string $role): array
    {
        $title = substr($property['title'] ?? 'Listing', 0, 25);
        $currency = $property['currency'] ?? 'KES';
        $price = number_format((float) ($property['price'] ?? 0));

        if ($role === 'lead') {
            $message = "GoldERP Match: We found a property matching your enquiry! {$title} ({$currency} {$price}). Contact us on +254700000000 to view.";
        } else {
            $client = substr($enquiry['client_name'] ?? 'Buyer', 0, 20);
            $message = "GoldERP Match ({$role}): {$client} matched your listing {$title} ({$currency} {$price}). Office will follow up shortly.";
        }

        return $this->send($phoneNumber, $message);
    }

    /**
     * Send Payment Receipt SMS (Requirement 5)
     */
    public function sendPaymentReceipt(string $phoneNumber, array $payment, array $sale): array
    {
        $receipt = $payment['receipt_number'] ?? $payment['id'];
        $amount = number_format((float) ($payment['amount'] ?? 0));
        $currency = $sale['currency'] ?? 'KES';
        $prop = substr($sale['property_title'] ?? 'Property', 0, 25);

        $message = "PAYMENT CONFIRMED: Rec #{$receipt}. Received {$currency} {$amount} for {$prop}. Thank you for choosing GoldERP.";
        return $this->send($phoneNumber, $message);
    }

    /**
     * Send Installment Due Reminder SMS (Requirement 5)
     */
    public function sendInstallmentReminder(string $phoneNumber, array $installment, array $sale): array
    {
        $amount = number_format((float) ($installment['amount_due'] ?? 0));
        $dueDate = $installment['due_date'] ?? 'soon';
        $currency = $sale['currency'] ?? 'KES';
        $saleId = $sale['id'] ?? '';

        $message = "REMINDER: Installment #{$installment['installment_number']} of {$currency} {$amount} is due on {$dueDate}. Ref: SALE-{$saleId}.";
        return $this->send($phoneNumber, $message);
    }

    public function sendPaymentDueReminder(
        string $phoneNumber,
        array $target,
        array $invoice,
        string $event,
        string $outstandingAmount,
        array $companyProfile
    ): array {
        $label = match ($event) {
            'due_today' => 'due today',
            'overdue', 'overdue_followup' => 'overdue',
            default => 'due soon',
        };
        $companyName = trim((string) ($companyProfile['company_name'] ?? 'GoldERP'));
        $property = substr((string) ($target['property_title'] ?? 'your property'), 0, 40);
        $message = sprintf(
            '%s: %s %s payment of %s %s for %s is %s (due %s). Invoice %s. Contact management for help.',
            $companyName,
            $target['target_type'] === 'installment' ? 'Installment' : 'Rent',
            $target['target_type'] === 'installment' ? '#' . (int) $target['installment_number'] : '',
            $target['currency'],
            number_format((float) $outstandingAmount, 2),
            $property,
            $label,
            $target['due_date'],
            $invoice['invoice_number']
        );
        return $this->send($phoneNumber, $message);
    }

    /**
     * Normalize international and Kenyan phone numbers to E.164 (+254XXXXXXXXX)
     */
    public function normalizePhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[\s+\-()]/', '', $phone) ?? '';

        if (preg_match('/^0([17]\d{8})$/', $phone, $matches)) {
            return '+254' . $matches[1];
        } elseif (preg_match('/^254([17]\d{8})$/', $phone, $matches)) {
            return '+254' . $matches[1];
        } elseif (preg_match('/^([17]\d{8})$/', $phone, $matches)) {
            return '+254' . $matches[1];
        }

        if (strpos($phone, '+') !== 0) {
            $phone = '+' . $phone;
        }

        return $phone;
    }

    private function sendViaAfricasTalking(string $phone, string $message): array
    {
        $username = (string) getenv('AFRICASTALKING_USERNAME');
        $apiKey = (string) getenv('AFRICASTALKING_API_KEY');

        $url = $username === 'sandbox'
            ? 'https://api.sandbox.africastalking.com/version1/messaging'
            : 'https://api.africastalking.com/version1/messaging';

        $postData = http_build_query([
            'username' => $username,
            'to' => $phone,
            'message' => $message,
            'from' => $this->senderId,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'apiKey: ' . $apiKey,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            throw new RuntimeException("Africa's Talking cURL error: {$curlError}");
        }

        $result = json_decode($response ?: '', true);
        if ($httpCode >= 200 && $httpCode < 300 && isset($result['SMSMessageData'])) {
            return [
                'success' => true,
                'provider' => 'africastalking',
                'raw' => $result,
            ];
        }

        throw new RuntimeException("Africa's Talking SMS failed (HTTP {$httpCode}): {$response}");
    }

    private function sendViaTwilio(string $phone, string $message): array
    {
        $sid = (string) getenv('TWILIO_ACCOUNT_SID');
        $token = (string) getenv('TWILIO_AUTH_TOKEN');
        $fromNumber = (string) getenv('TWILIO_PHONE_NUMBER');

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
        $postData = http_build_query([
            'To' => $phone,
            'From' => $fromNumber,
            'Body' => $message,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$sid}:{$token}",
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            throw new RuntimeException("Twilio SMS cURL error: {$curlError}");
        }

        $result = json_decode($response ?: '', true);
        if ($httpCode >= 200 && $httpCode < 300 && isset($result['sid'])) {
            return [
                'success' => true,
                'provider' => 'twilio',
                'message_sid' => $result['sid'],
            ];
        }

        throw new RuntimeException("Twilio SMS failed (HTTP {$httpCode}): {$response}");
    }
}
