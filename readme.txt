=== NodeThemes SEO AI ===
Contributors: nodethemes
Tags: seo, schema, sitemap, ai, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SEO for Google and AI answer engines: page scores, meta tags, schema, sitemaps, llms.txt, redirects and an internal-link map, with optional AI help.

== Description ==

NodeThemes SEO AI is an SEO plugin for WordPress and WooCommerce that checks every post, page and product for Google and for AI answer engines such as ChatGPT, Gemini, Claude and Perplexity, and helps you fix what is missing.

Everything below is included in this plugin, with no usage limits:

* SEO score and AI-search score for every post, page and product, with a clear list of what passes and what to fix (also in the admin bar)
* SEO title, meta description, focus keyword, canonical URL, robots (noindex / nofollow), Open Graph and X (Twitter) cards
* Structured data (schema): Organization, WebSite, Article, Product (WooCommerce), FAQ and BreadcrumbList, with only one BreadcrumbList on WooCommerce pages
* FAQ and "Key takeaways" blocks for every page, also output as schema
* XML sitemaps with images and last-modified dates; pages set to noindex are left out
* llms.txt for AI assistants, and per-bot rules for AI crawlers (GPTBot, ClaudeBot, PerplexityBot, Google-Extended and more) in robots.txt
* A log of AI-crawler visits
* Redirects (301, 302, 307, 410), automatic redirects when you change a slug, and a 404 monitor
* Visual internal-link map: see which pages link to each other and which pages have no inbound links (orphan pages)
* Breadcrumbs shortcode [ntseoai_breadcrumbs], table of contents, WooCommerce clean product URLs
* Site verification for Google, Bing, Yandex and Pinterest, and Google Analytics 4 or Google Tag Manager by ID
* One-click import from Yoast SEO, Rank Math and All in One SEO
* Optional AI help: focus keyword ideas, SEO titles and meta descriptions, short product descriptions, short and medium articles, FAQ, an SEO action plan for the page, and "Optimize this page with AI". You review every result before saving.

The AI tools use the AI service connected in WordPress (Settings > Connectors, WordPress 7.0 and newer) or your own OpenAI, Anthropic (Claude) or Google Gemini API key, so you pay the provider directly. All other features work without AI. A daily request limit and a daily budget keep AI costs under control.

= Optional Pro add-on =

NodeThemes SEO AI Pro is a separate add-on plugin sold at [nodethemes.com](https://nodethemes.com/product/nodethemes-seo-ai-pro-ai-seo-plugin-for-wordpress-woocommerce/). It adds the Auto Writer (one topic to a complete page that is checked and repaired before it is saved), "Fix with AI" on every failed check, internal-link suggestions with one-click linking, an AI-visibility tracker, long-form writing, bulk AI optimisation, IndexNow and a Google Search Console and Analytics connection. Nothing in this plugin stops working without it, and your settings and data stay when you add it.

== Installation ==

1. Install the plugin from Plugins > Add New Plugin, or upload the zip, and activate it.
2. NodeThemes SEO > Settings: check the modules you want (all are on by default) and describe your business.
3. Optional, for the AI tools: on WordPress 7.0 and newer, connect an AI service under Settings > Connectors and keep "WordPress AI" as the provider. Or paste your own OpenAI, Anthropic or Google Gemini API key under NodeThemes SEO > Settings > AI Provider and click "Test connection".
4. Open any post, page or product and look at the NodeThemes SEO AI box below the editor.
5. Link Map: click "Scan my site's links" to find pages that nothing links to.

Moving from another SEO plugin? Use NodeThemes SEO > Settings > Tools > Import, then deactivate the other plugin so the tags are not output twice.

Tip: keep API keys out of the database by adding define( 'NTSEOAI_OPENAI_KEY', '...' ); (or NTSEOAI_ANTHROPIC_KEY / NTSEOAI_GEMINI_KEY) to wp-config.php.

== Frequently Asked Questions ==

= Do I need an AI key? =

No. Scores, meta tags, schema, sitemaps, llms.txt, redirects and the link map work without AI. The AI buttons are optional and use the AI service connected in WordPress or your own API key.

= Does the AI change my pages on its own? =

No. AI results are shown in the editor first, and nothing is saved until you click Save or Update.

= Can it guarantee rankings? =

No tool can. The scores are on-page checks that remove technical and content gaps, so your pages can compete properly.

= Does it work with WooCommerce? =

Yes. Products get scores, Product schema with price and stock, clean product URLs and AI help for product descriptions.

= Will my data be lost if I add the Pro add-on? =

No. Pro is installed next to this plugin and uses the same settings and data.

== External services ==

This plugin does not contact any external service on its own. It only connects to the services below when you use or switch on the feature yourself.

= AI providers (only when you click an AI button or "Test connection") =

On WordPress 7.0 and newer the plugin can use the WordPress AI Client: the AI service is the one the site owner connected under Settings > Connectors, and that service's terms apply. Or you can add your own API key for one of the providers below; the plugin then connects to that provider directly.

When you use an AI tool, the plugin sends the text needed for that task: the page title, content, URL slug, focus keyword and current SEO fields of the page you are working on, plus your site name, site address, tagline and the business description and brand voice you entered in the settings. Nothing is sent until you click an AI button.

* OpenAI (api.openai.com): [Terms and policies](https://www.openai.com/policies), [Privacy policy](https://openai.com/policies/privacy-policy)
* Anthropic (api.anthropic.com): [Commercial terms](https://www.anthropic.com/legal/commercial-terms), [Privacy policy](https://www.anthropic.com/legal/privacy)
* Google Gemini (generativelanguage.googleapis.com): [Gemini API terms](https://ai.google.dev/gemini-api/terms), [Privacy policy](https://policies.google.com/privacy)

= Google Analytics 4 and Google Tag Manager (only when you enter an ID) =

When you enter a GA4 measurement ID or a Google Tag Manager container ID under NodeThemes SEO > Settings > Search & Analytics, your visitors' browsers load the Google script (www.googletagmanager.com) on your public pages, and Google receives the usual analytics data from them. Nothing is loaded while the fields are empty. You can skip logged-in editors in the same settings.

* Google Analytics: [Terms of Service](https://marketingplatform.google.com/about/analytics/terms/us/), [Privacy policy](https://policies.google.com/privacy)
* Google Tag Manager: [Use policy](https://marketingplatform.google.com/about/analytics/tag-manager/use-policy/), [Privacy policy](https://policies.google.com/privacy)

== Privacy ==

* The plugin stores its settings, scores, redirects, the 404 log, the link map and the AI-crawler log in your own WordPress database.
* The AI-crawler log keeps the bot name, the day, the number of visits and the last URL. It does not store visitor IP addresses.
* The 404 monitor keeps the missing URL, the number of hits and the last referring page.
* API keys are stored encrypted and are never shown again in full.
* Uninstalling the plugin removes its tables and settings. SEO data saved on your posts (titles, descriptions, FAQ) is kept so nothing is lost if you reinstall.

== Changelog ==

= 1.4.5 =
* All function, class, option, hook, post meta and table names now use the longer, unique prefix "ntseoai" instead of "nra". Data saved by earlier versions is moved automatically on update.
* The plugin no longer changes the PHP error display setting.

= 1.4.4 =
* The Auto Writer in the page editor now offers all lengths (long, extra long and epic) without a Pro check.
* Admin list-column styles are added with the style API, background-job input is filtered before it is stored, and the longer PHP time limit is only used by the AI actions that need it.

= 1.4.3 =
* Compatibility: the WordPress 7.0 AI Client function is now called only after the plugin has confirmed it exists, so the plugin keeps working on WordPress 6.0 and up (Plugin Check).

= 1.4.2 =
* Code: every AJAX request checks its nonce inside the handler, and submitted FAQ, takeaways and API keys are unslashed and sanitized (Plugin Check).

= 1.4.1 =
* Code: all admin output is escaped (Plugin Check), including the Pro buttons and the meta tag names.

= 1.4.0 =
* WordPress.org edition: no usage limits in this plugin. The Auto Writer, internal-link suggestions and the AI-visibility tracker are now part of the separate Pro add-on.
* AI: uses the WordPress AI Client (WordPress 7.0+, Settings > Connectors) as an option. Your own OpenAI, Anthropic or Gemini key stays available.
* Google Analytics 4 and Google Tag Manager are loaded with the WordPress script API, and schema is printed with wp_print_inline_script_tag().
* The setup notice only shows on the Dashboard and Plugins screens and can be dismissed.
* Plugin name shortened to "NodeThemes SEO AI". Updates come from WordPress.org.

= 1.3.0 =
* Auto Writer (moved to the Pro add-on in 1.4.0).

= 1.2.8 =
* Product tag archives follow the "Noindex tag archives" setting.
* The WooCommerce Shop page uses the SEO title, description and noindex from its own SEO box, and gets a canonical URL.
* Only one BreadcrumbList in the structured data on WooCommerce pages.

== Upgrade Notice ==

= 1.4.0 =
The Auto Writer, link suggestions and the AI-visibility tracker are now in the Pro add-on. If you use Pro, update it to 1.4.0 as well.
