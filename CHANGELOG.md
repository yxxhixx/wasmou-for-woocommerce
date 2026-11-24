# Changelog

## 1.1.0 - 2026-11-25
- **Dollar accounts**: international Wasmou accounts are billed in USD. The plugin now reads the currency the API reports and prices, balances and low-balance alerts follow it (before, USD prices were treated as dinar).
- **Your order number on every purchase**: each Wasmou order carries `WC-{order id}-{item id}` as its `reference`, so it shows in your Wasmou dashboard, order lookups and webhooks.
- **Instant updates (webhooks)**: one click in Delivery turns on signed webhooks, so customers get their code in seconds. Events are verified (HMAC, 5-minute window), repeats are ignored, and the order is always re-read from the API. Polling stays as a safety net. The plugin never replaces a webhook you already set up for something else.
- New texts translated into French and Arabic.

## 1.0.0 - 2026-10-06
First public release.

- One-click import of Wasmou products with images, categories, plans, warranty and activation guides
- Margin (percent or fixed), rounding and currency conversion from DZD
- Price and stock sync, without overwriting your own edits
- Automatic purchase and delivery of codes, with safe retries (idempotent, never buys twice)
- Price protection and wallet check at checkout
- Failure handling: On hold with an e-mail alert, or automatic refund
- HPOS and block-based Cart and Checkout compatible
- English, French and Arabic translations
