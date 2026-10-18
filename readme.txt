=== NeoSEO ===
Contributors: mtpamir
Tags: structured data, product schema, rich results, woocommerce, llms.txt
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
WC tested up to: 11.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Give Google the product data it needs to show your variants, prices, shipping and returns — and stand aside politely when a full SEO plugin runs.

== Description ==

Most WooCommerce stores are invisible to Google's product results for a reason nobody tells them about: their markup is missing a field. One absent property does not shrink the rich result — it removes the whole thing, silently, with no warning in the dashboard.

NeoSEO exists to close that gap. It reads what WooCommerce already knows — variations, shipping zones, brands, dimensions, stock — and turns it into the structured data Google, Bing and the AI answer engines actually read.

= Your variants, as separate products =

This is the part almost nothing else does. A variable product is not one purchasable thing; it is several, each with its own price, SKU, colour and availability. NeoSEO emits a proper `ProductGroup` with every variation nested inside it:

* `ProductGroup` with `hasVariant`, `variesBy` and `productGroupID`
* Each variation as its own Product node — SKU, GTIN, image, price, stock and condition
* Colour, size, material and pattern mapped from your own attributes, in your own language
* `AggregateOffer` alongside, so the price range still reads correctly

= Shipping and returns, from your actual settings =

Google increasingly wants both before it will show a product with price and availability. NeoSEO builds them from your live WooCommerce configuration rather than asking you to retype it:

* `shippingDetails` derived from your real shipping zones — rate, destination and free-shipping thresholds
* `hasMerchantReturnPolicy` from a short form on the settings screen
* Nothing is invented. A shipping cost that depends on a formula, or a return policy you have not filled in, is left out rather than guessed at

= One connected graph, not scattered blobs =

Every node links to the others by `@id`, so a consumer sees one description of your site instead of five disconnected fragments:

* **Organization** — identity, logo and social profiles
* **WebSite** — with a SearchAction pointing at your site search
* **WebPage** — correctly subtyped (CollectionPage on archives, ItemPage on products, CheckoutPage at checkout)
* **BreadcrumbList** — home → shop → category → product
* **Product / ProductGroup** — the full detail above, plus `AggregateRating` and real Review nodes
* **ItemList** — the products on a shop or category page, with positions that continue across pages
* **BlogPosting** — headline, author, dates, images and `speakable` hints
* **FAQPage** — read automatically from core Details blocks. No shortcode, no proprietary block

= Built for AI search (AEO / GEO) =

* **llms.txt** — a cached, always-current markdown summary of your site served at `/llms.txt`
* `speakable` markup for voice and AI answers
* Clean meta descriptions, Open Graph and Twitter cards
* Product Open Graph tags (`product:price:amount`, `product:availability`) for Facebook catalogue and Pinterest rich pins

= Plays fair with the plugin you already use =

NeoSEO is not trying to replace your SEO plugin. When Yoast, Rank Math, All in One SEO, SEOPress, Slim SEO, The SEO Framework or Squirrly is active, every overlapping module switches itself off.

It also detects **structured-data-only** plugins — Schema & Structured Data for WP & AMP, Schema Pro and others — and tells you plainly when two plugins are both writing Product markup, which is the most common reason rich results get ignored.

One exception, and it is deliberate: **variant markup keeps running**, because none of those plugins produces it. You get your variants either way, and you can switch that off too.

= Light by design =

No frontend JavaScript. No CSS. **No database tables** — not now, not later. One settings screen. WooCommerce is optional; without it you still get the full content graph.

= For developers =

Extend or override anything through filters:

`neoseo_schema_providers`, `neoseo_schema_graph`, `neoseo_product_node`, `neoseo_product_group_node`, `neoseo_product_variants_node`, `neoseo_product_mpn`, `neoseo_product_condition`, `neoseo_product_certification`, `neoseo_attribute_property`, `neoseo_offer_shipping`, `neoseo_offer_return_policy`, `neoseo_meta_description`, `neoseo_description_post_types`, `neoseo_webpage_type`, `neoseo_llms_txt`, `neoseo_module_enabled`

== Installation ==

1. Install and activate NeoSEO from the Plugins screen.
2. Visit NeoSEO → Settings and fill in your organization profile.
3. If you sell physical goods, fill in the Returns section — Google asks for it, and NeoSEO publishes nothing there until you do.
4. Validate any product with the Google Rich Results Test.

== Frequently Asked Questions ==

= Do I need WooCommerce? =

No. The product features activate only when WooCommerce is present; the Organization, WebSite, WebPage, breadcrumb, article and FAQ graph works on any WordPress site.

= Will it conflict with my SEO plugin? =

No. NeoSEO detects Yoast, Rank Math, AIOSEO, SEOPress, Slim SEO, The SEO Framework and Squirrly, and disables its overlapping output automatically. Product variant markup is the one thing it keeps emitting, because none of them produce it — and that is a toggle like any other.

= I already use a schema plugin. Will I get duplicate markup? =

NeoSEO detects the common structured-data plugins and switches its own schema module off. If it finds one it cannot switch off cleanly, it says so on the settings screen rather than quietly emitting a second Product node.

= Why is there no return policy in my markup? =

Because you have not declared one. A return policy is a promise to your customers as well as a signal to Google, so NeoSEO will not invent a plausible-looking one. Fill in the Returns section and it appears.

= My variations do not show colour or size =

NeoSEO recognises the usual attribute names in several languages. If yours is named something of your own, map it under Settings → Product attributes.

= How do I get FAQ schema? =

Build your FAQ with core Details blocks — question in the summary, answer inside. Two or more become a valid FAQPage node automatically.

== Screenshots ==

1. The Dashboard: how much of your catalogue carries schema, and what each module is doing.
2. The schema inspector showing real ProductGroup output — productGroupID, hasVariant, and a variation with its own SKU, colour and offer.
3. Settings: return policy, attribute mapping and module toggles.
4. The Search Appearance box on the product editor, with a live search-result preview.

== Changelog ==

= 1.0.0 =
* Initial release.
* Connected JSON-LD `@graph`: Organization, WebSite, WebPage, BreadcrumbList, BlogPosting, Product, FAQPage.
* WooCommerce `ProductGroup` with `hasVariant`, `variesBy` and `productGroupID`; every variation carries its own SKU, price, image and offer.
* Attribute mapping to colour, size, material, pattern and audience, with multilingual detection and a per-attribute override.
* `shippingDetails` derived from live shipping zones, and `hasMerchantReturnPolicy` from declared return terms.
* Brand from the WooCommerce brand taxonomy, product gallery images, item condition, MPN, weight and dimensions.
* `ItemList` on shop and category archives, with positions that continue across pages.
* Product Open Graph tags, meta descriptions and Twitter cards.
* `Certification` support for EU energy labelling, emitted only when the data exists.
* `/llms.txt` endpoint for AI crawlers.
* Automatic back-off for full SEO plugins and structured-data plugins, with a duplicate-markup warning.
