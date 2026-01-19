# Database Setup - SQLite

## Overview

This application uses **SQLite** as its database. SQLite is a lightweight, file-based database that requires no separate server installation.

## Database Location

The SQLite database file is stored at:
```
/database/flipandstrip.db
```

## Database Protection Through Updates

### Initial Deployment

The pre-initialized database file `flipandstrip.db` is **included in the repository** for easy deployment. This allows the application to work immediately after cloning.

### After First Deployment - IMPORTANT

To protect your database from being overwritten during git updates, you have two options:

**Option 1: Uncomment the .gitignore line (Recommended)**

After your site is deployed and has live data:

1. Open `.gitignore` in the root directory
2. Find the commented line: `# database/flipandstrip.db`
3. Uncomment it to: `database/flipandstrip.db`
4. Commit this change

This will prevent git from tracking changes to your database file, protecting your data during updates.

**Option 2: Rename your production database**

Alternatively, rename your production database to a protected name:

```bash
mv database/flipandstrip.db database/flipandstrip.db.local
```

Then update `src/config/config.php` to point to the new filename:

```php
'database' => [
    'driver' => 'sqlite',
    'path' => __DIR__ . '/../../database/flipandstrip.db.local'
]
```

The `.local` suffix is already in `.gitignore` and will never be overwritten.

### Backup Your Database

Regular backups are recommended. Create backups with:

```bash
# Create a dated backup
cp database/flipandstrip.db database/backups/backup-$(date +%Y%m%d-%H%M%S).db

# Or use SQLite's backup command
sqlite3 database/flipandstrip.db ".backup 'database/backups/backup.db'"
```

The `database/backups/` directory is automatically excluded from git.

## Initial Setup

### 1. Initialize the Database

Run the initialization script to create the database and all tables:

```bash
php database/init-sqlite.php
```

This will:
- Create the `flipandstrip.db` file
- Create all necessary tables (products, orders, categories, coupons, etc.)
- Insert default categories
- Enable foreign key constraints

### 2. Create Admin User

After initializing the database, create the admin user:

```bash
php admin/init-admin.php
```

Default credentials: `admin` / `admin123`

### 3. Login and Configure

1. Visit `/admin/` and login
2. Change your password at `/admin/password.php`
3. Configure API credentials at `/admin/settings.php`

## Database Schema

The database includes the following tables:

- **products** - Product catalog with eBay integration
- **categories** - Product categories
- **orders** - Customer orders
- **order_items** - Order line items
- **coupons** - Discount codes and promotions
- **ebay_sync_log** - eBay synchronization history
- **admin_users** - Admin user accounts

## Backup and Restore

### Backup

Simply copy the database file:

```bash
cp database/flipandstrip.db database/backup-$(date +%Y%m%d).db
```

### Restore

Replace the current database with a backup:

```bash
cp database/backup-20260119.db database/flipandstrip.db
```

## Advantages of SQLite

✅ **No Server Required** - Works immediately without MySQL/MariaDB installation
✅ **Easy Setup** - Single file, no configuration
✅ **Portable** - Entire database in one file
✅ **Fast** - Excellent performance for small to medium datasets
✅ **Zero Configuration** - No usernames, passwords, or ports
✅ **Reliable** - ACID-compliant, same quality as MySQL

## Troubleshooting

### Permission Errors

If you get permission errors, ensure the database directory is writable:

```bash
chmod 755 database/
chmod 664 database/flipandstrip.db  # After creation
```

### Database Locked

If you get "database is locked" errors, ensure no other processes are accessing the database.

### Recreate Database

To start fresh (⚠️ **this deletes all data**):

```bash
rm database/flipandstrip.db
php database/init-sqlite.php
php admin/init-admin.php
```

## Migration from MySQL

If you previously used MySQL, the SQLite schema is fully compatible. Data migration can be done using:

1. Export data from MySQL
2. Convert to SQLite-compatible format
3. Import into SQLite

Tools like `mysql2sqlite` can help automate this process.

## Performance

SQLite performs excellently for:
- Up to 100,000 products
- Up to 10,000 orders per month
- Multiple concurrent read operations
- Single-writer scenarios

For higher loads, consider MySQL/MariaDB.

## Schema File

The database schema is defined in:
- `schema.sqlite.sql` - SQLite-optimized schema

## Configuration

Database settings are in `src/config/config.php`:

```php
'database' => [
    'driver' => 'sqlite',
    'path' => __DIR__ . '/../../database/flipandstrip.db'
]
```

## Support

For issues or questions:
- Check the main README.md
- Review error logs
- Ensure file permissions are correct
