# Changelog

All notable changes are documented here. The project follows [Semantic Versioning](https://semver.org/).

## 1.8.0 — 2026-10-18

- Stock: catalog items carry `stock` when WooCommerce manages the stock of the product — a simple product its quantity, a variable product the sum of its purchasable variations that manage stock (the parent's stock once for variations that inherit it); no `stock` without stock management, when a variation is in stock without it, or when backorders are allowed (the product or a variation that counts — zero stock does not mean "not available" there, the platform would take the product off sale). Stock below zero is sent as 0. Availability is unchanged: the platform uses the stock only with "Use stock levels from the API and plugins" in its catalog settings.
- The product is uploaded again when its stock changes: `woocommerce_product_set_stock` and `woocommerce_variation_set_stock` (orders placed, cancelled and refunded, also without a product save) besides the product saves of the admin and the REST API — through the same queue, one waiting upload per product.
- Wishlist (setting "Wishlist", on by default): `add_to_wishlist` / `remove_from_wishlist` from the server for YITH WooCommerce Wishlist (`yith_wcwl_added_to_wishlist`, `yith_wcwl_removed_from_wishlist`) and TI WooCommerce Wishlist (`tinvwl_product_added`, `tinvwl_product_removed`, `tinvwl_wishlist_product_removed_from_wishlist`), with the signed-in customer and the tracker cookie like the cart events; a variation is the product with its `variant_id`. When a shop manager changes the wishlist of another customer, only the owner's user id is sent. Themes with another wishlist use `data-cdp-wishlist` / `data-cdp-wishlist-remove` (tracker 1.13.0).
- "Notify when available" (setting, on by default): `<div data-cdp-notify="{product id}">` on the page of a product out of stock (a variable product without a purchasable variation in stock) — after the add to cart area of classic templates (`woocommerce_single_product_summary`, priority 35) and after the "Add to cart form" / "Add to cart with options" block of block themes, once per product; the tracker (1.14.0) draws the form of the kind "Notify when available" from Site → Forms.
- Requires onetrace-php 1.10.

## 1.7.0 — 2026-10-13

- Promo codes: Settings → OneTrace → Promo codes binds a pool of OneTrace promo codes to a template coupon of the store ("pool_key = TEMPLATE-CODE" per line). Every 5 minutes (Action Scheduler) new and changed codes of the pool come by the cursor of the platform and become coupons with the discount, restrictions and limits of the template, one use each and the expiry of the code; coupons of the store with the same code are left as they are. "Test connection" checks the promo.read permission.
- Referrals: on registration the friend's code — from the optional "Friend's code" field or from the tracker's `cdp_ref` cookie of a `?ref=` link — goes to the platform (`loyalty()->refer()`), repeated while the new customer is unknown to it; the friend gets the welcome reward at once.
- My account → Bonus points: the referral link with a copy button, friends who came and the points for them, active promo codes of the customer; referral rewards in the history.
- Requires onetrace-php 1.7.

## 1.6.0 — 2026-10-13

- Bonus points of the OneTrace loyalty program: signed-in customers see their balance and pay for part of the order with points in the cart and at checkout — classic pages and the Cart and Checkout blocks. The points are held for 30 minutes (a negative fee in the totals), confirmed with the order number when the order is placed (retried from Action Scheduler if the platform is unavailable) and returned by the platform when the order is cancelled or refunded.
- My account → Bonus points: the balance, points waiting for the return period, the tier, points expiring soon and the latest operations; the "Join the program" button for programs with explicit enrollment.
- Settings → OneTrace → Loyalty program; "Test connection" checks the loyalty.read and loyalty.write permissions of the secret key.
- Requires onetrace-php 1.6.

## 1.5.1 — 2026-10-10

- The plugin starts once per request: an older copy without the second-copy check (1.4.0 and earlier, a zip from GitHub in the `onetrace-woocommerce` folder) booted the same classes again from its own callback. Hooks are static methods, so nothing ran twice, but this no longer depends on it.

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
