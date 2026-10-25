CREATE SEQUENCE application_number_seq AS BIGINT START 1 MINVALUE 1 NO CYCLE;

-- Application Number is separate from the permanent Student ID.
-- status is the student-visible workflow state; eligibility lives in its own tables.
CREATE TABLE applications (
    id                      BIGSERIAL PRIMARY KEY,
    student_profile_id      BIGINT NOT NULL REFERENCES student_profiles(id),
    application_number      TEXT NOT NULL UNIQUE
                            DEFAULT ('IHA-APP-' || to_char(now() AT TIME ZONE 'UTC', 'YYYY') || '-'
                                     || lpad(nextval('application_number_seq')::text, 6, '0')),
    status                  TEXT NOT NULL DEFAULT 'DRAFT'
                            CHECK (status IN ('DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED',
                                              'REJECTED', 'RETURNED_FOR_CORRECTION')),
    completion_percent      SMALLINT NOT NULL DEFAULT 0 CHECK (completion_percent BETWEEN 0 AND 100),
    section_status          JSONB NOT NULL DEFAULT '{}'::jsonb,
    declaration_accepted_at TIMESTAMPTZ,
    submission_key          UUID UNIQUE,          -- idempotency key: repeated clicks / retries collapse to one submission
    submitted_at            TIMESTAMPTZ,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT app_submitted_has_timestamp CHECK (status = 'DRAFT' OR submitted_at IS NOT NULL)
);

-- A student may have only one open application at a time; a later application
-- (after APPROVED/REJECTED) reuses the same permanent Student ID with a new number.
CREATE UNIQUE INDEX applications_one_open_per_student
    ON applications (student_profile_id)
    WHERE status IN ('DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'RETURNED_FOR_CORRECTION');

CREATE INDEX applications_status_idx    ON applications (status);
CREATE INDEX applications_submitted_idx ON applications (submitted_at);
CREATE TRIGGER applications_updated BEFORE UPDATE ON applications FOR EACH ROW EXECUTE FUNCTION set_updated_at();
