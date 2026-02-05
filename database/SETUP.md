# Database Setup Guide

The database file (`flipandstrip.db`) is NOT included in the repository to prevent admin password resets on deployment.

## First Time Setup

### 1. Initialize the Database

Run the database initialization script:

```bash
php database/init-sqlite.php
```

This will:
- Create `database/flipandstrip.db`
- Set up all required tables
- Apply the schema from `schema.sqlite.sql`

### 2. Create Admin User

Run the admin initialization script:

```bash
php admin/init-admin.php
```

This will create the default admin account:
- **Username**: `admin`
- **Password**: `admin123`

**Important**: Change this password immediately after first login at `/admin/password.php`

## Updating the Application

When you pull new code updates:

1. The database file will NOT be overwritten (it's in `.gitignore`)
2. Your admin password and all data will be preserved
3. Run any new migration scripts if provided in the update notes

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

If you can't log in, recreate the admin user:

```bash
php admin/init-admin.php
```

This will only create an admin if none exists. If an admin already exists, change your password through the admin interface or manually update the database.
