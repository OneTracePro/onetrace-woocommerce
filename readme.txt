=== OneTrace for WooCommerce ===
Contributors: onetrace
Tags: woocommerce, cdp, marketing automation, abandoned cart, recommendations
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 9.0
WC tested up to: 11.2
Stable tag: 1.5.1
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

1. In Plugins → Add New Plugin search for "OneTrace for WooCommerce", install and activate it.
2. In your OneTrace project create a write key and a secret key with the products.write permission (API keys section).
3. Go to WooCommerce → Settings → OneTrace, enter the platform address and both keys, click "Test connection".

== Frequently Asked Questions ==

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

= 1.5.1 =
If an older copy from GitHub (folder onetrace-woocommerce) is still active, the plugin starts once; deactivate and delete that copy.

= 1.5.0 =
The plugin now comes from wordpress.org (folder onetrace-for-woocommerce). If you installed a zip from GitHub before, deactivate and delete that copy (folder onetrace-woocommerce) after installing this one: settings and the queue are kept.
