=== Wasmou for WooCommerce ===
Contributors: wasmou
Tags: woocommerce, gift cards, game top-up, digital products, dropshipping
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 7.0
WC tested up to: 11.1
Stable tag: 1.0.0
License: GPLv2 or later

Sell Wasmou gift cards, game top-ups and AI subscriptions in your own WooCommerce store. Products, prices and stock sync automatically, and codes are delivered to your customers without you lifting a finger.

== Description ==

1. Install, paste your Wasmou API key (Wasmou account > menu > API access).
2. Set your margin and, if your store is not in Algerian dinar, the exchange rate.
3. Pick the products to sell in WooCommerce > Wasmou > Import products.
4. When a customer pays, the plugin buys from your Wasmou wallet and delivers the codes by e-mail and in "My account > Orders".

Features: one-click import with images and categories; margin in % or fixed, rounding, currency conversion; price and stock sync (WP-Cron) that never overwrites your edits to names and descriptions; activation guides and warranty on product pages (Arabic, French, English); buyer fields (e-mail, player ID) with validation; price protection (the cart is re-checked against the live Wasmou price before payment); wallet protection (checkout is blocked if your Wasmou balance cannot cover the order); safe purchases (idempotent, a retry can never buy twice); subscriptions added to the buyer's own account are followed until confirmed; failure handling (on hold or automatic refund) with e-mail alerts; HPOS and block checkout compatible; Arabic and French translations.

== Frequently Asked Questions ==

= Who pays Wasmou? =
Your Wasmou wallet pays when your customer pays you. Keep it funded: https://myapp.wasmou.net/deposit

= My store is in USD/EUR, what price do I get? =
Enter the exchange rate (DZD per 1 unit of your currency) in the Pricing tab. Prices are recalculated at every sync.

= Can I edit imported products? =
Yes. Re-syncing only refreshes prices, stock and plans.

== Changelog ==

= 1.0.0 =
* First release.
