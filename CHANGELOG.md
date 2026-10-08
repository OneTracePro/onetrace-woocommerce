# Changelog

All notable changes are documented here. The project follows [Semantic Versioning](https://semver.org/).

## 1.0.0 — 2026-10-08

First release.

- Orders from the server: placed (classic and block checkout), paid (cash on delivery — when completed), cancelled and refunded, with deterministic message ids.
- Customers: identify on registration, login, profile and address changes and at checkout; guest orders are linked by email.
- Newsletter consent checkboxes: checkout (classic and block), registration and the account page.
- Cart events from the server: add_to_cart, remove_from_cart, checkout_started.
- The website tracker with product views; waits for the statistics consent with the WP Consent API.
- Product catalog sync: on product changes, daily and on demand; variations as one product, categories with ancestors, attributes and brands.
- Recommendation widgets (settings and the [onetrace_widget] shortcode) and Web Push (/cdp-sw.js and [onetrace_push_button]).
- Queue with retries (1, 5, 30 minutes, 2 and 12 hours); sending pauses on an invalid key until the settings are saved.
- WooCommerce → Settings → OneTrace with the connection check and the queue state; WP-CLI commands; English and Russian.
