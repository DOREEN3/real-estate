<?php
/**
 * JWT (JSON Web Token) Helper for secure authentication
 * Uses HS256 signing algorithm with a secret key from environment
 */

declare(strict_types=1);

class JWT
{
    private static ?string $secretKey = null;
    private static string $issuer = 'real-estate-api';
    private static int $accessTokenTtl = 900;   // 15 minutes
    private static int $refreshTokenTtl = 604800; // 7 days

    public static function init(string $secretKey): void
    {
        if (strlen($secretKey) < 32) {
            throw new InvalidArgumentException('JWT_SECRET must be at least 32 characters long.');
        }

        self::$secretKey = $secretKey;
    }

    public static function setAccessTokenTtl(int $seconds): void
    {
        self::$accessTokenTtl = $seconds;
    }

    public static function setRefreshTokenTtl(int $seconds): void
    {
        self::$refreshTokenTtl = $seconds;
    }

    /**
     * Generate base64url-encoded string without padding
     */
    private static function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Decode base64url-encoded string
     */
    private static function base64urlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * HMAC-SHA256 signature
     */
    private static function sign(string $data): string
    {
        if (self::$secretKey === null) {
            throw new LogicException('JWT has not been initialized.');
        }

        return hash_hmac('sha256', $data, self::$secretKey, true);
    }

    /**
     * Encode JWT header + payload + signature
     */
    public static function encode(array $payload, int $ttl = 0): string
    {
        $header = self::base64urlEncode(json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT',
        ], JSON_UNESCAPED_SLASHES));

        $now = time();
        $payload = array_merge([
            'iat' => $now,
            'iss' => self::$issuer,
        ], $payload);

        if ($ttl > 0) {
            $payload['exp'] = $now + $ttl;
        }

        $payloadEncoded = self::base64urlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signingInput = $header . '.' . $payloadEncoded;
        $signature = self::base64urlEncode(self::sign($signingInput));

        return $signingInput . '.' . $signature;
    }

    /**
     * Decode and verify JWT
     * Returns payload array on success, false on failure
     */
    public static function decode(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode(self::base64urlDecode($headerB64), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $signingInput = $headerB64 . '.' . $payloadB64;
        $expectedSignature = self::base64urlEncode(self::sign($signingInput));

        // Constant-time comparison to prevent timing attacks
        if (!hash_equals($expectedSignature, $signatureB64)) {
            return null;
        }

        $payload = json_decode(self::base64urlDecode($payloadB64), true);
        if (!$payload || !is_array($payload)) {
            return null;
        }

        // Check expiration
        if (!isset($payload['exp']) || !is_numeric($payload['exp']) || (int) $payload['exp'] <= time()) {
            return null;
        }

        if (!isset($payload['iss']) || $payload['iss'] !== self::$issuer) {
            return null;
        }

        return $payload;
    }

    /**
     * Generate access token for a user
     */
    public static function generateAccessToken(int $userId, string $email, string $role): string
    {
        return self::encode([
            'sub' => (string) $userId,
            'email' => $email,
            'role' => $role,
        ], self::$accessTokenTtl);
    }

    /**
     * Generate refresh token for a user
     */
    public static function generateRefreshToken(int $userId): string
    {
        return self::encode([
            'sub' => (string) $userId,
            'type' => 'refresh',
        ], self::$refreshTokenTtl);
    }

    /**
     * Extract Bearer token from Authorization header
     */
    public static function getBearerToken(): ?string
    {
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }
}