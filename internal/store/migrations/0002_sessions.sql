-- Schema version 2: sessions. token_hash is the primary key on purpose — a
-- session is looked up by its hash, never by user, so the hash IS the
-- identity of the row. The raw token itself never touches the database.
CREATE TABLE sessions (
    token_hash TEXT PRIMARY KEY,
    user_id    TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL
);

CREATE INDEX idx_sessions_user ON sessions(user_id);
CREATE INDEX idx_sessions_expiry ON sessions(expires_at);
