<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/EmailService.php';

class PasswordResetMailer
{
    public static function assertConfigured(): void
    {
        $service = new EmailService();
        $service->assertConfigured();
    }

    public static function send(string $email, string $token): array
    {
        $service = new EmailService();
        $result = $service->sendPasswordReset($email, $token);

        $appEnv = strtolower((string) (getenv('APP_ENV') ?: 'development'));
        if (empty($result['success']) && $appEnv !== 'development') {
            throw new RuntimeException('Password reset email delivery failed: ' . ($result['error'] ?? 'unknown error'));
        }

        return $result;
    }
}
