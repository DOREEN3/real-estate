<?php

declare(strict_types=1);

if (!isset($pdo)) {
    require_once __DIR__ . '/../../config/database.php';
}

class CompanyProfileModel
{
    public function get(): array
    {
        global $pdo;

        $stmt = $pdo->query(
            'SELECT company_name, logo_url, physical_address, city, phone_number,
                    whatsapp_number, email, kra_pin, updated_at
             FROM company_profile WHERE id = 1'
        );
        $profile = $stmt->fetch();
        if ($profile === false) {
            return [
                'company_name' => 'Real Estate Portal',
                'logo_url' => null,
                'physical_address' => null,
                'city' => null,
                'phone_number' => null,
                'whatsapp_number' => null,
                'email' => null,
                'kra_pin' => null,
                'updated_at' => null,
            ];
        }

        return $profile;
    }

    public function updateProfile(array $data, int $userId): void
    {
        global $pdo;

        $stmt = $pdo->prepare(
            'INSERT INTO company_profile
                (id, company_name, physical_address, city, phone_number, whatsapp_number, email, kra_pin, updated_by)
             VALUES
                (1, :company_name, :physical_address, :city, :phone_number, :whatsapp_number, :email, :kra_pin, :updated_by)
             ON DUPLICATE KEY UPDATE
                company_name = VALUES(company_name),
                physical_address = VALUES(physical_address),
                city = VALUES(city),
                phone_number = VALUES(phone_number),
                whatsapp_number = VALUES(whatsapp_number),
                email = VALUES(email),
                kra_pin = VALUES(kra_pin),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            ':company_name' => $data['company_name'] ?? 'Real Estate Portal',
            ':physical_address' => $data['physical_address'] ?? null,
            ':city' => $data['city'] ?? null,
            ':phone_number' => $data['phone_number'] ?? null,
            ':whatsapp_number' => $data['whatsapp_number'] ?? null,
            ':email' => $data['email'] ?? null,
            ':kra_pin' => $data['kra_pin'] ?? null,
            ':updated_by' => $userId,
        ]);
    }

    public function updateName(string $companyName, int $userId): void
    {
        $current = $this->get();
        $current['company_name'] = $companyName;
        $this->updateProfile($current, $userId);
    }

    public function updateLogoUrl(string $logoUrl, int $userId): void
    {
        global $pdo;

        $stmt = $pdo->prepare(
            'INSERT INTO company_profile (id, company_name, logo_url, updated_by)
             VALUES (1, :company_name, :logo_url, :updated_by)
             ON DUPLICATE KEY UPDATE
                logo_url = VALUES(logo_url),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            ':company_name' => $this->get()['company_name'],
            ':logo_url' => $logoUrl,
            ':updated_by' => $userId,
        ]);
    }
}
