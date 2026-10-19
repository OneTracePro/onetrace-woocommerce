# OneTrace.pro for WooCommerce

[![CI](https://github.com/OneTracePro/onetrace-woocommerce/actions/workflows/ci.yml/badge.svg)](https://github.com/OneTracePro/onetrace-woocommerce/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

Connects a WooCommerce store to [OneTrace.pro](https://onetrace.pro), the customer data platform: orders, customers and newsletter consents from the server, the website tracker, the product catalog, recommendation widgets and Web Push. Abandoned cart, browse abandonment, post-purchase and win-back journeys, product recommendations and predictions work right after you connect the store.

[Русская версия](README.ru.md)

- WordPress 6.6+, WooCommerce 9.0+ (tested up to 11.2), PHP 7.4–8.5; HPOS and the Cart and Checkout blocks are supported
- Orders and their statuses are sent from the server — not lost to ad blockers or closed tabs — through a queue with retries: the store never waits for the platform
- The same events and properties as every OneTrace shop plugin ([e-commerce contract](https://onetrace.pro/en/docs/api))

## What it sends

| Data | When | How |
|---|---|---|
| `identify` — email, phone, name, city, country, language | registration, login, profile and address changes, checkout | server, secret key |
| Newsletter consent | the checkbox at checkout (classic and block), in the registration form and on the account page | server, with `identify` |
| `order_completed`, `order_paid`, `order_cancelled`, `order_refunded` | order placed, paid (cash on delivery — when completed), cancelled, refunded | server, once per order event |
| `order_shipped`, `order_delivered` | a parcel with tracking (WooCommerce Shipment Tracking, Advanced Shipment Tracking, TrackShip) or the order completed without tracking; the "Delivered" status | server, once per parcel / order |
| `add_to_cart`, `remove_from_cart`, `checkout_started` | cart changes and the checkout page, for visitors with the tracker cookie or signed in | server |
| `add_to_wishlist`, `remove_from_wishlist` | the wishlist of YITH WooCommerce Wishlist or TI WooCommerce Wishlist, for the same visitors | server |
| `product_viewed`, page views | the storefront | browser tracker |
| Product catalog with `stock` | product and stock changes (orders, cancellations, refunds, the admin, REST), daily, and the "Upload the whole catalog" button | server, `products.write` |

Guest orders without the tracker cookie are linked by email: the identify and the order events of a guest land in one profile.

By default `product_id` is the post ID. If the catalog and history of your project already use SKUs (for example after moving from another platform), set **Product id → SKU**: events, orders, the catalog and widgets then use the SKU (the post ID for products without one).

## Installation

1. Install **OneTrace for WooCommerce** from the WordPress plugin directory (**Plugins → Add New Plugin**, [wordpress.org/plugins/onetrace-for-woocommerce](https://wordpress.org/plugins/onetrace-for-woocommerce/)), or upload `onetrace-for-woocommerce.zip` from the [latest release](https://github.com/OneTracePro/onetrace-woocommerce/releases/latest) in **Plugins → Add New → Upload Plugin**.
2. In your OneTrace project, open **API keys** and create a write key (for the storefront) and a secret key with the `products.write` permission (for the server).
3. In **WooCommerce → Settings → OneTrace** enter the platform address (the domain you open OneTrace at) and both keys, then click **Test connection**.

The keys can be kept out of the database in `wp-config.php`:

```php
define('ONETRACE_URL', 'https://cdp.onetrace.pro'); // or the domain of your white-label brand
define('ONETRACE_WRITE_KEY', 'cdp_wk_…');
define('ONETRACE_SECRET_KEY', 'cdp_sk_…');
```

## Stock, wishlist and "Notify when available"

- **Stock:** products that manage stock carry `stock` in the catalog (a variable product — the sum of its variations that manage stock); products without stock management or with backorders allowed are sent without it. The platform uses the stock — a zero stock takes the product off sale, "low stock" and "back in stock" journeys — only with **Use stock levels from the API and plugins** in its catalog settings.
- **Wishlist:** WooCommerce has no wishlist of its own. With [YITH WooCommerce Wishlist](https://wordpress.org/plugins/yith-woocommerce-wishlist/) or [TI WooCommerce Wishlist](https://wordpress.org/plugins/ti-woocommerce-wishlist/) adding and removing products is sent from the server (setting **Wishlist**). With another wishlist, mark the buttons of the theme for the tracker (1.13.0 and later), which sends the events on click:

  ```html
  <button data-cdp-wishlist="123">Add to wishlist</button>
  <button data-cdp-wishlist-remove="123">Remove from wishlist</button>
  ```

  `123` is the product id of the catalog: the post ID, or the SKU with **Product id → SKU**.
- **Notify when available:** on the page of a product that is out of stock (a variable product — when no variation is in stock) the plugin puts `<div data-cdp-notify="{product id}"></div>` after the add to cart area (classic templates and block themes). The tracker (1.14.0 and later) draws the form there; the form itself is set up in **Site → Forms** of the platform (kind "Notify when available"). The setting **Notify when available** turns it off.

## Shipping and delivery

The platform shows the carrier, the tracking number and the tracking link to the customer and to the AI of the online chat ("where is my order?"). WooCommerce itself has no parcels, so the plugin reads them from the tracking plugins:

- **Tracking plugins** — [WooCommerce Shipment Tracking](https://woocommerce.com/products/shipment-tracking/), [Advanced Shipment Tracking](https://wordpress.org/plugins/woo-advanced-shipment-tracking/) and [TrackShip](https://wordpress.org/plugins/trackship-for-woocommerce/) on top of them: every tracking item added to the order is one `order_shipped` with `carrier` (the provider name of the plugin, or the custom provider), `tracking_number` and `tracking_url` (the provider link with the number, or the custom link). A second parcel is a new event; saving the order again sends nothing. Products of a partial shipment are sent when the plugin keeps them (AST PRO).
- **Without tracking:** when the order becomes **Completed** (fulfilled) or **Shipped** (a status of tracking plugins), one `order_shipped` without tracking — only if no parcel was sent for the order and it has products to ship.
- **Delivered:** the **Delivered** status of AST or TrackShip is `order_delivered`; with TrackShip also its shipment status "delivered" once every parcel of the order is delivered. WooCommerce has no delivered status of its own, so without these plugins the event is not sent.

Another tracking plugin can pass its parcels with the `onetrace_woocommerce_order_shipments` filter and tell the plugin about a new parcel:

```php
add_filter('onetrace_woocommerce_order_shipments', function (array $shipments, WC_Order $order): array {
    foreach (my_tracking_parcels($order) as $parcel) {
        $shipments[] = [
            'carrier' => $parcel->carrier,                // the name the customer sees
            'tracking_number' => $parcel->number,
            'tracking_url' => $parcel->url,
            'shipped_at' => $parcel->shipped_at,          // timestamp or DateTimeInterface
            'estimated_delivery' => $parcel->eta,         // 'YYYY-MM-DD', optional
            'items' => [$orderItemId => 1],               // partial shipment: order item id => quantity, optional
        ];
    }

    return $shipments;
}, 10, 2);

do_action('onetrace_woocommerce_sync_shipments', $order); // after a parcel is saved
```

Both events follow the **Orders** setting.

## Recommendations, search and Web Push

- Widgets from **Site → Widgets** of the platform: set their ids for the product page and the cart in the settings, or put `[onetrace_widget id="k3x9…"]` anywhere. On product, category and cart pages the current products are passed to the widget.
- Product search: turn on "Product search" (the plan of the OneTrace project must include it). The product search of the store (`?s=…&post_type=product`, the search box of the theme and the product search block) keeps the theme template, but its products, their order and the number of pages come from OneTrace: word forms, typos, the wrong keyboard layout, the visitor's interests. The search boxes get suggestions while typing (products, categories, popular queries). The results page sends the `search` event from the server. On any API error or after 3 seconds the usual WordPress search runs.
- Web Push: turn it on in the settings — the plugin serves `/cdp-sw.js` from the store root (pretty permalinks are required) — and put `[onetrace_push_button]` where visitors subscribe.

## Loyalty program

Turn on "Bonus points" in **Settings → OneTrace → Loyalty program**; the program itself (earning, tiers, the share of an order payable with points) is set up in the **Loyalty** section of the platform, and the secret key needs the `loyalty.read` and `loyalty.write` permissions.

- **Cart and checkout** (classic pages and the Cart and Checkout blocks): a signed-in customer sees the balance, how many points the cart can take and the points for the order, enters the points and clicks "Use points". The points are held on the platform for 30 minutes and the discount is a negative fee in the totals; when the cart changes, the points are held again within the allowed share. Guests see a hint to sign in.
- **Order:** the hold is confirmed with the order number when the order is placed; if the platform is unavailable, Action Scheduler retries. Cancelled and refunded orders return the points on the platform (the order events).
- **My account → Bonus points:** the balance, points waiting for the return period, the tier, points expiring soon and the latest operations; "Join the program" for programs with explicit enrollment; the referral link with a copy button and the friends who came by it; active promo codes of the customer.
- **Referrals:** turn on referrals in the platform (Loyalty → Referrals). On registration the friend's code — from a `?ref=` link (the tracker keeps it in the `cdp_ref` cookie for 30 days) or from the optional "Friend's code" field (setting "Referrals") — goes to the platform, and the friend gets the welcome reward; the member gets points for the friend's first order.

Customers are found on the platform by `user_id` (the WordPress user id), like in the order events.

## Promo codes

Pools of promo codes are set up in the **Promo codes** section of the platform; the discount is a coupon of the store. Create a template coupon in **Marketing → Coupons** (discount, products and categories, minimum amount, individual use…) and bind it to the pool in **Settings → OneTrace → Promo codes**, one per line:

```
welcome = WELCOME-TEMPLATE
```

Every 5 minutes new and changed unique codes of the pool become coupons with the settings of the template, one use each and the expiry of the code (Action Scheduler, `onetrace_promo_sync`). Coupons of the store with the same code are not touched; pools with one shared code are not synced — create that coupon yourself. The order events carry the coupon, so the platform counts the use. The secret key needs `promo.read`.

## Privacy

- With a consent plugin that supports the [WP Consent API](https://wordpress.org/plugins/wp-consent-api/), the tracker loads after the visitor agrees to statistics cookies (can be turned off in the settings).
- A consent is sent only when the customer ticks the checkbox; unticking it on the account page unsubscribes.
- Data already sent stays in the OneTrace project; delete profiles there (GDPR).

## WP-CLI

```bash
wp onetrace test           # checks both keys
wp onetrace status         # queue and last error
wp onetrace flush          # sends what is waiting now
wp onetrace sync-catalog   # uploads the whole catalog now
```

## Hooks

- `onetrace_woocommerce_client_options` — options of the [onetrace-php](https://github.com/OneTracePro/onetrace-php) client (e.g. timeouts or a custom transport).
- `onetrace_woocommerce_tracker_options` — options of `cdp.init()` on the storefront.
- `onetrace_woocommerce_paid_statuses` — order statuses that mean "paid".
- `onetrace_woocommerce_order_shipments` — parcels of the order for `order_shipped`; `do_action('onetrace_woocommerce_sync_shipments', $order)` sends the new ones.
- `onetrace_woocommerce_shipped_statuses` (`completed`, `shipped`) and `onetrace_woocommerce_delivered_statuses` (`delivered`) — order statuses of a shipment without tracking and of the delivery.

## Development

```bash
composer install
tests/env/run.sh                                            # integration tests: latest WordPress and WooCommerce in Docker
WP_TAG=6.8-php8.1-apache WC_VERSION=9.9.5 tests/env/run.sh  # older versions
vendor/bin/phpstan analyse
php bin/build.php && tests/env/smoke.sh                     # dist/onetrace-for-woocommerce.zip and a smoke test over cURL
```

The build bundles the onetrace-php library under the plugin's namespace (`OneTrace\WooCommerce\Vendor\OneTrace`), so another plugin with a different version of the library cannot conflict with it.

## Releases

The plugin is published at [wordpress.org/plugins/onetrace-for-woocommerce](https://wordpress.org/plugins/onetrace-for-woocommerce/) (SVN `plugins.svn.wordpress.org/onetrace-for-woocommerce`) by CI:

1. Raise the version in the plugin header, `ONETRACE_WC_VERSION` and `Stable tag` of `readme.txt`; add the changelog to `CHANGELOG.md` and `readme.txt`.
2. Push a tag `vX.Y.Z` and create the GitHub release. After the tests and the smoke test the build goes to `trunk` and `tags/X.Y.Z` of the wordpress.org SVN (job **Publish to wordpress.org**, environment `wordpress.org` with the secrets `SVN_USERNAME` and `SVN_PASSWORD` — the SVN password of the wordpress.org account), the zip — to the GitHub release.
3. `readme.txt` (for example "Tested up to") and the page images in `.wordpress-org/` (banners, icons, screenshots) follow `main` without a release (workflow **wordpress.org readme and images**) once the stable version is published.

## License

GPL-2.0-or-later. The bundled onetrace-php library is MIT.
