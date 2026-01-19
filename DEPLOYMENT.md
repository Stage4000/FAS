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

After deployment with live data, protect your configuration and database:

#### Option A: Update .gitignore (Recommended)

Edit `.gitignore` and uncomment this line:

```
# database/flipandstrip.db
```

Change it to:

```
database/flipandstrip.db
```

Then commit:

```bash
git add .gitignore
git commit -m "Protect production database from updates"
git push
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

### Protected by Default (.gitignore)

These files are never tracked by git:

- `src/config/config.php.local` - Production config
- `database/flipandstrip.db.local` - Production database (if renamed)
- `database/backups/` - All backup files
- `database/*.backup` - Backup files
- `.env` files
- `*.log` files
- `/cache/` directory
- `/tmp/` directory
- User uploads in `/public/uploads/`

### Initially Tracked (for easy deployment)

These files ARE in the repository initially but should be protected after deployment:

- `src/config/config.php` - Included with safe defaults
- `database/flipandstrip.db` - Included as pre-initialized empty database

**After deployment**, protect these files using one of the methods above.

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
