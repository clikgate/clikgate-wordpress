=== Clikgate ===
Contributors: clikgate
Tags: attribution, payments, woocommerce, analytics, conversions
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Confirms payments from WooCommerce and popular payment-form plugins to your Clikgate Payment goal, matched to the click that brought the customer. No keys to copy.

== Description ==

Clikgate for WordPress connects your site to your Clikgate organisation and:

* **Connects without copying keys.** Click "Connect to Clikgate", approve the short code in the Clikgate dashboard, done. The site receives a plugin key that can only send payment results and goals and read its own settings.
* **Adds the Clikgate web script** to every page, with your web key. No theme editing. The tag is the same for every visitor, so page-caching plugins keep working.
* **Confirms payments for your Payment goal** after your own payment plugin has marked them paid: WooCommerce, Gravity Forms, Easy Digital Downloads, Fluent Forms, WPForms (Stripe), WP Simple Pay, MemberPress and Paid Memberships Pro. Each result carries the Clikgate click ID saved when the order or entry was created. Refunds are sent where the plugin documents a refund hook.
* **Shows your App banners** (optional, Growth plan and up): one setting adds the Clikgate App banners loader, which invites visitors on phones to open your app, or to install it and then open the same product. Banners never show on checkout, cart, order and payment pages. An Owner or Admin approves it once in the Clikgate dashboard.
* **Never touches your gateway keys.** Clikgate doesn't need your Paystack, Flutterwave, Stripe or any other secret key. It never charges, refunds or moves money.

Settings › Clikgate lets you choose the goal each plugin, form, product or membership level records (usually a Payment goal), send a test, and see the last results.

= What leaves your site =

For each confirmed payment: the gateway name, the gateway's payment reference, the status (paid or refunded), the amount and currency, the time, the order or entry number (left out when your Clikgate organisation uses private mode) and the Clikgate click ID. No names, email addresses, phone numbers, addresses or card data.

With App banners on, every page also gets the App banners loader (from api.clikgate.com) and a small page description: the page type and, on product pages, the product ID, SKU, name, slug and category slugs. No prices, cart contents or anything about the visitor.

This plugin connects to the Clikgate service (api.clikgate.com) and loads the Clikgate web script from sdk.clikgate.com. See the [Clikgate terms](https://clikgate.com/legal/terms) and [privacy policy](https://clikgate.com/legal/privacy).

== Installation ==

1. Install and activate the plugin.
2. Go to Settings › Clikgate and click "Connect to Clikgate".
3. Approve the code in the Clikgate dashboard (you need owner or admin access). Choose live or test.
4. Back in WordPress, pick the goal each plugin records and click "Send a test".

== Frequently Asked Questions ==

= Do I need to paste an API key? =

No. The connection uses a one-time code that you approve in Clikgate.

= Which payment plugins are supported? =

Only plugins whose official documentation describes a reliable "payment completed" hook: WooCommerce (any gateway plugin, including the official Paystack and Flutterwave plugins), Gravity Forms payment add-ons, Easy Digital Downloads, Fluent Forms payments, WPForms with Stripe, WP Simple Pay, MemberPress and Paid Memberships Pro.

= What is a Payment goal? =

A goal in Clikgate for anything involving money. A Payment goal counts only when your payment provider or server confirms the payment, with the real amount. This plugin is one of those trusted sources. Payment goals need the Starter plan or above.

= Is a payment counted when the customer reaches the thank-you page? =

No. A Payment goal counts a payment only when a trusted source confirms it. This plugin sends the result only after your payment plugin has marked the payment as paid.

= Does it work with caching plugins? =

Yes. The plugin adds the same script tag to every page and never changes cached pages per visitor.

= How long is the click ID kept on Safari? =

Safari, and every browser on iPhone, keeps a click ID saved by a script for up to 7 days (sometimes less). When your server saves it, it lasts as long as you choose, up to the browser's limit. The plugin does this for you: when a visitor arrives from a Clikgate link, the web script asks your own site (the REST route `/wp-json/clikgate/v1/keep`) to save the click ID in a 30-day cookie. The cookie is readable by the page on purpose, because the web script reads it. It is a REST request, not a cookie set while the page is built, so page caches never share one visitor's cookie. It is on by default; turn it off under Settings › Clikgate › "Keep the click ID from your server". Developers can change the 30 days with the `clikgate_keep_days` filter (1–400).

= How do App banners work with WooCommerce? =

Turn on "Show my App banners on this site" under Settings › Clikgate. Clikgate asks an Owner or Admin to approve it in the dashboard (with a security code), and your site must be verified in Clikgate first. Banners are designed in the Clikgate dashboard. On product pages the banner passes the product to your app; if your app can't open the page, it opens your app's home screen. Turning the setting off removes the banners from your site and keeps your settings in Clikgate.

== Changelog ==

= 1.0.0 =
* First release of Clikgate for WordPress: device sign-in, automatic web script, Payment goal confirmations from WooCommerce and supported payment-form plugins, goal mapping, "Send a test" and a status panel.
* App banners: one setting adds the App banners loader with WooCommerce-aware page types and product data, never on checkout, cart, order or payment pages.
