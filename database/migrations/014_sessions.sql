-- Optional database-backed PHP sessions (SESSION_DRIVER=database), needed on hosts where the local disk is not
-- shared or not kept between requests (for example Vercel). The table holds a SHA-256 of the session id, never the id itself.
CREATE TABLE sessions (
    id            CHAR(64) PRIMARY KEY CHECK (id ~ '^[0-9a-f]{64}$'),
    data          TEXT NOT NULL,                      -- base64 of PHP's serialized session data
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_activity TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX sessions_last_activity_idx ON sessions (last_activity);
