<?php
/**
 * Rate Limiter - prevents brute force and API abuse
 * Uses sliding window with database-backed logging
 */

declare(strict_types=1);

class RateLimiter
{
    private int $maxRequests;
    private int $windowSeconds;
    private string $identifier;
    private string $endpoint;

    public function __construct(string $identifier, string $endpoint, int $maxRequests = 60, int $windowSeconds = 60)
    {
        $this->identifier = $identifier;
        $this->endpoint = $endpoint;
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
    }

    /**
     * Check if the request is within rate limit.
     * Returns true if allowed, false if rate limited.
     * Throws exception on database error.
     */
    public function check(): bool
    {
        global $pdo;

        $now = time();
        $windowStart = date('Y-m-d H:i:s', $now - $this->windowSeconds);
        $windowStartTs = date(
            'Y-m-d H:i:s',
            (int) (floor($now / $this->windowSeconds) * $this->windowSeconds)
        );

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $lockName = 'rl:' . substr(hash('sha256', $this->identifier . "\0" . $this->endpoint), 0, 61);

        if ($driver === 'mysql') {
            $lock = $pdo->prepare("SELECT GET_LOCK(:lock_name, 5)");
            $lock->execute([':lock_name' => $lockName]);
            if ((int) $lock->fetchColumn() !== 1) {
                throw new RuntimeException('Unable to acquire the rate-limit lock.');
            }
        } elseif ($driver === 'sqlite') {
            $pdo->exec('BEGIN IMMEDIATE');
        } else {
            throw new RuntimeException('The configured database driver does not support rate limiting.');
        }

        try {
            $cleanup = $pdo->prepare(
                "DELETE FROM rate_limit_logs WHERE window_start < :window_start"
            );
            $cleanup->execute([':window_start' => $windowStart]);

            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(request_count), 0)
                 FROM rate_limit_logs
                 WHERE identifier = :identifier AND endpoint = :endpoint
                 AND window_start >= :window_start"
            );
            $stmt->execute([
                ':identifier' => $this->identifier,
                ':endpoint' => $this->endpoint,
                ':window_start' => $windowStart,
            ]);

            if ((int) $stmt->fetchColumn() >= $this->maxRequests) {
                if ($driver === 'sqlite') {
                    $pdo->exec('COMMIT');
                }
                return false;
            }

            if ($driver === 'mysql') {
                $insert = $pdo->prepare(
                    "INSERT INTO rate_limit_logs (identifier, endpoint, request_count, window_start)
                     VALUES (:identifier, :endpoint, 1, :window_start)
                     ON DUPLICATE KEY UPDATE request_count = request_count + 1"
                );
            } else {
                $insert = $pdo->prepare(
                    "INSERT INTO rate_limit_logs (identifier, endpoint, request_count, window_start)
                     VALUES (:identifier, :endpoint, 1, :window_start)
                     ON CONFLICT (identifier, endpoint, window_start)
                     DO UPDATE SET request_count = request_count + 1"
                );
            }
            $insert->execute([
                ':identifier' => $this->identifier,
                ':endpoint' => $this->endpoint,
                ':window_start' => $windowStartTs,
            ]);

            if ($driver === 'sqlite') {
                $pdo->exec('COMMIT');
            }
            return true;
        } catch (Throwable $exception) {
            if ($driver === 'sqlite') {
                $pdo->exec('ROLLBACK');
            }
            throw $exception;
        } finally {
            if ($driver === 'mysql') {
                $release = $pdo->prepare("SELECT RELEASE_LOCK(:lock_name)");
                $release->execute([':lock_name' => $lockName]);
                if ((int) $release->fetchColumn() !== 1) {
                    throw new RuntimeException('Unable to release the rate-limit lock.');
                }
            }
        }
    }

    /**
     * Get client IP address with proxy support
     */
    public static function getClientIp(): string
    {
        $remoteAddress = trim($_SERVER['REMOTE_ADDR'] ?? '');
        if (!self::isValidIp($remoteAddress)) {
            return '0.0.0.0';
        }

        $trustedProxies = array_filter(array_map(
            'trim',
            explode(',', getenv('TRUSTED_PROXIES') ?: '')
        ));
        if (!in_array($remoteAddress, $trustedProxies, true)) {
            return $remoteAddress;
        }

        $forwardedFor = array_map(
            'trim',
            explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')
        );
        $forwardedFor[] = $remoteAddress;

        for ($index = count($forwardedFor) - 1; $index >= 0; $index--) {
            $address = $forwardedFor[$index];
            if (!self::isValidIp($address)) {
                return $remoteAddress;
            }
            if (!in_array($address, $trustedProxies, true)) {
                return $address;
            }
        }

        return $remoteAddress;
    }

    private static function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}