# Installation Guide

## Requirements

- PHP 7.0 or higher
- **SQLite PDO driver** (pdo_sqlite extension)
- Composer (for existing dependencies)

## Checking SQLite Support

Run this command to check if SQLite is available:

```bash
php -m | grep pdo_sqlite
```

If you see `pdo_sqlite` in the output, you're good to go!

If not, you'll need to install it.

## Installing SQLite PDO Support

### Ubuntu/Debian

```bash
sudo apt-get update
sudo apt-get install php-sqlite3
sudo service apache2 restart  # or php-fpm restart
```

### CentOS/RHEL

```bash
sudo yum install php-pdo
sudo systemctl restart httpd  # or php-fpm
```

### macOS (using Homebrew)

```bash
brew install php
# SQLite is usually included by default
```

### Windows

1. Open `php.ini`
2. Uncomment (remove `;` from) this line:
   ```
   extension=pdo_sqlite
   ```
3. Restart your web server

## Verifying Installation

After installing, verify with:

```bash
php -r "var_dump(class_exists('PDO')); var_dump(in_array('sqlite', PDO::getAvailableDrivers()));"
```

You should see:
```
bool(true)
bool(true)
```

## Testing the Queue System

Once SQLite is installed, test the setup:

```bash
php test_queue.php
```

You should see:
```
=== Testing Queue ===
✓ Queue initialized
✓ Created batch: batch_xxxxx
...
=== All Tests Passed ===
```

## Current Environment Issue

**Note:** The current environment does not have SQLite PDO support installed. The code is ready to use, but requires SQLite to be installed on the production server.

Available database drivers in current environment:
- MySQL (pdo_mysql, mysqli)
- PostgreSQL (pdo_pgsql, pgsql)

If you cannot install SQLite, let me know and I can adapt the code to use MySQL or PostgreSQL instead.

## Next Steps

1. Install SQLite PDO support on your server
2. Run `php test_queue.php` to verify
3. Set up worker (see QUEUE_SETUP.md)
4. Start using the new queue-based interface!
