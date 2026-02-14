# Flip and Strip - E-commerce Website

Modern e-commerce website for Flip and Strip motorcycle, ATV/UTV, boat, and automotive parts business.

## Website: https://flipandstrip.com

## Features

- **Modern Design**: Built with Bootstrap 5 for responsive, mobile-first design
- **Product Catalog**: Browse motorcycle, ATV/UTV, boat, and automotive parts by category
- **Shopping Cart**: JavaScript-based shopping cart with localStorage
- **eBay Integration**: Ready for eBay API integration to sync listings
- **Payment Gateway**: PayPal checkout integration (to be configured)
- **Shipping**: EasyShip integration ready (to be configured)
- **Live Chat**: Tawk.to integration for customer support
- **Analytics**: Google Analytics integration for tracking and insights

## Technology Stack

- **Frontend**: HTML5, CSS3, JavaScript, Bootstrap 5
- **Backend**: PHP 7.4+
- **Database**: SQLite (backup data) / MySQL (production)
- **Payment**: PayPal
- **Shipping**: EasyShip
- **APIs**: eBay Finding/Shopping API

## Project Structure

```
/
├── index.php              # Homepage
├── products.php           # Product listing page
├── cart.php              # Shopping cart
├── about.php             # About page
├── contact.php           # Contact page
├── includes/
│   ├── header.php        # Common header
│   └── footer.php        # Common footer
├── public/
│   ├── css/
│   │   └── style.css     # Custom styles
│   └── js/
│       └── main.js       # Main JavaScript
├── gallery/              # Product images (2400+ images)
├── src/
│   ├── config/           # Configuration files
│   ├── models/           # Data models
│   └── controllers/      # Business logic
├── composer.json         # PHP dependencies
└── .gitignore           # Git ignore rules
```

## Setup Instructions

### Requirements

- PHP 7.4 or higher with extensions: PDO, JSON, cURL
- MySQL/MariaDB 5.7+ (for production)
- Composer (for PHP dependencies)
- Web server (Apache/Nginx)

### Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/Stage4000/FAS.git
   cd FAS
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Configure database:
   - Import database schema: `mysql -u root -p database_name < database/schema.sql`
   - Copy `src/config/config.example.php` to `src/config/config.php`
   - Update database credentials in `src/config/config.php`

4. Configure APIs:
   - **eBay API**: Add your eBay App ID, Cert ID, Dev ID in `src/config/config.php`
   - **PayPal API**: Add your PayPal Client ID and Secret in `src/config/config.php`
   - **EasyShip API**: Add your EasyShip API key in `src/config/config.php`
   - **Google Analytics**: Add your GA4 Measurement ID in `src/config/config.php` (optional)

5. Set up web server to serve from the root directory

6. Access admin panel at `/admin/` to sync products from eBay

### First Time Setup

1. Configure your API credentials in `src/config/config.php`
2. Import the database schema from `database/schema.sql`
3. Initialize admin user:
   ```bash
   php admin/init-admin.php
   ```
   Default credentials: `admin` / `admin123` (change after first login)
4. Visit `/admin/` and login with default credentials
5. Change admin password immediately at `/admin/password.php`
6. Configure API settings at `/admin/settings.php`
7. Click "Start eBay Sync" to import products
8. Products will be automatically synced from your eBay store (moto800)
9. Set up automated sync with cron job (see below)

### Automated eBay Synchronization

Set up a cron job for regular product updates:

```bash
# Edit crontab
crontab -e

# Add one of these lines:

# Sync every hour (recommended)
0 * * * * /usr/bin/php /path/to/FAS/cron/ebay-sync-cron.php >> /var/log/fas-sync.log 2>&1

# Sync every 6 hours
0 */6 * * * /usr/bin/php /path/to/FAS/cron/ebay-sync-cron.php >> /var/log/fas-sync.log 2>&1

# Sync daily at 2 AM
0 2 * * * /usr/bin/php /path/to/FAS/cron/ebay-sync-cron.php >> /var/log/fas-sync.log 2>&1
```

See `cron/README.md` for detailed setup instructions.

#### Sold Items Management

When items are sold or ended on eBay, they are automatically handled during synchronization:

- **Detection**: The eBay API identifies items with status != 'active' or quantity = 0
- **Action**: These items are automatically hidden from the website (`show_on_website = 0`)
- **Preservation**: Items remain in the database for order history but are not visible to customers
- **Logging**: The sync process logs the number of hidden items for monitoring
- **Both sync methods**: This applies to both manual sync (`/api/ebay-sync.php`) and automated cron sync

This ensures customers never see out-of-stock or sold items, preventing confusion and potential disputes.

#### eBay API Rate Limits

eBay enforces rate limits on API calls to prevent abuse:
- **Finding API**: 5,000 calls per day per App ID
- If you hit rate limits, the sync will automatically retry with exponential backoff (5, 15, 45 seconds)
- If rate limits persist, wait 5-10 minutes before trying again
- Space out your syncs (hourly or every 6 hours recommended, not more frequently)

#### Sync API Key

The **Sync API Key** is a security token that protects the sync endpoint from unauthorized access:
- **Purpose**: Prevents anyone from triggering product syncs on your site
- **Default**: `fas_sync_key_2026` 
- **Location**: Configured in `src/config/config.php` under `security.sync_api_key`
- **Usage**: Append `?key=YOUR_KEY` when calling `/api/ebay-sync.php`
- **Best Practice**: Change the default key to a random string for better security
- **Where to find it**: Admin Panel > Settings > Security Settings > Sync API Key

## API Integration

### eBay API ✅
- **Integration**: Complete
- **File**: `src/integrations/EbayAPI.php`
- **Features**:
  - Fetch items from eBay store (moto800)
  - Get single item details
  - Search items by keywords
  - Automatic product synchronization
  - **Automatic sold item handling**: Items sold or ended on eBay are automatically hidden from the website during sync
- **Endpoint**: `/api/ebay-sync.php?key=fas_sync_key_2026`

### PayPal Integration ✅
- **Integration**: Complete
- **File**: `src/integrations/PayPalAPI.php`
- **Features**:
  - Create PayPal orders
  - Capture payments
  - Handle callbacks
- **Usage**: Integrated in checkout flow

### EasyShip Integration ✅
- **Integration**: Complete
- **File**: `src/integrations/EasyShipAPI.php`
- **Features**:
  - Calculate real-time shipping rates
  - Multiple courier options
  - Delivery time estimates
  - Automatic parcel building from cart items
- **Usage**: Integrated in checkout flow via `/api/shipping-rates.php`

### Google Analytics ✅
- **Integration**: Complete
- **Configuration**: Add to `src/config/config.php`:
  ```php
  'google_analytics' => [
      'enabled' => true,
      'measurement_id' => 'G-XXXXXXXXXX' // Your GA4 Measurement ID
  ]
  ```
- **Features**:
  - Automatic page view tracking
  - User behavior analytics
  - E-commerce tracking ready
- **Usage**: Automatically included on all frontend pages via `includes/header.php`
- **How to get Measurement ID**: 
  1. Sign up for Google Analytics at https://analytics.google.com
  2. Create a GA4 property
  3. Copy the Measurement ID (format: G-XXXXXXXXXX)
  4. Add it to your config.php

## Admin Panel ✅

Access the admin panel at `/admin/` with password protection:

**Features:**
- Password-protected access (default: admin/admin123)
- Change password functionality
- Full configuration management via web interface
- View dashboard with statistics
- One-click eBay sync
- Monitor sync logs

**Initial Setup:**
```bash
php admin/init-admin.php
```

**Admin Pages:**
- `/admin/` - Dashboard
- `/admin/settings.php` - Configure all API credentials and settings
- `/admin/password.php` - Change admin password
- `/admin/login.php` - Login page
- `/admin/logout.php` - Logout

## Database Schema ✅

Complete database schema includes:
- `products` - Product catalog
- `categories` - Product categories
- `orders` - Customer orders
- `order_items` - Order line items
- `ebay_sync_log` - Synchronization logs
- `admin_users` - Admin authentication

See `database/schema.sql` for full schema.

## API Endpoints

- **GET** `/api/ebay-sync.php?key=KEY` - Sync products from eBay store (manual trigger)
- **POST** `/api/shipping-rates.php` - Calculate shipping rates via EasyShip
  - Body: `{ "items": [...], "address": {...} }`
- **POST** `/api/create-order.php` - Create PayPal order (to be implemented)
- **POST** `/api/capture-payment.php` - Capture PayPal payment (to be implemented)

## Automated Synchronization

Use the cron job script for regular eBay product updates:
- **Script**: `cron/ebay-sync-cron.php`
- **Recommended**: Run hourly or every 6 hours
- **Logs**: All syncs to `ebay_sync_log` table
- See `cron/README.md` for setup details

## Color Scheme

- Primary: `#db0335` (Red)
- Dark: `#242629`
- Light: `#f8f8f8`

## eBay Store

Our eBay store: https://www.ebay.com/str/moto800

## License

Copyright © 2026 Flip and Strip. All rights reserved.
