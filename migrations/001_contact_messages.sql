-- 001: messages from the contact / "Ask about this gun" form.
-- Apply to an existing database:  sqlite3 gun_specs.db < migrations/001_contact_messages.sql
CREATE TABLE IF NOT EXISTS contact_messages (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT NOT NULL,
    email         TEXT NOT NULL,
    phone         TEXT,
    stock_number  TEXT,                                 -- the gun asked about, if any
    message       TEXT NOT NULL,
    ip_hash       TEXT,                                 -- hashed, only used for rate limiting
    emailed       INTEGER NOT NULL DEFAULT 0 CHECK (emailed IN (0,1)),
    handled       INTEGER NOT NULL DEFAULT 0 CHECK (handled IN (0,1)),
    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_contact_messages_created ON contact_messages(created_at);
