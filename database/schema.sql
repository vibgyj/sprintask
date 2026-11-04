PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    timezone TEXT NOT NULL DEFAULT 'UTC',
    theme TEXT NOT NULL DEFAULT 'system',
    created_at_utc INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS otp_challenges (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL,
    email TEXT NOT NULL,
    purpose TEXT NOT NULL,
    code_hash TEXT NOT NULL,
    expires_at_utc INTEGER NOT NULL,
    consumed_at_utc INTEGER NULL,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL,
    resend_available_at_utc INTEGER NOT NULL,
    requested_ip TEXT NULL,
    user_agent TEXT NULL,
    created_at_utc INTEGER NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_otp_email_purpose_created ON otp_challenges(email, purpose, created_at_utc);
CREATE INDEX IF NOT EXISTS idx_otp_ip_created ON otp_challenges(requested_ip, created_at_utc);

-- Tracks a per-user "session epoch". Bumping this value invalidates every
-- previously issued session/remember-me token for the user (used by the
-- "log out of other devices" feature). Kept in its own table (rather than an
-- ALTER TABLE on users) so schema.sql stays safe to re-run on every request.
CREATE TABLE IF NOT EXISTS user_security (
    user_id INTEGER PRIMARY KEY,
    session_version INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Long-lived "remember me" login tokens (selector/validator pattern).
-- Only validator_hash (sha256 of a random validator) is stored, never the
-- raw validator, so a leaked DB does not allow session hijacking.
CREATE TABLE IF NOT EXISTS remember_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    selector TEXT NOT NULL UNIQUE,
    validator_hash TEXT NOT NULL,
    session_version INTEGER NOT NULL,
    expires_at_utc INTEGER NOT NULL,
    created_at_utc INTEGER NOT NULL,
    requested_ip TEXT NULL,
    user_agent TEXT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_remember_tokens_user ON remember_tokens(user_id);
CREATE INDEX IF NOT EXISTS idx_remember_tokens_expires ON remember_tokens(expires_at_utc);

CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    created_at_utc INTEGER NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE(user_id, id)
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_categories_user_name_nocase
ON categories(user_id, name COLLATE NOCASE);

CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    category_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    created_at_utc INTEGER NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id, category_id) REFERENCES categories(user_id, id) ON DELETE CASCADE,
    UNIQUE(user_id, id)
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_tasks_user_category_name_nocase
ON tasks(user_id, category_id, name COLLATE NOCASE);

CREATE TABLE IF NOT EXISTS time_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    task_id INTEGER NOT NULL,
    started_at_utc INTEGER NOT NULL,
    ended_at_utc INTEGER NULL,
    created_at_utc INTEGER NOT NULL,
    updated_at_utc INTEGER NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id, task_id) REFERENCES tasks(user_id, id) ON DELETE CASCADE,
    CHECK (ended_at_utc IS NULL OR ended_at_utc > started_at_utc)
);

CREATE INDEX IF NOT EXISTS idx_entries_user_time ON time_entries(user_id, started_at_utc, ended_at_utc);
CREATE INDEX IF NOT EXISTS idx_entries_user_task ON time_entries(user_id, task_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_one_active_timer_per_user
ON time_entries(user_id)
WHERE ended_at_utc IS NULL;
