=== NodeThemes SEO AI - SEO & AI-Search Optimization ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later

SEO for Google and for AI answer engines (ChatGPT, Gemini, Claude, Perplexity), powered by your own OpenAI / Claude / Gemini API key.

== Free and Pro ==
The free plugin is complete for single-page SEO and includes the Auto Writer for 2 complete pages a month (up to 1,200 words). Pro (sold at nodethemes.com) adds: the Auto Writer without limits (up to 4,000 words, many products at once, a topic schedule), Fix-to-100 AI editing, smart one-click internal linking, long-form writer (to 5,000 words), bulk AI, unlimited AI-visibility questions, IndexNow.
Pro is a separate add-on plugin (NodeThemes SEO AI Pro) sold at nodethemes.com. The free plugin only contacts nodethemes.com to check for new versions of itself.

== Setup ==
1. Upload and activate. 2. NodeThemes SEO AI > Settings: paste your API key, press "Test connection". 3. Describe your business. 4. Open any page/product/post: press "Optimize this page with AI" and review. 5. Link Map > "Scan my site's links".

Tip: keep keys out of the database by adding define('NRA_OPENAI_KEY','...'); (or NRA_ANTHROPIC_KEY / NRA_GEMINI_KEY) to wp-config.php.
Deactivate Yoast / Rank Math / AIOSEO after importing, so tags are not output twice.

== Auto Writer ==
NodeThemes SEO > Auto Writer turns one topic into a complete page: focus keyword, title, SEO title, meta description, a direct-answer opening, key takeaways, sections, a table, FAQ (also as FAQ schema), internal links and up to 2 outbound source links that are checked to load. It then runs the plugin's own SEO and AI-search checks and repairs what still fails, aiming for 100 in both scores.
* New pages are saved as drafts by default. You can choose "publish", which only publishes when both scores are 98-100 and nothing is marked [ADD: ...].
* Products: open the product and use SEO box > AI writer > "Write the whole page". It writes the description, the short description and the SEO fields from the product's own data (name, price, attributes, categories, current text). The result goes into the editor; nothing is saved until you click Update.
* The writer never invents prices, numbers, guarantees or reviews: facts it was not given appear as [ADD: ...] for you to fill.
* When a new page is published, up to 2 related pages of your site get a link to it (can be switched off on the Auto Writer page).
* Free: 2 runs per calendar month. Each run uses about 5-8 requests to your own AI provider (billed by them).

== Changelog ==
= 1.3.0 =
* New: Auto Writer (NodeThemes SEO > Auto Writer, and SEO box > AI writer > "Write the whole page" on every page, post and product).
* Pages written by the Auto Writer show "links pointing to this page" as planned while they are drafts; the links are added when the page is published.
* Free: 2 Auto Writer runs per month, up to 1,200 words. Pro: unlimited, up to 4,000 words, bulk products and a topic schedule.

= 1.2.8 =
* Product tag archives follow the "Noindex tag archives" setting.
* The WooCommerce Shop page uses the SEO title, description and noindex from its own SEO box, and gets a canonical URL.
* Only one BreadcrumbList in the structured data on WooCommerce pages (WooCommerce's duplicate is removed).

= 1.2.7 =
* SEO box: the help text now points to the correct settings tab (Settings > Modules).

= 1.2.6 =
* Settings: support contacts (email, WhatsApp, Telegram); field labels no longer show raw HTML tags.

= 1.2.5 =
* Updates are now delivered from nodethemes.com: new versions appear in Dashboard → Updates and can be installed with one click or with auto-updates.
