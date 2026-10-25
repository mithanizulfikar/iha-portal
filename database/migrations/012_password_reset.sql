-- Forgot-password links: only a SHA-256 hash of each token is stored (same approach as email verification).
CREATE TABLE password_reset_tokens (
    id         BIGSERIAL PRIMARY KEY,
    user_id    BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash CHAR(64) NOT NULL UNIQUE CHECK (token_hash ~ '^[0-9a-f]{64}$'),
    expires_at TIMESTAMPTZ NOT NULL,
    used_at    TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX prt_user_idx ON password_reset_tokens (user_id);

-- Sessions that started before this moment are signed out (set whenever a password changes).
ALTER TABLE users ADD COLUMN password_changed_at TIMESTAMPTZ;
