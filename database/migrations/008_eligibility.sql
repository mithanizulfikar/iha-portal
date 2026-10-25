-- ADMIN-ONLY DATA. Student-facing code must never read these tables
-- (see database/hardening/roles.sql for database-level enforcement).

CREATE TABLE eligibility_rules (
    id             SERIAL PRIMARY KEY,
    version        INTEGER NOT NULL UNIQUE CHECK (version > 0),
    dob_min        DATE NOT NULL,
    dob_max        DATE NOT NULL,
    min_percentage NUMERIC(5,2) NOT NULL CHECK (min_percentage BETWEEN 0 AND 100),
    is_active      BOOLEAN NOT NULL DEFAULT FALSE,
    created_by     BIGINT REFERENCES users(id),
    notes          TEXT,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT er_dob_ordered CHECK (dob_min <= dob_max)
);
CREATE UNIQUE INDEX eligibility_rules_one_active ON eligibility_rules (is_active) WHERE is_active;

-- A rule version is immutable once created; changing a rule means inserting a new version
-- and activating it. Only is_active may be toggled.
CREATE FUNCTION eligibility_rules_freeze() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'eligibility rule versions cannot be deleted';
    END IF;
    IF NEW.version <> OLD.version OR NEW.dob_min <> OLD.dob_min OR NEW.dob_max <> OLD.dob_max
       OR NEW.min_percentage <> OLD.min_percentage THEN
        RAISE EXCEPTION 'eligibility rule version % is immutable; create a new version', OLD.version;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER er_freeze BEFORE UPDATE OR DELETE ON eligibility_rules
    FOR EACH ROW EXECUTE FUNCTION eligibility_rules_freeze();

-- Immutable history of every calculated evaluation.
CREATE TABLE eligibility_results (
    id                    BIGSERIAL PRIMARY KEY,
    application_id        BIGINT NOT NULL REFERENCES applications(id),
    rule_id               INTEGER NOT NULL REFERENCES eligibility_rules(id),
    calculated_status     TEXT NOT NULL CHECK (calculated_status IN ('ELIGIBLE', 'INELIGIBLE', 'PENDING_REVIEW')),
    dob_criterion         TEXT NOT NULL CHECK (dob_criterion IN ('PASS', 'FAIL', 'PENDING')),
    academic_criterion    TEXT NOT NULL CHECK (academic_criterion IN ('PASS', 'FAIL', 'PENDING')),
    calculated_percentage NUMERIC(5,2) CHECK (calculated_percentage IS NULL OR calculated_percentage BETWEEN 0 AND 100),
    reason                TEXT,
    trigger_event         TEXT NOT NULL,          -- e.g. SUBMISSION, CORRECTION, RULE_CHANGE, MANUAL_RECALC
    evaluated_by          BIGINT REFERENCES users(id),
    evaluated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- The database refuses a status that contradicts the criteria it summarises:
    -- ELIGIBLE iff both PASS; INELIGIBLE iff at least one FAIL; otherwise PENDING_REVIEW.
    CONSTRAINT er_status_consistent CHECK (
        (calculated_status = 'ELIGIBLE')       = (dob_criterion = 'PASS' AND academic_criterion = 'PASS')
        AND
        (calculated_status = 'INELIGIBLE')     = (dob_criterion = 'FAIL' OR academic_criterion = 'FAIL')
    )
);
CREATE INDEX eres_application_idx ON eligibility_results (application_id, evaluated_at DESC);
CREATE TRIGGER eres_append_only BEFORE UPDATE OR DELETE ON eligibility_results
    FOR EACH ROW EXECUTE FUNCTION prevent_modification();

-- Manual overrides are separate rows; they never rewrite the calculated result.
CREATE TABLE eligibility_overrides (
    id                BIGSERIAL PRIMARY KEY,
    application_id    BIGINT NOT NULL REFERENCES applications(id),
    based_on_result_id BIGINT NOT NULL REFERENCES eligibility_results(id),
    override_status   TEXT NOT NULL CHECK (override_status IN ('ELIGIBLE', 'INELIGIBLE', 'PENDING_REVIEW')),
    reason            TEXT NOT NULL CHECK (length(btrim(reason)) >= 10),
    created_by        BIGINT NOT NULL REFERENCES users(id),
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX eovr_application_idx ON eligibility_overrides (application_id, created_at DESC);
CREATE TRIGGER eovr_append_only BEFORE UPDATE OR DELETE ON eligibility_overrides
    FOR EACH ROW EXECUTE FUNCTION prevent_modification();

-- Current effective state, kept apart from history. effective_status = override if present,
-- otherwise the latest calculated status. Maintained only by the eligibility service.
CREATE TABLE application_eligibility (
    application_id   BIGINT PRIMARY KEY REFERENCES applications(id) ON DELETE CASCADE,
    latest_result_id BIGINT NOT NULL REFERENCES eligibility_results(id),
    override_id      BIGINT REFERENCES eligibility_overrides(id),
    effective_status TEXT NOT NULL CHECK (effective_status IN ('ELIGIBLE', 'INELIGIBLE', 'PENDING_REVIEW')),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX appelig_status_idx ON application_eligibility (effective_status);
CREATE TRIGGER appelig_updated BEFORE UPDATE ON application_eligibility FOR EACH ROW EXECUTE FUNCTION set_updated_at();
