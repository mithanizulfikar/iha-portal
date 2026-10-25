-- Centralised Pakistan geography. Names are stored as imported from the verified dataset
-- (see geo_dataset_sources); nothing a student types is ever inserted here.

CREATE TABLE provinces (
    id          SERIAL PRIMARY KEY,
    code        TEXT NOT NULL UNIQUE,
    name        TEXT NOT NULL UNIQUE,
    region_type TEXT NOT NULL CHECK (region_type IN ('province', 'territory')),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE districts (
    id          SERIAL PRIMARY KEY,
    province_id INTEGER NOT NULL REFERENCES provinces(id),
    name        TEXT NOT NULL CHECK (length(btrim(name)) > 0),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- target for composite FKs that force district to belong to the chosen province
    CONSTRAINT districts_id_province_uq UNIQUE (id, province_id)
);
CREATE UNIQUE INDEX districts_province_name_uq ON districts (province_id, lower(name));

CREATE TABLE cities (
    id          SERIAL PRIMARY KEY,
    district_id INTEGER NOT NULL REFERENCES districts(id),
    name        TEXT NOT NULL CHECK (length(btrim(name)) > 0),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- target for composite FKs that force city to belong to the chosen district
    CONSTRAINT cities_id_district_uq UNIQUE (id, district_id)
);
CREATE UNIQUE INDEX cities_district_name_uq ON cities (district_id, lower(name));

-- Provenance of every import, so coverage is never overstated.
CREATE TABLE geo_dataset_sources (
    id            SERIAL PRIMARY KEY,
    name          TEXT NOT NULL,
    source_url    TEXT,
    coverage      TEXT NOT NULL,
    admin_level   TEXT NOT NULL,
    dataset_date  DATE,
    rows_imported INTEGER NOT NULL DEFAULT 0,
    notes         TEXT,
    imported_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
