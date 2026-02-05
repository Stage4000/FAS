# Deployment and Update Guide

## Initial Deployment

### 1. Clone the Repository

```bash
git clone https://github.com/Stage4000/FAS.git
cd FAS
```

### 2. Set Up the Application

The database and configuration files are pre-initialized for easy deployment:

```bash
# Create admin user
php admin/init-admin.php
```

Default admin credentials: `admin` / `admin123`

### 3. Configure the Site

1. Login to admin at `/admin/`
2. **Immediately change your password** at `/admin/password.php`
3. Configure API credentials at `/admin/settings.php`:
   - eBay API credentials
   - PayPal credentials
   - EasyShip API key
   - Tawk.to chat settings (optional)

### 4. Protect Your Data (Important!)

After deployment with live data, protect your configuration and database from being overwritten by git updates:

#### Option A: Tell Git to Ignore Changes (Recommended)

The `.gitignore` file is already configured to protect your data, but since these files are already tracked by git, you need to tell git to ignore future changes:

```bash
# Tell git to ignore changes to the database and config
git update-index --assume-unchanged database/flipandstrip.db
git update-index --assume-unchanged src/config/config.php
```

This ensures that:
- Your local modifications won't be committed
- Git updates won't overwrite your files
- You can safely pull updates without losing data

To undo this later (e.g., to commit intentional changes):

```bash
git update-index --no-assume-unchanged database/flipandstrip.db
git update-index --no-assume-unchanged src/config/config.php
```

#### Option B: Use Local Filename

Rename files to use `.local` suffix (already protected):

```bash
# Rename database
mv database/flipandstrip.db database/flipandstrip.db.local

# Update config to point to new database location
# Edit src/config/config.php and change the path:
'database' => [
    'driver' => 'sqlite',
    'path' => __DIR__ . '/../../database/flipandstrip.db.local'
]
```

## Updating the Application

### Pull Updates Safely

If you protected your files as described above, you can safely pull updates:

```bash
# Backup your database first!
cp database/flipandstrip.db database/backups/backup-$(date +%Y%m%d).db

# Pull updates
git pull origin main
```

Your local data and configuration will be preserved.

### Backup Strategy

Regular backups are essential:

```bash
# Create automated backup script
cat > database/backup.sh << 'EOF'
#!/bin/bash
DATE=$(date +%Y%m%d-%H%M%S)
cp database/flipandstrip.db database/backups/backup-$DATE.db
# Keep only last 30 days of backups
find database/backups/ -name "backup-*.db" -mtime +30 -delete
EOF

chmod +x database/backup.sh

# Add to crontab for daily backups
# crontab -e
# Add: 0 2 * * * /path/to/FAS/database/backup.sh
```

## File Protection Summary

### Protected by .gitignore

The following files are protected in `.gitignore` and changes to them won't be tracked:

**Configuration Files:**
- `src/config/config.php` - Main configuration (use git update-index to activate protection)
- `src/config/config.production.php` - Production config
- `src/config/config.local.php` - Local config
- `admin/settings.json` - Admin settings
- `admin/config.json` - Admin configuration
- `.env`, `.env.local`, `.env.production` - Environment files

**Database Files:**
- `database/flipandstrip.db` - Main database (use git update-index to activate protection)
- `database/flipandstrip.db.local` - Local database copy
- `database/*.backup` - Backup files
- `database/backups/` - Backup directory
- `database/*.sql` - SQL files
- `*.sql.backup`, `*.sql.gz`, `*.sql.bak` - SQL backups

**User Data:**
- `/public/uploads/*` - User uploaded files
- `/gallery/uploads/*` - Gallery images
- `/sessions/` - Session files
- `*.sess`, `session_*` - Session data

**Logs and Cache:**
- `*.log`, `logs/`, `debug.log`, `app.log` - Log files
- `/cache/`, `/storage/cache/` - Cache directories
- `gallery/cache/`, `gallery/thumbs/` - Image cache

**Backups and Exports:**
- `admin/backups/` - Admin backup directory
- `admin/exports/` - Admin export directory
- `exports/`, `backups/` - Data exports and backups

### Initially Tracked (for easy deployment)

These files ARE in the repository initially for easy first-time deployment:

- `src/config/config.php` - Included with safe defaults
- `database/flipandstrip.db` - Included as pre-initialized empty database

**After deployment with live data**, use `git update-index --assume-unchanged` to tell git to ignore changes to these files (see "Protect Your Data" section above).

## Configuration File Management

### Development Environment

Use the included config file:
```
src/config/config.php (from repository)
```

### Production Environment

After initial deployment, either:

**Option 1**: Uncomment `src/config/config.php` in `.gitignore` to stop tracking changes

**Option 2**: Copy to a local version:

```bash
cp src/config/config.php src/config/config.php.local
```

Then update `src/config/Database.php` to require the `.local` version:

```php
$config = require __DIR__ . '/config.php.local';
```

## Troubleshooting

### "Database file keeps getting overwritten"

Make sure you've protected your database as described in step 4 of Initial Deployment.

### "Config changes are lost after git pull"

Make sure `src/config/config.php` is in your `.gitignore` or you're using the `.local` version.

### "Permission errors on database"

```bash
chmod 755 database/
chmod 666 database/flipandstrip.db
```

Make sure your web server has write access to the database directory.

## Security Recommendations

1. **Change Default Admin Password** - Do this immediately after deployment
2. **Protect Database File** - Follow the protection steps above
3. **Regular Backups** - Automate database backups
4. **Secure API Credentials** - Never commit real credentials to git
5. **File Permissions** - Set appropriate permissions on sensitive files:
   ```bash
   chmod 600 src/config/config.php
   chmod 755 database/
   chmod 666 database/flipandstrip.db
   ```

## Cron Jobs

Set up automated eBay synchronization:

```bash
# Edit crontab
crontab -e

# Add one of these:
# Sync every hour:
0 * * * * /usr/bin/php /path/to/FAS/cron/ebay-sync-cron.php >> /var/log/fas-sync.log 2>&1

# Sync every 6 hours:
0 */6 * * * /usr/bin/php /path/to/FAS/cron/ebay-sync-cron.php >> /var/log/fas-sync.log 2>&1

# Sync daily at 2 AM:
0 2 * * * /usr/bin/php /path/to/FAS/cron/ebay-sync-cron.php >> /var/log/fas-sync.log 2>&1
```

See `cron/README.md` for more details.

## Support

- **eBay User Token Guide**: `/admin/ebay-token-guide.php`
- **Database Documentation**: `database/README.md`
- **Cron Setup**: `cron/README.md`
