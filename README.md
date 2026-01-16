# Flip and Strip - E-commerce Website

Modern e-commerce website for Flip and Strip motorcycle and ATV parts business.

## Website: https://flipandstrip.com

## Features

- **Modern Design**: Built with Bootstrap 5 for responsive, mobile-first design
- **Product Catalog**: Browse motorcycle and ATV parts by category
- **Shopping Cart**: JavaScript-based shopping cart with localStorage
- **eBay Integration**: Ready for eBay API integration to sync listings
- **Payment Gateway**: PayPal checkout integration (to be configured)
- **Shipping**: EasyShip integration ready (to be configured)

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
3. Visit `/admin/` and click "Start eBay Sync" to import products
4. Products will be automatically synced from your eBay store (moto800)

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

### EasyShip Integration
- **Status**: Configuration ready
- **Note**: Implement shipping rate calculation and label generation

## Admin Panel ✅

Access the admin panel at `/admin/` to:
- View dashboard with statistics
- Sync products from eBay
- Monitor sync logs
- Manage orders (coming soon)

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

- **GET** `/api/ebay-sync.php?key=KEY` - Sync products from eBay store
- **POST** `/api/create-order.php` - Create PayPal order (to be implemented)
- **POST** `/api/capture-payment.php` - Capture PayPal payment (to be implemented)

## Color Scheme

- Primary: `#db0335` (Red)
- Dark: `#242629`
- Light: `#f8f8f8`

## eBay Store

Our eBay store: https://www.ebay.com/str/moto800

## License

Copyright © 2026 Flip and Strip. All rights reserved.
