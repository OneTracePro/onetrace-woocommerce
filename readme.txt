=== OneTrace for WooCommerce ===
Contributors: onetrace
Tags: woocommerce, cdp, marketing automation, abandoned cart, recommendations
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 9.0
WC tested up to: 11.2
Stable tag: 1.13.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects WooCommerce to the OneTrace.pro customer data platform: orders, customers, consents, catalog, recommendations and Web Push.

== Description ==

OneTrace.pro is a customer data platform: it builds customer profiles from your store's events, segments them and sends emails, SMS, messenger and push messages with journeys and campaigns.

This plugin connects your WooCommerce store:

* Orders and their statuses from the server, through a queue with retries — not lost to ad blockers, and the store never waits for the platform.
* Shipments with the carrier, tracking number and link from WooCommerce Shipment Tracking, Advanced Shipment Tracking and TrackShip, and delivered orders — for "where is my order?" in the online chat and shipping journeys.
* Customers and newsletter consents from registration, the account page and the checkout (classic and block).
* Cart events for abandoned cart journeys; wishlist events from YITH WooCommerce Wishlist and TI WooCommerce Wishlist.
* The website tracker with product views; waits for the statistics consent with the WP Consent API.
* The product catalog for recommendations and emails, with the stock of products that track it — for "back in stock" and "low stock" journeys.
* The "Notify when available" form of OneTrace on the page of a product that is out of stock.
* Recommendation widgets and Web Push.
* Product search: the store search results and suggestions while typing come from OneTrace — word forms, typos, the wrong keyboard layout and the visitor's interests; the usual search on any error.
* The OneTrace search widget on the search page of your theme: facets, sorting, infinite scroll and an "Add to cart" button, with a search layer under the search fields.
* Catalog languages from TranslatePress, WPML and Polylang: recommendations, search and emails in the visitor's language.
* Loyalty program: signed-in customers pay with bonus points in the cart and at checkout and see their points in My account; referral links and the friend's code on registration.
* Newsletters in My account: customers choose subscription topics by channel, how often marketing messages come, pause them or unsubscribe from everything — the OneTrace preference center inside the store.
* Promo codes: unique codes of OneTrace pools become WooCommerce coupons of a template coupon.
* Store emails: the emails of WooCommerce, WordPress and other plugins can go out through the email integration of the OneTrace project, with delivery statuses in the customer profile; order emails — from OneTrace templates in the customer's language, including shipped and delivered emails WooCommerce does not have. Emails to the shop owner and staff and whatever OneTrace does not accept, the store sends itself.

HPOS and the Cart and Checkout blocks are supported.

= External service =

The plugin sends data to the OneTrace.pro platform at the address you enter in the settings (your own brand domain or cdp.onetrace.pro): customer data (email, phone, name, city, country, language), consents, orders with their shipments (carrier, tracking number and link), cart and wishlist events and the product catalog with stock from the server; with the loyalty program — the customer id and the cart amount to show and hold bonus points, the friend's referral code on registration; with promo codes the plugin reads the codes of the bound pools; when store emails are turned on in the OneTrace project, the emails the store sends (recipient, subject, content, attachments, the order data of order emails, the customer's first name and language); page and product views from the visitor's browser; with product search or the search widget, the search queries of visitors and their tracker id; with the AI chat bot (store MCP) turned on, the platform calls the store for a customer confirmed in the chat — their orders, parcel tracking, stock and prices, delivery methods and the return policy, the actions you allowed — and the store emails confirmation codes to the customer. Terms of service: https://onetrace.pro/en/legal/terms, privacy policy: https://onetrace.pro/en/legal/privacy.

== Installation ==

1. In Plugins → Add New Plugin search for "OneTrace for WooCommerce", install and activate it.
2. In your OneTrace project create a write key and a secret key with the products.write permission (API keys section).
3. Go to WooCommerce → Settings → OneTrace, enter the platform address and both keys, click "Test connection".

== Frequently Asked Questions ==

= Which wishlist plugins are supported? =

YITH WooCommerce Wishlist and TI WooCommerce Wishlist: adding and removing products is sent from the server (setting "Wishlist"). With another wishlist, put `data-cdp-wishlist="{product id}"` on the "Add to wishlist" button of the theme and `data-cdp-wishlist-remove="{product id}"` on the "Remove" button — the OneTrace tracker (1.13.0 and later) sends the events on click. The product id is the one of the catalog: the post ID, or the SKU with "Product id → SKU".

= Which shipment tracking plugins are supported? =

WooCommerce Shipment Tracking, Advanced Shipment Tracking and TrackShip: every tracking item added to the order is sent as order_shipped with the carrier, the tracking number and the link; the "Delivered" status of AST and TrackShip is order_delivered. Without a tracking plugin the "Completed" status is one order_shipped without tracking. Other tracking plugins can pass their parcels with the onetrace_woocommerce_order_shipments filter (see the README on GitHub).

= What does the OneTrace search widget change? =

With the setting "OneTrace search widget" on and the widget turned on in OneTrace → Site → Search, the product search page keeps the header and footer of your theme (classic or block theme) and shows the results widget of OneTrace instead of the store's results: facets, sorting, infinite scroll and an "Add to cart" button that adds to the WooCommerce cart and refreshes the mini-cart. Products with options open their page. The widget needs the tracker; while it is off in OneTrace, OneTrace is unavailable or the visitor has not agreed to statistics cookies (WP Consent API), the search page stays as it is.

= How does "Notify when available" work? =

On the page of a product that is out of stock (a variable product — when no variation is in stock) the plugin puts `<div data-cdp-notify="{product id}"></div>` after the add to cart area; the tracker draws the form there. The form itself is set up in OneTrace → Site → Forms (kind "Notify when available"); without an active form of this kind nothing is shown. The setting "Notify when available" turns the place off.

= Can the store's emails go through OneTrace? =

Yes, in the "Store emails" section of the OneTrace project: all emails sent with wp_mail() as the store made them, and order emails (placed, paid, shipped, delivered, cancelled, refunded) from OneTrace templates in the customer's language; emails to the shop owner and staff always go out from the store. The secret key needs the "Send messages manually" permission; WooCommerce → Settings → OneTrace shows the state. If OneTrace does not accept an email (no email integration, the quota, the limit per recipient, the platform unavailable), the store sends it itself.

= Can the AI chat bot see orders of other customers? =

No. The bot asks the store only for orders of a confirmed customer: signed in on the website, or confirmed with a code the shop sends to the email of the orders. An order number of someone else answers "not found".

= Do I need an account? =

Yes, a OneTrace.pro project (or a project at a brand that runs the platform).

= What happens if the platform is unavailable? =

Messages wait in the plugin's queue and are sent later; checkout is never slowed down.

== Screenshots ==

1. Settings: the platform address, the keys and what the store sends to OneTrace.
2. Recommendation blocks from OneTrace on the product page: viewed together and similar products.
3. Product search: suggestions while typing, with typos and synonyms handled by the platform.
4. The customer profile in OneTrace: identities, traits and predictions, segments, messages and the event history from the store.

== Changelog ==

= 1.13.0 =
* The OneTrace search widget (setting "OneTrace search widget", off by default): the product search page shows the results widget of OneTrace inside the theme — classic and block themes — with facets, sorting, infinite scroll and an "Add to cart" button; the search fields open the search layer of the widget. The store does not search on the server in this mode. While the widget is off in OneTrace or OneTrace is unavailable, the search page stays as it is.
* "Add to cart" of the widget adds to the WooCommerce cart with the usual stock and quantity checks and refreshes the mini-cart (classic cart fragments and the Mini-Cart block); products with options open their page.
* Product search over the monthly search limit of the plan falls back to the WordPress search right away.

= 1.12.0 =
* My account → Newsletters: subscription topics of the OneTrace project by channel (email, SMS, messengers, browser notifications), how often marketing messages come (as they come, at most once a week or a month), a pause for 30 or 90 days and "Unsubscribe from everything". Turn it on in WooCommerce → Settings → OneTrace → Newsletter consent; the secret key needs the profiles.read and profiles.write permissions.

= 1.11.0 =
* The AI chat bot of OneTrace works with the store over MCP: order status and contents, parcel tracking, stock and prices, delivery methods and the return policy — live from WooCommerce. Turn it on in WooCommerce → Settings → OneTrace → AI chat bot: store MCP.
* Customers signed in on the website are confirmed for the bot automatically; guests and messenger customers get a code from the shop by email.
* Actions are off by default: allow the bot to cancel unpaid orders and to pass return and delivery change requests to the shop (an order note and an email), each only after the customer agreed in the chat.

= 1.10.0 =
* Store emails through OneTrace: with the "Store emails" section of the OneTrace project, emails of WooCommerce, WordPress and other plugins (wp_mail) go out through the project's email integration as the store made them — subject, HTML, text, reply-to, sender name and attachments — one request per recipient; the store sends whatever OneTrace does not accept itself, only to those recipients. Emails to the shop owner and staff (admin emails, copies) are always sent by the store.
* Order emails from OneTrace templates: order data (products with images and links, totals, addresses, tracking, refund) and the customer's language instead of the store's email; shipped and delivered emails from the shipment hooks in the background, once per parcel and per order.
* WooCommerce → Settings → OneTrace shows the state of the store emails.

= 1.9.0 =
* Shipments: order_shipped with the carrier, tracking number and tracking link for every tracking item of WooCommerce Shipment Tracking, Advanced Shipment Tracking and TrackShip — once per parcel, a second parcel is a new event. Without tracking, the "Completed" or "Shipped" status is one order_shipped without tracking when no parcel was sent.
* Delivered orders: order_delivered from the "Delivered" status of AST and TrackShip, or when TrackShip reports every parcel of the order delivered.
* Other tracking plugins: the onetrace_woocommerce_order_shipments filter and the onetrace_woocommerce_sync_shipments action.

= 1.8.0 =
* Stock: the catalog carries the stock of products that track it without backorders (a variable product — the sum of its variations), and a product is uploaded again when its stock changes — orders, cancellations, refunds, the admin and the REST API. The platform uses it with "Use stock levels from the API and plugins" in its catalog settings.
* Wishlist: adding to and removing from the wishlist of YITH WooCommerce Wishlist and TI WooCommerce Wishlist is sent from the server (add_to_wishlist, remove_from_wishlist); other wishlists — with data-cdp-wishlist attributes on the theme's buttons.
* "Notify when available": the place of the OneTrace form on the page of a product that is out of stock (setting, on by default).

= 1.7.0 =
* Promo codes: unique codes of OneTrace promo code pools become WooCommerce coupons every 5 minutes, with the discount, restrictions and expiry of a template coupon, one use each.
* Referrals: the friend's code from a referral link (?ref=, kept by the tracker) or from the optional "Friend's code" field goes to the platform on registration — the friend gets the welcome reward.
* My account → Bonus points: the referral link with a copy button, friends who came by it, active promo codes of the customer.

= 1.6.0 =
* Bonus points of the OneTrace loyalty program: the balance and paying for part of the order with points in the cart and at checkout (classic and block), confirmed with the order number; returned when the order is cancelled or refunded.
* My account → Bonus points: balance, pending points, tier, points expiring soon and the history; joining the program.

= 1.5.1 =
* With an older copy of the plugin still active (a zip from GitHub, 1.4.0 and earlier), the plugin starts once: the older copy no longer boots it again.

= 1.5.0 =
* Published on wordpress.org as onetrace-for-woocommerce: the plugin folder and the text domain are onetrace-for-woocommerce, updates come from the plugin directory.
* Installed twice (this copy and an older zip from GitHub in the onetrace-woocommerce folder), the second copy stays off and asks to delete it; settings are shared.

= 1.4.0 =
* Search redirects: a query with a redirect rule set on the platform opens its page instead of the results.

= 1.3.1 =
* Search on the other languages of TranslatePress uses OneTrace too: TranslatePress emptied the query for its own search.

= 1.3.0 =
* Catalog languages: with TranslatePress, WPML or Polylang the catalog carries the translated names, links, categories and attributes, so recommendations, search and emails show the visitor's language.

= 1.2.0 =
* Product search from OneTrace (the "Product search" setting, off by default): the results of the store search keep the theme template but take the products and their order from the platform; the search box gets suggestions while typing. On any error of the platform the usual WordPress search runs.

= 1.1.1 =
* The newsletter checkbox of the classic checkout is under the billing fields: in the order review it lost the tick when the customer changed the country or the email.

= 1.1.0 =
* Setting "Product id": the SKU instead of the post ID in events, orders, the catalog and widgets — for stores whose OneTrace catalog and history already use SKUs.

= 1.0.2 =
* Errors outside the platform's answers (database, building catalog items) are retried with pauses instead of dropping the data.

= 1.0.1 =
* Links to the terms of service and the privacy policy of the external service.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.13.0 =
The OneTrace search widget on the search page of your theme: facets, sorting, infinite scroll and "Add to cart".

= 1.12.0 =
My account → Newsletters: subscription topics by channel, frequency, a pause and unsubscribing from everything.

= 1.9.0 =
Shipped and delivered orders with tracking numbers from WooCommerce Shipment Tracking, Advanced Shipment Tracking and TrackShip.

= 1.8.0 =
Stock in the catalog, wishlist events of YITH and TI wishlists, the "Notify when available" form on products out of stock.

= 1.5.1 =
If an older copy from GitHub (folder onetrace-woocommerce) is still active, the plugin starts once; deactivate and delete that copy.

= 1.5.0 =
The plugin now comes from wordpress.org (folder onetrace-for-woocommerce). If you installed a zip from GitHub before, deactivate and delete that copy (folder onetrace-woocommerce) after installing this one: settings and the queue are kept.
