=== Wahou Commerce ===
Contributors: thierrygustin
Tags: ecommerce, stripe, paypal, eu vat, digital downloads
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frictionless e-commerce: mobile-first, fast, SEO & GEO ready, Stripe and PayPal in two minutes, automatic EU VAT, upsells and abandoned cart recovery.

== Description ==

Wahou Commerce removes the friction points of selling online with WordPress, for physical and digital products.

**Speed**

* Session-less cart: catalog pages stay fully cacheable.
* Dedicated indexed tables for orders, carts, downloads and invoices.
* Small minified assets loaded only on store pages; Stripe.js only on checkout; PayPal SDK loaded on demand.

**Conversion & UX (mobile-first)**

* One-page checkout, email first, autocomplete everywhere, no postal address for digital-only carts.
* Apple Pay, Google Pay and Link through Stripe, PayPal buttons, bank transfer.
* Slide-in cart with free-shipping progress bar and cross-sells, sticky add-to-cart bar, buy-now button.
* Order bumps, one-click post-purchase upsells and downsells, frequently-bought-together discounts, bundles.
* Abandoned cart emails (3-step sequence with optional personal coupon).
* Direct checkout links: `?wahou_buy=123`, coupon links: `?wahou_coupon=CODE`.

**EU compliance**

* VAT rates of the 27 member states (books and e-books included), OSS, VIES-validated reverse charge, territories outside the EU VAT area.
* Omnibus reference price (lowest price of the previous 30 days) on discounts.
* Digital content withdrawal waiver checkbox and durable-medium confirmation.
* Explicit order button, sequential invoices and credit notes, GDPR export/erasure.

**SEO & GEO**

* Complete JSON-LD (Product, offers, return policy, shipping details, ratings, FAQ, breadcrumbs).
* `/llms.txt`, `/llms-full.txt`, Markdown version of every product, Google Merchant Center feed.
* WordPress Abilities (6.9+) to let AI agents search the catalog and get checkout links.

**Multilingual**

* French and Spanish translations included, WPML and Polylang compatible, emails sent in the order language.

== Installation ==

1. Upload the plugin zip in Plugins → Add New → Upload.
2. Activate it: the setup wizard opens.
3. Connect Stripe and/or PayPal (test mode first), create the store pages and add your first product.

HTTPS is required for live payments, webhooks and Apple Pay.

== Frequently Asked Questions ==

= Does it work with my theme? =

Yes. Templates support block themes and classic themes, and can be overridden in `your-theme/wahou-commerce/`.

= Are the generated invoices e-invoicing compliant? =

They are printable HTML invoices with sequential numbering. They are not VERI*FACTU (Spain) or French e-invoicing platform compliant yet.

== Changelog ==

= 1.0.0 =
* First release.
