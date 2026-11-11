<?php

declare(strict_types=1);

class MpesaDarajaService
{
    /**
     * Query Daraja directly because STK callbacks do not include a verifiable signature.
     *
     * @return array{checkout_request_id: string, result_code: int}
     */
    
    /**
     * Initiate an M-Pesa STK Push request.
     *
     * The transaction type, credentials and callback URL are supplied
     * through environment configuration.
     *
     * @return array{
     *     merchant_request_id: string,
     *     checkout_request_id: string,
     *     response_code: int,
     *     customer_message: string
     * }
     */
    public function initiateStkPush(
        string $phoneNumber,
        float $amount,
        string $accountReference,
        string $transactionDescription
    ): array {
        $environment = strtolower((string) getenv('MPESA_ENV'));

        if (!in_array($environment, ['sandbox', 'production'], true)) {
            throw new RuntimeException('MPESA_ENV must be sandbox or production.');
        }

        $consumerKey = getenv('MPESA_CONSUMER_KEY');
        $consumerSecret = getenv('MPESA_CONSUMER_SECRET');
        $shortCode = getenv('MPESA_SHORTCODE');
        $passkey = getenv('MPESA_PASSKEY');
        $callbackUrl = getenv('MPESA_CALLBACK_URL');
        $transactionType = getenv('MPESA_TRANSACTION_TYPE');

        foreach ([
            'MPESA_CONSUMER_KEY' => $consumerKey,
            'MPESA_CONSUMER_SECRET' => $consumerSecret,
            'MPESA_SHORTCODE' => $shortCode,
            'MPESA_PASSKEY' => $passkey,
            'MPESA_CALLBACK_URL' => $callbackUrl,
            'MPESA_TRANSACTION_TYPE' => $transactionType,
        ] as $name => $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new RuntimeException("{$name} is not configured.");
            }
        }

        if (!in_array($transactionType, [
            'CustomerPayBillOnline',
            'CustomerBuyGoodsOnline',
        ], true)) {
            throw new RuntimeException('Invalid MPESA_TRANSACTION_TYPE.');
        }

        if (
            !filter_var($callbackUrl, FILTER_VALIDATE_URL)
            || strtolower((string) parse_url($callbackUrl, PHP_URL_SCHEME)) !== 'https'
        ) {
            throw new RuntimeException('MPESA_CALLBACK_URL must be a valid HTTPS URL.');
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for M-Pesa payments.');
        }

        if (!is_finite($amount) || $amount <= 0 || $amount !== floor($amount)) {
            throw new InvalidArgumentException('M-Pesa amount must be a positive whole number.');
        }

        // Normalize Kenyan phone numbers to 2547XXXXXXXX or 2541XXXXXXXX.
        $phoneNumber = preg_replace('/[\s+\-]/', '', $phoneNumber) ?? '';

        if (preg_match('/^0([17]\d{8})$/', $phoneNumber, $matches)) {
            $phoneNumber = '254' . $matches[1];
        } elseif (preg_match('/^([17]\d{8})$/', $phoneNumber, $matches)) {
            $phoneNumber = '254' . $matches[1];
        }

        if (!preg_match('/^254[17]\d{8}$/', $phoneNumber)) {
            throw new InvalidArgumentException('Enter a valid Kenyan M-Pesa phone number.');
        }

        if (
            trim($accountReference) === ''
            || strlen($accountReference) > 12
            || trim($transactionDescription) === ''
            || strlen($transactionDescription) > 13
        ) {
            throw new InvalidArgumentException('Invalid M-Pesa account reference or description.');
        }

        $baseUrl = $environment === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';

        $tokenResponse = $this->requestJson(
            $baseUrl . '/oauth/v1/generate?grant_type=client_credentials',
            null,
            [$consumerKey, $consumerSecret]
        );

        $accessToken = $tokenResponse['access_token'] ?? null;

        if (!is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('Daraja did not return an access token.');
        }

        $timestamp = (new DateTimeImmutable(
            'now',
            new DateTimeZone('Africa/Nairobi')
        ))->format('YmdHis');

        $password = base64_encode($shortCode . $passkey . $timestamp);

        $response = $this->requestJson(
            $baseUrl . '/mpesa/stkpush/v1/processrequest',
            [
                'BusinessShortCode' => $shortCode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => $transactionType,
                'Amount' => (int) $amount,
                'PartyA' => $phoneNumber,
                'PartyB' => $shortCode,
                'PhoneNumber' => $phoneNumber,
                'CallBackURL' => $callbackUrl,
                'AccountReference' => $accountReference,
                'TransactionDesc' => $transactionDescription,
            ],
            null,
            $accessToken
        );

        $responseCode = filter_var(
            $response['ResponseCode'] ?? null,
            FILTER_VALIDATE_INT
        );

        $merchantRequestId = $response['MerchantRequestID'] ?? null;
        $checkoutRequestId = $response['CheckoutRequestID'] ?? null;

        if (
            $responseCode !== 0
            || !is_string($merchantRequestId)
            || $merchantRequestId === ''
            || !is_string($checkoutRequestId)
            || $checkoutRequestId === ''
        ) {
            throw new RuntimeException(
                'Daraja did not return a valid STK Push initiation response.'
            );
        }

        return [
            'merchant_request_id' => $merchantRequestId,
            'checkout_request_id' => $checkoutRequestId,
            'response_code' => $responseCode,
            'customer_message' => (string) ($response['CustomerMessage'] ?? ''),
        ];
    }

    public function queryStkStatus(string $checkoutRequestId): array
    {
        $environment = strtolower((string) getenv('MPESA_ENV'));
        if (!in_array($environment, ['sandbox', 'production'], true)) {
            throw new RuntimeException('MPESA_ENV must be sandbox or production.');
        }

        $consumerKey = getenv('MPESA_CONSUMER_KEY');
        $consumerSecret = getenv('MPESA_CONSUMER_SECRET');
        $shortCode = getenv('MPESA_SHORTCODE');
        $passkey = getenv('MPESA_PASSKEY');
        foreach ([
            'MPESA_CONSUMER_KEY' => $consumerKey,
            'MPESA_CONSUMER_SECRET' => $consumerSecret,
            'MPESA_SHORTCODE' => $shortCode,
            'MPESA_PASSKEY' => $passkey,
        ] as $name => $value) {
            if (!is_string($value) || $value === '') {
                throw new RuntimeException("{$name} is not configured.");
            }
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for M-Pesa verification.');
        }

        $baseUrl = $environment === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
        $tokenResponse = $this->requestJson(
            $baseUrl . '/oauth/v1/generate?grant_type=client_credentials',
            null,
            [$consumerKey, $consumerSecret]
        );
        $accessToken = $tokenResponse['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('Daraja did not return an access token.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi'));
        $timestamp = $now->format('YmdHis');
        $queryResponse = $this->requestJson(
            $baseUrl . '/mpesa/stkpushquery/v1/query',
            [
                'BusinessShortCode' => $shortCode,
                'Password' => base64_encode($shortCode . $passkey . $timestamp),
                'Timestamp' => $timestamp,
                'CheckoutRequestID' => $checkoutRequestId,
            ],
            null,
            $accessToken
        );
        $verifiedCheckoutRequestId = $queryResponse['CheckoutRequestID'] ?? null;
        $responseCode = filter_var($queryResponse['ResponseCode'] ?? null, FILTER_VALIDATE_INT);
        $resultCode = filter_var($queryResponse['ResultCode'] ?? null, FILTER_VALIDATE_INT);
        if (
            !is_string($verifiedCheckoutRequestId)
            || $responseCode !== 0
            || $resultCode === false
        ) {
            throw new RuntimeException('Daraja returned an incomplete STK query result.');
        }

        return [
            'checkout_request_id' => $verifiedCheckoutRequestId,
            'result_code' => $resultCode,
        ];
    }

    private function requestJson(
        string $url,
        ?array $payload,
        ?array $basicAuth = null,
        ?string $bearerToken = null
    ): array {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize the Daraja request.');
        }

        try {
            $headers = ['Accept: application/json'];
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];
            if ($payload !== null) {
                try {
                    $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new RuntimeException('Unable to encode the Daraja request.', 0, $exception);
                }
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = $encodedPayload;
                $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            }
            if ($basicAuth !== null) {
                $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
                $options[CURLOPT_USERPWD] = $basicAuth[0] . ':' . $basicAuth[1];
            }
            if ($bearerToken !== null) {
                $options[CURLOPT_HTTPHEADER][] = 'Authorization: Bearer ' . $bearerToken;
            }
            curl_setopt_array($curl, $options);
            $responseBody = curl_exec($curl);
            $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($curl);

            if (!is_string($responseBody) || $curlError !== '') {
                throw new RuntimeException('Daraja request failed: ' . ($curlError ?: 'empty response'));
            }
            try {
                $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Daraja returned invalid JSON.', 0, $exception);
            }
            if ($statusCode < 200 || $statusCode >= 300 || !is_array($response)) {
                throw new RuntimeException('Daraja rejected the verification request.');
            }

            return $response;
        } finally {
            curl_close($curl);
        }
    }
}
