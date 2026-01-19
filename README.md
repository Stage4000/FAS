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

## API Integration

### eBay API ✅
- **Integration**: Complete
- **File**: `src/integrations/EbayAPI.php`
- **Features**:
  - Fetch items from eBay store (moto800)
  - Get single item details
  - Search items by keywords
  - Automatic product synchronization
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
