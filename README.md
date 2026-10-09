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
| `add_to_cart`, `remove_from_cart`, `checkout_started` | cart changes and the checkout page, for visitors with the tracker cookie or signed in | server |
| `product_viewed`, page views | the storefront | browser tracker |
| Product catalog | product changes, daily, and the "Upload the whole catalog" button | server, `products.write` |

Guest orders without the tracker cookie are linked by email: the identify and the order events of a guest land in one profile.

By default `product_id` is the post ID. If the catalog and history of your project already use SKUs (for example after moving from another platform), set **Product id → SKU**: events, orders, the catalog and widgets then use the SKU (the post ID for products without one).

## Installation

1. Download `onetrace-woocommerce.zip` from the [latest release](https://github.com/OneTracePro/onetrace-woocommerce/releases/latest) and upload it in **Plugins → Add New → Upload Plugin**.
2. In your OneTrace project, open **API keys** and create a write key (for the storefront) and a secret key with the `products.write` permission (for the server).
3. In **WooCommerce → Settings → OneTrace** enter the platform address (the domain you open OneTrace at) and both keys, then click **Test connection**.

The keys can be kept out of the database in `wp-config.php`:

```php
define('ONETRACE_URL', 'https://cdp.onetrace.pro'); // or the domain of your white-label brand
define('ONETRACE_WRITE_KEY', 'cdp_wk_…');
define('ONETRACE_SECRET_KEY', 'cdp_sk_…');
```

## Recommendations, search and Web Push

- Widgets from **Site → Widgets** of the platform: set their ids for the product page and the cart in the settings, or put `[onetrace_widget id="k3x9…"]` anywhere. On product, category and cart pages the current products are passed to the widget.
- Product search: turn on "Product search" (the plan of the OneTrace project must include it). The product search of the store (`?s=…&post_type=product`, the search box of the theme and the product search block) keeps the theme template, but its products, their order and the number of pages come from OneTrace: word forms, typos, the wrong keyboard layout, the visitor's interests. The search boxes get suggestions while typing (products, categories, popular queries). The results page sends the `search` event from the server. On any API error or after 3 seconds the usual WordPress search runs.
- Web Push: turn it on in the settings — the plugin serves `/cdp-sw.js` from the store root (pretty permalinks are required) — and put `[onetrace_push_button]` where visitors subscribe.

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

## Development

```bash
composer install
tests/env/run.sh                                            # integration tests: latest WordPress and WooCommerce in Docker
WP_TAG=6.8-php8.1-apache WC_VERSION=9.9.5 tests/env/run.sh  # older versions
vendor/bin/phpstan analyse
php bin/build.php && tests/env/smoke.sh                     # dist/onetrace-woocommerce.zip and a smoke test over cURL
```

The build bundles the onetrace-php library under the plugin's namespace (`OneTrace\WooCommerce\Vendor\OneTrace`), so another plugin with a different version of the library cannot conflict with it.

## License

GPL-2.0-or-later. The bundled onetrace-php library is MIT.
