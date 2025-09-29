# WooCommerce Bitcoin Price Display - Project Notes

## Overview
- Purpose: Display product/cart totals in Bitcoin (sats) alongside store currency in WooCommerce.
- Key features:
  - Shows sats for product prices, cart line items, subtotals, shipping, taxes, and totals
  - Toggle/original/bitcoin-only/both display modes
  - BTCPay Server integration (v1 and v2) to fetch BTC exchange rate
  - Optional discount for Bitcoin payments by adjusting the rate
  - Caching for rates and formatted values

## Important Hooks/Filters Used
- Frontend price rendering: `woocommerce_get_price_html`, `woocommerce_cart_item_price`, `woocommerce_cart_item_subtotal`, `woocommerce_cart_subtotal`, `woocommerce_cart_totals_order_total_html`
- Shipping: `woocommerce_cart_totals_shipping_total`, `woocommerce_order_shipping_to_display`, `woocommerce_shipping_rate_format_price`, `woocommerce_shipping_rate_cost`, `woocommerce_package_rates`
- Taxes:
  - Cart: `woocommerce_cart_tax_totals`, `woocommerce_cart_totals_taxes_total_html`
  - Order: `woocommerce_order_get_tax_totals` (replaces deprecated `woocommerce_order_tax_totals`)

## Known Compatibility/Deprecations
- WooCommerce deprecated `woocommerce_order_tax_totals` since 3.0.0; plugin now uses `woocommerce_order_get_tax_totals`.
- Ensure PHP 7.3+ for SameSite cookie array options (fallback present for older versions).

## Configuration
- Settings page under `Settings → Bitcoin Price Display`.
- Required: BTCPay Server URL, Store ID, API key.
- Options: display mode, rounding, "both prices" layout, prefix/suffix, icon, thousand rounding, discount.

## BTCPay
- Auto-detects v1/v2 via `/api/v1/server/info`. Caches version for 24h.
- Permissions: for v2, ensure API key has `btcpay.store.canviewstoresettings`.

## Maintenance
- Clear caches when changing currency or BTCPay settings (`clear_all_caches`).
- If prices look off, verify discount setting and rate fetched from BTCPay.
- If checkout errors mention deprecated filters, ensure `woocommerce_order_get_tax_totals` is used.

## Future Ideas
- Add unit tests around formatting and caching.
- Consider using WooCommerce price filters that accept contexts to minimize custom HTML.
- Provide per-currency overrides and better fallback when BTCPay is unreachable.
