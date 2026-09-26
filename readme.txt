=== Konsol Connector ===
Contributors: wpkonsol
Tags: woocommerce, seo, ai, content
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects a WordPress/WooCommerce site to Konsol (WC Konsol and/or WP Konsol) for SEO field sync, sales totals, and reliable revision checks.

== Description ==

Konsol generates and publishes AI content — product content for WooCommerce stores (WC Konsol) and blog
posts for any WordPress site (WP Konsol). Catalog/content sync and image publishing work over core REST
alone — this plugin is optional and only needed for the small set of things core REST cannot do:

* Writing Yoast SEO / Rank Math meta title and description — for WooCommerce products/categories AND WP Konsol blog posts
* Reading daily sales totals (WooCommerce sites)
* A reliable, plugin-reported revision signal (in addition to the hash-based check Konsol already does over REST)

No WooCommerce dependency — works on a plain WordPress site too. Install it, go to the "Konsol" menu, generate
a pairing code from WC Konsol's Stores screen or WP Konsol's Sites screen, and paste it in.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wckonsol-connector`, or install via the WordPress plugin screen.
2. Activate the plugin.
3. Go to the "Konsol" menu.
4. In WC Konsol (Stores → your store) or WP Konsol (Sites → your site), open "Connect with the plugin" and copy the pairing code.
5. Paste the code and click Connect.

== Changelog ==

= 0.2.0 =
* Extended to WP Konsol: new `/posts/:id/seo` endpoint writes Yoast/Rank Math meta for blog posts, not just WooCommerce products/categories.
* Dropped the WooCommerce requirement — the plugin now activates on a plain WordPress site.
* Settings page and menu label are no longer WooCommerce-only worded.

= 0.1.0 =
* Initial release: pairing, heartbeat, site-info/capabilities, Yoast/Rank Math SEO field writes.
