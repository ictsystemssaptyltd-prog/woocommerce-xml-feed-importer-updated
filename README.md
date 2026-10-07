# WooCommerce XML Feed Importer

A powerful WooCommerce plugin for importing product feeds from XML and CSV sources with support for:

- ✅ **Manual Trigger Buttons** - Import feeds on-demand directly from the admin interface
- ✅ **Scheduled Imports** - Automatic imports via WordPress cron (hourly, twice daily, or daily)
- ✅ **XML & CSV Support** - Parse both XML and CSV product feeds
- ✅ **Advanced Authentication** - API keys, Basic Auth, Bearer tokens, and custom headers
- ✅ **Flexible Field Mapping** - Map any feed field to WooCommerce product attributes
- ✅ **XPath Support** - Use XPath expressions to extract data from complex XML structures
- ✅ **Stock Management** - Skip zero-stock items or update existing products
- ✅ **Logging** - Comprehensive logging of all import operations

## Features

### Manual Import Triggers
Each feed in the admin list now has a **Trigger** button next to Edit and Delete, allowing you to:
- Run imports immediately without waiting for scheduled cron jobs
- Test feed configurations before setting up schedules
- Handle urgent inventory updates

### Flexible Feed Configuration

#### Feed Settings
- **Feed Name** - Descriptive name for your feed
- **Base URL** - Source URL for XML or CSV file
- **Format** - Choose between XML or CSV
- **Product XPath** - For XML feeds: XPath to product nodes (e.g., `/products/product`)
- **Frequency** - Daily, twice daily, or hourly automatic imports
- **Enabled** - Toggle feeds on/off without deleting
- **Skip Zero Stock** - Automatically skip products with zero inventory

#### Authentication
Supports multiple authentication methods:
- **None** - Public feeds
- **API Key** - Custom header-based API keys
- **Basic Auth** - Username/password authentication
- **Bearer Token** - OAuth-style token authentication
- **Custom Header** - Any custom header key/value pair
- **Query Parameters** - Add parameters to the request URL
- **Path Parameters** - For templated URLs (e.g., Pinnacle feeds)

#### Field Mappings
Map feed fields to WooCommerce product attributes:
- `name` - Product title
- `sku` - Stock keeping unit
- `description` - Full product description
- `short_description` - Short description
- `price` - Regular price
- `sale_price` - Sale price
- `stock_quantity` - Inventory count
- `stock_status` - Stock status (instock/outofstock)
- `category` - Product categories (pipe-separated)
- `image` - Product image URL

### Logging
All import operations are logged with:
- Timestamp and user information
- Operation type (import, error, warning)
- Feed details and context
- Easy filtering and searching in the admin panel

## Installation

1. Download or clone this repository into your `wp-content/plugins/` directory
2. Activate the plugin in WordPress
3. WooCommerce must be installed and active

## Requirements

- PHP 7.4+
- WordPress 5.0+
- WooCommerce 3.0+

## Usage

### Creating a Feed

1. Go to **WooCommerce > XML Feed Importer**
2. Click **Add Feed**
3. Configure:
   - Feed name and URL
   - Format (XML or CSV)
   - XPath (for XML)
   - Authentication method if needed
   - Field mappings
4. Save the feed
5. Use **Trigger** to test immediately, or set a schedule

### Manual Triggering

In the feed list:
- Click **Trigger** next to any feed
- Confirm the prompt
- The import runs immediately
- Check the **View Logs** page for results

### Example Feed Configurations

#### Simple XML Feed
```
Format: XML
Base URL: https://example.com/products.xml
Product XPath: /products/product

Field Mappings:
name=name
sku=sku
price=price
stock_quantity=qty
```

#### CSV Feed with API Key
```
Format: CSV
Base URL: https://api.example.com/export/products.csv
Authentication: API Key
API Key Header: X-API-Key
API Key: your-api-key-here

Field Mappings:
name=Product Name
sku=SKU
price=Price
category=Category
```

#### Pinnacle Feed (with path parameters)
```
Format: XML
Base URL: https://pinnacle.example.com/feeds/
Authentication: Custom

Path Parameters:
id=11305
uid=bf672543-bc4c-40a9-a8c6-0ac6259bb4de

Field Mappings:
name=ProdName
sku=StockCode
price=ProdPriceExclVAT
stock_quantity=ProdQty
```

## Troubleshooting

### Feed not importing
1. Check **View Logs** for error messages
2. Verify the feed URL is accessible
3. Test authentication credentials
4. Check XPath (for XML) matches your feed structure
5. Ensure field mappings are correct

### Products not updating
- Verify the `sku` field is correctly mapped (used as product identifier)
- Check that product SKUs match between feed and WooCommerce
- Look for "Skipped" warnings in the logs

### CSV parsing errors
- Verify delimiter (comma, semicolon, etc.)
- Check enclosure character (usually double quotes)
- Ensure headers are present in the CSV

## Logging

Logs are stored in WordPress and displayed in:
- **WooCommerce > XML Feed Importer Logs**
- Last 500 operations retained
- Filter by log level (Info, Warning, Error)
- Search by keyword
- Clear logs manually

Logs are also sent to the WooCommerce logger if available.

## API Reference

### WPFI_Importer
```php
$importer = new WPFI_Importer($logger);
$result = $importer->import($feed); // Returns true/false
```

### WPFI_Feed_Repository
```php
$repo = new WPFI_Feed_Repository();
$all_feeds = $repo->all(); // Get all feeds
$feed = $repo->get($id); // Get specific feed
$repo->save($feed); // Save/update feed
$repo->delete($id); // Delete feed
```

### WPFI_Scheduler
```php
$scheduler = new WPFI_Scheduler($repo, $importer);
$scheduler->sync(); // Sync all scheduled imports
$scheduler->clear(); // Clear all scheduled imports
```

## Development

This plugin follows WordPress coding standards:
- PSR-2 compliance
- Security: nonce verification, capability checks, sanitization
- Proper escaping for output
- WP-CLI integration ready
- Action/filter hooks for extensibility

## License

GNU General Public License v2.0 or later

See LICENSE file for full text.

## Support

For issues, questions, or contributions:
https://github.com/ictsystemssaptyltd-prog/woocommerce-xml-feed-importer-updated

## Changelog

### Version 2.0.0
- ✨ Added manual trigger buttons for on-demand feed imports
- 🔧 Improved plugin bootstrap and standards compliance
- 📝 Comprehensive documentation and examples
- 🐛 Fixed compatibility issues in class initialization
- 🔐 Enhanced security with nonce verification
- 📊 Better logging and error handling

### Version 1.0.0
- Initial release
