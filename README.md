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

- PHP 7.4 or higher
- MySQL/MariaDB (for production)
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
   - Copy `src/config/config.example.php` to `src/config/config.php`
   - Update database credentials

4. Configure APIs:
   - Add eBay API credentials in config
   - Add PayPal API credentials in config
   - Add EasyShip API key in config

5. Set up web server to serve from the root directory

## API Integration (To Be Implemented)

### eBay API
- Use eBay Finding/Shopping API to fetch listings
- Sync products from eBay store (https://www.ebay.com/str/moto800)
- Update inventory and prices automatically

### PayPal Integration
- Implement PayPal Checkout for payments
- Handle payment success/failure callbacks

### EasyShip Integration
- Calculate shipping rates
- Create shipment labels
- Track packages

## Color Scheme

- Primary: `#db0335` (Red)
- Dark: `#242629`
- Light: `#f8f8f8`

## eBay Store

Our eBay store: https://www.ebay.com/str/moto800

## License

Copyright © 2026 Flip and Strip. All rights reserved.
