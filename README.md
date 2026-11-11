# Real Estate Management Backend & Application

Real estate management system with verified agent and property owner inventory, email self-registration & admin verification workflow, automated enquiry matching, multi-channel notifications (Email, SMS, WhatsApp), financial statements, annual profit-and-loss reporting with manual expense tracking, company branding for receipts and statements, and a modern React 19 frontend.

## Quick Start

### 1. Environment Configuration
Copy `.env.example` to `.env` and configure your database and security settings:
```powershell
Copy-Item .env.example .env
```
Ensure `JWT_SECRET` has at least 32 characters:
```powershell
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

### 2. Database Migrations
Run MySQL in XAMPP or Docker and run the migrations:
```powershell
C:\xampp\php\php.exe -r "$sql = file_get_contents('database/bank_payment_migration.sql'); require 'config/database.php'; $pdo->exec($sql);"
C:\xampp\php\php.exe -r "$sql = file_get_contents('database/finance_and_company_profile_migration.sql'); require 'config/database.php'; $pdo->exec($sql);"
```
Apply `database\payment_invoices_and_reminders_migration.sql` once after the existing payment and rental schema is installed. It adds invoice records, reminder dispatch deduplication, configurable tenancy grace periods, and rent-charge receipt links. Run the SQL file using your MySQL client/phpMyAdmin.

### 3. Run Automated Tests
```powershell
C:\xampp\php\php.exe tests\security_test.php
C:\xampp\php\php.exe tests\finance_accounting_test.php
C:\xampp\php\php.exe tests\payment_report_pagination_test.php
C:\xampp\php\php.exe tests\enquiry_api_test.php
C:\xampp\php\php.exe tests\payment_api_test.php
C:\xampp\php\php.exe tests\payment_reminder_schedule_test.php
C:\xampp\php\php.exe tests\payment_reminder_model_test.php
C:\xampp\php\php.exe tests\property_api_test.php
C:\xampp\php\php.exe tests\notification_log_test.php
C:\xampp\php\php.exe tests\bank_and_report_test.php
C:\xampp\php\php.exe tests\rentals_and_bnb_test.php
```

### 4. Frontend
```powershell
cd real-estate
npm install
npm run dev      # Local development on http://localhost:5173
npm run build    # Production build to real-estate/dist
```

## Documentation
- [Payment Invoices and Reminders](PAYMENT_REMINDERS.md)
- [Client Production Requirements & Handover Checklist](CLIENT_PRODUCTION_REQUIREMENTS.md)
- [Production Documentation & Integration Guide](PRODUCTION_DOCUMENTATION.md)
- [Security Hardening & Configuration](SECURITY.md)
- [Archived Extras & Overengineered Modules Backup](extras/README.md)
