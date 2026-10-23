# Changelog

All notable changes are documented here. The project follows [Semantic Versioning](https://semver.org/).

## 1.13.0 — 2026-10-23

- The OneTrace search widget (setting `search_widget`, off by default, needs the tracker): when `search()->widget()` (`GET /api/v1/search/widget`, onetrace-php 1.15) answers `enabled: true` — cached for 10 minutes in the `onetrace_search_widget` transient, an error for a minute (402, 401, 403, 404 — for 10 minutes) and read again when the settings are saved — the product search page (`?s=…&post_type=product`) renders the theme with `<div data-cdp-search-results data-query-param="s" data-cart-url="…">` instead of the results: block themes keep the template parts of their template (the main group is replaced), classic themes get `templates/search-results.php` (`get_header('shop')`, the WooCommerce content wrappers, `get_footer('shop')`). The main query runs no product query and the server does not call `GET /search` (the tracker sends the `search` event). Search fields get `data-cdp-search` also without the "Product search" setting while the widget is on. The widget off, an API error or the statistics consent the tracker waits for (WP Consent API) not given — the search page as before.
- The add to cart endpoint of the widget: `POST /?wc-ajax=onetrace_add_to_cart&product={id}` with `quantity`, only with `X-Requested-With: XMLHttpRequest` (400 otherwise); `{id}` — the catalog id (post ID or SKU, `Products::postIds()`). `{"ok": true, "count": N}` (items in the cart) after `WC()->cart->add_to_cart()` with `woocommerce_add_to_cart_validation`; `{"ok": false, "redirect": "<product page>"}` for products with options (no `ajax_add_to_cart` support), not purchasable, out of stock or rejected; 404 with the shop page for an unknown id. After the add the page refreshes the cart fragments (`wc_fragment_refresh`) and the Mini-Cart block (`wc-blocks_added_to_cart`) on the tracker event `cdp:add-to-cart`.
- Product search: a 402 of the search API (`quota_exceeded` — the monthly limit of the plan) falls back to the WordPress search without retries, as any error; the platform's results are no longer kept in the WordPress query cache (`cache_results` off for the replaced main query), so the fallback search is never served the platform's answer from a persistent object cache.
- Requires onetrace-php 1.15.

## 1.12.0 — 2026-10-22

- My account → Newsletters (`newsletters` endpoint, setting `preferences`, off by default): the OneTrace preference center inside the store (onetrace-php 1.14) — topics of the project by channel with the channel names in the store language, the frequency of marketing messages (as they come, weekly, monthly), a pause for 30 or 90 days or resuming, "Unsubscribe from everything". Saving sends every topic of the form in every channel that can change, so an unticked box unsubscribes; blocked channels (the address does not receive messages) are read only. A customer the platform does not know yet sees that nothing is sent. The settings check warns when the secret key has no `profiles.read` and `profiles.write`.

## 1.11.0 — 2026-10-21

- Store MCP server for the OneTrace AI chat bot (store MCP contract v1, onetrace-php 1.13): `POST /wp-json/onetrace/v1/mcp`, the platform key is generated on first use (`onetrace_store_mcp_key`) and the server is connected to the bot when the settings are saved (`chats()->connectStoreMcp()`, the secret key needs `chats.manage`); the state is shown in the plugin settings.
- Tools from WooCommerce: the customer, orders of the account and guest orders by the billing email (newest first, contract statuses, `shipped` with a parcel), an order (items, totals, payment, shipping, carrier and tracking from `Shipments`, can cancel / return), tracking, stock and prices with variations, delivery methods of the matching shipping zone (flat rate cost, free shipping threshold), the "Refund and returns policy" page.
- Customers: `user:<id>` or `email:<address>`; the signed-in customer is passed to the chat with `cdp.chatCustomer()` (signed token, the secret in `onetrace_store_mcp_secret`); others get a code by email (WooCommerce mailer, transients; `onetrace_woocommerce_send_code` for SMS).
- Actions, off by default (`store_mcp_tools`): cancel an unpaid order (`onetrace_woocommerce_cancellable_statuses`, default pending, on-hold, failed), return and delivery change requests as an order note and an email to the shop owner; repeats with the same idempotency key answer the same.

## 1.10.0 — 2026-10-20

- Store emails through OneTrace (the "Store emails" section of the project, `GET /api/v1/emails/settings`, cached for 5 minutes and read again when the plugin settings are saved; a failure — for a minute, for a key without `messages.send` or an older platform — for 10 minutes). Every `wp_mail()` (`pre_wp_mail`) — WooCommerce, WordPress, other plugins — gets a kind: the WooCommerce email being sent (`woocommerce_mail_callback_params` … `woocommerce_email_sent`) or the WordPress notification filters (`retrieve_password_notification_email`, `wp_new_user_notification_email`, `wp_new_user_notification_email_admin`, `wp_password_change_notification_email`, `password_change_email`, `email_change_email`), anything else `other`; the filter `onetrace_woocommerce_email_kind` changes it. Mapping: on-hold → `order_placed`; processing → `order_paid` after an on-hold email (bank transfer, cheque; the order keeps `_onetrace_on_hold_emailed`), otherwise `order_placed`; completed, AST partially shipped / updated tracking → `order_shipped` (`order_completed` for orders without shipping); refunded and partially refunded → `order_refunded`; cancelled → `order_cancelled`; failed → `order_failed`; invoice → `invoice`; customer note → `order_note`; reset password → `password_reset`; new account → `account_new`; emails to the shop owner → `admin_{id}` (`admin_new_order`, `admin_cancelled_order`, `admin_failed_order`).
- Emails to the shop owner and staff never go through OneTrace, also with relay on (it would make customer profiles of staff addresses): every `admin_*` kind (WooCommerce emails to the store, `admin_new_user`, `admin_password_changed`), recipients that are the site admin email or the recipients of the WooCommerce emails to the store, and Cc / Bcc copies — the store sends them itself.
- Relay ("all store emails through OneTrace"): `POST /api/v1/emails` per recipient with the subject, HTML (plain text emails — as HTML with clickable links and as `text`), the text of multipart WooCommerce emails, reply-to, sender name, attachments (PDF, PNG, JPEG, GIF, TXT, CSV, ICS, up to 5 files and 5 MB — another type or more: the store sends the email itself) and, for the customer of the order or the user, the language and first name. The idempotency key is a hash of the email within 10 minutes (resending from the order screen — a new one).
- Order emails from OneTrace templates (`Emails::route()` → template): `order` of `Order::emailData()` — number, the order page (My account or the order received page of guests), lines with SKU, image, link and options (`LineItem::withDisplay()`), totals, the payment and shipping method titles, billing and shipping addresses with the names of the region and the country, the customer's note, payment instructions of the gateway for `order_placed`, the refund amount and reason, the carrier and tracking — plus `language` (WPML / Polylang / the storefront locale, the account locale, the store locale) and `traits.first_name`; the store's email goes along (sent as is if the template was switched off meanwhile). Idempotency key per store, order, kind and part (refund, parcel).
- Shipped and delivered emails, which WooCommerce does not have: with the template on, every new parcel and the delivery of `Shipments` schedule `onetrace_order_email` (Action Scheduler; retries after 1, 5 and 30 minutes on network errors, 5xx and 429); the order keeps the sent keys in `_onetrace_emails`. The Completed email is then the shipped email without tracking (and the background one is skipped), or is not sent when the order has parcels.
- Fallback: any failure — the platform answers with an error (422, 402, 429 …), does not answer within 5 seconds (the `emails` client: 5 s, connect 2 s, no retries), no secret key or permission — the store sends the email itself, only to the recipients OneTrace did not accept (the others are not sent twice).
- WooCommerce → Settings → OneTrace: the read-only "Store emails" block — relay on or off, order emails from OneTrace templates, the link to the section of the project (the project uid of the secret key, cached for a day), a note about the "Send messages manually" permission. Russian translation.
- Requires onetrace-php 1.12.

## 1.9.0 — 2026-10-19

- Shipments: `order_shipped` with the carrier, the tracking number and the tracking link — one event per tracking item of WooCommerce Shipment Tracking, Advanced Shipment Tracking (Zorem) and TrackShip on top of them (the order meta `_wc_shipment_tracking_items`, followed through the order meta actions of WooCommerce — HPOS and posts storage — so `save()`, `save_meta_data()` and `update_post_meta()` all count). The carrier name and the link come from the provider list of the tracking plugin (a custom provider — its name and link), the time from the shipping date (today — the moment of saving); AST PRO parcels with products carry the shipped products. A second parcel of the order is a new event; repeated saves send nothing again (the order keeps the sent parcels in `_onetrace_shipped`, and the messageId of a parcel is its tracking number).
- Without tracking: the "Completed" status (fulfilled in WooCommerce) or the "Shipped" status of tracking plugins is one `order_shipped` without tracking, only when no parcel was sent for the order and it has something to ship (not for virtual and downloadable orders). AST "mark as shipped" saves the parcel before the status, so the order gets the parcel only.
- `order_delivered`: the "Delivered" status of AST and TrackShip, or TrackShip's shipment status "delivered" (`trackship_shipment_status_trigger`) once every tracking number of the order is delivered; once per order. WooCommerce itself has no delivered status, so nothing else sends it.
- Other tracking plugins: the filter `onetrace_woocommerce_order_shipments` adds parcels (carrier, tracking_number, tracking_url, shipped_at, estimated_delivery, items of a partial shipment) and `do_action('onetrace_woocommerce_sync_shipments', $order)` sends the new ones after a parcel is saved; the statuses are filterable with `onetrace_woocommerce_shipped_statuses` and `onetrace_woocommerce_delivered_statuses`. All of it follows the "Orders" setting.
- Requires onetrace-php 1.11.

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
