-- Only the SHA-256 hash of the QR token is stored. The raw token appears only inside the QR URL.
CREATE TABLE student_qr_codes (
    id                 BIGSERIAL PRIMARY KEY,
    student_profile_id BIGINT NOT NULL REFERENCES student_profiles(id),
    application_id     BIGINT NOT NULL REFERENCES applications(id),
    token_hash         CHAR(64) NOT NULL UNIQUE CHECK (token_hash ~ '^[0-9a-f]{64}$'),
    status             TEXT NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'REVOKED')),
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    revoked_at         TIMESTAMPTZ,
    revoked_by         BIGINT REFERENCES users(id),     -- NULL when revoked automatically by the system
    revoke_reason      TEXT,
    replaced_by_id     BIGINT REFERENCES student_qr_codes(id),
    CONSTRAINT qr_revoked_consistent CHECK ((status = 'REVOKED') = (revoked_at IS NOT NULL))
);
-- At most one ACTIVE code per application: duplicates are impossible, not merely unlikely.
CREATE UNIQUE INDEX qr_one_active_per_application ON student_qr_codes (application_id) WHERE status = 'ACTIVE';
CREATE INDEX qr_student_idx ON student_qr_codes (student_profile_id);

-- Scan log for the public verification endpoint. Raw tokens are never stored here.
CREATE TABLE qr_verification_events (
    id         BIGSERIAL PRIMARY KEY,
    qr_code_id BIGINT REFERENCES student_qr_codes(id),   -- NULL for unknown tokens
    result     TEXT NOT NULL CHECK (result IN ('VALID', 'INVALID')),
    ip_hash    CHAR(64),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX qve_qr_idx ON qr_verification_events (qr_code_id, created_at);
