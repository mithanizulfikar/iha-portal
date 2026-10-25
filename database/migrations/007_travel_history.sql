-- Yes/No screening answers (one row per application). Travel never affects eligibility.
CREATE TABLE travel_responses (
    application_id      BIGINT PRIMARY KEY REFERENCES applications(id) ON DELETE CASCADE,
    outside_home_city   BOOLEAN,
    another_district    BOOLEAN,
    another_province    BOOLEAN,
    international       BOOLEAN,
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE travel_history (
    id               BIGSERIAL PRIMARY KEY,
    application_id   BIGINT NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    sort_order       SMALLINT NOT NULL DEFAULT 0,
    country_visited  TEXT,
    province_state   TEXT,
    district_visited TEXT,
    city_visited     TEXT,
    purpose          TEXT,
    start_date       DATE,
    end_date         DATE,
    mode_of_travel   TEXT,
    accompanied_by   TEXT,
    notes            TEXT,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT th_dates_ordered CHECK (start_date IS NULL OR end_date IS NULL OR end_date >= start_date)
);
CREATE INDEX th_application_idx ON travel_history (application_id);
