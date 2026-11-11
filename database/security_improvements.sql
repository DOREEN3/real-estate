-- One-time, additive migration for existing databases.
-- This file adds indexes only; it does not delete or update application records.
-- Before running, confirm these queries return no rows:
--
-- SELECT identifier, endpoint, window_start, COUNT(*) AS duplicates
-- FROM rate_limit_logs
-- GROUP BY identifier, endpoint, window_start
-- HAVING COUNT(*) > 1;
--
-- SELECT token_hash, COUNT(*) AS duplicates
-- FROM refresh_tokens
-- GROUP BY token_hash
-- HAVING COUNT(*) > 1;

ALTER TABLE users
ADD INDEX idx_password_reset_token (password_reset_token);

ALTER TABLE rate_limit_logs
ADD UNIQUE KEY uq_rate_limit_bucket (identifier, endpoint, window_start);

ALTER TABLE refresh_tokens
MODIFY COLUMN expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE refresh_tokens
ADD UNIQUE KEY uq_refresh_token_hash (token_hash);
