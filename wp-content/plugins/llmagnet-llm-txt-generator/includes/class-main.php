<?php
/**
 * Main plugin class
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

// Explicitly require classes that might not be autoloaded correctly
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-analytics.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-email-reports.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-visibility-score.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-attribution.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-product-analytics.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-woocommerce.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-robots-txt.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-mcp.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-mcp-tools.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-mcp-tokens.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-abilities.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-page-details.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-schema-jsonld.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-news-feed.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-privacy.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-score-store.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-post-meta.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-list-table-columns.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-dashboard-widget.php';

// Agent-readiness foundations (Phase C, Lane C3)
// Admin split (improvement plan P2-1): alt-text AJAX, onboarding REST,
// React-shell enqueue/payloads, and the canonical settings sanitizer.
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-settings-sanitizer.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-alt-text-manager.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-onboarding.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-admin-assets.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-react-build-assets.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-i18n-script-translations.php';

require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-agent-readiness-options.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-well-known.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-http-headers.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-seo-plugin-detector.php';

// Agent-readiness Phase D (Lane D3): public skill registry + well-known providers
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-agent-skills-registry.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-well-known-providers.php';

// Phase E shell wiring: Gutenberg editor assets (Lane E1), MCP & AI admin
// REST backend (Lane E2), Agent-Ready audit + WebMCP (Lane E3).
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-editor-assets.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-mcp-admin-rest.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-mcp-oauth.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-agent-audit.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-webmcp.php';

// Agent-readiness Phase F (Lane F-A): markdown endpoints, discovery surface,
// OG verify-or-fill, security headers, IndexNow.
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-markdown-converter.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-markdown-endpoints.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-link-headers.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-schemamap.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-open-graph.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-security-headers.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-indexnow.php';

// Phase F (Lane F-B): adoption surfaces — Elementor, admin bar, Site Health,
// publish nudge.
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-elementor.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-elementor-schema.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-admin-bar.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-site-health.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-publish-nudge.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-upgrade-notice.php';

// Phase F-E polish: structured drawer recommendations + event-driven alerts.
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-readiness-recommendations.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-event-alerts.php';

// Lifecycle emails classes
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-lifecycle-helpers.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-brevo-key-store.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-brevo-client.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-filesystem-helper.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-email-state.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-email-log.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-lifecycle-emails.php';

/**
 * Main plugin class
 */
class Main {
    /**
     * Admin instance
     *
     * @var Admin
     */
    private $admin;

    /**
     * Alt text manager instance (image/alt-text AJAX backend)
     *
     * @var Alt_Text_Manager
     */
    private $alt_text_manager;

    /**
     * Onboarding REST + state instance
     *
     * @var Onboarding
     */
    private $onboarding;

    /**
     * Admin assets (React shell enqueue + localize payloads) instance
     *
     * @var Admin_Assets
     */
    private $admin_assets;

    /**
     * Generator instance
     *
     * @var Generator
     */
    private $generator;

    /**
     * Cron instance
     *
     * @var Cron
     */
    private $cron;
    
    /**
     * Analytics instance
     *
     * @var Analytics
     */
    private $analytics;
    
    /**
     * Email Reports instance
     *
     * @var Email_Reports
     */
    private $email_reports;

    /**
     * Visibility Score instance
     *
     * @var Visibility_Score
     */
    private $visibility_score;

    /**
     * WooCommerce integration instance
     *
     * @var WooCommerce
     */
    private $woocommerce;

    /**
     * Robots_Txt instance
     *
     * @var Robots_Txt
     */
    private $robots_txt;

    /**
     * MCP server instance
     *
     * @var MCP
     */
    private $mcp;

    /**
     * MCP OAuth 2.1 authorization server instance
     *
     * @var MCP_OAuth
     */
    private $mcp_oauth;

    /**
     * Page Details instance
     *
     * @var Page_Details
     */
    private $page_details;

    /**
     * Schema JSON-LD instance
     *
     * @var Schema_Jsonld
     */
    private $schema_jsonld;

    /**
     * News feed instance
     *
     * @var News_Feed
     */
    private $news_feed;

    /**
     * Privacy / data governance instance
     *
     * @var Privacy
     */
    private $privacy;

    /**
     * Post Meta instance
     *
     * @var Post_Meta
     */
    private $post_meta;

    /**
     * List-table "LLM Score" column instance
     *
     * @var List_Table_Columns
     */
    private $list_table_columns;

    /**
     * WP dashboard widget instance
     *
     * @var Dashboard_Widget
     */
    private $dashboard_widget;

    /**
     * Abilities API integration instance
     *
     * @var Abilities
     */
    private $abilities;

    /**
     * Agent-readiness options scaffolding instance
     *
     * @var Agent_Readiness_Options
     */
    private $agent_readiness_options;

    /**
     * Well-Known router instance
     *
     * @var Well_Known
     */
    private $well_known;

    /**
     * HTTP headers manager instance
     *
     * @var Http_Headers
     */
    private $http_headers;

    /**
     * SEO plugin detector instance
     *
     * @var Seo_Plugin_Detector
     */
    private $seo_plugin_detector;

    /**
     * Public agent-skills registry instance
     *
     * @var Agent_Skills_Registry
     */
    private $agent_skills_registry;

    /**
     * Well-known endpoint providers instance
     *
     * @var Well_Known_Providers
     */
    private $well_known_providers;

    /**
     * Gutenberg editor assets instance (Phase E, Lane E1)
     *
     * @var Editor_Assets
     */
    private $editor_assets;

    /**
     * Admin REST backend for the MCP & AI page (Phase E, Lane E2)
     *
     * @var MCP_Admin_REST
     */
    private $mcp_admin_rest;

    /**
     * Agent-Ready audit instance (Phase E, Lane E3)
     *
     * @var Agent_Audit
     */
    private $agent_audit;

    /**
     * WebMCP loader + public REST routes instance (Phase E, Lane E3)
     *
     * @var Webmcp
     */
    private $webmcp;

    /**
     * Markdown endpoints instance (Phase F, Lane F-A)
     *
     * @var Markdown_Endpoints
     */
    private $markdown_endpoints;

    /**
     * Link headers instance (Phase F, Lane F-A)
     *
     * @var Link_Headers
     */
    private $link_headers;

    /**
     * Schemamap provider instance (Phase F, Lane F-A)
     *
     * @var Schemamap
     */
    private $schemamap;

    /**
     * Open Graph verify-or-fill instance (Phase F, Lane F-A)
     *
     * @var Open_Graph
     */
    private $open_graph;

    /**
     * Security headers instance (Phase F, Lane F-A)
     *
     * @var Security_Headers
     */
    private $security_headers;

    /**
     * IndexNow integration instance (Phase F, Lane F-A)
     *
     * @var IndexNow
     */
    private $indexnow;

    /**
     * Elementor editor integration instance (Phase F, Lane F-B)
     *
     * @var Elementor_Integration
     */
    private $elementor_integration;

    /**
     * Elementor per-page JSON-LD schema settings.
     *
     * @var Elementor_Schema
     */
    private $elementor_schema;

    /**
     * Admin-bar score node instance (Phase F, Lane F-B)
     *
     * @var Admin_Bar
     */
    private $admin_bar;

    /**
     * Site Health tests instance (Phase F, Lane F-B)
     *
     * @var Site_Health
     */
    private $site_health;

    /**
     * Post-publish nudge instance (Phase F, Lane F-B)
     *
     * @var Publish_Nudge
     */
    private $publish_nudge;

    /**
     * Post-upgrade cache-refresh admin notice.
     *
     * @var Upgrade_Notice
     */
    private $upgrade_notice;

    /**
     * Event-driven email alerts (Phase F-E)
     *
     * @var Event_Alerts
     */
    private $event_alerts;

    /**
     * Lifecycle Emails instance
     *
     * @var \LLMagnet\Lifecycle\Lifecycle_Emails
     */
    private $lifecycle_emails;

    /**
     * Email State instance
     *
     * @var \LLMagnet\Lifecycle\Email_State
     */
    private $email_state;

    /**
     * Brevo Client instance
     *
     * @var \LLMagnet\Lifecycle\Brevo_Client
     */
    private $brevo_client;

    /**
     * Initialize the plugin
     *
     * @return void
     */
    public function init() {
        // Load local translations from /languages (P2-4.4). Runs on the
        // `init` hook (Main::init is invoked from llmagnet_ai_seo_init on
        // init), as required since WP 6.7. WordPress.org language packs
        // still auto-load without this call.
        load_plugin_textdomain(
            'llmagnet-llm-txt-generator',
            false,
            dirname( LLMAGNET_AISEO_PLUGIN_BASENAME ) . '/languages'
        );

        // Initialize components
        $this->init_components();

        // Setup hooks
        $this->setup_hooks();
    }



    /**
     * Initialize plugin components
     *
     * @return void
     */
    private function init_components() {
        Brevo_Key_Store::maybe_migrate_from_constant();

        // Initialize generator
        $this->generator = new Generator();
        
        // Initialize analytics first so it can be used by Admin
        $this->analytics = new Analytics();
        $this->analytics->init();
        
        // Initialize privacy / GDPR layer (telemetry consent, retention pruning, exporters)
        $this->privacy = new Privacy();
        $this->privacy->init();

        // Initialize admin (menu/pages/settings/core AJAX).
        // Always initialize Admin + the split-out admin components so the
        // REST routes and AJAX hooks are registered in every context.
        $this->admin = new Admin($this->generator);

        // Image/alt-text AJAX backend (split from Admin, P2-1.1)
        $this->alt_text_manager = new Alt_Text_Manager($this->generator);
        $this->alt_text_manager->init();

        // Onboarding REST endpoints + state (split from Admin, P2-1.2)
        $this->onboarding = new Onboarding();
        $this->onboarding->init();

        // Direct plugin URLs for Vite ES modules (Elementor Cloud dynamic_asset fix).
        React_Build_Assets::init();

        // React shell enqueue + localize payloads (split from Admin, P2-1.3)
        $this->admin_assets = new Admin_Assets(
            $this->generator,
            $this->analytics,
            $this->alt_text_manager,
            $this->onboarding
        );
        $this->admin_assets->init();

        // Initialize cron with visibility score instance
        $this->visibility_score = new Visibility_Score();
        $this->visibility_score->init();

        // Initialize email reports before cron so scheduled report callbacks can use the shared instance
        $this->email_reports = new Email_Reports($this->analytics);

        // Initialize cron with generator, visibility score, and email reports
        $this->cron = new Cron($this->generator, $this->visibility_score, $this->email_reports);
        
        // Initialize lifecycle emails components
        $this->brevo_client = new \LLMagnet\Lifecycle\Brevo_Client();
        $this->email_state = new \LLMagnet\Lifecycle\Email_State();
        $email_log = new \LLMagnet\Lifecycle\Email_Log();
        
        $this->lifecycle_emails = new \LLMagnet\Lifecycle\Lifecycle_Emails(
            $this->brevo_client,
            $this->email_state,
            $email_log,
            $this->analytics,
            $this->visibility_score
        );
        $this->lifecycle_emails->init();
        
        // Make the email reports instance globally accessible
        global $llmagnet_email_reports;
        $llmagnet_email_reports = $this->email_reports;
        
        // Make the main plugin instance globally accessible
        global $llmagnet_plugin;
        $llmagnet_plugin = $this;
        
        // Make the visibility score instance globally accessible
        global $llmagnet_visibility_score;
        $llmagnet_visibility_score = $this->visibility_score;

        // Initialize robots.txt integration
        $this->robots_txt = new Robots_Txt();
        $this->robots_txt->init();

        // Initialize WooCommerce integration
        $this->woocommerce = new WooCommerce();
        $this->woocommerce->init();

        // Initialize MCP server (shared tool registry + token manager)
        $mcp_tools  = new MCP_Tools(
            $this->analytics,
            $this->visibility_score,
            $this->generator,
            $this->robots_txt,
            null, // Page_Details — Main instantiates it AFTER MCP; lazy default is fine.
            null, // Attribution — owned by WooCommerce component; lazy default is fine.
            $this->email_reports // Write tool send_report_email (Phase F, FC-1).
        );
        $mcp_tokens = new MCP_Tokens();
        $this->mcp  = new MCP( null, null, $mcp_tools, $mcp_tokens );
        $this->mcp->init();

        // OAuth 2.1 authorization server: lets strict MCP clients (ChatGPT
        // custom connectors, Claude, …) connect privately without a custom
        // Bearer header. Shares the token manager so OAuth-issued access tokens
        // verify through the same MCP auth path.
        $this->mcp_oauth = new MCP_OAuth( $mcp_tokens );
        $this->mcp_oauth->init();

        // WordPress Abilities API (WP 6.9+; clean no-op on older versions)
        $this->abilities = new Abilities( $this->mcp->get_tools_registry() );
        $this->abilities->init();

        // MCP & AI admin page REST backend (mcp-ai-spec §8, Phase E Lane E2).
        // Reuses the shared MCP instance for the self-test loopback + cached defs.
        $this->mcp_admin_rest = new MCP_Admin_REST( $this->mcp );
        $this->mcp_admin_rest->init();

        // Initialize page details (for page/post scoring and drawer)
        $this->page_details = new Page_Details();
        add_action('rest_api_init', [$this->page_details, 'register_rest_routes']);

        // Per-post score store (adoption Phase 0.1/0.2) — idempotent;
        // also booted defensively from Cron::init().
        Score_Store::boot();

        // Plugin post meta registration (llms.txt exclude toggle, §2.1).
        // Passing the Generator lets Post_Meta re-sync export files on the
        // rest_after_insert_* hook (Gutenberg writes meta AFTER save_post, so
        // the Generator's save_post regen would otherwise read the stale value).
        $this->post_meta = new Post_Meta( $this->generator );
        $this->post_meta->init();

        // Admin adoption surfaces (Phase D, Lane D1): list-table score
        // column + WP dashboard widget. Admin screens only.
        if ( is_admin() ) {
            $this->list_table_columns = new List_Table_Columns();
            $this->list_table_columns->init();

            $this->dashboard_widget = new Dashboard_Widget( $this->generator );
            $this->dashboard_widget->init();

            // Gutenberg editor panel + classic meta box assets (Phase E, Lane E1).
            $this->editor_assets = new Editor_Assets();
            $this->editor_assets->init();
        }

        // Phase F, Lane F-B: adoption surfaces.
        // Admin bar renders on the FRONT END too; Site Health REST routes
        // and the publish listener must work in REST/CLI contexts — so
        // these three are NOT behind is_admin().
        $this->admin_bar = new Admin_Bar();
        $this->admin_bar->init();

        $this->site_health = new Site_Health( $this->generator, $this->robots_txt );
        $this->site_health->init();

        $this->publish_nudge = new Publish_Nudge();
        $this->publish_nudge->init();

        $this->upgrade_notice = new Upgrade_Notice();
        $this->upgrade_notice->init();

        $this->event_alerts = new Event_Alerts( $this->analytics );
        $this->event_alerts->init();

        // Elementor editor runs as an admin request; the class's two hooks
        // are Elementor-fired, so this is a no-op without Elementor.
        if ( is_admin() ) {
            $this->elementor_integration = new Elementor_Integration();
            $this->elementor_integration->init();
        }

        $this->elementor_schema = new Elementor_Schema();
        $this->elementor_schema->init();

        $this->schema_jsonld = new Schema_Jsonld();
        $this->schema_jsonld->init();

        $this->news_feed = new News_Feed();
        add_action('rest_api_init', [$this->news_feed, 'register_rest_routes']);

        // Make the WooCommerce instance globally accessible
        global $llmagnet_woocommerce;
        $llmagnet_woocommerce = $this->woocommerce;

        // Agent-readiness foundations (Phase C, Lane C3 — agent-readiness spec Phase 0)
        $this->agent_readiness_options = new Agent_Readiness_Options();
        $this->agent_readiness_options->init();

        $this->well_known = new Well_Known();
        $this->well_known->init();

        $this->http_headers = new Http_Headers();
        $this->http_headers->init();

        $this->seo_plugin_detector = new Seo_Plugin_Detector();
        $this->seo_plugin_detector->init();

        // Agent-readiness Phase D (Lane D3): public skill registry feeding
        // agent-card.json / agent-skills (and WebMCP in Phase E), plus the
        // five Feature-3 well-known providers.
        $this->agent_skills_registry = new Agent_Skills_Registry();

        $this->well_known_providers = new Well_Known_Providers(
            $this->mcp->get_tools_registry(),
            $this->agent_skills_registry
        );
        $this->well_known_providers->init();

        // Agent-readiness Phase E (Lane E3): Agent-Ready audit (F1 + scoring)
        // and WebMCP (F4). Audit REST under llm-analytics/v1/agent-audit/*;
        // public no-auth routes under llm-analytics/v1/public/* (rate-limited,
        // 404 while the webmcp toggle is off). Registers the
        // `get_agent_readiness` MCP tool via the llmagnet_mcp_tools filter, so
        // Agent_Audit::init() must run before any MCP_Tools::get_definitions()
        // call — placing it at the very end of init_components() satisfies that
        // (the registry builds lazily at request time) and guarantees the
        // shared MCP tools registry + agent-skills registry already exist.
        $this->agent_audit = new Agent_Audit(
            $this->generator,
            $this->robots_txt,
            $this->mcp->get_tools_registry(),
            $this->agent_skills_registry
        );
        $this->agent_audit->init();

        $this->webmcp = new Webmcp(
            $this->mcp->get_tools_registry(),
            $this->agent_skills_registry
        );
        $this->webmcp->init();

        // Agent-readiness Phase F (Lane F-A): F5 markdown endpoints +
        // Accept negotiation, F6 Link headers + schemamap.xml, F7 OG
        // verify-or-fill, F8 security headers + IndexNow. All behind
        // Agent_Readiness_Options toggles (default OFF).
        $this->markdown_endpoints = new Markdown_Endpoints(
            $this->mcp->get_tools_registry(),
            $this->generator
        );
        $this->markdown_endpoints->init();

        $this->link_headers = new Link_Headers( $this->markdown_endpoints );
        $this->link_headers->init();

        $this->schemamap = new Schemamap();
        $this->schemamap->init();

        $this->open_graph = new Open_Graph();
        $this->open_graph->init();

        $this->security_headers = new Security_Headers();
        $this->security_headers->init();

        $this->indexnow = new IndexNow( $this->generator );
        $this->indexnow->init();
    }

    /**
     * Setup plugin hooks
     *
     * @return void
     */
    private function setup_hooks() {
        // Post save hook covers create + update; avoid duplicate post_updated firing.
        add_action('save_post', [$this->generator, 'maybe_regenerate'], 10, 2);
        
        // Add llms.txt link tag to wp_head for discoverability
        add_action('wp_head', [$this, 'add_llms_txt_link_tag']);
        
        // Register WP-CLI commands if available
        if (defined('WP_CLI') && WP_CLI) {
            $this->register_cli_commands();
        }
    }

    /**
     * Add llms.txt link tag to the head section
     *
     * @return void
     */
    public function add_llms_txt_link_tag() {
        echo '<link rel="alternate" type="text/plain" href="' . esc_url(home_url('/llms.txt')) . '">' . "\n";

        $llms_full_path = trailingslashit(ABSPATH) . 'llms-full.txt';
        if (file_exists($llms_full_path)) {
            echo '<link rel="alternate" type="text/plain" href="' . esc_url(home_url('/llms-full.txt')) . '">' . "\n";
        }
    }

    /**
     * Register WP-CLI commands
     *
     * @return void
     */
    private function register_cli_commands() {
        \WP_CLI::add_command('llmagnet-ai-seo', new CLI($this->generator, $this->lifecycle_emails));
    }
} 