<?php
/**
 * Input Validation and Sanitization
 * Protects against XSS, SQL injection, and malformed input
 */

declare(strict_types=1);

class Validator
{
    private array $errors = [];

    /**
     * Validate required field
     */
    public function required(mixed $value, string $field): bool
    {
        if ($value === null || $value === '' || (is_array($value) && count($value) === 0)) {
            $this->errors[$field] = "$field is required";
            return false;
        }
        return true;
    }

    /**
     * Validate email format
     */
    public function email(string $value, string $field): bool
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = "Invalid $field format";
            return false;
        }
        return true;
    }

    /**
     * Validate password strength
     */
    public function password(string $value, string $field = 'password'): bool
    {
        if (strlen($value) < 8) {
            $this->errors[$field] = "$field must be at least 8 characters";
            return false;
        }
        if (!preg_match('/[A-Z]/', $value)) {
            $this->errors[$field] = "$field must contain at least one uppercase letter";
            return false;
        }
        if (!preg_match('/[a-z]/', $value)) {
            $this->errors[$field] = "$field must contain at least one lowercase letter";
            return false;
        }
        if (!preg_match('/[0-9]/', $value)) {
            $this->errors[$field] = "$field must contain at least one number";
            return false;
        }
        if (!preg_match('/[^A-Za-z0-9]/', $value)) {
            $this->errors[$field] = "$field must contain at least one special character";
            return false;
        }
        return true;
    }

    /**
     * Validate string length range
     */
    public function length(string $value, int $min, int $max, string $field): bool
    {
        $len = mb_strlen($value);
        if ($len < $min || $len > $max) {
            $this->errors[$field] = "$field must be between $min and $max characters";
            return false;
        }
        return true;
    }

    /**
     * Validate numeric range
     */
    public function numericRange(float $value, float $min, float $max, string $field): bool
    {
        if ($value < $min || $value > $max) {
            $this->errors[$field] = "$field must be between $min and $max";
            return false;
        }
        return true;
    }

    /**
     * Validate enum value
     */
    public function inArray(string $value, array $allowed, string $field): bool
    {
        if (!in_array($value, $allowed, true)) {
            $this->errors[$field] = "$field must be one of: " . implode(', ', $allowed);
            return false;
        }
        return true;
    }

    /**
     * Validate Kenyan or international phone number
     */
    public function phone(string $value, string $field): bool
    {
        $clean = preg_replace('/[\s+\-()]/', '', $value);
        if (!preg_match('/^(?:\+?254|0)[17]\d{8}$/', $clean) && !preg_match('/^\+?\d{8,15}$/', $clean)) {
            $this->errors[$field] = "Invalid $field format";
            return false;
        }
        return true;
    }

    /**
     * Validate date format YYYY-MM-DD
     */
    public function date(string $value, string $field): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !strtotime($value)) {
            $this->errors[$field] = "$field must be a valid date in YYYY-MM-DD format";
            return false;
        }
        return true;
    }

    /**
     * Validate positive numeric value
     */
    public function numeric(mixed $value, string $field): bool
    {
        if (!is_numeric($value) || (float) $value < 0) {
            $this->errors[$field] = "$field must be a positive number";
            return false;
        }
        return true;
    }

    /**
     * Sanitize string for output (XSS prevention)
     */
    public static function sanitizeOutput(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Sanitize input string (trim, remove control chars, and strip dangerous tags)
     */
    public static function sanitizeInput(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value); // Remove control characters
        $value = strip_tags($value); // Strip HTML tags to eliminate stored XSS vectors
        return $value;
    }

    /**
     * Recursively sanitize input array
     */
    public static function sanitizeArray(array $data): array
    {
        $cleaned = [];
        foreach ($data as $key => $val) {
            $safeKey = self::sanitizeInput((string) $key);
            if (is_array($val)) {
                $cleaned[$safeKey] = self::sanitizeArray($val);
            } elseif (is_string($val)) {
                $cleaned[$safeKey] = self::sanitizeInput($val);
            } else {
                $cleaned[$safeKey] = $val;
            }
        }
        return $cleaned;
    }

    /**
     * Sanitize filename for secure file upload
     */
    public static function sanitizeFilename(string $filename): string
    {
        $filename = basename($filename);
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        return $filename;
    }

    /**
     * Get all validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Check if there are any errors
     */
    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }
}