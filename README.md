# woocommerce-bitcoin-price-display

This WordPress/WooCommerce plugin integrates with BTCPay Server to display product prices in Bitcoin satoshis (sats) alongside the default currency.

CURRENTLY ONLY SUPPORTS USD/BTC CONVERSION. MULTI-CURRENCY SOON!

## Features

- Periodically pulls exchange rate data from your BTCPay Server every 10 minutes
- Displays product prices in sats throughout your WooCommerce store
- Button to allow customers to toggle USD price display (There's a setting to disable this)

## Requirements

- WordPress
- WooCommerce
- BTCPay Server instance with API access
- PHP

## Installation

1. Download the plugin zip file
2. Log in to your WordPress admin panel
3. Navigate to Plugins > Add New
4. Click "Upload Plugin" and select the downloaded zip file
5. Click "Install Now" and then "Activate Plugin"

## Configuration

1. Enter BTCPay Server Address
     - URL of your BTCPay Server i.e. `https://btcpay.example.com`
2. Enter Store ID
     - Login to your BTCPay Server
     - Go to Settings > General and copy the string in "Store ID"
4. Enter BTCPay Server API Key
     - Login to your BTCPay Server
     - Go to Settings > Access Tokens > Click link to "generate Greenfield API Keys"
     - Click "Generate Key" button
     - Enter any label for the key
     - Mark ONLY "View your stores btcpay.store.canviewstoresettings"
     - Click "Generate API Key" button at bottom
     - Back on the "API Keys" screen, click the "Reveal" link next to the labled key you just made
     - Copy/Paste that key into the "API Key" in the plug-in
5. Mark "Display Bitcoin Only" to hide USD prices in your store (optional)
6. Set "Rounding" to set if your store will display prices rounded to the nearest 1,000 Sats, 100 Sats, 10 or 1
7. Save Changes

## Usage

Once configured, the plugin will automatically display Sats prices by default. Customers may toggle back to USD prices, if not disabled.

## Support

For bug reports or feature requests, please open an issue on GitHub.

## Contributing

Contributions are welcome and appreciated!

## License

This project is licensed under [The Unlicense](LICENSE).

## Acknowledgements

- [BTCPay Server](https://btcpayserver.org/)
- [WooCommerce](https://woocommerce.com/)

## Thank you!

If you find this plug-in useful and want to send a few sats my way, my lightning address is btcpins@btcpay0.voltageapp.io

I encourage you to support the REAL devs by donating some Bitcoin to [OpenSats](https://opensats.org/). That money will help support further development of BTCPay Server as well as many other Bitcoin and privacy-enabling projects.
