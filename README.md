# Wahou Commerce

**L'e-commerce WordPress sans friction.** Mobile-first, ultra-rapide, SEO & GEO natifs, Stripe et PayPal connectés en deux minutes, TVA européenne automatique, bundles, upsells, relances de paniers abandonnés, produits physiques et numériques.

- Version : 1.0.0
- Prérequis : WordPress 6.5+ (testé jusqu'à 7.1), PHP 8.1+, HTTPS (obligatoire pour les webhooks Stripe/PayPal et Apple Pay)
- Licence : GPL-2.0-or-later
- Auteur : Thierry Gustin

---

## Pourquoi Wahou Commerce ?

| Point de friction classique | Réponse de Wahou Commerce |
|---|---|
| Sites lents, panier qui casse le cache | Panier sans session PHP (cookie + table dédiée) : toutes les pages catalogue restent en cache. CSS/JS légers chargés seulement là où ils servent, Stripe.js uniquement au checkout, SDK PayPal chargé à la demande. |
| Base de données encombrée | Commandes, paniers, téléchargements et factures dans des tables indexées dédiées (pas de postmeta). |
| Checkout long sur mobile | Checkout une page, e-mail en premier, champs `autocomplete`, Apple Pay / Google Pay / Link en un geste, aucune adresse demandée pour les produits numériques. |
| Configuration des paiements pénible | Collez vos clés : le webhook Stripe est créé automatiquement, le domaine Apple Pay enregistré, le webhook PayPal configuré. |
| TVA européenne complexe | OSS, taux des 27 pays, livres et e-books, autoliquidation B2B vérifiée via VIES, territoires hors TVA (Canaries, DOM…), prix TTC identiques pour tous les clients. |
| Contenu invisible pour les IA | Fiches 100 % rendues côté serveur, JSON-LD complet, `llms.txt`, version Markdown de chaque produit, flux Google Merchant, abilities WordPress pour les agents IA. |

## Fonctionnalités

### Catalogue
- Produits physiques, numériques (fichiers privés, liens signés, limite et expiration) et services.
- Variantes (taille, couleur…) avec prix, stock, SKU et image par variante.
- Bundles à prix fixe ou en % de remise sur les composants (stock déduit sur chaque composant).
- « Fréquemment achetés ensemble » avec remise, ventes croisées dans le panier, montées en gamme sur la fiche.
- Avis clients avec note, badge « achat vérifié », option avis vérifiés uniquement, demande d'avis automatique par e-mail.
- Points forts, FAQ produit, fiche technique, badges, GTIN/MPN/marque.
- Prix barré conforme Omnibus : prix de référence = prix le plus bas des 30 jours précédant la réduction, pourcentage calculé sur ce prix.

### Conversion
- Tiroir panier avec barre « livraison offerte dans X € », ventes croisées en un tap, code promo.
- Barre d'ajout au panier collante sur mobile, bouton « Acheter maintenant », estimation de livraison.
- **Order bumps** (case à cocher au checkout), **upsells post-achat en un clic** (sans ressaisir la carte, Stripe) et **downsells** si l'offre est refusée.
- Liens d'achat directs à partager partout (bio Instagram, e-mail, QR code, fin d'un e-book) :
  - `https://votresite.fr/?wahou_buy=123` → checkout direct avec le produit 123
  - `https://votresite.fr/?wahou_buy=123&wahou_coupon=LANCEMENT` → avec code promo appliqué
  - `https://votresite.fr/?wahou_add=123` → ajout au panier
- Pas de faux compteurs ni de fausse urgence (interdits par la directive Omnibus) : seules des données réelles sont affichées (stock, heure limite d'expédition).

### Paniers abandonnés
- Capture de l'e-mail dès sa saisie au checkout (avec mention ou case de consentement, au choix).
- Séquence de 3 relances configurable (1 h, 24 h, 72 h par défaut), coupon personnel à usage unique en option.
- Lien de reprise qui restaure le panier, lien de désinscription, statistiques (taux de récupération, CA récupéré).

### Checkout & paiements
- Stripe (cartes, Apple Pay, Google Pay, Link, moyens locaux), PayPal (avec paiement en plusieurs fois PayPal si disponible), virement bancaire.
- Commandes gratuites (coupon 100 %, lead magnet) sans paiement.
- Remboursements depuis l'admin (Stripe/PayPal) avec avoir numéroté.
- Protections : limitation de débit contre le « card testing », champ piège anti-bot, vérification des montants côté serveur, signature des webhooks.

### Commandes, factures, clients
- Statuts, notes, suivi de colis, e-mails d'expédition, export comptable CSV (TVA ventilée par taux).
- Factures et avoirs HTML imprimables à numérotation continue, mentions légales selon le pays du vendeur.
- Comptes clients sans mot de passe (lien de connexion magique), création automatique après achat, espace « Mes commandes / Mes téléchargements ».

### Admin & onboarding
- Assistant de démarrage en 7 étapes (activité, TVA, paiements, livraison, design, pages, c'est parti 🎉) avec produits de démonstration.
- Tableau de bord : CA, panier moyen, paniers récupérés, top produits, liste de vérification de mise en ligne.
- Réglages complets par onglets, zones de livraison, éditeur de produits par onglets, coupons, offres & funnels.
- Import des produits WooCommerce.

### SEO & GEO
- JSON-LD : Product, Offer/AggregateOffer, politique de retour, frais et délais de livraison, AggregateRating, avis, BreadcrumbList, FAQPage, ItemList.
- Balises meta / Open Graph produit (désactivées automatiquement si Yoast, Rank Math, SEOPress, AIOSEO… sont actifs, sauf les balises de prix).
- `/llms.txt`, `/llms-full.txt`, version Markdown de chaque fiche (`/produit/mon-livre.md`), lien `rel="alternate"`.
- Flux Google Merchant Center : `/wahou-feed/google.xml`.
- robots.txt : autorisation (ou blocage) des robots IA au choix, exclusion du panier/checkout/compte.
- Abilities WordPress (API des capacités, WP 6.9+) : recherche produit, fiche produit, infos boutique, lien de paiement — exploitables par les agents IA via l'adaptateur MCP.

### Multilingue
- Interface traduite en français et en espagnol (anglais par défaut), formats de prix et noms de pays selon la langue du visiteur.
- Compatible WPML et Polylang (pages traduites, champs synchronisés, stock partagé entre traductions).
- E-mails envoyés dans la langue de la commande.

## Installation

1. Téléchargez `wahou-commerce.zip`, puis **Extensions → Ajouter → Téléverser**.
2. Activez l'extension : l'assistant s'ouvre automatiquement.
3. Connectez Stripe et/ou PayPal (mode test d'abord), créez les pages, ajoutez votre premier produit.

Clés API :
- Stripe : <https://dashboard.stripe.com/apikeys>
- PayPal : <https://developer.paypal.com/dashboard/applications>

Les secrets peuvent aussi être définis dans `wp-config.php` (ils sont alors prioritaires) :

```php
define( 'WAHOU_STRIPE_LIVE_SECRET', 'sk_live_...' );
define( 'WAHOU_PAYPAL_LIVE_SECRET', '...' );
define( 'WAHOU_ENCRYPTION_KEY', 'une-longue-chaine-aleatoire' ); // clé de chiffrement des secrets en base
define( 'WAHOU_TRUST_PROXY', true ); // derrière Cloudflare / un proxy : IP réelle pour l'anti-fraude
```

Hébergement nginx : bloquez l'accès direct aux fichiers privés.

```nginx
location ~* /wp-content/uploads/(wahou-private|wahou-logs)/ { deny all; }
```

## TVA et conformité (vérifié le 30/10/2026)

| Règle | Ce que fait le plugin | Source |
|---|---|---|
| Seuil OSS de 10 000 € (ventes à distance de biens + services électroniques, UE) | Sous le seuil : TVA du pays du vendeur. Option OSS : TVA du pays du client. | Directive 2006/112/CE art. 59 quater ; [Commission – OSS](https://vat-one-stop-shop.ec.europa.eu/one-stop-shop_en) |
| Taux des 27 États membres (normal, réduits, livres, e-books) | Table intégrée, modifiable pays par pays | [TEDB – Commission européenne](https://ec.europa.eu/taxation_customs/tedb/) (situation au 30/10/2026) |
| Territoires hors TVA UE (Canaries, Ceuta, Melilla, DOM, Åland, mont Athos…) ; Monaco = France | Détection par pays et code postal, vente traitée comme une exportation | Directive 2006/112/CE art. 6 et 7 |
| Autoliquidation B2B intra-UE | Numéro de TVA vérifié en direct via VIES, TVA à 0 %, mention légale sur la facture | [VIES](https://ec.europa.eu/taxation_customs/vies/) ; art. 196 et 138 de la directive |
| Mentions de facture selon le pays du vendeur | ES : « Inversión del sujeto pasivo », « Entrega intracomunitaria exenta » ; FR : « Autoliquidation », « Exonération de TVA, article 262 ter, I du CGI », « TVA non applicable, article 293 B du CGI » | Directive art. 219 bis ; [RD 1619/2012 art. 6](https://www.boe.es/eli/es/rd/2012/11/30/1619) ; [CGI ann. II art. 242 nonies A](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000050811276) |
| Prix de référence des promotions (30 jours) | Historique des prix, prix barré = plus bas des 30 jours précédents | [C. conso. L112-1-1](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000044549592) ; [Ley 7/1996 art. 20](https://www.boe.es/buscar/act.php?id=BOE-A-1996-1072#a20) ; CJUE C-330/23 |
| Contenu numérique : renonciation au droit de rétractation | Case non cochée obligatoire au checkout + rappel dans l'e-mail de confirmation | Directive 2011/83 art. 16 m) ; [C. conso. L221-28 13°](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000044563170) ; TRLGDCU art. 103 m) |
| Bouton de commande explicite | « Payer maintenant – 49,90 € » (libellé modifiable, ex. « Commande avec obligation de paiement ») | Directive 2011/83 art. 8(2) ; [C. conso. L221-14](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000032226854) ; TRLGDCU art. 98.2 |
| Prix TTC affichés aux consommateurs | Affichage TTC partout, mention « TTC » | Directive 98/6/CE |
| RGPD | Export / effacement des données, durée de conservation des paniers, texte pour la politique de confidentialité | Outils de confidentialité WordPress |
| Accessibilité (European Accessibility Act) | Interface pensée pour le clavier, les lecteurs d'écran et le contraste | Directive 2019/882, applicable depuis le 28/06/2025 (microentreprises de services exemptées, art. 4.5) |

Les taux changent : vérifiez-les régulièrement dans **Réglages → TVA** (chaque taux peut être remplacé).

## À surveiller avant d'encaisser (important)

- **Espagne – VERI\*FACTU** : les factures générées par le plugin ne sont **pas** au format VERI\*FACTU (pas de chaînage, pas de QR, pas d'envoi à l'AEAT). Dates en vigueur : 1er janvier 2027 (sociétés à l'IS) et **1er juillet 2027 pour les autónomos** ([RDL 15/2025](https://www.boe.es/buscar/doc.php?id=BOE-A-2025-24446)). Le ministère a annoncé le 05/10/2026 un report à octobre 2028, mais aucun texte n'est encore publié au BOE ([note Hacienda](https://www.hacienda.gob.es/sgt/gabsehacienda/nota-informativa-verifactu.pdf)). D'ici là, émettez vos factures officielles avec un logiciel conforme, ou utilisez celles du plugin comme justificatifs.
- **France – facturation électronique** : réception obligatoire pour toutes les entreprises depuis le 1er septembre 2026 ; émission et e-reporting (y compris B2C) au plus tard le 1er septembre 2027 pour les PME et microentreprises ([impots.gouv.fr](https://www.impots.gouv.fr/professionnel/je-passe-la-facturation-electronique)). Le plugin ne transmet pas de données à une plateforme agréée : l'export CSV comptable sert de base.
- **OSS 2027** : à partir du 1er janvier 2027, seules les livraisons de biens expédiées depuis l'État d'établissement comptent dans le seuil de 10 000 €, et l'inscription à l'OSS vaut option ([Directive 2025/516](https://eur-lex.europa.eu/eli/dir/2025/516/oj)).
- **Preuves de localisation (e-books)** : le plugin conserve le pays de facturation et, si votre CDN le fournit (Cloudflare…), le pays de l'adresse IP. Une seule preuve suffit tant que vos ventes de services électroniques B2C restent ≤ 100 000 € par an.
- Taxes locales non gérées : IGIC (Canaries), IPSI (Ceuta, Melilla), octroi de mer (DOM).

## Performance

- Aucune session PHP, pages catalogue compatibles avec tous les caches (LiteSpeed, WP Rocket, Cloudflare…), purge automatique du cache d'un produit quand son stock change.
- Assets chargés uniquement sur les pages boutique ; ailleurs, un script en ligne minuscule affiche le badge panier.
- Images responsive avec dimensions réservées (pas de décalage de mise en page), image principale prioritaire, autres en lazy-load.
- Préchargement spéculatif des pages produits (WordPress 6.8+), exclusion du panier et du checkout.
- Tables SQL indexées pour les commandes et paniers.

## Sécurité

- Secrets chiffrés en base (libsodium), surchargeables par constantes.
- Webhooks Stripe signés (HMAC-SHA256, tolérance 5 min) et PayPal vérifiés via l'API PayPal.
- Montants recalculés côté serveur et comparés au paiement reçu.
- Fichiers numériques hors de la médiathèque, servis via jetons à usage limité, protection contre la traversée de répertoires.
- Nonces + capacités sur toutes les actions admin, rôles dédiés (`wahou_manager`, `wahou_customer`).
- Limitation de débit (checkout, coupons, VIES, connexion magique), champ piège anti-bot.
- Connexion magique refusée aux comptes d'équipe (éditeurs, admins).

## Pour les développeurs

- Surcharge des templates : copiez `templates/xxx.php` dans `votre-theme/wahou-commerce/xxx.php`.
- API REST `wahou/v1` (panier, checkout, commandes, produits) — voir `includes/Rest/RestApi.php`.
- Hooks et contrats internes : [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).
- WP-CLI : `wp wahou status`, `wp wahou cron`, `wp wahou pages`.
- Fonctions utiles : `wahou_get_product()`, `wahou_get_order()`, `wahou_cart()`, `wahou_price()`, `wahou_buy_url()`.

## Feuille de route

- Abonnements (Stripe Billing), multidevise, filtres à facettes, alertes de retour en stock.
- Factures VERI\*FACTU (Espagne) et transmission à une plateforme agréée (France).
- Tatouage numérique des PDF/EPUB, clés de licence pour les logiciels.

## Licence

GPL-2.0-or-later. © Thierry Gustin.
