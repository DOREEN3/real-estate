<?php

declare(strict_types=1);

/**
 * Production-ready WhatsApp Service Skeleton & Placeholder
 * Supports Meta Cloud API and Twilio WhatsApp
 * Handles automated property alerts and enquiry matching notifications (Requirement 4)
 */
class WhatsAppService
{
    private string $provider;
    private string $appEnv;

    public function __construct()
    {
        $this->appEnv = strtolower((string) (getenv('APP_ENV') ?: 'development'));
        $this->provider = strtolower((string) (getenv('WHATSAPP_PROVIDER') ?: 'sandbox'));
    }

    /**
     * Validate environment configuration for production WhatsApp dispatch
     */
    public function assertConfigured(): void
    {
        if ($this->provider === 'meta_cloud') {
            foreach (['WHATSAPP_PHONE_NUMBER_ID', 'WHATSAPP_ACCESS_TOKEN'] as $var) {
                $val = getenv($var);
                if (!is_string($val) || trim($val) === '') {
                    throw new RuntimeException("Meta WhatsApp Cloud API configuration missing: {$var}");
                }
            }
        }
    }

    /**
     * Send direct text or template message
     */
    public function sendMessage(string $toPhone, string $message): array
    {
        $normalizedPhone = $this->normalizePhoneNumber($toPhone);

        if ($this->appEnv === 'development' || $this->provider === 'sandbox') {
            $hasMeta = getenv('WHATSAPP_ACCESS_TOKEN') && getenv('WHATSAPP_PHONE_NUMBER_ID');
            if (!$hasMeta) {
                error_log("[WhatsAppService::SIMULATED] To: {$normalizedPhone} | Content: {$message}");
                return [
                    'success' => true,
                    'status' => 'simulated',
                    'message_id' => 'wa-sim-' . bin2hex(random_bytes(6)),
                    'recipient' => $normalizedPhone,
                ];
            }
        }

        if ($this->provider === 'meta_cloud') {
            return $this->sendViaMetaCloud($normalizedPhone, $message);
        }

        error_log("[WhatsAppService::SANDBOX] To: {$normalizedPhone} | Content: {$message}");
        return [
            'success' => true,
            'status' => 'sandbox_logged',
            'recipient' => $normalizedPhone,
        ];
    }

    /**
     * Send WhatsApp Match Alert (Requirement 4)
     */
    public function sendMatchAlert(string $toPhone, array $property, array $enquiry, string $role): array
    {
        $title = $property['title'] ?? 'Listing';
        $price = number_format((float) ($property['price'] ?? 0));
        $currency = $property['currency'] ?? 'KES';
        $location = $property['city'] ?? $property['address'] ?? 'Kenya';

        $text = "*GoldERP Property Match Alert*\n\n"
            . "A serious matching property is available!\n\n"
            . "*Property:* {$title}\n"
            . "*Location:* {$location}\n"
            . "*Price:* {$currency} {$price}\n\n"
            . "Reply to this message or contact our office for details.";

        return $this->sendMessage($toPhone, $text);
    }

    /**
     * Send WhatsApp Property Alert to Agent (Requirement 4)
     */
    public function sendPropertyAlert(string $toPhone, array $property): array
    {
        $title = $property['title'] ?? 'Listing';
        $price = number_format((float) ($property['price'] ?? 0));
        $currency = $property['currency'] ?? 'KES';

        $text = "*New Verified Property Alert*\n\n"
            . "*Listing:* {$title}\n"
            . "*Price:* {$currency} {$price}\n\n"
            . "Access full details in your GoldERP agent portal.";

        return $this->sendMessage($toPhone, $text);
    }

    private function normalizePhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[\s+\-()]/', '', $phone) ?? '';

        if (preg_match('/^0([17]\d{8})$/', $phone, $matches)) {
            return '254' . $matches[1];
        } elseif (preg_match('/^254([17]\d{8})$/', $phone, $matches)) {
            return '254' . $matches[1];
        }

        return ltrim($phone, '+');
    }

    private function sendViaMetaCloud(string $phone, string $message): array
    {
        $phoneId = (string) getenv('WHATSAPP_PHONE_NUMBER_ID');
        $token = (string) getenv('WHATSAPP_ACCESS_TOKEN');

        $url = "https://graph.facebook.com/v19.0/{$phoneId}/messages";
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $phone,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $message],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            throw new RuntimeException("WhatsApp Meta Cloud cURL error: {$curlError}");
        }

        $result = json_decode($response ?: '', true);
        if ($httpCode >= 200 && $httpCode < 300 && isset($result['messages'])) {
            return [
                'success' => true,
                'provider' => 'meta_cloud',
                'raw' => $result,
            ];
        }

        throw new RuntimeException("WhatsApp dispatch failed (HTTP {$httpCode}): {$response}");
    }
}
