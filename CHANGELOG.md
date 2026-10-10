# Changelog

All notable changes are documented here. The project follows [Semantic Versioning](https://semver.org/).

## 1.5.0 — 2026-10-10

- Published on wordpress.org: https://wordpress.org/plugins/onetrace-for-woocommerce/ — the plugin folder, the release zip (`onetrace-for-woocommerce.zip`) and the text domain are `onetrace-for-woocommerce`, so the zip from GitHub and the directory version are the same plugin and update from the WordPress admin.
- A second copy of the plugin (an older zip from GitHub in the `onetrace-woocommerce` folder) is not loaded: it shows an admin notice to deactivate and delete it. Settings and the queue are shared.
- Releases are published to wordpress.org by CI from the version tag; readme and directory images follow the main branch.

## 1.4.0 — 2026-10-09

- Search redirects: a query with a redirect rule (Site → Search of the platform, e.g. "delivery" → the delivery page) opens that page instead of the search results.

## 1.3.1 — 2026-10-09

- Search on other languages of TranslatePress (/ru/): TranslatePress emptied the query for its own dictionary search, so the results came from WordPress (often a single product, which WooCommerce opens directly). The plugin searches the visitor's text from the original query.

## 1.3.0 — 2026-10-09

- Catalog languages: with TranslatePress, WPML or Polylang the catalog upload carries the names, links, category names and attribute values of the other store languages, so recommendations, widgets, search and emails show the visitor's language. WPML and Polylang copies of a product are one product of the platform (the default language id in the catalog and in events); deleting a copy uploads the product again instead of removing it.
- Search results take the page language and link to the product copy of that language.
- Requires onetrace-php 1.4.

## 1.2.0 — 2026-10-09

- Product search from OneTrace (the "Product search" setting, off by default): the store search results keep the theme template but take the products, their order and the number of pages from the platform (word forms, typos, the wrong keyboard layout, the visitor's interests); the search boxes of the theme and the blocks get suggestions while typing; the results page sends the `search` event. Any API error falls back to the WordPress search.
- Requires onetrace-php 1.3.

## 1.1.1 — 2026-10-09

- The newsletter checkbox of the classic checkout is under the billing fields: in the order review, which WooCommerce redraws on every change of the country or the email, it lost the customer's tick.

## 1.1.0 — 2026-10-08

- Setting "Product id": the SKU instead of the post ID in events, cart and order lines, the catalog, deletions and widgets — for stores whose OneTrace catalog and history already use SKUs (e.g. after moving from another platform). Variations send their own SKU as variant_id; products without a SKU fall back to the post ID.

## 1.0.2 — 2026-10-08

- Errors that did not come from the platform (database, building catalog items) are retried with pauses instead of dropping the queued data.

## 1.0.1 — 2026-10-08

- readme.txt links to the terms of service and the privacy policy of OneTrace.pro, as wordpress.org requires for external services.

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
