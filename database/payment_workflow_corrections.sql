-- Payment workflow integrity migration.
-- Run the duplicate checks first. Resolve any returned rows before applying the
-- normalization updates and unique indexes below.

SELECT
    LOWER(REPLACE(REPLACE(TRIM(provider), '-', ''), ' ', '')) AS normalized_provider,
    provider_reference,
    COUNT(*) AS duplicate_count
FROM payments
WHERE payment_source = 'provider'
  AND provider IS NOT NULL
  AND provider_reference IS NOT NULL
GROUP BY normalized_provider, provider_reference
HAVING COUNT(*) > 1;

SELECT transaction_reference, COUNT(*) AS duplicate_count
FROM rent_payments
WHERE transaction_reference IS NOT NULL
GROUP BY transaction_reference
HAVING COUNT(*) > 1;

UPDATE payments
SET provider = LOWER(REPLACE(REPLACE(TRIM(provider), '-', ''), ' ', ''))
WHERE payment_source = 'provider'
  AND provider IS NOT NULL;

ALTER TABLE payments
    ADD UNIQUE KEY unique_payment_provider_reference (provider, provider_reference);

ALTER TABLE rent_payments
    ADD UNIQUE KEY unique_rent_transaction_reference (transaction_reference);

ALTER TABLE payment_receipts
    MODIFY issued_by INT(11) NULL,
    ADD issuer_type VARCHAR(10) NOT NULL DEFAULT 'user';

ALTER TABLE rent_payment_receipts
    MODIFY issued_by INT(11) NULL,
    ADD issuer_type VARCHAR(10) NOT NULL DEFAULT 'user';
