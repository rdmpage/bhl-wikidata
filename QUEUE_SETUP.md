# Queue and Cache Setup Guide

This repository now uses an asynchronous job queue to handle long-running identifier processing without timeouts.

## New Architecture

1. **User submits identifiers** → Jobs created in SQLite queue
2. **Background worker processes jobs** → No timeout issues
3. **UI polls for progress** → Real-time feedback
4. **Results displayed** when complete

## Components

### Core Files

- `lib/Queue.php` - SQLite job queue manager
- `lib/Cache.php` - SQLite persistent cache for SPARQL queries
- `db/schema.sql` - Database schema
- `worker.php` - Background job processor
- `status.php` - AJAX endpoint for progress polling
- `index.php` - Updated to use queue (form submission redirects to progress page)

### Database Files (auto-created)

- `db/queue.db` - Job queue database
- `db/cache.db` - Persistent cache database

## Usage

### Option 1: Manual Worker (Simplest)

1. User submits identifiers via web form
2. Manually run worker: `php worker.php`
3. Watch progress in browser

### Option 2: Cron Job (Recommended)

Add to crontab to run every minute:

```bash
*/1 * * * * cd /path/to/bhl-wikidata && php worker.php >> logs/worker.log 2>&1
```

### Option 3: Daemon Mode (Advanced)

Run worker continuously in background:

```bash
nohup php worker.php --daemon >> logs/worker.log 2>&1 &
```

Worker will poll for new jobs every 10 seconds.

## Worker Options

```bash
php worker.php                 # Process up to 10 jobs, then exit
php worker.php --limit=50      # Process up to 50 jobs, then exit
php worker.php --daemon        # Run continuously
```

## Features

### Caching

- SPARQL query results cached for 24 hours in SQLite
- Dramatically reduces load on Wikidata servers
- Works seamlessly with batched queries from PR #18
- Cache automatically used by `fetch_wikidata_items_for_dois()`

### Queue

- Jobs tracked in SQLite database
- Supports multiple identifier types (DOI, etc.)
- Automatic retry of stale jobs
- Batch tracking for UI progress
- No timeouts - process any number of identifiers

### UI Improvements

- Real-time progress bar
- Status updates every 2 seconds
- Shows: pending, processing, completed, failed counts
- Displays results incrementally
- Clear separation of new items vs. existing items vs. errors

## Monitoring

Check queue status directly:

```bash
sqlite3 db/queue.db "SELECT status, COUNT(*) FROM jobs GROUP BY status;"
```

Check cache stats:

```bash
sqlite3 db/cache.db "SELECT COUNT(*) as total,
  SUM(CASE WHEN expires_at > strftime('%s','now') THEN 1 ELSE 0 END) as valid
  FROM cache;"
```

Clear expired cache entries:

```bash
sqlite3 db/cache.db "DELETE FROM cache WHERE expires_at < strftime('%s','now');"
```

## Troubleshooting

### Jobs stuck in "processing" state

Run: `php worker.php` - it will auto-reset stale jobs (>5 minutes old)

### Worker not auto-starting

The code attempts to spawn workers via `exec()`, but this may fail depending on server configuration. Use cron job instead.

### Database locked errors

SQLite uses file-level locking. If you see "database is locked" errors:
- Reduce concurrent workers to 1
- Or switch to MySQL/PostgreSQL (requires code changes)

## Identifier Type Support

Currently supports:
- `doi` - Digital Object Identifiers

To add more identifier types:
1. Add detection logic in `index.php` (around line 271)
2. Add handler in `worker.php` (around line 74)
3. Create corresponding `add_from_*()` function in `wikidata.php` or `shared.php`

## Performance

Expected improvements:
- **No timeouts** - Can process 100+ identifiers
- **Faster processing** - Cached SPARQL queries + batching from PR #18
- **Better UX** - Real-time progress vs. waiting for page to load
- **Scalable** - Can run multiple workers (though SQLite has limits)

## Migration from Old System

The old synchronous code is completely replaced. If you need to revert:
1. `git checkout HEAD~1 -- index.php`
2. Remove queue/cache code

Otherwise, just start using the new interface - it's backward compatible in terms of functionality.
