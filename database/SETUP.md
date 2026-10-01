# Database Setup Guide

The database file (`flipandstrip.db`) and configuration file (`src/config/config.php`) are NOT included in the repository to prevent admin password resets and credential exposure on deployment.

## First Time Setup

### 1. Create Configuration File

Copy the example configuration file and customize it:

```bash
cp src/config/config.example.php src/config/config.php
```

Then edit `src/config/config.php` with your actual credentials (eBay, PayPal, etc.).

**Important**: `config.php` is in `.gitignore` and will never be committed to the repository.

### 2. Initialize the Database

Run the database initialization script:

```bash
php database/init-sqlite.php
```

This will:
- Create `database/flipandstrip.db`
- Set up all required tables
- Apply the schema from `schema.sqlite.sql`

### 3. Create Admin User

Set `FAS_INITIAL_ADMIN_USERNAME`, `FAS_INITIAL_ADMIN_EMAIL`, and `FAS_INITIAL_ADMIN_PASSWORD` in the server environment, then run the admin initialization script:

```bash
php admin/init-admin.php
```

This creates the first administrator with your configured username, email, and unique 12–72 byte password. No default credentials are used.

## Quick Setup (Alternative)

You can also use the combined installer:

```bash
php install.php
```

This will:
1. Create the configuration file from the example (if it doesn't exist)
2. Initialize the database
3. Create the admin user
4. Delete itself after successful setup

## Updating the Application

When you pull new code updates:

1. The database file will NOT be overwritten (it's in `.gitignore`)
2. The configuration file will NOT be overwritten (it's in `.gitignore`)
3. Your admin password and all data will be preserved
4. Run any new migration scripts if provided in the update notes

## Database Location

The database is stored at: `database/flipandstrip.db`

This file is ignored by git and will persist through code updates.

## Troubleshooting

### "No such table" errors

If you see errors about missing tables, the database needs to be initialized:

```bash
php database/init-sqlite.php
php admin/init-admin.php
```

### Database already exists

If `init-sqlite.php` reports the database already exists, it means you're already set up. No action needed unless you want to recreate it (this will delete all data).

### Admin login not working

If no administrator exists, create the first account:

```bash
php admin/init-admin.php
```

This only creates an account if none exists. For an existing account, use the admin panel's password change or another active administrator's reset control.
