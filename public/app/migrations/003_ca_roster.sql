-- 003: local copy of the CA DOJ Handgun Roster, and a link from each firearm to its roster entry.
CREATE TABLE IF NOT EXISTS ca_roster (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    detail_path      TEXT NOT NULL,          -- roster page of the model, e.g. /firearms/handgun/grp (stable id)
    manufacturer     TEXT NOT NULL,          -- as listed by DOJ, e.g. 'Sturm, Ruger & Co.'
    model            TEXT NOT NULL,          -- as listed, without the court-order asterisk, e.g. 'GRP / Steel'
    model_base       TEXT NOT NULL,          -- part before ' / ' (the material)
    material         TEXT,
    gun_type         TEXT,                   -- Pistol / Revolver
    barrel_length_in REAL,
    caliber          TEXT,
    expires_on       TEXT,                   -- YYYY-MM-DD
    court_order      INTEGER NOT NULL DEFAULT 0 CHECK (court_order IN (0,1)),  -- * = added by court order (Boland v. Bonta)
    imported_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_ca_roster_path ON ca_roster(detail_path);
CREATE INDEX IF NOT EXISTS idx_ca_roster_make ON ca_roster(manufacturer);

ALTER TABLE firearms ADD COLUMN ca_roster_entry TEXT;  -- ca_roster.detail_path this model was matched to
