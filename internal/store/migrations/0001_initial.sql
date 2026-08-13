-- Schema version 1: the tables Teil A needs. Everything belonging to the media
-- path (grants, requests, quotas, audit) arrives with its own migration in a
-- later stage, so this file stays the record of what 0.0.1 actually used.
CREATE TABLE users (
    id                   TEXT PRIMARY KEY,
    display_name         TEXT NOT NULL,
    created_at           TEXT NOT NULL,
    quota_rule           TEXT,
    active               INTEGER NOT NULL DEFAULT 1,
    syncthing_device_id  TEXT,
    syncthing_folder_id  TEXT
);

CREATE TABLE identities (
    user_id   TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    provider  TEXT NOT NULL,
    subject   TEXT NOT NULL,
    email     TEXT,
    PRIMARY KEY (provider, subject)
);

CREATE TABLE invitations (
    token_hash   TEXT PRIMARY KEY,
    created_at   TEXT NOT NULL,
    valid_until  TEXT NOT NULL,
    redeemed_by  TEXT REFERENCES users(id),
    redeemed_at  TEXT,
    revoked      INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX idx_identities_user ON identities(user_id);
