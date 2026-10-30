# Wahou Commerce – Architecture & contrats internes

Ce document décrit les conventions et les interfaces entre les modules. Toute
contribution doit les respecter.

## Conventions

- PHP 8.1+, WordPress 6.5+ (testé jusqu'à 7.1). Namespace `WahouCommerce\`, autoload PSR-4 (`includes/Foo/Bar.php`).
- Style WordPress (tabulations, `array()`, espaces dans les parenthèses). Chaînes source en anglais, domaine `wahou-commerce`.
- Chaque module = classe avec `public static function init(): void` qui n'enregistre que des hooks (liste dans `includes/Plugin.php`).
- Tous les montants sont des **entiers en unités mineures** (centimes). Affichage TTC (règle B2C UE).
- Sécurité : échappement systématique, nonces + `current_user_can()` côté admin, requêtes préparées, aucun prix ne vient du navigateur.

## Données

| Donnée | Stockage |
|---|---|
| Produits | CPT `wahou_product` (+ `wahou_product_cat`, `wahou_product_tag`), méta `_wahou_*` (voir `Products/Product.php`) |
| Coupons | CPT `wahou_coupon` (titre = code), méta `_wahou_coupon_*` |
| Offres (bump/upsell/downsell) | CPT `wahou_offer`, méta `_wahou_offer_*` |
| Paniers | table `wahou_carts` (token cookie httpOnly) |
| Commandes | tables `wahou_orders`, `wahou_order_items`, `wahou_order_notes` |
| Téléchargements | table `wahou_downloads` (liens signés) |
| Factures / avoirs | tables `wahou_invoices`, `wahou_credit_notes` (numérotation continue) |
| Réglages | option `wahou_settings` (voir `Settings::defaults()`), secrets chiffrés dans `wahou_secrets` |

Méta calculée `_wahou_active_price` : prix courant (soldé ou plus petite variante), tenue à jour par
`PriceHistory::record()` et utilisée pour le tri par prix. `business_days` : 0 = dimanche … 6 = samedi.
Le poids est saisi dans l'unité `weight_unit` (`Product::weight()`), `Product::weight_kg()` le convertit.

Méta des fichiers numériques (`_wahou_files`) : `[ { id: 'f_xxx', name: 'Livre.pdf', path: 'a1b2c3/Livre.pdf' } ]`
(chemin relatif à `Install::private_dir()`) ou `{ id, name, url }` pour un fichier externe.

Méta de commande utiles : `tax_lines`, `shipping_line`, `tax_evidence`, `consents`, `refunds`, `tracking`
(`{carrier, number, url}`), `stripe_payment_intent`, `stripe_customer`, `stripe_payment_method`,
`paypal_order_id`, `paypal_capture_id`, `one_click_ready`, `offers_seen`, `offers_accepted`, `tracked`.

## Hooks

- `wahou_order_created( Order )`, `wahou_order_status_changed( Order, from, to )`, `wahou_order_status_{to}( Order, from )`
- `wahou_order_paid( Order )` — priorités : 5 téléchargements, 7 compte client, 20 e-mails
- `wahou_order_completed( Order )`, `wahou_order_refunded( Order, amount, refund )`
- `wahou_cart_updated( Cart )`, `wahou_cart_email_captured( Cart )`, `wahou_cart_converted( cart_id, order_id, status )`
- `wahou_stock_changed( product_id, stock, variant_id, delta )`, `wahou_low_stock( product_id, stock, variant_id )`
- Cron : `wahou_cron_frequent` (15 min), `wahou_cron_daily`
- Filtres : `wahou_tax_rate`, `wahou_tax_resolve`, `wahou_calculated_totals`, `wahou_shipping_options`, `wahou_gateways`,
  `wahou_checkout_errors`, `wahou_checkout_address_fields`, `wahou_template_path`, `wahou_translate_page_id`, `wahou_cart_line`

## API publique entre modules

- `Emails\Emails::magic_link( string $email, string $url, ?string $locale = null ): bool`
- `Emails\Emails::resend( Order $order, string $type ): bool` — types : `order_confirmation`, `order_shipped`, `bank_instructions`
- `Downloads\Downloads::for_order( Order $order ): array` — `[ { name, url, product, remaining (?int), expires (?string) } ]`
- `Customers\Account::magic_url( string $email, int $ttl = DAY_IN_SECONDS ): string`
- `Recovery\Recovery::stats( string $from_gmt, string $to_gmt ): array` — `abandoned`, `recovered`, `recovered_revenue`, `rate`
- `Seo\Breadcrumbs::items(): array` — `[ { name, url } ]` pour la requête courante
- `Compat\Compat::seo_plugin_active(): bool`
- `wahou_date( $gmt, $format )` : date localisée (mois dans la langue du site)
- `Gateways\Stripe::connect( string $mode, string $publishable, string $secret )` / `Gateways\PayPal::connect( string $mode, string $client_id, string $secret )` → `array|WP_Error`
- AJAX admin (nonce `wahou_gateway_connect`, capacité `manage_wahou`) : `wahou_gateway_connect` `{ gateway, mode, public_key, secret }`, `wahou_gateway_disconnect` `{ gateway, mode }`

## Front-end

Les fichiers minifiés (`*.min.js`, `*.min.css`, générés par `bin/build.sh`) sont servis via
`Support\Assets::url()` sauf si `SCRIPT_DEBUG` est actif.

Handles : style `wahou` (`assets/css/wahou.css`, composants de base), style `wahou-checkout` (`assets/css/checkout.css`),
scripts `wahou` (`assets/js/wahou.js`), `wahou-checkout` (`assets/js/checkout.js`, dépend de `wahou`),
`wahou-stripe` / `wahou-paypal` (`assets/js/gateways/*.js`, dépendent de `wahou-checkout`).

- `window.wahouData` : `{ restUrl, nonce, currency, locale, cartMode, cartUrl, checkoutUrl, analytics, brandColor, radius, i18n }`
  (le nonce n'est fourni qu'aux utilisateurs connectés : les pages publiques restent cachables).
- `window.Wahou` (wahou.js) : `api( path, { method, body } )`, `cart.open() / close() / refresh() / render( data )`,
  `format( amount )`, `track( event, data )` (dataLayer GA4) ; évènement DOM `wahou:cart-updated` (detail = panier).
- `window.wahouCheckoutData` : `{ gateways: { id: checkout_config() }, totals, needsShipping, hasDigital, i18n }`.
- `window.WahouCheckout.registerGateway( id, impl )` — voir le contrat dans `includes/Gateways/Gateway.php`.
  L'objet `api` passé aux passerelles : `collect()`, `validateForm()`, `checkConsents()`, `submitOrder( extra )`,
  `showError( message )`, `setBusy( bool )`, `redirect( url )`, `totals()`, `fill( details )`.
- `window.WahouStripe.handleNextAction( clientSecret )` (stripe.js) pour l'authentification 3DS des upsells.
- Fragments HTML serveur renvoyés par l'API panier (`?html=drawer,summary,page`) : templates `cart/drawer.php`,
  `cart/summary.php`, `cart/page.php` avec `$cart` (Cart|null) et `$totals`.

Classes CSS : préfixe `wahou-` (BEM). Variables : `--wahou-brand`, `--wahou-on-brand`, `--wahou-radius`,
`--wahou-border`, `--wahou-surface`, `--wahou-muted`, `--wahou-success`, `--wahou-danger`.

## URLs

- Commande reçue : page de commande + `?wahou_order=KEY` ; offre post-achat : `&wahou_offer=ID`
- Facture : `/?wahou_invoice=KEY` ; téléchargement : `/?wahou_dl=TOKEN` ; connexion magique : `/?wahou_login=TOKEN`
- Relance panier : `/?wahou_recover=ID.SIG` ; désinscription : `/?wahou_unsub=ID.SIG`
- Liens marketing : `?wahou_buy=ID[:QTE]`, `?wahou_add=ID`, `?wahou_coupon=CODE`
- GEO : `/llms.txt`, `/llms-full.txt`, `/{base-produit}/{slug}.md`, flux `/wahou-feed/google.xml`
- REST : `wahou/v1/*` (voir `includes/Rest/RestApi.php`)
