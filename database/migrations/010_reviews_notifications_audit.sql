CREATE TABLE application_reviews (
    id             BIGSERIAL PRIMARY KEY,
    application_id BIGINT NOT NULL REFERENCES applications(id),
    reviewer_id    BIGINT NOT NULL REFERENCES users(id),
    decision       TEXT NOT NULL CHECK (decision IN ('UNDER_REVIEW', 'APPROVED', 'REJECTED', 'RETURNED_FOR_CORRECTION')),
    reason         TEXT,
    decided_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- A decision other than "taking it for review" needs a documented reason.
    CONSTRAINT rev_reason_required CHECK (decision = 'UNDER_REVIEW' OR length(btrim(COALESCE(reason, ''))) >= 5)
);
CREATE INDEX rev_application_idx ON application_reviews (application_id, decided_at DESC);
CREATE TRIGGER rev_append_only BEFORE UPDATE OR DELETE ON application_reviews
    FOR EACH ROW EXECUTE FUNCTION prevent_modification();

-- Internal, admin-only notes. Never joined into student-facing queries.
CREATE TABLE review_notes (
    id             BIGSERIAL PRIMARY KEY,
    application_id BIGINT NOT NULL REFERENCES applications(id),
    author_id      BIGINT NOT NULL REFERENCES users(id),
    note           TEXT NOT NULL CHECK (length(btrim(note)) > 0),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX rn_application_idx ON review_notes (application_id, created_at DESC);

-- Student-facing notifications. Content must be neutral: no eligibility classification or reasons.
CREATE TABLE notifications (
    id            BIGSERIAL PRIMARY KEY,
    user_id       BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type          TEXT NOT NULL,
    title         TEXT NOT NULL,
    body          TEXT NOT NULL,
    email_status  TEXT NOT NULL DEFAULT 'NOT_SENT' CHECK (email_status IN ('NOT_SENT', 'QUEUED', 'SENT', 'FAILED')),
    read_at       TIMESTAMPTZ,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX notif_user_idx ON notifications (user_id, created_at DESC);

CREATE TABLE audit_logs (
    id            BIGSERIAL PRIMARY KEY,
    actor_user_id BIGINT REFERENCES users(id),   -- NULL for system actions
    actor_role    TEXT,
    action        TEXT NOT NULL,                  -- e.g. ELIGIBILITY_RECALCULATED, QR_REVOKED, RULE_CHANGED
    entity_type   TEXT NOT NULL,
    entity_id     TEXT,
    old_values    JSONB,
    new_values    JSONB,
    ip_address    INET,
    user_agent    TEXT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX audit_entity_idx ON audit_logs (entity_type, entity_id, created_at DESC);
CREATE INDEX audit_actor_idx  ON audit_logs (actor_user_id, created_at DESC);
CREATE INDEX audit_action_idx ON audit_logs (action, created_at DESC);
CREATE TRIGGER audit_append_only BEFORE UPDATE OR DELETE ON audit_logs
    FOR EACH ROW EXECUTE FUNCTION prevent_modification();
