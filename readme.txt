=== WC Konsol Connector ===
Contributors: wpkonsol
Tags: woocommerce, seo, ai, content
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects a WooCommerce store to WC Konsol for SEO field sync, sales totals, and reliable revision checks.

== Description ==

WC Konsol (wckonsol.com) generates and publishes AI product content for WooCommerce stores. Catalog sync
and product content/image publishing work over WooCommerce's own REST API alone — this plugin is optional
and only needed for the small set of things core REST cannot do:

* Writing Yoast SEO / Rank Math meta title and description
* Reading daily sales totals
* A reliable, plugin-reported revision signal (in addition to the hash-based check WC Konsol already does over REST)

Install it, go to Settings → WC Konsol, generate a pairing code in WC Konsol's Stores screen, and paste it in.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wckonsol-connector`, or install via the WordPress plugin screen.
2. Activate the plugin.
3. Go to Settings → WC Konsol.
4. In WC Konsol, open Stores → your store → "Connect with the plugin" and copy the pairing code.
5. Paste the code and click Connect.

== Changelog ==

= 0.1.0 =
* Initial release: pairing, heartbeat, site-info/capabilities, Yoast/Rank Math SEO field writes.
