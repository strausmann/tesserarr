-- Schema version 3: API tokens. Deliberately its own table, not a row in
-- sessions with a "kind" column: requireToken must resolve a bearer value
-- against a store that a session token was never written into, and vice
-- versa — see the package comment on internal/apitoken and ADR-0007's
-- "no shared branching logic" decision point. token_hash is the primary key
-- for the same reason it is on sessions: a token is looked up by its hash,
-- never by user, so the hash IS the identity of the row. The raw token
-- itself never touches the database.
CREATE TABLE api_tokens (
    token_hash    TEXT PRIMARY KEY,
    user_id       TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    label         TEXT NOT NULL,
    created_at    TEXT NOT NULL,
    last_used_at  TEXT
);

CREATE INDEX idx_api_tokens_user ON api_tokens(user_id);
