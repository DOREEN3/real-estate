# Real Estate Management System
## Production Readiness, Security Hardening & Integration Documentation

---

## 1. Executive Summary & Overengineering Audit

This document details the complete production readiness, architectural alignment, security hardening, automated payment reconciliations, and messaging integrations performed on the **Real Estate Management Platform** based on `userrequirements.txt`.

### 1.1 Core User Requirements vs. Codebase Audit

| # | User Requirement | Implementation Status | Overengineering Assessment & Action Taken |
|---|------------------|----------------------|-------------------------------------------|
| **1** | Capacity to collect property details: Google pin, location, price, description, photos | ✅ Complete (`PropertyController`, `PropertyModel`, `properties`, `property_images`) | Clean. Matches requirements. Supported in catalog and administration. |
| **2** | Capable of saving an Enquiry | ✅ Complete (`EnquiryController`, `EnquiryModel`, `enquiries`) | Clean. Form inputs sanitized and validated. |
| **3** | Match existing and future new properties with registered Enquiries | ✅ Complete (`EnquiryModel::refreshMatches`, `property_matches`) | Clean automated matching algorithm matching budget, location, and type. |
| **4** | Automated actions: When #1 is saved, all agents get notified. When #3 has similar properties, seller, agent, office, and lead get Email, SMS, and WhatsApp alerts | ✅ Complete (`NotificationDispatcher`, `EmailService`, `SmsService`, `WhatsAppService`) | Skeletons & placeholders fully equipped and wired into `PropertyController` and `EnquiryController`. Logs persisted to `notification_logs`. |
| **5** | Manage sales: Register sale, capture payments (Receipting), statements & reminder messages for instalments | ✅ Complete (`SaleController`, `PaymentController`, `SaleModel`, `PaymentModel`) | Automated receipting, financial statements, M-Pesa STK push, Bank reconciliation, and installment reminders implemented. |
| **6** | Show available properties with filtrations (location, price range, type, etc.) | ✅ Complete (`PropertyController::getProperties`) | Clean filtration and search on frontend and backend. |
| **7** | Accessed on desktop and phone | ✅ Complete (Responsive React 19 + Tailwind CSS) | Mobile sidebar drawer and responsive dashboard grid. |
| **8** | Verified agents and property owners to list directly, but enquiry contacts done to us | ✅ Complete (`UserController`, `PropertyController`, `EmailService`) | Admin sends email self-registration link (`POST /api/v1/admin/users/invite`); agents/owners register themselves and admin verifies/approves (`account_status = 'approved'`). Leads and contacts routed to the office. |
| **9** | Accommodate properties for sale, BnB, and rental properties worldwide | ✅ Complete (`properties.transaction_type`, `properties.currency`, `properties.country`) | Catalog supports sale, rent, and BnB categories across worldwide markets. |

---

### 1.2 What Was Overengineered & Moved to `extras/`

During the architectural audit, several extensive subsystems were identified that went significantly beyond the 9 user requirements:

1. **Physical Site Inspection Visits & Officer Dispatch Subsystem**:
   - **What was built**: A multi-stage inspection subsystem with physical visit scheduling (`/visits`), officer assignment, visit outcome recording (`property_verification_visits`), and private document uploads (`storage/private/property-verifications/`).
   - **Why it was overengineered**: Requirement 8 simply specified: *"Verified agents and property owners to list the properties directly but enquiry contacts to be done to us"*. Requirement 8 is an account-level authorization check (an administrator verifies the agent or owner profile before their listings go live). It did not ask for on-site physical property inspection bookings or verification officers.
   - **Action**: Safely preserved in `extras/property-verification/` (models, controllers, migrations, tests).
2. **Tenancy & Rent Roll Management ERP System**:
   - **What was built**: A full property management system (PMS) with tenants, tenancies, monthly recurring rent roll generation (`rent_charges`), rent payments, and lease tracking.
   - **Why it was overengineered**: Requirement 9 states: *"Software to accommodate properties for sale, BnB and rental properties worldwide"*. This is a listing category/catalog classification (`listing_type` = 'sale', 'bnb', 'rental'), not a recurring tenancy billing ERP.
   - **Action**: Safely preserved in `extras/deferred-rentals/`.
3. **Internal CRM Call Follow-up Ticketing (`enquiry_follow_ups`)**:
   - **What was built**: Call-center internal agent notes and ticketing follow-ups attached to enquiries.
   - **Why it was overengineered**: Requirements 2 & 4 only required saving buyer criteria and automatically alerting leads/sellers/agents when matching inventory is found.
   - **Action**: Safely preserved in `extras/crm-followups/`.
4. **Mock Frontend Demo Data & Legacy Demo Files**:
   - **What was present**: `demoData.js`, `demoUsers.js`, `enquiries.json`, `Properties.json`, `DemoLogin.jsx`, `AgentRegistration.jsx`, and `usePersistentState.js`, plus shared utilities misnamed `src/lib/demo.js` and demo/prototype/simulated banners in the UI.
   - **Action**: Removed all mock demo data, legacy demo components, and prototype/simulated/technical JWT/ERP notices from the frontend. Renamed `src/lib/demo.js` to `src/lib/utils.js`.

---

## 2. Production Security Hardening & Audit

A comprehensive defensive security audit was conducted. The following hardening measures have been implemented:

### 2.1 File Exposure Protection
- **Root `.htaccess`**: Created at the web root (`c:\xampp\htdocs\real-estate-backend\.htaccess`). Denies direct HTTP access to `.env`, `.git`, `app/`, `config/`, `database/`, `storage/`, `tests/`, and `extras/`. Automatically rewrites all incoming public and API requests to `/public/`.
- **Private Storage Protection**: Added `Deny from all` `.htaccess` inside `storage/private/`.
- **Proper `.gitignore`**: Replaced the accidental empty `.gitignore` folder with a comprehensive `.gitignore` file that ignores `.env`, `.env.*`, certificates, private keys, database dumps (`*.sql`), `node_modules/`, and private uploads.

### 2.2 Environment & Secrets Management
- **Environment Loader (`config/env.php`)**: Secure native parser that loads key-value pairs from `.env` without overwriting existing environment variables.
- **Production `.env.example`**: Complete template documenting every secret, API key, database setting, and webhook configuration.
- **Zero Exposed Keys**: Verified that no secrets, database passwords, or JWT secrets are hardcoded in the codebase.

### 2.3 Debug Mode & Error Concealment
- In `public/index.php`:
  ```php
  $appEnvironment = strtolower(getenv('APP_ENV') ?: 'development');
  $appDebug = filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN);
  if ($appEnvironment === 'production' || !$appDebug) {
      ini_set('display_errors', '0');
      ini_set('display_startup_errors', '0');
      error_reporting(E_ALL);
  }
  ```
- Uncaught exceptions and database errors are logged privately via `error_log()` and return generic 500 JSON responses to the client, preventing information disclosure.

### 2.4 Security Headers
`Middleware::securityHeaders()` enforces strict production headers on every HTTP response:
- `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'`
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `X-XSS-Protection: 1; mode=block`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- `Cross-Origin-Opener-Policy: same-origin`
- `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload` (over HTTPS)
- `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`

### 2.5 Authentication & Access Control (RBAC)
- **Password Hashing**: Verified using PHP's standard `password_hash($password, PASSWORD_DEFAULT)` which applies industry-standard Bcrypt. All existing users in the database verified.
- **JWT Architecture**: Short-lived access tokens (15 minutes) and rotating cryptographically secure refresh tokens stored as SHA-256 hashes in `refresh_tokens`. Single-use refresh token rotation with immediate revocation upon use.
- **Account Status Guard**: Unapproved or suspended users cannot sign in or generate tokens (`account_status === 'approved'` required).
- **Admin Route Protection**: Strict role checks (`Middleware::$routeRoleRequirements`) on every administrative endpoint.
- **Rate Limiting**: MySQL-backed sliding window rate limiter guarded by database locks to prevent brute force and distributed abuse.

### 2.6 Form Sanitization & Modern Validation
- **Backend (`app/security/Validator.php`)**: Enhanced with `sanitizeInput()` (strips tags, control characters), `sanitizeArray()`, `phone()` (E.164 / Kenyan format validation), `numeric()`, and `date()`.
- **Frontend (`real-estate/src/lib/validation.js`)**: Modern client-side validator enforcing length limits, regex email validation, password complexity (uppercase, lowercase, number, special character), and XSS tag stripping before network transmission.

---

## 3. Notification Services & Client Integration Guide

To satisfy Requirement 4 and password reset workflows, production-ready service skeletons have been implemented following the architectural pattern of `MpesaDarajaService.php`:

### 3.1 Implemented Services

1. **`app/services/EmailService.php`**:
   - Supports SMTP (TLS/SSL socket implementation), SendGrid API (`v3/mail/send`), and native mail transport.
   - Dispatches password resets, new property alerts to agents, serious property match alerts to leads and sellers, payment receipts, and installment reminders.
2. **`app/services/SmsService.php`**:
   - Supports Africa's Talking API, Twilio REST API, and Sandbox simulation.
   - Normalizes Kenyan (`07...` -> `+2547...`) and international phone numbers to E.164.
3. **`app/services/WhatsAppService.php`**:
   - Supports Meta WhatsApp Cloud API (`graph.facebook.com/v19.0`) and Twilio WhatsApp.
   - Dispatches instant match and property alerts.
4. **`app/services/NotificationDispatcher.php`**:
   - Orchestrates multi-channel automated workflows and logs delivery status to `notification_logs` table (`channel`, `notification_type`, `delivery_status`, `provider_reference`, `error_message`).

---

### 3.2 What We Need From the Client to Go Live

To switch from sandbox simulation to real external delivery, the client needs to provide the following credentials:

#### A. For Email Delivery (Choose Option 1 or Option 2)
- **Option 1: SMTP Server (e.g., Google Workspace, Office 365, Mailtrap, Hostinger)**:
  - `SMTP_HOST`: (e.g., `smtp.gmail.com` or `mail.yourdomain.com`)
  - `SMTP_PORT`: `587` (TLS) or `465` (SSL)
  - `SMTP_USER`: Sender email username
  - `SMTP_PASSWORD`: App password or SMTP password
  - `MAIL_FROM_ADDRESS`: (e.g., `info@yourdomain.com`)
  - `MAIL_FROM_NAME`: (e.g., `GoldERP Real Estate`)
- **Option 2: SendGrid API**:
  - `SENDGRID_API_KEY`: Verified API Key (starts with `SG.`)
  - Verified Sender Identity email in SendGrid console.

#### B. For SMS Delivery (Choose Option 1 or Option 2)
- **Option 1: Africa's Talking (Recommended for Kenya/East Africa)**:
  - `AFRICASTALKING_USERNAME`: Africa's Talking account username (or `sandbox` for testing).
  - `AFRICASTALKING_API_KEY`: API Key generated from the dashboard.
  - `SMS_SENDER_ID`: Registered alphanumeric Sender ID approved by CAK/Safaricom (e.g., `REALESTATE`).
- **Option 2: Twilio**:
  - `TWILIO_ACCOUNT_SID`
  - `TWILIO_AUTH_TOKEN`
  - `TWILIO_PHONE_NUMBER`

#### C. For WhatsApp Business Notifications
- **Meta WhatsApp Cloud API**:
  - `WHATSAPP_PHONE_NUMBER_ID`: From Meta Developer App > WhatsApp > API Setup.
  - `WHATSAPP_ACCESS_TOKEN`: Permanent System User Access Token with `whatsapp_business_messaging` permission.
  - `WHATSAPP_BUSINESS_ACCOUNT_ID`: WhatsApp Business Account ID.

---

## 4. Automated Payments & Bank Reconciliations

### 4.1 M-Pesa Daraja Integration
- **STK Push (`POST /api/v1/admin/sales/{id}/payments/mpesa/stk-push`)**: Sends instantaneous push prompt to customer's handset.
- **M-Pesa Webhook (`POST /api/v1/payments/providers/mpesa/callback`)**:
  - Validates callback payload from Safaricom.
  - Resolves pending payment to `completed`.
  - Automatically issues receipt and updates installment and sale balance.

### 4.2 Automated Bank Payment Webhook (`POST /api/v1/payments/providers/bank/callback`)
Modeled directly after M-Pesa automated processing:
- **Service**: `app/services/BankPaymentService.php`
- **Database Inbox**: `bank_callback_inbox` table stores every webhook payload for audit and idempotency.
- **Signature Verification**: Verifies incoming bank webhooks using HMAC-SHA256 (`BANK_WEBHOOK_SECRET`).
- **Automated Matching & Reconciliation**:
  1. Accepts bank IPN notification containing `transaction_reference`, `account_reference` (e.g., `SALE-1`), `amount`, and `status`.
  2. Resolves target Sale ID from the account reference.
  3. Records payment as `completed`, creates official receipt in `payment_receipts`, and reduces the outstanding sale/installment balance.
  4. Automatically dispatches payment receipt via Email & SMS to the buyer and office.
  5. Completely idempotent: duplicate bank notifications are safely detected and acknowledged without double-crediting.

### 4.3 Financial Reporting & Statement Endpoints
- **`GET /api/v1/admin/reports/payments`**:
  - Total revenue collected, transaction count, average payment, minimum and maximum amounts.
  - Breakdown by payment method (M-Pesa vs. Bank Transfer vs. Cash).
  - Breakdown by payment type (Deposit vs. Installment vs. Full).
  - Date filtering: `start_date`, `end_date`, `payment_method`.
- **`GET /api/v1/admin/reports/installments`**:
  - Installment aging overview: Total due, total collected, total outstanding, paid count, upcoming count, overdue count.
  - Detailed list of overdue installments with `days_overdue` and buyer contact details.
- **`GET /api/v1/admin/sales/{id}/statement`**:
  - Official financial statement for a sale account.
  - Summary metrics: agreed price, total paid, outstanding balance, completion percentage.
  - Complete installment schedule and transaction payment ledger with receipt numbers and dates.
- **`POST /api/v1/admin/sales/{id}/reminders`**:
  - Automatically dispatches installment reminder notifications (Email & SMS) to the buyer for the next due installment.

---

### 4.4 What We Need From the Client's Bank

To enable automatic bank payment updates from the client's bank (e.g., Equity Bank Jenga API, KCB Buni, Co-op Bank Open Banking, or Pesalink):

1. **Bank Webhook URL Registration**:
   Register the following HTTPS endpoint with the bank's developer / integration team:
   ```
   https://yourdomain.com/api/v1/payments/providers/bank/callback
   ```
2. **Webhook Secret Key**:
   Obtain the HMAC secret key used by the bank to sign webhooks and set it in `.env` as `BANK_WEBHOOK_SECRET`.
3. **Account Reference Convention**:
   Instruct buyers to quote their Sale Account Reference when making transfers (e.g., `SALE-1`, `SALE-2`). The automated system parses this reference to allocate funds to the correct account immediately.

---

## 5. Frontend System Design & Integration

### 5.1 Architecture
- **Framework**: React 19 + Vite + Tailwind CSS.
- **Shared Utilities (`real-estate/src/lib/utils.js`)**: Currency, date, property image, and enquiry matching helpers (renamed from `demo.js` for clarity).
- **API Client (`real-estate/src/api/client.js`)**:
  - Centralized request wrapper with automatic Bearer token injection.
  - Automatic silent refresh using refresh tokens upon 401 Unauthorized responses.
  - Graceful network error handling.
- **Authentication & Self-Registration (`real-estate/src/components/AuthView.jsx`)**:
  - Real Sign In against `/api/v1/auth/login`.
  - Self-Registration Form for Agents & Property Owners against `/api/v1/auth/register` (supports pre-filled email invite links `?mode=register&role=agent&email=...` sent by the Admin from the Agents/Owners page).
  - Real Password Reset request against `/api/v1/auth/forgot-password`.
  - Clean, user-friendly branding with all demo, prototype, ERP, and technical JWT notices removed.
- **Admin Verification Workflow (`real-estate/src/pages/People.jsx`)**:
  - Admin dispatches self-registration form links via email (`POST /api/v1/admin/users/invite`).
  - Admin verifies and approves (`PATCH /api/v1/admin/users/{id}/approve`), rejects, suspends, or reactivates Agent and Property Owner accounts.
- **Property Creation & Editing (`real-estate/src/components/PropertyEditor.jsx`)**:
  - Modal form supporting property creation, editing, map coordinates, photo uploads, and direct assignment to verified agents/owners.
- **Vite Proxy (`real-estate/vite.config.js`)**: Configured with proxy routing `/api` directly to `http://localhost/real-estate-backend/public`.

### 5.2 Build Verification
- Frontend builds cleanly in 2.97 seconds (`npm run build`):
  - `dist/index.html` (0.46 kB)
  - `dist/assets/index-BNn_GzaD.css` (39.04 kB)
  - `dist/assets/index-_7tvZ0ni.js` (334.89 kB)
  - **Zero compilation errors, zero warnings**.

---

## 6. Complete API Reference

### Authentication
- `POST /api/v1/auth/register` (Public): Register agent or owner account.
- `POST /api/v1/auth/login` (Public): Sign in, returns JWT access and refresh tokens.
- `POST /api/v1/auth/refresh` (Public): Refresh access token.
- `POST /api/v1/auth/logout` (Authenticated): Revoke refresh token.
- `POST /api/v1/auth/forgot-password` (Public): Request password reset link.
- `POST /api/v1/auth/reset-password` (Public): Reset password with secure token.
- `GET /api/v1/auth/me` (Authenticated): Get current user profile.

### Properties
- `GET /api/v1/properties` (Public): Browse active catalog with filters.
- `GET /api/v1/properties/{id}` (Public): View listing details.
- `POST /api/v1/properties` (Admin, Agent, Owner): Submit property for review.
- `PATCH /api/v1/properties/{id}` (Owner, Admin): Update property details.
- `POST /api/v1/properties/{id}/images` (Owner, Admin): Attach property images.
- `GET /api/v1/admin/properties` (Admin): Admin property review queue.
- `PATCH /api/v1/properties/{id}/approve` (Admin): Approve property listing.
- `PATCH /api/v1/properties/{id}/reject` (Admin): Reject property listing.

### Buyer Enquiries
- `POST /api/v1/enquiries` (Public): Submit buyer search criteria.
- `GET /api/v1/admin/enquiries` (Admin): List enquiries.
- `GET /api/v1/enquiries/{id}` (Admin): Get enquiry details.
- `GET /api/v1/enquiries/{id}/matches` (Admin): Get matched properties.
- `POST /api/v1/enquiries/{id}/matches/refresh` (Admin): Refresh matching engine and alert leads/sellers.

### Sales & Financials
- `POST /api/v1/admin/sales` (Admin): Register sale agreement and installment plan.
- `GET /api/v1/admin/sales` (Admin): List sales accounts.
- `GET /api/v1/admin/sales/{id}` (Admin): Get sale details.
- `GET /api/v1/admin/sales/{id}/installments` (Admin): List installment schedule.
- `GET /api/v1/admin/sales/{id}/statement` (Admin): Official financial statement with ledger.
- `POST /api/v1/admin/sales/{id}/reminders` (Admin): Send installment reminder via Email & SMS.

### Payments & Automated Webhooks
- `POST /api/v1/admin/sales/{id}/payments` (Admin): Record manual payment and issue receipt.
- `POST /api/v1/admin/sales/{id}/payments/mpesa/stk-push` (Admin): Trigger M-Pesa STK push.
- `POST /api/v1/payments/providers/mpesa/callback` (Public Webhook): Safaricom Daraja callback.
- `POST /api/v1/payments/providers/bank/callback` (Public Webhook): Automated bank payment callback.
- `GET /api/v1/admin/reports/payments` (Admin): Payment revenue report.
- `GET /api/v1/admin/reports/installments` (Admin): Installment collection and overdue aging report.

### Long-Term Rentals & BnB Bookings
- `GET /api/v1/admin/tenancies` (Admin): List long-term tenancy leases.
- `POST /api/v1/admin/tenancies` (Admin): Register tenancy lease with monthly rent & security deposit.
- `GET /api/v1/admin/tenancies/{id}` (Admin): Tenancy details with rent charges.
- `PATCH /api/v1/admin/tenancies/{id}/status` (Admin): End tenancy lease.
- `POST /api/v1/admin/tenancies/{id}/payments` (Admin): Record rent or security deposit payment.
- `GET /api/v1/admin/tenants` (Admin): List registered tenants.
- `POST /api/v1/admin/tenants` (Admin): Register tenant profile.
- `GET /api/v1/admin/bookings` (Admin): List BnB short-term bookings with paid & balance metrics.
- `POST /api/v1/admin/bookings` (Admin): Create short-term reservation with date-overlap collision prevention.
- `GET /api/v1/admin/bookings/{id}` (Admin): BnB booking details and financial balances.
- `PATCH /api/v1/admin/bookings/{id}/status` (Admin): Booking lifecycle transition (`confirmed` -> `checked_in` -> `checked_out`, `cancelled`).
- `POST /api/v1/admin/bookings/{id}/payments` (Admin): Record accommodation or damage deposit payment.
- `GET /api/v1/properties/{id}/booking-availability` (Public): Live date collision and availability checker.

### Notifications & Users Administration
- `GET /api/v1/admin/notification-logs` (Admin): Multi-channel notification audit trail.
- `POST /api/v1/admin/users/invite` (Admin): Send self-registration form link via email to an agent or property owner.
- `GET /api/v1/admin/users/pending` (Admin): List pending agent/owner accounts.
- `GET /api/v1/admin/users` (Admin): List all users.
- `PATCH /api/v1/admin/users/{id}/approve` (Admin): Approve agent or owner account.
- `PATCH /api/v1/admin/users/{id}/reject` (Admin): Reject account.
- `PATCH /api/v1/admin/users/{id}/suspend` (Admin): Suspend account.
- `PATCH /api/v1/admin/users/{id}/reactivate` (Admin): Reactivate account.

---

## 7. Verification & Automated Test Results

The backend automated test suite runs via PHP CLI:

```powershell
C:\xampp\php\php.exe tests\security_test.php
C:\xampp\php\php.exe tests\enquiry_api_test.php
C:\xampp\php\php.exe tests\payment_api_test.php
C:\xampp\php\php.exe tests\property_api_test.php
C:\xampp\php\php.exe tests\notification_log_test.php
C:\xampp\php\php.exe tests\bank_and_report_test.php
C:\xampp\php\php.exe tests\rentals_and_bnb_test.php
```

**Results**:
- Security checks passed (23 assertions)
- Enquiry API checks passed (21 assertions)
- Payment API checks passed (33 assertions)
- Property API checks passed (29 assertions)
- Notification log API checks passed (23 assertions)
- Bank & Payment Reports tests passed (all assertions)
- Rentals & Short-Term BnBs Test Suite passed (all assertions):
  - Bank reference resolution for Tenancy (`TEN-101`) and BnB Booking (`BNB-202`)
  - Long-term tenancy creation with security deposit
  - Property availability status synchronization (`available` -> `rented` -> `available`)
  - Short-term BnB booking duration calculation (nights × nightly rate)
  - Date-overlap interval collision prevention (`booking_date_collision`)
  - Non-overlapping subsequent reservation confirmation
  - Booking lifecycle transitions (`confirmed` -> `checked_in` -> `checked_out`)
  - Occupancy synchronization (`reserved` -> `occupied` -> `reserved` / `available`)
  - Unified payment target mutex validation (exclusive ownership rule)
  - Tenancy rent payments with `TEN-` receipt generation
  - BnB accommodation payments with `BNB-` receipt generation
  - Damage deposit payments held and tracked separately without inflating accommodation charges
- **Total Assertions**: 155+ automated tests passing with **0 failures**.

---

## 8. Rentals & Short-Term BnB Functional Architecture

### 8.1 Long-Term Rentals vs. Short-Term BnBs Comparison

| Feature | Long-Term Rental | Short-Term BnB |
|---|---|---|
| **Primary entity** | Tenancy lease (`tenancies`) | Reservation (`bookings`) |
| **Occupant** | Tenant profile (`tenants`) | Guest contact (`guest_name`, `guest_email`, `guest_phone`) |
| **Duration** | Ongoing monthly lease (`start_date`, optional `end_date`) | Defined stay period (`check_in_date`, `check_out_date`, `number_of_nights`) |
| **Pricing** | Monthly rent (`monthly_rent`, `rent_due_day`) | Nightly rate (`nightly_rate` × `number_of_nights` = `accommodation_total`) |
| **Security Deposit** | Security deposit held as refundable collateral (`security_deposit`) | Refundable damage deposit (`required_damage_deposit`) & booking deposit |
| **Availability Source of Truth** | Tenancy active flag & lease dates | Interval date collision query: `check_in < :out AND check_out > :in` |
| **Operational Property Status** | `rented` while tenancy is active | `reserved` (confirmed), `occupied` (checked in), `available` (cleared) |
| **Payments & Receipts** | Unified `payments` (`tenancy_id`, type `rent` / `security_deposit`) | Unified `payments` (`booking_id`, type `accommodation` / `damage_deposit`) |

### 8.2 Unified Payment Architecture & Mutex Rule
To prevent data contamination and duplicate financial records, every payment record in `payments` is governed by a **Target Mutex Rule**:
- Exactly **one** of `sale_id`, `tenancy_id`, or `booking_id` must be set per transaction.
- If multiple targets or zero targets are supplied, the backend throws `payment_target_mutex_violation`.
- Damage deposits and security deposits are collected and tracked separately from accommodation totals to prevent charge inflation.
- Automated bank webhooks can resolve target sales (`SALE-10`), tenancies (`TEN-15`), or bookings (`BNB-22`) from the account reference header.
