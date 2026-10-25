CREATE TABLE parent_guardians (
    id                BIGSERIAL PRIMARY KEY,
    application_id    BIGINT NOT NULL UNIQUE REFERENCES applications(id) ON DELETE CASCADE,

    father_name       TEXT,
    father_cnic       TEXT CHECK (father_cnic IS NULL OR father_cnic ~ '^[0-9]{5}-?[0-9]{7}-?[0-9]$'),
    father_contact    TEXT,
    father_email      TEXT,
    father_occupation TEXT,

    mother_name       TEXT,
    mother_contact    TEXT,

    guardian_name         TEXT,
    guardian_relationship TEXT,
    guardian_cnic         TEXT CHECK (guardian_cnic IS NULL OR guardian_cnic ~ '^[0-9]{5}-?[0-9]{7}-?[0-9]$'),
    guardian_contact      TEXT,
    guardian_email        TEXT,
    primary_guardian      TEXT CHECK (primary_guardian IN ('father', 'mother', 'guardian')),

    address_line      TEXT,
    province_id       INTEGER REFERENCES provinces(id),
    district_id       INTEGER,
    city_id           INTEGER,
    city_other        TEXT,

    emergency_name         TEXT,
    emergency_contact      TEXT,
    emergency_relationship TEXT,

    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT pg_district_fk FOREIGN KEY (district_id, province_id) REFERENCES districts (id, province_id),
    CONSTRAINT pg_city_fk     FOREIGN KEY (city_id, district_id)     REFERENCES cities (id, district_id),
    CONSTRAINT pg_geo_chain   CHECK ((district_id IS NULL OR province_id IS NOT NULL)
                                 AND (city_id IS NULL OR district_id IS NOT NULL)
                                 AND (city_other IS NULL OR district_id IS NOT NULL)),
    CONSTRAINT pg_city_xor    CHECK (city_id IS NULL OR city_other IS NULL),
    CONSTRAINT pg_city_other_len CHECK (city_other IS NULL OR length(btrim(city_other)) BETWEEN 2 AND 100)
);
CREATE TRIGGER pg_updated BEFORE UPDATE ON parent_guardians FOR EACH ROW EXECUTE FUNCTION set_updated_at();
