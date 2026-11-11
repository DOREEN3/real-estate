# Client Production Handover & Configuration Requirements

To move the Real Estate Management System from staging/sandbox mode to live production hosting, please provide the following details and credentials for the backend integrations and environment configuration.

---

## 1. Domain & Hosting Details
Required for CORS security, SSL/HTTPS links, password reset links, and agent/owner email registration form links:
- **Primary Website / Frontend URL (`APP_URL` / `REGISTRATION_URL`)**: e.g., `https://portal.yourcompany.com`
- **Backend API Base URL**: e.g., `https://api.yourcompany.com` (or `https://portal.yourcompany.com/api/v1`)
- **Password Reset Page URL (`PASSWORD_RESET_URL`)**: e.g., `https://portal.yourcompany.com/reset-password`
- **MySQL Production Database Credentials**:
  - `DB_HOST` (Database server host)
  - `DB_PORT` (Default: `3306`)
  - `DB_NAME` (Production database name)
  - `DB_USER` (Database username)
  - `DB_PASSWORD` (Database password)

---

## 2. Official Office / Admin Contact Details
Used for receiving automated copies of buyer enquiries, property match notifications, and system alerts:
- **Admin Notification Email (`ADMIN_NOTIFICATION_EMAIL`)**: e.g., `enquiries@yourcompany.com`
- **Admin Notification Phone / WhatsApp (`ADMIN_NOTIFICATION_PHONE`)**: e.g., `+2547XXXXXXXX`

---

## 3. Email Delivery Service (`app/services/EmailService.php`)
Used for sending **Agent & Owner Self-Registration Forms**, **Account Verification Approvals**, **Password Reset Links**, **New Property Alerts to Agents**, **Property Match Alerts**, **Payment Receipts**, and **Installment Reminders**.

Please provide credentials for **either Option A (SMTP) or Option B (SendGrid)**:

### Option A: Business Email SMTP (Google Workspace, Microsoft 365, cPanel, Zoho, etc.)
- `MAIL_DRIVER=smtp`
- `SMTP_HOST`: Outgoing mail server (e.g., `smtp.gmail.com` or `mail.yourcompany.com`)
- `SMTP_PORT`: `587` (TLS) or `465` (SSL)
- `SMTP_ENCRYPTION`: `tls` or `ssl`
- `SMTP_USER`: Full email address / SMTP username
- `SMTP_PASSWORD`: Email password or App Password
- `MAIL_FROM_ADDRESS`: Sender email address (e.g., `noreply@yourcompany.com`)
- `MAIL_FROM_NAME`: Sender display name (e.g., `Your Company Real Estate`)

### Option B: SendGrid Transactional Email API
- `MAIL_DRIVER=sendgrid`
- `SENDGRID_API_KEY`: Live SendGrid API Key (`SG.xxxx...`)
- `MAIL_FROM_ADDRESS`: Verified Sender Identity email in SendGrid
- `MAIL_FROM_NAME`: Sender display name

---

## 4. SMS Gateway Service (`app/services/SmsService.php`)
Used for sending automated **New Property SMS Alerts to Agents**, **Buyer/Seller Match Notifications**, **Payment Confirmation Receipts**, and **Installment Due Reminders**.

Please provide credentials for **either Option A (Africa's Talking) or Option B (Twilio)**:

### Option A: Africa's Talking (Recommended for Kenya / East Africa)
- `SMS_PROVIDER=africastalking`
- `AFRICASTALKING_USERNAME`: Your live Africa's Talking username
- `AFRICASTALKING_API_KEY`: Your live Africa's Talking API Key
- `SMS_SENDER_ID`: Registered alphanumeric Sender ID / Shortcode (e.g., `YOURBRAND`)

### Option B: Twilio SMS (International)
- `SMS_PROVIDER=twilio`
- `TWILIO_ACCOUNT_SID`: Twilio Account SID (`ACxxxx...`)
- `TWILIO_AUTH_TOKEN`: Twilio Auth Token
- `TWILIO_PHONE_NUMBER`: Registered Twilio sender phone number (`+1...`)

---

## 5. WhatsApp Business Cloud API (`app/services/WhatsAppService.php`)
Used for dispatching instant **WhatsApp Property Match Alerts** to the buyer lead, property seller/owner, introducing agent, and office, as well as **New Property Alerts** to agents.

- `WHATSAPP_PROVIDER=meta_cloud`
- `WHATSAPP_PHONE_NUMBER_ID`: Meta WhatsApp Business Phone Number ID
- `WHATSAPP_BUSINESS_ACCOUNT_ID`: Meta WhatsApp Business Account ID
- `WHATSAPP_ACCESS_TOKEN`: Permanent System User Access Token from Meta Business Suite with `whatsapp_business_messaging` permission

---

## 6. M-Pesa Daraja API (`app/services/MpesaDarajaService.php`)
Used for initiating **Live M-Pesa STK Push** payment prompts and receiving real-time payment confirmations from Safaricom.

- `MPESA_ENV=production`
- `MPESA_TRANSACTION_TYPE`: `CustomerPayBillOnline` (Paybill) or `CustomerBuyGoodsOnline` (Till Number)
- `MPESA_SHORTCODE`: Business Paybill or BuyGoods Till Shortcode
- `MPESA_CONSUMER_KEY`: Production Consumer Key from Safaricom Daraja Portal
- `MPESA_CONSUMER_SECRET`: Production Consumer Secret from Safaricom Daraja Portal
- `MPESA_PASSKEY`: Lipa Na M-Pesa Online Passkey provided by Safaricom
- **Callback URL to whitelist with Safaricom**:
  `https://yourdomain.com/api/v1/payments/providers/mpesa/callback`

---

## 7. Automated Bank Payment Integration (`app/services/BankPaymentService.php`)
Used for automatic reconciliation of bank transfers and deposits (Pesalink, Equity Jenga, KCB Buni, Co-op Bank, etc.) quoting references (`SALE-{id}`, `TEN-{id}`, `BNB-{id}`, `INST-{id}`).

- `BANK_PROVIDER`: Bank / aggregator name (e.g., `pesalink`, `equity`, `kcb`, `coop`)
- `BANK_NAME`: Bank name to display on customer payment instructions and invoices
- `BANK_ACCOUNT_NUMBER`: Company bank account number for buyer/tenant deposits
- `BANK_API_KEY`: Bank API Key (if required by your bank's IPN portal)
- `BANK_WEBHOOK_SECRET`: Secret key (minimum 16 characters) shared with the bank to verify HMAC-SHA256 webhook signatures
- **Bank Webhook / IPN URL to register with the bank**:
  `https://yourdomain.com/api/v1/payments/providers/bank/callback`
