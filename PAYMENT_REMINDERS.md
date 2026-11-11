# Payment Invoices and Reminders

## Reminder schedule

The CLI scheduler sends a reminder 7 days before a due date, 3 days before, on the due date, after the tenancy's configured grace period, and again 7 days after the overdue notice. Sale installments have no tenancy grace period, so their overdue notice is sent 1 day after the due date.

Rent reminders are limited to active tenancies. Rent and installment reminders use the remaining balance calculated from completed payments only. Pending or failed payments do not reduce the balance. A fully paid charge is excluded. Each target/event is recorded once, with channel-level retry state, so rerunning the scheduler does not duplicate successful messages.

The first reminder creates an invoice if one does not already exist. Its invoice number is included in the reminder email/SMS and linked to subsequent receipts and sale statements. Tenant grace periods are configurable when creating a tenancy; existing tenancies default to zero days.

## Database update

Apply `database\payment_invoices_and_reminders_migration.sql` to the same MySQL database used by the application after the existing payments and rentals tables are installed. Back up the database first and verify the SQL completes before enabling the scheduled task.

## Run manually

From PowerShell:

```powershell
& C:\xampp\php\php.exe C:\xampp\htdocs\real-estate-backend\scripts\send-payment-reminders.php
```

The script is CLI-only, uses the configured application database and mail/SMS providers, and exits nonzero if delivery or scheduling fails. In development, the existing email/SMS services log simulated messages instead of sending them.

## Windows Task Scheduler

Create a daily task (for example, 8:00 AM local time) with:

- **Program/script:** `C:\xampp\php\php.exe`
- **Add arguments:** `C:\xampp\htdocs\real-estate-backend\scripts\send-payment-reminders.php`
- **Start in:** `C:\xampp\htdocs\real-estate-backend`
- Run as the Windows account that has access to the application configuration and database.

Confirm the first run's JSON summary and PHP error log. Configure production credentials in the task's environment or the application's standard configuration before enabling live delivery.

## Verification

```powershell
C:\xampp\php\php.exe tests\payment_reminder_schedule_test.php
C:\xampp\php\php.exe tests\payment_reminder_model_test.php
C:\xampp\php\php.exe tests\payment_api_test.php
C:\xampp\php\php.exe tests\rentals_and_bnb_test.php
```
