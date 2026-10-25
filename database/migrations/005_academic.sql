CREATE TABLE academic_records (
    id                   BIGSERIAL PRIMARY KEY,
    application_id       BIGINT NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    -- Exactly one record per application is flagged as the result that drives eligibility;
    -- other history rows are never mixed into the eligibility percentage.
    is_eligibility_basis BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order           SMALLINT NOT NULL DEFAULT 0,

    institution_name     TEXT,
    institution_reg_no   TEXT,
    institution_type     TEXT,
    address_line         TEXT,
    province_id          INTEGER REFERENCES provinces(id),
    district_id          INTEGER,
    city_id              INTEGER,
    city_other           TEXT,

    current_class        TEXT,
    academic_session     TEXT,
    exam_board           TEXT,
    exam_name            TEXT,
    exam_year            SMALLINT CHECK (exam_year IS NULL OR exam_year BETWEEN 1990 AND 2100),
    roll_number          TEXT,
    result_status        TEXT,
    previous_institution TEXT,

    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT ar_district_fk FOREIGN KEY (district_id, province_id) REFERENCES districts (id, province_id),
    CONSTRAINT ar_city_fk     FOREIGN KEY (city_id, district_id)     REFERENCES cities (id, district_id),
    CONSTRAINT ar_geo_chain   CHECK ((district_id IS NULL OR province_id IS NOT NULL)
                                 AND (city_id IS NULL OR district_id IS NOT NULL)
                                 AND (city_other IS NULL OR district_id IS NOT NULL)),
    CONSTRAINT ar_city_xor    CHECK (city_id IS NULL OR city_other IS NULL),
    CONSTRAINT ar_city_other_len CHECK (city_other IS NULL OR length(btrim(city_other)) BETWEEN 2 AND 100)
);
CREATE UNIQUE INDEX ar_one_basis_per_application ON academic_records (application_id) WHERE is_eligibility_basis;
CREATE INDEX ar_application_idx ON academic_records (application_id);
CREATE INDEX ar_institution_idx ON academic_records (lower(institution_name));
CREATE TRIGGER ar_updated BEFORE UPDATE ON academic_records FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- Drafts may hold incomplete rows (NULL marks); is_valid marks the rows that count toward
-- calculations. The PHP service recomputes everything server-side and ignores invalid rows.
CREATE TABLE academic_subjects (
    id                 BIGSERIAL PRIMARY KEY,
    academic_record_id BIGINT NOT NULL REFERENCES academic_records(id) ON DELETE CASCADE,
    sort_order         SMALLINT NOT NULL DEFAULT 0,
    subject_name       TEXT NOT NULL DEFAULT '',
    total_marks        NUMERIC(8,2) CHECK (total_marks IS NULL OR total_marks > 0),
    marks_obtained     NUMERIC(8,2) CHECK (marks_obtained IS NULL OR marks_obtained >= 0),
    is_valid           BOOLEAN GENERATED ALWAYS AS (
                           COALESCE(length(btrim(subject_name)) > 0
                                    AND total_marks > 0
                                    AND marks_obtained >= 0
                                    AND marks_obtained <= total_marks, FALSE)
                       ) STORED,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT as_obtained_le_total CHECK (marks_obtained IS NULL OR total_marks IS NULL OR marks_obtained <= total_marks)
);
CREATE INDEX as_record_idx ON academic_subjects (academic_record_id);
