-- 009 (Michael, 2026-10-14): several admins, and a log of who changed what.
-- admins: one row per person (short username, which can be an employee number, plus first and
-- last name). role: owner = everything incl. admins, log, CA roster, deletes; staff = listings,
-- photos, firearms. Passwords are stored only as hashes. The old shared password in config is
-- kept as the recovery password (username "recovery").
CREATE TABLE IF NOT EXISTS admins (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    username             TEXT NOT NULL UNIQUE COLLATE NOCASE,
    first_name           TEXT NOT NULL,
    last_name            TEXT NOT NULL,
    email                TEXT,                               -- for "Forgot password" links
    mobile               TEXT,
    password_hash        TEXT NOT NULL,
    role                 TEXT NOT NULL DEFAULT 'staff' CHECK (role IN ('owner','staff')),
    active               INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
    must_change_password INTEGER NOT NULL DEFAULT 0 CHECK (must_change_password IN (0,1)),
    last_login_at        TEXT,
    created_at           TEXT NOT NULL DEFAULT (datetime('now')),
    created_by           TEXT
);

-- "Forgot password": only a hash of the emailed token is stored; a token works once, for 1 hour.
CREATE TABLE IF NOT EXISTS password_resets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id   INTEGER NOT NULL REFERENCES admins(id),
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    used_at    TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- There must always be an active owner.
CREATE TRIGGER IF NOT EXISTS trg_admins_last_owner_upd
BEFORE UPDATE OF role, active ON admins
WHEN OLD.role = 'owner' AND OLD.active = 1 AND (NEW.role <> 'owner' OR NEW.active = 0)
 AND (SELECT COUNT(*) FROM admins WHERE role = 'owner' AND active = 1 AND id <> OLD.id) = 0
BEGIN
  SELECT RAISE(ABORT, 'There must always be at least one active owner.');
END;

CREATE TRIGGER IF NOT EXISTS trg_admins_last_owner_del
BEFORE DELETE ON admins
WHEN OLD.role = 'owner' AND OLD.active = 1
 AND (SELECT COUNT(*) FROM admins WHERE role = 'owner' AND active = 1 AND id <> OLD.id) = 0
BEGIN
  SELECT RAISE(ABORT, 'There must always be at least one active owner.');
END;

-- Who did what. Name and username are copied in, so the log still reads right if an account changes.
CREATE TABLE IF NOT EXISTS audit_log (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    at           TEXT NOT NULL DEFAULT (datetime('now')),   -- UTC
    admin_id     INTEGER,                                    -- NULL = recovery login / system
    username     TEXT NOT NULL,
    admin_name   TEXT NOT NULL,
    action       TEXT NOT NULL,                              -- created, edited, deleted, status, photos, login, ...
    entity_type  TEXT NOT NULL,                              -- listing, firearm, admin, message, roster, session, ...
    entity_id    INTEGER,
    entity_label TEXT,                                       -- e.g. "U-0004 Glock G19 Gen3"
    summary      TEXT NOT NULL,
    details      TEXT                                        -- changed fields, old -> new
);
CREATE INDEX IF NOT EXISTS idx_audit_at     ON audit_log(at);
CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_log(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_audit_admin  ON audit_log(admin_id);
