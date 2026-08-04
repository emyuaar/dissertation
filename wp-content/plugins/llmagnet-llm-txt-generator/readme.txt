=== LLMagnet - AI Visibility & AI SEO for Claude, ChatGPT & More ===
Contributors: llmagnet
Tags: llms.txt, AI, SEO, connector, Claude
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 3.4.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your WordPress site visible to AI. Auto-generate llms.txt, track AI bot traffic, and optimize your content for answer engines.

== Description ==

Open playground [Free Demo](https://tastewp.com/create/NMS/8.0/6.8.2/llmagnet-llm-txt-generator/twentytwentythree?ni=true&origin=wp)

**LLMagnet** is the complete AI visibility solution for WordPress. As AI assistants like ChatGPT, Claude, and Perplexity become the new discovery layer, your site needs to be optimized for them-not just Google.

With full support for both llms.txt and llms-full.txt formats, seamless integration with RankMath and Yoast SEO, and an intelligent recommendations system, LLMagnet ensures your content is discovered and properly represented by AI crawlers.

**Schema.org JSON-LD** is built in as well: scan your site for existing structured data, review validation and recommendations, generate compliant markup, and publish it from the dashboard. Output is injected as a single JSON-LD block in the page head so it complements-rather than replaces-schema from your SEO plugin, giving search engines and AI assistants clearer signals about your organization, FAQs, and (on supported plans) WooCommerce products.

**Connect your site to any AI client and work directly from there.** Link LLMagnet to Claude, ChatGPT, Cursor, or any MCP-compatible AI assistant and manage your AI visibility through natural conversation. Ask your AI to pull live reports, surface your weakest pages and products, build a concrete action plan to improve your visibility score, and help you execute it-without leaving your chat. It turns your favorite AI assistant into a hands-on AI-SEO co-pilot for your business. Powered by the WordPress Abilities API (6.9+) and the official MCP Adapter, with MCP/OAuth kept opt-in and disabled by default for security.


https://www.youtube.com/watch?v=hhN-J5OFTQM

== Why You Need LLMagnet ==

AI bots are already visiting your site. The question is: are they understanding your content? LLMagnet ensures your WordPress site speaks the language of AI crawlers through:

- **llms.txt & llms-full.txt Protocol** – Full support for AI-readable site maps with extended metadata
- **Real-time Bot Analytics** – See which AI models visit your content
- **Smart Recommendations** – Get actionable suggestions to improve AI visibility
- **SEO Plugin Compatibility** – Works seamlessly with RankMath and Yoast SEO
- **WooCommerce Integration** – Track AI-driven product discovery and revenue
- **Schema.org JSON-LD** – Scan, validate, generate, and publish structured data so machines can read your site reliably
- **WordPress Abilities API support** – Your AI assistant (Claude, ChatGPT, Cursor) can read your AI visibility score, bot analytics, and llms.txt status natively via the WordPress 6.9+ Abilities API and the official MCP Adapter
- **Agent-Ready Site (Agentic Web)** – WebMCP browser tools, markdown endpoints, well-known agent discovery files, and AI crawler controls that make your site easy for autonomous agents to read and act on
- **Elementor Integration** – Check AI visibility scores and add per-page schema right inside the Elementor editor

== Key Features ==

= Work Directly From Claude, ChatGPT & Cursor =
* Connect your site to Claude, ChatGPT, Cursor, or any MCP-compatible AI client and manage AI visibility from your chat
* Ask your AI assistant to generate live reports on bot traffic, visibility scores, and llms.txt status
* Have your AI surface your weakest pages and products, then build a concrete action plan to improve them
* Create and execute a visibility improvement plan together-without leaving your AI assistant
* Built on the WordPress Abilities API (6.9+) and the official MCP Adapter; MCP/OAuth are opt-in and disabled by default for security

= Agent-Ready Site (Agentic Web) =
Make your site readable and actionable for autonomous AI agents and AI crawlers. Every feature ships **off by default** — enable only what you want agents to use.
* **WebMCP browser tools** – Register search and content tools on `navigator.modelContext` so in-browser agents can interact with your site on every front-end page (built on the emerging W3C Web Model Context API)
* **Markdown endpoints** – Serve any page as clean markdown at `{permalink}.md`, plus content negotiation that returns markdown on the normal URL when an agent sends `Accept: text/markdown`
* **Well-known agent discovery** – Serve `agent-card.json`, `agent-skills`, `mcp.json`, `security.txt`, and the change-password redirect so agents can discover what your site offers
* **AI crawler controls in robots.txt** – Managed `User-agent` groups for AI crawlers plus a Content-Signal block to declare how your content may be used
* **Link discovery headers** – HTTP `Link` headers advertising `llms.txt`, the agent card, and `schemamap.xml` for automatic discovery
* **IndexNow** – Ping IndexNow-backed engines automatically when content is published or updated for faster (re)indexing
* **Site hygiene for agents** – Optional security headers (nosniff, Referrer-Policy, Permissions-Policy, HSTS, CSP report-only), Open Graph verify-or-fill (only when no SEO plugin owns the tags), and restored RSS feed `<link rel="alternate">` tags
* **Live WebMCP status** – See the real-time status of the W3C Web Model Context API in your own browser and the tools this site registers for agents

= AI-Ready Content =
* Auto-generates `/llms.txt` and `/llms-full.txt` at your site root for AI crawler discovery
* Full support for llms-full.txt format with extended metadata
* Creates clean Markdown exports of your content in `/llms-docs/`
* Supports posts, pages, products, and custom post types
* Smart auto-updates on content changes + daily sync
* Automatic robots.txt integration for proper AI crawler guidance

= Schema.org JSON-LD =
* Scan pages for existing JSON-LD and catch invalid or missing structured data
* Actionable recommendations (for example Organization, LocalBusiness, FAQPage, and related types)
* Guided wizard to generate markup and publish a single JSON-LD block to the front end without overwriting schema from other plugins
* WooCommerce-aware recommendations and commerce schema options on supported plans

= Powerful Analytics Dashboard =
* Track visits from ChatGPT, Claude, Perplexity, Gemini, Grok, and more
* Interactive charts showing bot activity over time
* Click tracking via UTM parameters to measure real engagement
* Site-wide Visibility Score with actionable insights
* Smart recommendations system for improving AI visibility
* Real-time suggestions for meeting AI crawler standards

= Pages & Posts Content Analysis =
* **AI-Readiness Score** – Every page and blog post gets a score based on how well AI models can understand and cite it
* **Content Quality Audit** – Checks headings structure, meta descriptions, word count, and image ALT coverage
* **Actionable Insights** – Pinpoint exactly what's holding each piece of content back from AI visibility
* **Content Optimization Drawer** – Fix issues and improve scores without leaving the dashboard
* **Bulk Content Overview** – Identify your weakest content at a glance and prioritize improvements

= Elementor Integration =
* **AI Visibility score in the editor** – An "AI Visibility" button in the Elementor editor top bar (with a floating fallback) shows the current score for the page or product you're editing
* **Optimization drawer over the editor** – Open the same Page/Product optimization drawer right inside Elementor and fix issues without switching screens
* **Per-page JSON-LD schema field** – Add custom structured data from Elementor's document settings; it's merged into LLMagnet's single JSON-LD block so it never conflicts with other schema
* **Zero overhead when unused** – Loads only inside the Elementor editor and stays completely inactive on sites without Elementor

= WooCommerce Commerce Analytics (PRO) =
* **Product Visibility Scores** – AI-readiness rating for each product
* **AI Revenue Funnel** – Track bot visits → add-to-cart → purchases
* **Product of the Week** – Highlight top AI-discovered products
* **Content Quality Analysis** – Descriptions, tags, categories, image ALT coverage
* **Product Optimization Drawer** – Edit and improve products without leaving the dashboard

= AI-Powered Commerce =
For WooCommerce stores, LLMagnet brings the future of AI commerce today. Aligned with Google's emerging **UCP (Universal Commerce Protocol)** vision, we help your products get discovered, recommended, and purchased through AI assistants. Track how AI bots browse your catalog, measure the AI-to-revenue funnel, and optimize product content for maximum visibility in conversational commerce.


= Email Reports =
* Automated weekly/monthly reports delivered to your inbox
* Track AI engagement trends over time
* Share insights with your team


== How It Works ==

1. **Install & Activate** – LLMagnet starts working immediately with guided onboarding
2. **Configure Content** – Choose which post types to include
3. **Auto-Generation** – llms.txt, llms-full.txt and Markdown files are created and maintained
4. **Track AI Visits** – See which bots discover your content
5. **Follow Recommendations** – Get actionable suggestions to improve AI visibility
6. **Optimize Products** – Improve visibility scores for better AI recommendations
7. **Measure Revenue** – Connect AI traffic to actual conversions (WooCommerce)

== SEO Plugin Compatibility ==

LLMagnet works seamlessly alongside your existing SEO setup:
* **Full RankMath Integration** – Respects noindex settings and SEO configurations
* **Complete Yoast SEO Support** – Works with all Yoast meta settings
* **Smart robots.txt Management** – Automatically registers llms.txt and llms-full.txt
* **Elementor Editor Integration** – AI visibility scores and per-page schema available directly inside the Elementor editor
* No conflicts with your current SEO workflow

== AIO / GEO / AEO Benefits ==

* **AIO (AI Optimization)** – Structured content that AI models can parse accurately
* **GEO (Generative Engine Optimization)** – Better representation in AI-generated answers
* **AEO (Answer Engine Optimization)** – Increased chances of being cited as a source

== Data & Privacy ==

LLMagnet is designed to be privacy-first. **No data is sent to any external service unless you explicitly opt in.** This section describes exactly what data can leave your site, when, and how to control it.

= Optional telemetry (opt-in, off by default) =

During onboarding you are asked whether to share product data with us. If you choose "Continue without sharing" (or never answer), nothing is sent to the services below. Your choice is stored in the `llmagnet_telemetry_consent` option and can be changed at any time (re-run the onboarding wizard, update the option, or POST to the `llm-analytics/v1/privacy/settings` REST endpoint as an administrator).

**Brevo (https://www.brevo.com - email/CRM service).** Only after opt-in, the plugin syncs the site owner as a contact in our Brevo account and sends a small number of lifecycle emails (a getting-started reminder, a notification when the first AI bot visit is detected, and a trial reminder). Data sent: WordPress admin email address, site name, site URL/domain, plugin version, onboarding completion status, the site's AI visibility score, and free-trial status. Sync happens shortly after opt-in and again when the site identity or plugin version changes. See Brevo's privacy policy: https://www.brevo.com/legal/privacypolicy/

**Mixpanel (https://mixpanel.com - product analytics).** Only after opt-in, the plugin tracks how the **wp-admin dashboard of this plugin** is used (page views, button clicks such as "Generate Now", settings saves, onboarding progress, errors). Events run only on LLMagnet admin pages - never in your site visitors' browsers - and are identified by your site domain. Properties include: admin email, site URL, plugin and WordPress versions, site language, WooCommerce active yes/no, and plan/trial status. Data is sent to Mixpanel's EU endpoint (api-eu.mixpanel.com). See Mixpanel's privacy policy: https://mixpanel.com/legal/privacy-policy/

= Visitor analytics stay on your server =

AI bot visit logs (bot name, user-agent string, page path, timestamp) are stored locally in the `wp_llm_bot_visits` and `wp_llm_bot_page_clicks` database tables and are **never sent to any external service**. These tables log AI crawlers - not human visitors.

**Data retention:** logs older than the configured retention window are pruned automatically by a daily background task. The window is controlled by the `llmagnet_data_retention_days` option (allowed values: 30, 90, 180, 365, or 0 to keep forever; default 365).

= Attribution cookie (for your cookie policy) =

To attribute conversions to AI platforms, the plugin sets one first-party cookie on site visitors - but **only** when a visitor lands with a `utm_source` URL parameter matching a known AI platform (ChatGPT/OpenAI, Gemini, Copilot, Perplexity, Claude, DeepSeek, Grok, Bing, Llama/Meta AI, Mistral). Regular visitors never receive it.

* **Name:** `llmagnet_llm_attribution`
* **Purpose:** ties an AI-referred visit to a later purchase (WooCommerce AI revenue funnel)
* **Contents:** a random session ID (UUID), the detected AI platform name, the landing page path, `utm_medium`, `utm_campaign`, and a first-touch timestamp - no IP address and no personal identifiers
* **Lifetime:** 7 days (filterable via `llmagnet_attribution_ttl_days`)
* **Flags:** HttpOnly, SameSite=Lax, Secure on HTTPS sites

A matching session row is stored locally in the `wp_llm_attribution_sessions` table; unconverted sessions are deleted automatically after twice the cookie lifetime. Attribution tracking can be disabled entirely by setting the `llmagnet_attribution_tracking` option to `0` (also available via the `llm-analytics/v1/privacy/settings` REST endpoint) or via the `llmagnet_attribution_tracking_enabled` filter.

= WordPress privacy tools =

LLMagnet registers a personal data **exporter and eraser** (Tools → Export/Erase Personal Data) for attribution session data. Sessions are matched to a person through the billing email of their WooCommerce orders; erasure deletes the session rows and detaches the session from order analytics.

== Installation ==

1. Upload to `/wp-content/plugins/` or install via Plugins → Add New
2. Activate **LLMagnet**
3. Go to **LLMagnet** in your admin menu
4. Configure your content settings and click **Generate Now**
5. Verify at `https://your-site.com/llms.txt`

== Frequently Asked Questions ==

= What is llms.txt? =
`llms.txt` is an emerging standard (like robots.txt for search engines) that helps AI models understand your site structure and content.

= Will this slow down my site? =
No. All file generation happens in the background with zero front-end impact.

= Which AI bots can LLMagnet detect? =
ChatGPT, Claude, Perplexity, Gemini, Grok, Bing AI, Mistral, DeepSeek, Llama, and more.

= Does LLMagnet work with the WordPress Abilities API / MCP? =
Yes. On WordPress 6.9+ LLMagnet registers its analytics and llms.txt tools as WordPress abilities (under the `llmagnet` category). Install the official MCP Adapter plugin to let AI assistants like Claude securely query your AI SEO data using an Application Password. On older WordPress versions the plugin works normally; abilities are simply unavailable.

= Do I need WooCommerce? =
No. Core features work on any WordPress site. WooCommerce analytics are available for Plus and Enterprise plans.

= How is the Product Visibility Score calculated? =
It combines Bot Visibility (70%) – how often AI bots visit your product – with Content Quality (30%) – descriptions, tags, categories, and image ALT texts.

= Which languages does the admin interface support? =
LLMagnet ships bundled translations for the plugin admin dashboard (menus, settings screens, React admin UI, and related notices). Supported locales: **Hebrew** (`he_IL`), **Arabic** (`ar`), and **Spanish (Spain)** (`es_ES`). Set your site language under **Settings → General → Site Language**; WordPress loads the matching translation files automatically. Brand names (LLMagnet) and technical terms (llms.txt, MCP, JSON-LD, product names, and similar) are kept in English. Additional languages can be contributed via the WordPress.org translation project or by adding custom `.mo` and `.json` files to the plugin's `languages/` folder.

= Does the plugin support WordPress multisite? =

No. LLMagnet AI SEO Optimizer is a single-site plugin. It writes llms.txt,
llms-full.txt, and the llms-docs/ Markdown files to the site root directory,
which on a multisite network would be shared by every subsite - each subsite
would overwrite the others' files. To prevent this, the plugin refuses to
activate on multisite installations and shows an explanatory message instead.
Please use it on a standalone (single-site) WordPress installation.

== Screenshots ==

1. Analytics Dashboard with AI bot traffic visualization
2. WooCommerce Products Analytics with visibility scores
3. Product Optimization Drawer
4. AI Revenue Funnel tracking
5. Email Reports configuration

== Changelog ==

= 3.4.1 =
* Bug fix: admin pages (Pages, LLMs.txt, and other lazy-loaded screens) could fail after upgrading with a React "useState" error when the browser served stale JavaScript from cache
* Bug fix: React admin bundles now append the plugin version to lazy-loaded chunk URLs so caches invalidate on every release
* Bug fix: a dismissible admin notice prompts a hard refresh after plugin updates when stale assets may still be cached
* New: bundled admin translations for Hebrew (he_IL), Arabic (ar), and Spanish (Spain, es_ES)

= 3.4.0 =
* New: AI assistant connectivity via the WordPress Abilities API and an on-plugin MCP server (Claude, ChatGPT, Cursor). MCP and OAuth are now opt-in and disabled by default for security
* New: guided onboarding wizard and in-dashboard feedback/bug reporting (attaching the debug log is opt-in, and obvious secrets are masked)
* New: internationalization (i18n) infrastructure for the admin experience
* Security: rate limiting on dynamic OAuth client registration; safer analytics error states instead of silent placeholder data
* Maintenance: raised the minimum required PHP version to 7.4 to match the codebase, plus build/packaging and CI improvements

= 3.3.9 =
* Security hardening: removed leftover development/debug artifacts from the alt-text manager (hardcoded attachment ID fallback, local test URL map, verbose request logging)
* Removed diagnostic test.php file from the distributed plugin
* Multisite: activation is now blocked on multisite networks with a clear
  explanation (the plugin is single-site only; root-level llms.txt files would
  conflict between subsites).

= 3.3.8 =
* Schema.org JSON-LD now apply automaticly when publish the schema
* Bug fixes and stability improvements for AI tracking visits from llms

= 3.3.7 =
* Schema.org JSON-LD – scan, recommendations, guided generation, and publish structured data from the dashboard
* Improved experience across the admin UI and workflows
* Bug fixes and stability improvements

= 3.3.0 =
* Pages & Posts Content Analysis – AI-readiness scores and content quality analysis for all pages and blog posts, just like WooCommerce products
* PDF Report Downloads – Export your AI visibility reports as PDF files
* Notification Center – In-dashboard alerts and updates to keep you informed
* Improved Onboarding – Revamped onboarding flow with better guidance for new users
* Bug fixes and performance improvements

= 3.2.0 =
* Added full llms-full.txt support with extended metadata
* Enhanced llms.txt format with improved structure
* Automatic robots.txt integration for AI crawler guidance
* Full compatibility with RankMath and Yoast SEO
* New recommendations system with actionable visibility improvements
* Enhanced onboarding wizard with better user guidance
* Smart suggestions for meeting AI crawler standards

= 3.1.4 =
* WooCommerce Commerce Analytics (Plus & Enterprise)
* Product Visibility Score with optimization recommendations
* AI Revenue Funnel tracking
* Product Optimization Drawer with tag autocomplete
* Google Lighthouse-style score display

= 3.0.3 =
* Fix count visit in table and cards

= 3.0.2 =
* Change owner to company profile

= 3.0.0 =
* Add clicks analytics from LLM bots via UTM tracking
* Add table view with impressions, clicks, CTR, and trends
* Toggle between card and table views
* Improved analytics dashboard

= 2.0.8 =
* Add ALT text management for images
* Improved plugin activation

= 2.0.0 =
* Analytics per LLM bot agent
* Improved bot detection
* Email reports

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 3.4.1 =
Bug fix release: resolves admin screens failing after upgrade due to cached JavaScript. Hard-refresh your browser (Ctrl+Shift+R) if any page still shows an error.

= 3.4.0 =
Adds AI assistant connectivity (Abilities API + opt-in MCP/OAuth), a guided onboarding wizard, i18n, and security hardening. Now requires PHP 7.4 or newer.

= 3.3.9 =
Security hotfix: removes leftover debug artifacts from production code. Recommended for all users.

= 3.3.7 =
Adds Schema.org JSON-LD tools, general experience improvements, and bug fixes. Recommended for all users.

= 3.2.0 =
Enhanced AI visibility features: llms-full.txt support, RankMath/Yoast compatibility, smart recommendations system, and improved onboarding. Recommended for all users.

= 3.1.3 =
Major update: WooCommerce analytics, Product Visibility Scores, and AI Revenue Funnel tracking. Upgrade recommended for all e-commerce sites.
