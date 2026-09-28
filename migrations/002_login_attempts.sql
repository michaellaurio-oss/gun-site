-- 002: failed admin logins, for lockout after repeated wrong passwords.
CREATE TABLE IF NOT EXISTS login_attempts (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_hash       TEXT NOT NULL,
    attempted_at  TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_login_attempts ON login_attempts(ip_hash, attempted_at);
