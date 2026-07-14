=== CommerceOps Woo AI Schema ===
Contributors: rovidev
Tags: woocommerce, schema, seo, structured-data, json-ld
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatic Product + Offer + Review JSON-LD for WooCommerce. AI shopping readiness score included.

== Description ==

CommerceOps Woo AI Schema outputs clean schema.org markup on WooCommerce product pages:

* Product schema with name, description, SKU, images
* Offer schema with price, currency, availability
* AggregateRating when reviews exist
* Organization schema on homepage (optional)

**Pro features** (license from commerceops.io):

* GTIN / brand fields
* FAQPage schema from product content
* BuyAction for agent commerce
* Full AI readiness export

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the zip.
2. Activate through the 'Plugins' menu.
3. Go to WooCommerce → Woo AI Schema to view your AI readiness score.

== Frequently Asked Questions ==

= Does WooCommerce include schema by default? =

No. WooCommerce does not emit complete product schema out of the box.

= How do I activate Pro? =

Purchase at commerceops.io and enter your license key on the plugin settings page.

== Changelog ==

= 1.0.1 =
* BreadcrumbList schema on product/category pages
* WordPress.org assets (.wordpress-org/)
* Deploy script for SVN

= 1.0.0 =
* Initial release: Product, Offer, AggregateRating, Organization schema
* AI readiness dashboard
* Lemon Squeezy license integration
