<?php

declare(strict_types=1);

require_once __DIR__ . '/../models/CompanyProfileModel.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../security/Middleware.php';
require_once __DIR__ . '/../security/Validator.php';

class CompanyProfileController
{
    private const LOGO_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private CompanyProfileModel $profileModel;

    public function __construct()
    {
        $this->profileModel = new CompanyProfileModel();
    }

    public function get(): void
    {
        Middleware::requireRole(['admin']);
        sendSuccess('Company profile retrieved', ['profile' => $this->profileModel->get()]);
    }

    public function update(): void
    {
        $user = Middleware::requireRole(['admin']);
        $body = getJsonBody();
        $allowed = [
            'company_name',
            'physical_address',
            'city',
            'phone_number',
            'whatsapp_number',
            'email',
            'kra_pin',
        ];
        if (array_diff(array_keys($body), $allowed)) {
            sendError('Unsupported company profile fields', 400);
        }

        $companyName = Validator::sanitizeInput($body['company_name'] ?? '');
        if (!is_string($companyName) || mb_strlen($companyName) < 2 || mb_strlen($companyName) > 150) {
            sendError('Company name must be between 2 and 150 characters', 400);
        }

        $email = !empty($body['email']) ? Validator::sanitizeInput($body['email']) : null;
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendError('Invalid company email address', 400);
        }

        $data = [
            'company_name' => $companyName,
            'physical_address' => !empty($body['physical_address']) ? Validator::sanitizeInput($body['physical_address']) : null,
            'city' => !empty($body['city']) ? Validator::sanitizeInput($body['city']) : null,
            'phone_number' => !empty($body['phone_number']) ? Validator::sanitizeInput($body['phone_number']) : null,
            'whatsapp_number' => !empty($body['whatsapp_number']) ? Validator::sanitizeInput($body['whatsapp_number']) : null,
            'email' => $email,
            'kra_pin' => !empty($body['kra_pin']) ? strtoupper(trim(Validator::sanitizeInput($body['kra_pin']))) : null,
        ];

        $this->profileModel->updateProfile($data, (int) $user['sub']);
        sendSuccess('Company profile updated', ['profile' => $this->profileModel->get()]);
    }

    public function uploadLogo(): void
    {
        $user = Middleware::requireRole(['admin']);
        if (!isset($_FILES['logo']) || !is_array($_FILES['logo'])) {
            sendError('Upload an image using the logo multipart field', 400);
        }

        $file = $_FILES['logo'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            sendError('Logo upload failed', 400);
        }
        if (
            !isset($file['tmp_name'], $file['size'])
            || !is_string($file['tmp_name'])
            || !is_uploaded_file($file['tmp_name'])
            || (int) $file['size'] <= 0
            || (int) $file['size'] > 3 * 1024 * 1024
        ) {
            sendError('Logo must be a non-empty image no larger than 3 MB', 400);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!is_string($mimeType) || !isset(self::LOGO_MIME_EXTENSIONS[$mimeType])) {
            sendError('Only JPEG, PNG, and WebP logos are accepted', 400);
        }

        $storageRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'company';
        if (!is_dir($storageRoot) && !mkdir($storageRoot, 0755, true) && !is_dir($storageRoot)) {
            error_log('Unable to create company logo storage directory.');
            sendError('Logo storage is unavailable', 500);
        }

        $filename = bin2hex(random_bytes(24)) . '.' . self::LOGO_MIME_EXTENSIONS[$mimeType];
        $absolutePath = $storageRoot . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
            error_log('Unable to save uploaded company logo.');
            sendError('Unable to save company logo', 500);
        }
        if (!chmod($absolutePath, 0644)) {
            error_log('Unable to set company logo file permissions: ' . $absolutePath);
        }

        $oldLogoUrl = $this->profileModel->get()['logo_url'] ?? null;
        $logoUrl = '/uploads/company/' . $filename;
        try {
            $this->profileModel->updateLogoUrl($logoUrl, (int) $user['sub']);
        } catch (Throwable $exception) {
            if (is_file($absolutePath)) {
                unlink($absolutePath);
            }
            throw $exception;
        }

        $this->removeManagedLogo($oldLogoUrl);
        sendSuccess('Company logo updated', ['profile' => $this->profileModel->get()]);
    }

    private function removeManagedLogo(mixed $logoUrl): void
    {
        if (!is_string($logoUrl) || !preg_match('#^/uploads/company/([a-f0-9]{48}\.(?:jpg|png|webp))$#', $logoUrl, $matches)) {
            return;
        }

        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR
            . 'company' . DIRECTORY_SEPARATOR . $matches[1];
        if (is_file($path) && !unlink($path)) {
            error_log('Unable to remove the previous company logo: ' . $path);
        }
    }
}
