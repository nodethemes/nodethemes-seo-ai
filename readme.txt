=== NodeThemes SEO AI - SEO & AI-Search Optimization ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.2.8
License: GPLv2 or later

SEO for Google and for AI answer engines (ChatGPT, Gemini, Claude, Perplexity), powered by your own OpenAI / Claude / Gemini API key.

== Free and Pro ==
The free plugin is complete for single-page SEO. Pro (sold at nodethemes.com) adds: Fix-to-100 AI editing, smart one-click internal linking, long-form writer (to 5,000 words), bulk AI, unlimited AI-visibility questions, IndexNow.
Pro is a separate add-on plugin (NodeThemes SEO AI Pro) sold at nodethemes.com. The free plugin only contacts nodethemes.com to check for new versions of itself.

== Setup ==
1. Upload and activate. 2. NodeThemes SEO AI > Settings: paste your API key, press "Test connection". 3. Describe your business. 4. Open any page/product/post: press "Optimize this page with AI" and review. 5. Link Map > "Scan my site's links".

Tip: keep keys out of the database by adding define('NRA_OPENAI_KEY','...'); (or NRA_ANTHROPIC_KEY / NRA_GEMINI_KEY) to wp-config.php.
Deactivate Yoast / Rank Math / AIOSEO after importing, so tags are not output twice.

== Changelog ==
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
