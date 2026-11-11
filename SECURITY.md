# Security configuration

## Required environment settings

Set these in the web server or deployment secret manager; do not commit secrets.

- `APP_ENV`: `development` or `production`.
- `JWT_SECRET`: at least 32 characters. Generate one with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`.
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`: required in production. Development defaults target a local MySQL database.
- `CORS_ALLOWED_ORIGINS`: required in production; comma-separated exact HTTPS origins, with no paths or wildcard. Example: `https://app.example.com`.
- `PASSWORD_RESET_URL`: frontend password-reset page URL. The API appends the single-use token as a `token` query parameter.
- `MAIL_FROM_ADDRESS` and optional `MAIL_FROM_NAME`: sender identity for reset emails.
- `TRUSTED_PROXIES`: optional comma-separated proxy IP addresses. Forwarded client IP headers are ignored unless `REMOTE_ADDR` is a trusted proxy.

Password reset delivery uses PHP's configured `mail()` transport. Configure the production host's mail relay (or PHP mail transport) before enabling password reset requests. Delivery failures are logged while the API keeps the response identical for known and unknown accounts. The reset token expires after one hour, is stored only as a SHA-256 hash, is single-use, and successful resets revoke all refresh tokens for the account.

## Administrator provisioning

Public registration cannot create administrators. For a fresh deployment, generate a random setup key and provide it to the client through a secure channel:

```sh
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Set the generated value as `ADMIN_SETUP_KEY` in the hosting environment or secret manager before exposing the application. When the database has no approved administrator, the first-run setup page asks for this key along with the administrator's name, email, and password. The API compares the key in constant time and serializes setup attempts with a database lock and transaction, so only one first administrator can be created. The key must be at least 32 characters and is never stored in the database. Remove it from the hosting environment immediately after setup. If setup must be repeated after clearing the database, generate and deploy a new key.

## Existing installations

For an existing database, check the duplicate queries at the top of `database/security_improvements.sql` and run the migration once if they return no rows. The migration adds indexes and removes an unintended automatic timestamp update from refresh-token expiry; it does not delete records, and the column change preserves existing expiry values. Do not run it against a fresh database created from the updated `database/migrations.sql`.

Rate-limit state is stored in MySQL, shared across application instances using the same database, and guarded by database locks to avoid concurrent requests exceeding a limit.

## Verification

Run the standalone security checks with:

```powershell
php tests\security_test.php
```
