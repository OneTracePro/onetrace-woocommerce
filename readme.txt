=== OneTrace for WooCommerce ===
Contributors: onetrace
Tags: woocommerce, cdp, marketing automation, abandoned cart, recommendations
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 9.0
WC tested up to: 11.2
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects WooCommerce to the OneTrace.pro customer data platform: orders, customers, consents, catalog, recommendations and Web Push.

== Description ==

OneTrace.pro is a customer data platform: it builds customer profiles from your store's events, segments them and sends emails, SMS, messenger and push messages with journeys and campaigns.

This plugin connects your WooCommerce store:

* Orders and their statuses from the server, through a queue with retries — not lost to ad blockers, and the store never waits for the platform.
* Customers and newsletter consents from registration, the account page and the checkout (classic and block).
* Cart events for abandoned cart journeys.
* The website tracker with product views; waits for the statistics consent with the WP Consent API.
* The product catalog for recommendations and emails.
* Recommendation widgets and Web Push.
* Product search: the store search results and suggestions while typing come from OneTrace — word forms, typos, the wrong keyboard layout and the visitor's interests; the usual search on any error.
* Catalog languages from TranslatePress, WPML and Polylang: recommendations, search and emails in the visitor's language.

HPOS and the Cart and Checkout blocks are supported.

= External service =

The plugin sends data to the OneTrace.pro platform at the address you enter in the settings (your own brand domain or cdp.onetrace.pro): customer data (email, phone, name, city, country, language), consents, orders, cart events and the product catalog from the server; page and product views from the visitor's browser. Terms of service: https://onetrace.pro/en/legal/terms, privacy policy: https://onetrace.pro/en/legal/privacy.

== Installation ==

1. Install and activate the plugin.
2. In your OneTrace project create a write key and a secret key with the products.write permission (API keys section).
3. Go to WooCommerce → Settings → OneTrace, enter the platform address and both keys, click "Test connection".

== Frequently Asked Questions ==

= Do I need an account? =

Yes, a OneTrace.pro project (or a project at a brand that runs the platform).

= What happens if the platform is unavailable? =

Messages wait in the plugin's queue and are sent later; checkout is never slowed down.

== Changelog ==

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
