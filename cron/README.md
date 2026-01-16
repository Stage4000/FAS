# eBay Synchronization Cron Job Setup

This directory contains scripts for automated eBay product synchronization.

## Script: ebay-sync-cron.php

Automatically syncs products from your eBay store (moto800) to the local database.

### Features
- Fetches all products from eBay store
- Updates existing products with current prices and availability
- Adds new products automatically
- Logs all sync activities to database
- Rate limiting to avoid API throttling

### Setup Instructions

1. Make the script executable:
```bash
chmod +x /path/to/FAS/cron/ebay-sync-cron.php
```

2. Test the script manually:
```bash
php /path/to/FAS/cron/ebay-sync-cron.php
```

3. Add to crontab for automatic execution:
```bash
crontab -e
```

### Recommended Cron Schedules

**Every Hour** (Recommended for active stores):
```
0 * * * * /usr/bin/php /path/to/FAS/cron/ebay-sync-cron.php >> /var/log/fas-sync.log 2>&1
```

**Every 6 Hours**:
```
0 */6 * * * /usr/bin/php /path/to/FAS/cron/ebay-sync-cron.php >> /var/log/fas-sync.log 2>&1
```

**Daily at 2 AM**:
```
0 2 * * * /usr/bin/php /path/to/FAS/cron/ebay-sync-cron.php >> /var/log/fas-sync.log 2>&1
```

**Every 30 Minutes** (For high-volume stores):
```
*/30 * * * * /usr/bin/php /path/to/FAS/cron/ebay-sync-cron.php >> /var/log/fas-sync.log 2>&1
```

### Monitoring

View sync logs:
```bash
tail -f /var/log/fas-sync.log
```

Check sync history in database:
```sql
SELECT * FROM ebay_sync_log ORDER BY started_at DESC LIMIT 10;
```

### Troubleshooting

**Script not running:**
- Check file permissions: `ls -la cron/ebay-sync-cron.php`
- Verify PHP path: `which php`
- Check crontab: `crontab -l`

**Sync failures:**
- Check API credentials in `src/config/config.php`
- Verify database connection
- Review error logs: `tail /var/log/fas-sync.log`

**Rate limiting:**
- Reduce sync frequency
- Adjust sleep time in script
- Check eBay API limits

### Notes

- Each sync creates a log entry in the `ebay_sync_log` table
- Failed items are logged but don't stop the sync process
- Products marked as inactive in eBay remain in database but marked as inactive
- Sync respects eBay API rate limits with built-in delays
