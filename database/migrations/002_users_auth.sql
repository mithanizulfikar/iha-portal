CREATE FUNCTION set_updated_at() RETURNS trigger AS $$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Used on audit/history tables: rows may be inserted but never changed or removed.
CREATE FUNCTION prevent_modification() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION '% on % is not permitted: table is append-only', TG_OP, TG_TABLE_NAME;
END;
$$ LANGUAGE plpgsql;

CREATE TABLE users (
    id                 BIGSERIAL PRIMARY KEY,
    email              TEXT NOT NULL CHECK (email = lower(btrim(email)) AND position('@' IN email) > 1),
    password_hash      TEXT NOT NULL,
    full_name          TEXT NOT NULL CHECK (length(btrim(full_name)) > 0),
    role               TEXT NOT NULL DEFAULT 'student'
                       CHECK (role IN ('student', 'super_admin', 'application_reviewer', 'report_viewer')),
    status             TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended')),
    email_verified_at  TIMESTAMPTZ,
    terms_accepted_at  TIMESTAMPTZ,
    last_login_at      TIMESTAMPTZ,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX users_email_uq ON users (email);
CREATE INDEX users_role_idx ON users (role);
CREATE TRIGGER users_updated BEFORE UPDATE ON users FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- Only a SHA-256 hash of each token is stored; the raw token exists only in the emailed link.
CREATE TABLE email_verification_tokens (
    id          BIGSERIAL PRIMARY KEY,
    user_id     BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  CHAR(64) NOT NULL UNIQUE CHECK (token_hash ~ '^[0-9a-f]{64}$'),
    expires_at  TIMESTAMPTZ NOT NULL,
    used_at     TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX evt_user_idx ON email_verification_tokens (user_id);

-- Sliding-window rate limiting for login, registration, resend and verification.
CREATE TABLE auth_rate_limits (
    id           BIGSERIAL PRIMARY KEY,
    bucket       TEXT NOT NULL,
    key_hash     CHAR(64) NOT NULL,
    attempted_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX auth_rate_limits_lookup_idx ON auth_rate_limits (bucket, key_hash, attempted_at);

-- Admin permissions are data, so the backend checks permission codes rather than role names.
CREATE TABLE permissions (
    code        TEXT PRIMARY KEY,
    description TEXT NOT NULL
);
CREATE TABLE role_permissions (
    role            TEXT NOT NULL CHECK (role IN ('super_admin', 'application_reviewer', 'report_viewer')),
    permission_code TEXT NOT NULL REFERENCES permissions(code) ON DELETE CASCADE,
    PRIMARY KEY (role, permission_code)
);
