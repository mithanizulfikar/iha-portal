-- The QR token is never stored. It is derived as HMAC-SHA256(APP_KEY, 'iha-qr-v1|' || token_salt), so the
-- portal can redraw an issued QR code while the database holds only the salt and the token's SHA-256 hash.
-- Someone who steals only the database cannot reconstruct a token without APP_KEY.
ALTER TABLE student_qr_codes
    ADD COLUMN token_salt CHAR(32) CONSTRAINT qr_salt_hex CHECK (token_salt IS NULL OR token_salt ~ '^[0-9a-f]{32}$');
