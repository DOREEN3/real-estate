<?php

declare(strict_types=1);

require_once __DIR__ . '/EmailService.php';
require_once __DIR__ . '/SmsService.php';
require_once __DIR__ . '/WhatsAppService.php';

/**
 * Central Automated Notification Dispatcher
 * Directly implements Requirement 4 & 5:
 * - When property is saved, all agents get notified.
 * - When enquiry matches properties, seller, introducing agent, office and lead get Email, SMS, and WhatsApp alerts.
 * - Payment receipts and installment reminder notifications.
 * Automatically logs all actions to `notification_logs`.
 */
class NotificationDispatcher
{
    private EmailService $emailService;
    private SmsService $smsService;
    private WhatsAppService $whatsAppService;

    public function __construct()
    {
        $this->emailService = new EmailService();
        $this->smsService = new SmsService();
        $this->whatsAppService = new WhatsAppService();
    }

    /**
     * Requirement 4: Automated action on new property saved
     * All registered agents get notified of the new property
     */
    public function dispatchNewPropertyAlert(array $property): array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT id, full_name, email, phone_number
             FROM users
             WHERE role = 'agent' AND account_status = 'approved'"
        );
        $stmt->execute();
        $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        $propertyId = (int) ($property['id'] ?? 0);

        foreach ($agents as $agent) {
            $name = $agent['full_name'] ?? 'Agent';
            $email = $agent['email'] ?? '';
            $phone = $agent['phone_number'] ?? '';

            // 1. Email alert
            if (!empty($email)) {
                $status = 'sent';
                $error = null;
                $ref = null;
                try {
                    $res = $this->emailService->sendNewPropertyAlert($email, $name, $property);
                    $ref = $res['message_id'] ?? null;
                } catch (Throwable $e) {
                    $status = 'failed';
                    $error = $e->getMessage();
                }

                $this->logNotification([
                    'recipient_name' => $name,
                    'recipient_contact' => $email,
                    'channel' => 'email',
                    'notification_type' => 'new_property_agent',
                    'message' => "New property listed: {$property['title']}",
                    'delivery_status' => $status,
                    'provider' => 'email',
                    'provider_reference' => $ref,
                    'error_message' => $error,
                    'property_id' => $propertyId,
                ]);
                $results[] = ['agent' => $email, 'channel' => 'email', 'status' => $status];
            }

            // 2. SMS alert
            if (!empty($phone)) {
                $status = 'sent';
                $error = null;
                $ref = null;
                try {
                    $res = $this->smsService->sendNewPropertyAlert($phone, $property);
                    $ref = $res['message_id'] ?? null;
                } catch (Throwable $e) {
                    $status = 'failed';
                    $error = $e->getMessage();
                }

                $this->logNotification([
                    'recipient_name' => $name,
                    'recipient_contact' => $phone,
                    'channel' => 'sms',
                    'notification_type' => 'new_property_agent',
                    'message' => "New property alert: {$property['title']}",
                    'delivery_status' => $status,
                    'provider' => 'sms',
                    'provider_reference' => $ref,
                    'error_message' => $error,
                    'property_id' => $propertyId,
                ]);
                $results[] = ['agent' => $phone, 'channel' => 'sms', 'status' => $status];
            }
        }

        return $results;
    }

    /**
     * Requirement 4: Automated action on serious property match
     * Dispatches Email, SMS, and WhatsApp alerts to:
     * 1. The Lead (client)
     * 2. The Seller / Owner
     * 3. The Introducing Agent
     * 4. Ourselves (Office / Admin)
     */
    public function dispatchEnquiryMatchAlerts(int $enquiryId, array $matches): array
    {
        global $pdo;

        // Fetch enquiry details
        $stmt = $pdo->prepare("SELECT * FROM enquiries WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $enquiryId]);
        $enquiry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$enquiry) {
            return [];
        }

        $results = [];
        $adminEmail = (string) (getenv('ADMIN_NOTIFICATION_EMAIL') ?: 'admin@realestate.local');
        $adminPhone = (string) (getenv('ADMIN_NOTIFICATION_PHONE') ?: '+254700000000');

        foreach ($matches as $match) {
            $propertyId = (int) ($match['property_id'] ?? $match['id'] ?? 0);
            if ($propertyId <= 0) {
                continue;
            }

            // Fetch property details & owner/agent
            $pStmt = $pdo->prepare(
                "SELECT p.*, u.full_name AS owner_name, u.email AS owner_email, u.phone_number AS owner_phone, u.role AS owner_role
                 FROM properties p
                 LEFT JOIN users u ON u.id = p.owner_id
                 WHERE p.id = :id LIMIT 1"
            );
            $pStmt->execute([':id' => $propertyId]);
            $property = $pStmt->fetch(PDO::FETCH_ASSOC);
            if (!$property) {
                continue;
            }

            $recipients = [
                // 1. The Lead
                [
                    'name' => $enquiry['client_name'] ?? 'Valued Lead',
                    'email' => $enquiry['client_email'] ?? '',
                    'phone' => $enquiry['client_phone'] ?? '',
                    'role' => 'lead',
                ],
                // 2. The Seller / Owner
                [
                    'name' => $property['owner_name'] ?? 'Property Owner',
                    'email' => $property['owner_email'] ?? '',
                    'phone' => $property['owner_phone'] ?? '',
                    'role' => 'seller',
                ],
                // 3. Ourselves (Platform Admin)
                [
                    'name' => 'Real Estate Office',
                    'email' => $adminEmail,
                    'phone' => $adminPhone,
                    'role' => 'office',
                ],
            ];

            // If introducing agent is distinct from owner, include them
            if (($property['owner_role'] ?? '') === 'agent') {
                $recipients[1]['role'] = 'introducing_agent';
            }

            foreach ($recipients as $recipient) {
                $rName = $recipient['name'];
                $rEmail = $recipient['email'];
                $rPhone = $recipient['phone'];
                $rRole = $recipient['role'];

                // Channel A: Email
                if (!empty($rEmail) && filter_var($rEmail, FILTER_VALIDATE_EMAIL)) {
                    $status = 'sent';
                    $err = null;
                    $ref = null;
                    try {
                        $res = $this->emailService->sendMatchAlert($rEmail, $rName, $property, $enquiry, $rRole);
                        $ref = $res['message_id'] ?? null;
                    } catch (Throwable $e) {
                        $status = 'failed';
                        $err = $e->getMessage();
                    }

                    $this->logNotification([
                        'recipient_name' => $rName,
                        'recipient_contact' => $rEmail,
                        'channel' => 'email',
                        'notification_type' => 'property_match_' . $rRole,
                        'message' => "Serious match: {$property['title']} for enquiry #{$enquiryId}",
                        'delivery_status' => $status,
                        'provider' => 'email',
                        'provider_reference' => $ref,
                        'error_message' => $err,
                        'enquiry_id' => $enquiryId,
                        'property_id' => $propertyId,
                    ]);
                    $results[] = ['recipient' => $rEmail, 'role' => $rRole, 'channel' => 'email', 'status' => $status];
                }

                // Channel B: SMS
                if (!empty($rPhone)) {
                    $status = 'sent';
                    $err = null;
                    $ref = null;
                    try {
                        $res = $this->smsService->sendMatchAlert($rPhone, $property, $enquiry, $rRole);
                        $ref = $res['message_id'] ?? null;
                    } catch (Throwable $e) {
                        $status = 'failed';
                        $err = $e->getMessage();
                    }

                    $this->logNotification([
                        'recipient_name' => $rName,
                        'recipient_contact' => $rPhone,
                        'channel' => 'sms',
                        'notification_type' => 'property_match_' . $rRole,
                        'message' => "Serious match SMS: {$property['title']} for enquiry #{$enquiryId}",
                        'delivery_status' => $status,
                        'provider' => 'sms',
                        'provider_reference' => $ref,
                        'error_message' => $err,
                        'enquiry_id' => $enquiryId,
                        'property_id' => $propertyId,
                    ]);
                    $results[] = ['recipient' => $rPhone, 'role' => $rRole, 'channel' => 'sms', 'status' => $status];

                    // Channel C: WhatsApp
                    $waStatus = 'sent';
                    $waErr = null;
                    $waRef = null;
                    try {
                        $res = $this->whatsAppService->sendMatchAlert($rPhone, $property, $enquiry, $rRole);
                        $waRef = $res['message_id'] ?? null;
                    } catch (Throwable $e) {
                        $waStatus = 'failed';
                        $waErr = $e->getMessage();
                    }

                    $this->logNotification([
                        'recipient_name' => $rName,
                        'recipient_contact' => $rPhone,
                        'channel' => 'whatsapp',
                        'notification_type' => 'property_match_' . $rRole,
                        'message' => "Serious match WhatsApp: {$property['title']}",
                        'delivery_status' => $waStatus,
                        'provider' => 'whatsapp',
                        'provider_reference' => $waRef,
                        'error_message' => $waErr,
                        'enquiry_id' => $enquiryId,
                        'property_id' => $propertyId,
                    ]);
                    $results[] = ['recipient' => $rPhone, 'role' => $rRole, 'channel' => 'whatsapp', 'status' => $waStatus];
                }
            }
        }

        return $results;
    }

    /**
     * Requirement 5: Payment Receipting Notification
     */
    public function dispatchPaymentReceipt(array $payment, array $sale): void
    {
        $buyerEmail = $sale['buyer_email'] ?? '';
        $buyerName = $sale['buyer_name'] ?? 'Buyer';
        $buyerPhone = $payment['payer_phone'] ?? '';
        $saleId = (int) ($sale['id'] ?? 0);

        if (!empty($buyerEmail)) {
            try {
                $res = $this->emailService->sendPaymentReceipt($buyerEmail, $buyerName, $payment, $sale);
                $this->logNotification([
                    'recipient_name' => $buyerName,
                    'recipient_contact' => $buyerEmail,
                    'channel' => 'email',
                    'notification_type' => 'payment_receipt',
                    'message' => "Official receipt #{$payment['receipt_number']}",
                    'delivery_status' => 'sent',
                    'provider' => 'email',
                    'provider_reference' => $res['message_id'] ?? null,
                    'sale_id' => $saleId,
                ]);
            } catch (Throwable $e) {
                error_log("Receipt email error: " . $e->getMessage());
            }
        }

        if (!empty($buyerPhone)) {
            try {
                $res = $this->smsService->sendPaymentReceipt($buyerPhone, $payment, $sale);
                $this->logNotification([
                    'recipient_name' => $buyerName,
                    'recipient_contact' => $buyerPhone,
                    'channel' => 'sms',
                    'notification_type' => 'payment_receipt',
                    'message' => "Payment confirmed for {$sale['property_title']}",
                    'delivery_status' => 'sent',
                    'provider' => 'sms',
                    'provider_reference' => $res['message_id'] ?? null,
                    'sale_id' => $saleId,
                ]);
            } catch (Throwable $e) {
                error_log("Receipt SMS error: " . $e->getMessage());
            }
        }
    }

    /**
     * Requirement 5: Installment Reminder Notification
     */
    public function dispatchInstallmentReminder(array $installment, array $sale): void
    {
        $buyerEmail = $sale['buyer_email'] ?? '';
        $buyerName = $sale['buyer_name'] ?? 'Buyer';
        $buyerPhone = $sale['buyer_phone'] ?? '';
        $saleId = (int) ($sale['id'] ?? 0);
        $installmentId = (int) ($installment['id'] ?? 0);

        if (!empty($buyerEmail)) {
            try {
                $res = $this->emailService->sendInstallmentReminder($buyerEmail, $buyerName, $installment, $sale);
                $this->logNotification([
                    'recipient_name' => $buyerName,
                    'recipient_contact' => $buyerEmail,
                    'channel' => 'email',
                    'notification_type' => 'installment_reminder',
                    'message' => "Installment #{$installment['installment_number']} reminder",
                    'delivery_status' => 'sent',
                    'provider' => 'email',
                    'provider_reference' => $res['message_id'] ?? null,
                    'sale_id' => $saleId,
                    'installment_id' => $installmentId,
                ]);
            } catch (Throwable $e) {
                error_log("Reminder email error: " . $e->getMessage());
            }
        }

        if (!empty($buyerPhone)) {
            try {
                $res = $this->smsService->sendInstallmentReminder($buyerPhone, $installment, $sale);
                $this->logNotification([
                    'recipient_name' => $buyerName,
                    'recipient_contact' => $buyerPhone,
                    'channel' => 'sms',
                    'notification_type' => 'installment_reminder',
                    'message' => "Installment #{$installment['installment_number']} reminder SMS",
                    'delivery_status' => 'sent',
                    'provider' => 'sms',
                    'provider_reference' => $res['message_id'] ?? null,
                    'sale_id' => $saleId,
                    'installment_id' => $installmentId,
                ]);
            } catch (Throwable $e) {
                error_log("Reminder SMS error: " . $e->getMessage());
            }
        }
    }

    public function dispatchDuePaymentNotice(
        string $channel,
        array $target,
        array $invoice,
        string $event,
        string $outstandingAmount,
        array $companyProfile
    ): array {
        $name = (string) ($target['recipient_name'] ?? 'Customer');
        $contact = (string) ($target[$channel === 'email' ? 'recipient_email' : 'recipient_phone'] ?? '');
        $message = sprintf(
            '%s: %s %s is due on %s for %s. Invoice %s.',
            str_replace('_', ' ', ucfirst($event)),
            $target['currency'],
            $outstandingAmount,
            $target['due_date'],
            $target['property_title'],
            $invoice['invoice_number']
        );

        if ($channel === 'email') {
            $result = $this->emailService->sendPaymentInvoiceReminder(
                $contact,
                $name,
                $target,
                $invoice,
                $event,
                $outstandingAmount,
                $companyProfile
            );
        } elseif ($channel === 'sms') {
            $result = $this->smsService->sendPaymentDueReminder(
                $contact,
                $target,
                $invoice,
                $event,
                $outstandingAmount,
                $companyProfile
            );
        } else {
            throw new InvalidArgumentException('Unsupported notification channel.');
        }

        $success = !empty($result['success']);
        $this->logNotification([
            'recipient_name' => $name,
            'recipient_contact' => $contact,
            'channel' => $channel,
            'notification_type' => 'payment_' . $event,
            'message' => $message,
            'delivery_status' => $success ? 'sent' : 'failed',
            'provider' => $channel,
            'provider_reference' => $result['message_id'] ?? null,
            'error_message' => $success ? null : ($result['message'] ?? 'Delivery failed'),
            'sale_id' => $target['sale_id'] ?? null,
            'installment_id' => $target['target_type'] === 'installment' ? $target['target_id'] : null,
            'rent_charge_id' => $target['target_type'] === 'rent_charge' ? $target['target_id'] : null,
        ]);
        return $result;
    }

    /**
     * Persist record to notification_logs table
     */
    private function logNotification(array $data): void
    {
        global $pdo;

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO notification_logs
                    (recipient_name, recipient_contact, channel, notification_type,
                     message, delivery_status, provider, provider_reference, error_message,
                     enquiry_id, property_id, sale_id, installment_id, rent_charge_id, sent_at)
                 VALUES
                    (:recipient_name, :recipient_contact, :channel, :notification_type,
                     :message, :delivery_status, :provider, :provider_reference, :error_message,
                     :enquiry_id, :property_id, :sale_id, :installment_id, :rent_charge_id, NOW())"
            );
            $stmt->execute([
                ':recipient_name' => $data['recipient_name'] ?? 'User',
                ':recipient_contact' => $data['recipient_contact'] ?? '',
                ':channel' => $data['channel'] ?? 'email',
                ':notification_type' => $data['notification_type'] ?? 'general',
                ':message' => $data['message'] ?? '',
                ':delivery_status' => $data['delivery_status'] ?? 'pending',
                ':provider' => $data['provider'] ?? 'system',
                ':provider_reference' => $data['provider_reference'] ?? null,
                ':error_message' => $data['error_message'] ?? null,
                ':enquiry_id' => $data['enquiry_id'] ?? null,
                ':property_id' => $data['property_id'] ?? null,
                ':sale_id' => $data['sale_id'] ?? null,
                ':installment_id' => $data['installment_id'] ?? null,
                ':rent_charge_id' => $data['rent_charge_id'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log("Failed to insert notification_log: " . $e->getMessage());
        }
    }
}
