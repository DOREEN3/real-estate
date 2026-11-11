<?php

declare(strict_types=1);

/**
 * Email Service
 * Supports SMTP, SendGrid, and native mail transports
 * Dispatches registration invites, password resets, property alerts, and receipts
 */
class EmailService
{
    private string $driver;
    private string $fromAddress;
    private string $fromName;
    private string $appEnv;

    public function __construct()
    {
        $this->appEnv = strtolower((string) (getenv('APP_ENV') ?: 'development'));
        $this->driver = strtolower((string) (getenv('MAIL_DRIVER') ?: 'smtp'));
        $this->fromAddress = (string) (getenv('MAIL_FROM_ADDRESS') ?: 'noreply@example.com');
        $this->fromName = (string) (getenv('MAIL_FROM_NAME') ?: 'Real Estate Portal');
    }

    /**
     * Validate environment configuration for production email dispatch
     */
    public function assertConfigured(): void
    {
        if ($this->appEnv === 'development') {
            // Development environment allows testing without live production SMTP credentials
            return;
        }

        if (!filter_var($this->fromAddress, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('MAIL_FROM_ADDRESS is not a valid email address.');
        }

        if ($this->driver === 'smtp') {
            foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASSWORD'] as $var) {
                $val = getenv($var);
                if (!is_string($val) || trim($val) === '') {
                    throw new RuntimeException("SMTP configuration missing: {$var}");
                }
            }
        } elseif ($this->driver === 'sendgrid') {
            $apiKey = getenv('SENDGRID_API_KEY');
            if (!is_string($apiKey) || trim($apiKey) === '') {
                throw new RuntimeException('SendGrid configuration missing: SENDGRID_API_KEY');
            }
        }
    }

    /**
     * Send generic email
     */
    public function send(string $to, string $subject, string $htmlBody, string $textBody = ''): array
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid recipient email address: {$to}");
        }

        if (empty($textBody)) {
            $textBody = strip_tags($htmlBody);
        }

        if ($this->appEnv === 'development') {
            $hasSmtp = !empty(getenv('SMTP_HOST')) && !empty(getenv('SMTP_USER')) && !empty(getenv('SMTP_PASSWORD'));
            $hasSendgrid = !empty(getenv('SENDGRID_API_KEY'));
            if (!$hasSmtp && !$hasSendgrid) {
                error_log("[EmailService::DEV] To: {$to} | Subject: {$subject} (Simulated - no SMTP_PASSWORD in .env)");
                $simulatedResult = [
                    'success' => true,
                    'status' => 'logged_dev_offline',
                    'message_id' => 'msg-' . bin2hex(random_bytes(8)),
                    'recipient' => $to,
                    'notice' => 'Development mode: SMTP password is not set in .env. Email dispatch simulated locally.',
                ];
                $this->recordNotificationLog($to, $subject, $simulatedResult);
                return $simulatedResult;
            }
        }

        $result = null;
        if ($this->driver === 'sendgrid') {
            $result = $this->sendViaSendGrid($to, $subject, $htmlBody, $textBody);
        } elseif ($this->driver === 'smtp') {
            $result = $this->sendViaSmtp($to, $subject, $htmlBody, $textBody);
        } else {
            $result = $this->sendViaNativeMail($to, $subject, $htmlBody, $textBody);
        }

        $this->recordNotificationLog($to, $subject, $result);
        return $result;
    }

    /**
     * Send Self-Registration Form Invite Link to Agent or Property Owner
     */
    public function sendRegistrationInvite(string $toEmail, string $role, string $recipientName = ''): array
    {
        $baseUrl = rtrim((string) (getenv('REGISTRATION_URL') ?: getenv('APP_URL') ?: 'http://localhost:5173'), '/');
        $separator = strpos($baseUrl, '?') === false ? '?' : '&';
        $roleParam = in_array($role, ['agent', 'owner'], true) ? $role : 'agent';
        $queryParams = [
            'mode' => 'register',
            'role' => $roleParam,
            'email' => $toEmail,
        ];
        if (!empty($recipientName)) {
            $queryParams['name'] = $recipientName;
        }
        $registrationUrl = $baseUrl . $separator . http_build_query($queryParams);

        $roleLabel = $roleParam === 'owner' ? 'Property Owner' : 'Real Estate Agent';
        $greeting = !empty($recipientName) ? "Hello " . htmlspecialchars($recipientName) : "Hello";
        $subject = "Invitation to Register as a {$roleLabel}";

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>{$subject}</title></head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; background: #f4f6f8; padding: 20px;">
    <div style="max-width: 600px; margin: auto; background: #fff; padding: 30px; border-radius: 8px; border: 1px solid #e1e8ed;">
        <h2 style="color: #0F2A43; margin-top: 0;">Complete Your {$roleLabel} Registration</h2>
        <p>{$greeting},</p>
        <p>You have been invited to register your <strong>{$roleLabel}</strong> account on our Real Estate Portal. Please use the link below to open the registration form and submit your details:</p>
        <p style="margin: 30px 0; text-align: center;">
            <a href="{$registrationUrl}" style="background-color: #0F2A43; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;">Open Registration Form</a>
        </p>
        <p style="font-size: 13px; color: #666;">Or copy and paste this link into your browser:<br><a href="{$registrationUrl}" style="color: #0F2A43;">{$registrationUrl}</a></p>
        <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
        <p style="font-size: 12px; color: #888;">Once you submit your registration form, an administrator will verify your account so you can sign in and list properties directly.</p>
    </div>
</body>
</html>
HTML;

        $res = $this->send($toEmail, $subject, $html);
        $res['registration_url'] = $registrationUrl;
        return $res;
    }

    /**
     * Send Account Verification Status Email to Agent or Owner
     */
    public function sendAccountVerificationStatus(string $toEmail, string $recipientName, string $role, string $status): array
    {
        $roleLabel = $role === 'owner' ? 'Property Owner' : 'Real Estate Agent';
        $isApproved = $status === 'approved';
        $subject = $isApproved
            ? "Your {$roleLabel} Account Has Been Verified"
            : "Update on Your {$roleLabel} Account Registration";
        $portalUrl = (string) (getenv('APP_URL') ?: 'http://localhost:5173');
        $greeting = !empty($recipientName) ? "Hello " . htmlspecialchars($recipientName) : "Hello";

        $bodyText = $isApproved
            ? "<p>Your <strong>{$roleLabel}</strong> account has been verified and approved by our administrator. You can now sign in to the portal and list properties directly.</p>
               <p style=\"margin: 25px 0; text-align: center;\"><a href=\"{$portalUrl}\" style=\"background-color: #0F2A43; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;\">Sign In to Portal</a></p>"
            : "<p>Thank you for submitting your {$roleLabel} registration. Unfortunately, your application could not be verified at this time. Please contact our office if you need assistance or would like to provide additional verification details.</p>";

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>{$subject}</title></head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; background: #f4f6f8; padding: 20px;">
    <div style="max-width: 600px; margin: auto; background: #fff; padding: 30px; border-radius: 8px; border: 1px solid #e1e8ed;">
        <h2 style="color: #0F2A43; margin-top: 0;">{$subject}</h2>
        <p>{$greeting},</p>
        {$bodyText}
    </div>
</body>
</html>
HTML;

        return $this->send($toEmail, $subject, $html);
    }

    /**
     * Send Password Reset Email
     */
    public function sendPasswordReset(string $to, string $token, string $recipientName = ''): array
    {
        $resetBaseUrl = getenv('PASSWORD_RESET_URL') ?: 'https://app.example.com/reset-password';
        $separator = strpos($resetBaseUrl, '?') === false ? '?' : '&';
        $resetLink = $resetBaseUrl . $separator . 'token=' . rawurlencode($token);

        $greeting = !empty($recipientName) ? "Hello " . htmlspecialchars($recipientName) : "Hello";
        $subject = "Reset Your Password - Real Estate Portal";

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>{$subject}</title></head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; background: #f4f6f8; padding: 20px;">
    <div style="max-width: 600px; margin: auto; background: #fff; padding: 30px; border-radius: 8px; border: 1px solid #e1e8ed;">
        <h2 style="color: #0F2A43; margin-top: 0;">Password Reset Request</h2>
        <p>{$greeting},</p>
        <p>We received a request to reset your account password. Click the secure link below within 1 hour to set a new password:</p>
        <p style="margin: 30px 0; text-align: center;">
            <a href="{$resetLink}" style="background-color: #0F2A43; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;">Reset Password</a>
        </p>
        <p style="font-size: 13px; color: #666;">Or copy and paste this link into your browser:<br><a href="{$resetLink}" style="color: #0F2A43;">{$resetLink}</a></p>
        <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
        <p style="font-size: 12px; color: #888;">If you did not request this, please ignore this email. Your password remains safe.</p>
    </div>
</body>
</html>
HTML;

        $res = $this->send($to, $subject, $html);
        $res['reset_link'] = $resetLink;
        return $res;
    }

    /**
     * Send New Property Alert to Agents (Requirement 4)
     */
    public function sendNewPropertyAlert(string $agentEmail, string $agentName, array $property): array
    {
        $subject = "New Property Listed: " . ($property['title'] ?? 'New Listing');
        $price = number_format((float) ($property['price'] ?? 0), 2);
        $currency = $property['currency'] ?? 'KES';
        $location = htmlspecialchars($property['city'] ?? $property['address'] ?? 'Worldwide');
        $type = ucfirst($property['property_type'] ?? 'Property');

        $html = <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; background: #fff; border-radius: 8px; border: 1px solid #ddd;">
    <h2 style="color: #0F2A43;">New Property Available for Your Clients</h2>
    <p>Dear {$agentName},</p>
    <p>A new verified property listing has just been added to the inventory:</p>
    <div style="background: #f8f9fa; padding: 15px; border-left: 4px solid #D4A72C; margin: 20px 0;">
        <h3 style="margin: 0 0 10px 0; color: #0F2A43;">{$property['title']}</h3>
        <p style="margin: 4px 0;"><strong>Type:</strong> {$type}</p>
        <p style="margin: 4px 0;"><strong>Location:</strong> {$location}</p>
        <p style="margin: 4px 0;"><strong>Price:</strong> {$currency} {$price}</p>
    </div>
    <p>Log in to your agent portal to share with registered buyers.</p>
</div>
HTML;

        return $this->send($agentEmail, $subject, $html);
    }

    /**
     * Send Property Match Alert (Requirement 4: Lead, Seller, Introducing Agent, Office)
     */
    public function sendMatchAlert(string $toEmail, string $recipientName, array $property, array $enquiry, string $role): array
    {
        $subject = "Serious Property Match Available: " . ($property['title'] ?? 'Listing');
        $currency = $property['currency'] ?? 'KES';
        $price = number_format((float) ($property['price'] ?? 0), 2);
        $roleTitle = ucfirst($role);

        $html = <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; background: #fff; border: 1px solid #ddd; border-radius: 8px;">
    <div style="background: #0F2A43; color: #fff; padding: 15px; border-radius: 6px 6px 0 0;">
        <h2 style="margin: 0; color: #eac95f;">Serious Property Match Notification</h2>
    </div>
    <div style="padding: 20px;">
        <p>Dear {$recipientName} ({$roleTitle}),</p>
        <p>Our automated matching system has paired a qualified buyer enquiry with a verified property listing.</p>
        <div style="background: #f9f9f9; padding: 15px; border-radius: 6px; margin: 15px 0;">
            <h4 style="margin-top: 0; color: #0F2A43;">Property Details</h4>
            <p><strong>Listing:</strong> {$property['title']}</p>
            <p><strong>Price:</strong> {$currency} {$price}</p>
            <p><strong>Location:</strong> {$property['city']}, {$property['country']}</p>
        </div>
        <div style="background: #f9f9f9; padding: 15px; border-radius: 6px; margin: 15px 0;">
            <h4 style="margin-top: 0; color: #0F2A43;">Buyer Enquiry Criteria</h4>
            <p><strong>Client Name:</strong> {$enquiry['client_name']}</p>
            <p><strong>Budget Range:</strong> {$enquiry['currency']} {$enquiry['min_price']} - {$enquiry['max_price']}</p>
        </div>
        <p style="font-size: 13px; color: #666;">Please follow up with the platform administrator to proceed with viewing or reservations.</p>
    </div>
</div>
HTML;

        return $this->send($toEmail, $subject, $html);
    }

    /**
     * Send Payment Receipt Email (Requirement 5)
     */
    public function sendPaymentReceipt(string $toEmail, string $buyerName, array $payment, array $sale): array
    {
        $subject = "Official Payment Receipt - #" . ($payment['receipt_number'] ?? $payment['id']);
        $amount = number_format((float) ($payment['amount'] ?? 0), 2);
        $currency = $sale['currency'] ?? 'KES';

        $html = <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; background: #fff; border: 1px solid #ddd; border-radius: 8px;">
    <h2 style="color: #0F2A43; border-bottom: 2px solid #D4A72C; padding-bottom: 10px;">Payment Receipt Confirmed</h2>
    <p>Dear {$buyerName},</p>
    <p>Thank you for your payment towards your real estate purchase. Here are the official transaction details:</p>
    <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
        <tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Receipt Number:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">{$payment['receipt_number']}</td></tr>
        <tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Amount Paid:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">{$currency} {$amount}</td></tr>
        <tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Payment Method:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">{$payment['payment_method']}</td></tr>
        <tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Date:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">{$payment['payment_date']}</td></tr>
        <tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Property:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">{$sale['property_title']}</td></tr>
    </table>
    <p>Please keep this receipt for your records.</p>
</div>
HTML;

        return $this->send($toEmail, $subject, $html);
    }

    /**
     * Send Installment Reminder Email (Requirement 5)
     */
    public function sendInstallmentReminder(string $toEmail, string $buyerName, array $installment, array $sale): array
    {
        $subject = "Installment Due Reminder - {$sale['property_title']}";
        $amount = number_format((float) ($installment['amount_due'] ?? 0), 2);
        $dueDate = $installment['due_date'] ?? 'Upcoming';
        $currency = $sale['currency'] ?? 'KES';

        $html = <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; background: #fff; border: 1px solid #ddd; border-radius: 8px;">
    <h2 style="color: #0F2A43;">Installment Payment Reminder</h2>
    <p>Dear {$buyerName},</p>
    <p>This is a friendly reminder that installment #{$installment['installment_number']} for <strong>{$sale['property_title']}</strong> is due on <strong>{$dueDate}</strong>.</p>
    <div style="background: #fdf8e8; padding: 15px; border-radius: 6px; border: 1px solid #f2e2b0; margin: 20px 0;">
        <p style="margin: 0; font-size: 16px;"><strong>Amount Due:</strong> {$currency} {$amount}</p>
        <p style="margin: 5px 0 0 0; color: #876509;">Due Date: {$dueDate}</p>
    </div>
    <p>You can pay conveniently via M-Pesa or direct Bank Transfer quoting reference: <strong>SALE-{$sale['id']}</strong>.</p>
</div>
HTML;

        return $this->send($toEmail, $subject, $html);
    }

    public function sendPaymentInvoiceReminder(
        string $toEmail,
        string $recipientName,
        array $target,
        array $invoice,
        string $event,
        string $outstandingAmount,
        array $companyProfile
    ): array {
        $currency = htmlspecialchars((string) $target['currency'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $name = htmlspecialchars($recipientName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $property = htmlspecialchars((string) $target['property_title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $invoiceNumber = htmlspecialchars((string) $invoice['invoice_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $dueDate = htmlspecialchars((string) $target['due_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $amount = number_format((float) $outstandingAmount, 2);
        $companyName = htmlspecialchars(
            (string) ($companyProfile['company_name'] ?? $this->fromName),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $subjectStatus = match ($event) {
            'due_today' => 'Payment Due Today',
            'overdue', 'overdue_followup' => 'Overdue Payment Notice',
            default => 'Upcoming Payment Reminder',
        };
        $installmentLabel = $target['target_type'] === 'installment'
            ? 'Installment #' . (int) $target['installment_number']
            : 'Rent';
        $logoUrl = (string) ($companyProfile['logo_url'] ?? '');
        $logo = filter_var($logoUrl, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($logoUrl, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" alt="' . $companyName . '" style="max-width:180px;max-height:70px;">'
            : '';
        $subject = "{$subjectStatus} - {$property}";
        $html = <<<HTML
<div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;padding:24px;color:#243447;background:#fff;border:1px solid #e5e7eb;border-radius:8px;">
    <header style="text-align:center;margin-bottom:20px;">{$logo}<h2 style="color:#0F2A43;">{$companyName}</h2></header>
    <h3>{$subjectStatus}</h3>
    <p>Hello {$name},</p>
    <p>{$installmentLabel} payment for <strong>{$property}</strong> is due on <strong>{$dueDate}</strong>.</p>
    <table style="width:100%;border-collapse:collapse;margin:20px 0;">
        <tr><td style="padding:8px;border-bottom:1px solid #eee;">Invoice</td><td style="padding:8px;border-bottom:1px solid #eee;">{$invoiceNumber}</td></tr>
        <tr><td style="padding:8px;border-bottom:1px solid #eee;">Outstanding balance</td><td style="padding:8px;border-bottom:1px solid #eee;"><strong>{$currency} {$amount}</strong></td></tr>
        <tr><td style="padding:8px;border-bottom:1px solid #eee;">Due date</td><td style="padding:8px;border-bottom:1px solid #eee;">{$dueDate}</td></tr>
    </table>
    <p>Please contact management if you need assistance.</p>
</div>
HTML;
        $text = "{$subjectStatus}: {$currency} {$amount} for {$property} is due {$dueDate}. Invoice {$invoiceNumber}.";
        return $this->send($toEmail, $subject, $html, $text);
    }

    private function sendViaSendGrid(string $to, string $subject, string $html, string $text): array
    {
        $apiKey = (string) getenv('SENDGRID_API_KEY');
        $payload = [
            'personalizations' => [['to' => [['email' => $to]]]],
            'from' => ['email' => $this->fromAddress, 'name' => $this->fromName],
            'subject' => $subject,
            'content' => [
                ['type' => 'text/plain', 'value' => $text],
                ['type' => 'text/html', 'value' => $html],
            ],
        ];

        $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
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
            throw new RuntimeException("SendGrid cURL error: {$curlError}");
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'provider' => 'sendgrid', 'http_code' => $httpCode];
        }

        throw new RuntimeException("SendGrid API returned HTTP {$httpCode}: {$response}");
    }

    private function sendViaSmtp(string $to, string $subject, string $html, string $text): array
    {
        $host = (string) getenv('SMTP_HOST');
        $port = (int) (getenv('SMTP_PORT') ?: 587);
        $user = (string) getenv('SMTP_USER');
        $pass = (string) getenv('SMTP_PASSWORD');
        $encryption = strtolower((string) (getenv('SMTP_ENCRYPTION') ?: 'tls'));

        $timeout = 8;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ]
        ]);

        $prefix = ($encryption === 'ssl' || $port === 465) ? 'ssl://' : '';
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($prefix . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$socket) {
            $errorMsg = "SMTP connection to {$host}:{$port} failed: [{$errno}] {$errstr}";
            error_log($errorMsg);
            return [
                'success' => false,
                'status' => 'connection_failed',
                'error' => $errorMsg,
                'provider' => 'smtp',
                'to' => $to,
            ];
        }

        try {
            $greeting = $this->readSmtpResponse($socket);
            if ((int) substr($greeting, 0, 3) !== 220) {
                throw new RuntimeException("SMTP greeting rejected: " . trim($greeting));
            }

            // Always use a valid FQDN in EHLO (RFC 2821 / Exim requirement)
            $heloHost = !empty($host) ? $host : 'localhost.localdomain';
            $this->writeSmtpCommand($socket, "EHLO {$heloHost}", [250]);

            if ($encryption === 'tls' && $port !== 465) {
                $this->writeSmtpCommand($socket, "STARTTLS", [220]);
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $this->writeSmtpCommand($socket, "EHLO {$heloHost}", [250]);
            }

            if (!empty($user) && !empty($pass)) {
                $this->writeSmtpCommand($socket, "AUTH LOGIN", [334]);
                $this->writeSmtpCommand($socket, base64_encode($user), [334]);
                $this->writeSmtpCommand($socket, base64_encode($pass), [235]);
            } elseif (!empty($user) && empty($pass)) {
                error_log("Warning: SMTP_USER is set ({$user}) but SMTP_PASSWORD is empty in .env. Attempting unauthenticated send.");
            }

            $this->writeSmtpCommand($socket, "MAIL FROM:<{$this->fromAddress}>", [250]);
            $this->writeSmtpCommand($socket, "RCPT TO:<{$to}>", [250]);
            $this->writeSmtpCommand($socket, "DATA", [354]);

            $boundary = md5((string) time());
            $headers = [
                "From: {$this->fromName} <{$this->fromAddress}>",
                "To: <{$to}>",
                "Subject: {$subject}",
                "MIME-Version: 1.0",
                "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
            ];

            $messageBody = implode("\r\n", $headers) . "\r\n\r\n"
                . "--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
                . $text . "\r\n\r\n"
                . "--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
                . $html . "\r\n\r\n"
                . "--{$boundary}--\r\n.";

            $this->writeSmtpCommand($socket, $messageBody, [250]);
            $this->writeSmtpCommand($socket, "QUIT", [221, 250]);
            fclose($socket);

            return ['success' => true, 'provider' => 'smtp', 'to' => $to];
        } catch (Throwable $e) {
            if (is_resource($socket)) {
                fclose($socket);
            }
            $errorMsg = "SMTP delivery error: " . $e->getMessage();
            error_log($errorMsg);
            return [
                'success' => false,
                'status' => 'delivery_failed',
                'error' => $errorMsg,
                'provider' => 'smtp',
                'to' => $to,
            ];
        }
    }

    private function writeSmtpCommand($socket, string $command, array $expectedCodes = [250]): string
    {
        fwrite($socket, $command . "\r\n");
        $response = $this->readSmtpResponse($socket);
        $code = (int) substr($response, 0, 3);
        if (!empty($expectedCodes) && !in_array($code, $expectedCodes, true)) {
            throw new RuntimeException("SMTP error [{$code}]: " . trim($response));
        }
        return $response;
    }

    private function readSmtpResponse($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (strlen($line) >= 4 && substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return $response;
    }

    private function sendViaNativeMail(string $to, string $subject, string $html, string $text): array
    {
        $headers = [
            "From: {$this->fromName} <{$this->fromAddress}>",
            "MIME-Version: 1.0",
            "Content-Type: text/html; charset=UTF-8",
        ];

        $sent = @mail($to, $subject, $html, implode("\r\n", $headers));
        if (!$sent && $this->appEnv === 'development') {
            error_log("[EmailService::DEV_MAIL_SIMULATED] Simulated delivery to: {$to}");
            return [
                'success' => true,
                'status' => 'simulated',
                'provider' => 'dev_simulated',
                'to' => $to,
            ];
        }
        return [
            'success' => $sent,
            'provider' => 'native',
            'to' => $to,
        ];
    }

    /**
     * Persist email dispatch record to notification_logs
     */
    private function recordNotificationLog(string $to, string $subject, ?array $result): void
    {
        try {
            global $pdo;
            if (!$pdo) {
                return;
            }
            $status = (!empty($result['success']) && ($result['status'] ?? '') !== 'logged_dev_offline') ? 'sent' : 'failed';
            $error = $result['error'] ?? $result['notice'] ?? null;
            $provider = $result['provider'] ?? ($result['status'] ?? $this->driver);

            $stmt = $pdo->prepare(
                "INSERT INTO notification_logs
                    (recipient_contact, channel, notification_type, message, delivery_status, provider, error_message, sent_at)
                 VALUES
                    (:to, 'email', 'email_dispatch', :msg, :status, :provider, :error, " . ($status === 'sent' ? 'CURRENT_TIMESTAMP' : 'NULL') . ")"
            );
            $stmt->execute([
                ':to' => $to,
                ':msg' => "Subject: " . mb_substr($subject, 0, 190),
                ':status' => $status,
                ':provider' => mb_substr((string) $provider, 0, 95),
                ':error' => $error ? mb_substr((string) $error, 0, 500) : null,
            ]);
        } catch (Throwable) {
            // Non-blocking log failure
        }
    }
}
