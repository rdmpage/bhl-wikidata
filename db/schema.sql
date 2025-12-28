-- SQLite schema for BHL-Wikidata queue and cache

-- Job queue table
CREATE TABLE IF NOT EXISTS jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pid TEXT NOT NULL,                  -- persistent identifier (DOI, PMID, etc.)
    pid_type TEXT NOT NULL,             -- 'doi', 'pmid', 'jstor', 'bhl_part', etc.
    batch_id TEXT NOT NULL,             -- groups jobs together
    status TEXT DEFAULT 'pending',      -- pending, processing, completed, failed
    result TEXT,                        -- stores the quickstatements output
    error TEXT,                         -- error message if failed
    created_at INTEGER NOT NULL,        -- unix timestamp
    updated_at INTEGER NOT NULL,        -- unix timestamp
    completed_at INTEGER                -- unix timestamp
);

CREATE INDEX IF NOT EXISTS idx_status ON jobs(status);
CREATE INDEX IF NOT EXISTS idx_batch ON jobs(batch_id);
CREATE INDEX IF NOT EXISTS idx_pid ON jobs(pid, pid_type);

-- Job batch tracking table
CREATE TABLE IF NOT EXISTS job_batches (
    batch_id TEXT PRIMARY KEY,
    total_jobs INTEGER NOT NULL DEFAULT 0,
    completed_jobs INTEGER NOT NULL DEFAULT 0,
    failed_jobs INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL
);

-- Persistent cache table
CREATE TABLE IF NOT EXISTS cache (
    cache_key TEXT PRIMARY KEY,
    cache_value TEXT,
    expires_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_expires ON cache(expires_at);
