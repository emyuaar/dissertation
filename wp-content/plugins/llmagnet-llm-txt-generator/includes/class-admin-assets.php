<?php
/**
 * Admin Assets class
 *
 * Admin React shell enqueueing + localize-payload building, extracted
 * verbatim from class-admin.php (improvement plan P2-1.3). Hook names,
 * script handles, payload globals and the
 * llm-analytics/v1/admin-shell/bootstrap REST route are unchanged.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enqueue logic + window-global payload builders for the React admin shell
 */
class Admin_Assets {
    /**
     * Generator instance
     *
     * @var Generator
     */
    private $generator;

    /**
     * Analytics instance
     *
     * @var Analytics
     */
    private $analytics;

    /**
     * Alt text manager instance (imageData payload source)
     *
     * @var Alt_Text_Manager
     */
    private $alt_text_manager;

    /**
     * Onboarding instance (onboarding payload source)
     *
     * @var Onboarding
     */
    private $onboarding;

    /**
     * Constructor
     *
     * @param Generator        $generator        Generator instance
     * @param Analytics        $analytics        Analytics instance (optional)
     * @param Alt_Text_Manager $alt_text_manager Alt text manager instance
     * @param Onboarding       $onboarding       Onboarding instance
     */
    public function __construct(Generator $generator, Analytics $analytics = null, Alt_Text_Manager $alt_text_manager = null, Onboarding $onboarding = null) {
        $this->generator        = $generator;
        $this->analytics        = $analytics;
        $this->alt_text_manager = $alt_text_manager ?: new Alt_Text_Manager($generator);
        $this->onboarding       = $onboarding ?: new Onboarding();
    }

    /**
     * Register hooks (same hook names/priorities as the pre-split Admin)
     *
     * @return void
     */
    public function init() {
        // Add admin scripts and styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // Register the admin-shell bootstrap REST endpoint
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Register the admin-shell bootstrap REST route.
     *
     * @return void
     */
    public function register_rest_routes(): void {
        register_rest_route( 'llm-analytics/v1', '/admin-shell/bootstrap', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_admin_shell_bootstrap' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
            'args'                => [
                'wp_page' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page
     * @return void
     */
    public function enqueue_assets($hook) {
        // Only load on our plugin pages
        if (!in_array($hook, [
            'toplevel_page_llmagnet-ai-seo-optimizer',          // Main dashboard page
            'llmagnet_page_llmagnet-ai-seo-overview',           // Overview page
            'llmagnet_page_llmagnet-ai-seo-pages',              // Pages analytics page
            'llmagnet_page_llmagnet-ai-seo-products',           // Products analytics page (WooCommerce)
            'llmagnet_page_llmagnet-ai-seo-bot-analytics',      // Bot Analytics page
            'llmagnet_page_llmagnet-ai-seo-reports',            // Reports page
            'llmagnet_page_llmagnet-ai-seo-content-settings',   // Content Settings page
            'llmagnet_page_llmagnet-ai-seo-schema-jsonld',       // Schema JSON-LD page
            'llmagnet_page_llmagnet-ai-seo-agent-ready',        // Agent Ready page
            'llmagnet_page_llmagnet-ai-seo-mcp',                // MCP & AI page
            'llmagnet_page_llmagnet-ai-seo-system-information'  // System Information page
        ])) {
            return;
        }

        $this->enqueue_admin_react_shell_styles();

        $wp_page = Admin_WP_Helper::wp_page_from_hook( $hook );
        if ( $wp_page ) {
            $this->enqueue_llmagnet_unified_admin_shell( $hook, $wp_page );
        }
    }

    /**
     * Get plugin settings
     *
     * @return array Settings array
     */
    private function get_settings() {
        return $this->generator->get_settings();
    }

    /**
     * Freemius plan information (shared canonical implementation)
     *
     * @return array Plan data including name, title, and status
     */
    private function get_plan_data() {
        return Admin_WP_Helper::get_plan_data();
    }

    /**
     * Site / install context payload shared by every admin localize payload
     *
     * @return array
     */
    private function get_analytics_data() {
        return [
            'siteDomain'        => wp_parse_url( home_url(), PHP_URL_HOST ),
            'siteUrl'           => home_url(),
            'pluginVersion'     => LLMAGNET_AISEO_VERSION,
            'wordpressVersion'  => get_bloginfo( 'version' ),
            'woocommerceActive' => class_exists( 'WooCommerce' ),
            'siteLanguage'      => get_locale(),
            'adminEmail'        => get_option( 'admin_email' ),
            'installDate'       => get_option( 'llmagnet_install_timestamp', '' ),
            'telemetryOptIn'    => (bool) get_option( 'llmagnet_telemetry_consent', false ),
        ];
    }

    /**
     * Format bytes to human readable format
     *
     * @param int $bytes File size in bytes
     * @return string Formatted file size
     */
    private function format_bytes($bytes) {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }

    /**
     * Enqueue CSS for React mount placeholders and ScreenLoader (skeleton + spinner).
     *
     * @return void
     */
    private function enqueue_admin_react_shell_styles() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        wp_enqueue_style(
            'llmagnet-admin-react-shell',
            LLMAGNET_AISEO_PLUGIN_URL . 'assets/css/admin-react-shell.css',
            [],
            LLMAGNET_AISEO_VERSION
        );
        wp_add_inline_style('llmagnet-admin-react-shell', '#wpfooter { display: none !important; }');

        // Inject a tiny script that fades out the WP content area before navigating
        // so plugin-page transitions feel smooth instead of abrupt.
        add_action('admin_footer', function () {
            echo '<script id="llmagnet-nav-transition">'
                . '(function(){'
                . 'var s=document.createElement("style");'
                . 's.textContent="#wpcontent,#wpbody-content{transition:opacity .18s ease;}"'
                . '+"html.llmagnet-nav #wpcontent,html.llmagnet-nav #wpbody-content{opacity:0;}";'
                . 'document.head.appendChild(s);'
                . 'document.addEventListener("click",function(e){'
                . 'var a=e.target.closest("a[href]");'
                . 'if(!a)return;'
                . 'var h=a.href||"";'
                . 'if(!h||h===location.href||h.startsWith("javascript:")||a.target==="_blank")return;'
                . 'document.documentElement.classList.add("llmagnet-nav");'
                . '},true);'
                . '})();'
                . '</script>' . "\n";
        }, 99);
    }

    /**
     * Build llmagnetDashboardData (shared by enqueue and admin shell REST).
     *
     * @return array
     */
    private function build_llmagnet_dashboard_localize_data(): array {
        // Get dashboard data
        $plan_data = $this->get_plan_data();
        $root_path = $this->generator->get_root_path();
        $is_writable = wp_is_writable($root_path);

        // Log plan data for debugging (only when WP_DEBUG is enabled).
        \llmagnet_aiseo_debug_log('LLMagnet Plan Data: ' . wp_json_encode($plan_data));

        // Get LLMS.txt status
        $llms_txt_path = trailingslashit($root_path) . 'llms.txt';
        $llms_txt_exists = file_exists($llms_txt_path);
        $llms_txt_url = home_url('/llms.txt');
        $llms_txt_size = $llms_txt_exists ? $this->format_bytes(filesize($llms_txt_path)) : null;

        // Get last generated timestamp
        $last_generated = get_option('llmagnet_last_generated');
        $last_generated_formatted = $last_generated ? 
            date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_generated) : 
            null;

        // Get post counts
        $settings = $this->get_settings();
        $posts_count = $this->generator->count_posts_for_export($settings);

        // Get markdown files count
        $markdown_dir = trailingslashit($root_path) . 'llms-docs';
        $markdown_count = 0;
        if (is_dir($markdown_dir)) {
            $markdown_files = glob($markdown_dir . '/*.md');
            $markdown_count = count($markdown_files);
        }

        // Get post types
        $public_post_types = get_post_types(['public' => true], 'objects');
        unset($public_post_types['attachment']);

        $post_types_for_js = [];
        foreach ($public_post_types as $post_type) {
            $post_types_for_js[] = [
                'name' => $post_type->name,
                'label' => $post_type->label
            ];
        }

        // Calculate total count of ALL public post types (for AI availability score)
        $post_types_names = array_keys($public_post_types);
        $total_all_post_types = 0;
        if (!empty($post_types_names)) {
            $args = [
                'post_type' => $post_types_names,
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'fields' => 'ids',
            ];
            $query = new \WP_Query($args);
            $total_all_post_types = $query->found_posts;
        }

        // Calculate per-post-type counts for breakdown
        $post_type_breakdown = [];
        foreach ($public_post_types as $post_type) {
            // Get the post type object to retrieve the correct label
            $type_obj = get_post_type_object($post_type->name);
            $type_label = $type_obj ? $type_obj->labels->name : $post_type->label;

            // Get total count for this post type
            $total_query = new \WP_Query([
                'post_type' => $post_type->name,
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'fields' => 'ids',
            ]);
            $total_count = $total_query->found_posts;

            // Get count for this post type if it's in current settings
            $selected_count = 0;
            if (in_array($post_type->name, $settings['post_types'], true)) {
                $selected_args = [
                    'post_type' => $post_type->name,
                    'post_status' => 'publish',
                    'posts_per_page' => 1,
                    'fields' => 'ids',
                ];

                // Add date filter if set
                if (!empty($settings['days_to_include']) && $settings['days_to_include'] > 0) {
                    $selected_args['date_query'] = [
                        'after' => gmdate('Y-m-d', strtotime('-' . $settings['days_to_include'] . ' days')),
                    ];
                }

                $selected_query = new \WP_Query($selected_args);
                $selected_count = $selected_query->found_posts;
            }

            $post_type_breakdown[] = [
                'name' => $post_type->name,
                'label' => $type_label,  // Use the correct label from post type object
                'total' => $total_count,
                'selected' => $selected_count,
            ];
        }

        // Count items actually included in llms.txt by post type
        $items_in_llms = 0;
        $llms_breakdown_by_type = [];
        if ($llms_txt_exists && file_exists($llms_txt_path)) {
            $llms_content = file_get_contents($llms_txt_path);
            if ($llms_content) {
                // Count links that look like internal post links (simple heuristic)
                // Using 'm' flag for multiline mode
                preg_match_all('/^- \/[^\s]+ /m', $llms_content, $matches);
                $items_in_llms = count($matches[0]);

                // Initialize all post types with 0 count
                foreach ($public_post_types as $post_type) {
                    $type_obj = get_post_type_object($post_type->name);
                    $type_label = $type_obj ? $type_obj->labels->name : $post_type->label;

                    // Initialize each post type
                    $found = false;
                    foreach ($post_type_breakdown as &$pt) {
                        if ($pt['name'] === $post_type->name) {
                            $pt['in_llms'] = 0;
                            $found = true;
                            break;
                        }
                    }
                }

                // Parse post type sections to count by type
                // Format: ## Post Type Label (e.g., "## Posts", "## Pages", "## Products")
                if (preg_match_all('/^## (.+?)$/m', $llms_content, $type_matches)) {
                    foreach ($type_matches[1] as $section_label) {
                        // Skip non-post-type sections
                        if (in_array(strtolower($section_label), ['featured image', 'markdown exports', 'post images'], true)) {
                            continue;
                        }

                        // Count items under each section until the next section
                        $pattern = '/^## ' . preg_quote($section_label) . '$(.*?)(?=^##|$)/ms';
                        preg_match($pattern, $llms_content, $section_match);

                        if (!empty($section_match[1])) {
                            // Count list items (lines starting with -)
                            preg_match_all('/^- \/[^\s]+ /m', $section_match[1], $items);
                            $count = count($items[0]);

                            if ($count > 0) {
                                // Try to match this section label to a post type
                                $matched = false;
                                foreach ($post_type_breakdown as &$pt) {
                                    $type_obj = get_post_type_object($pt['name']);
                                    $type_label = $type_obj ? $type_obj->labels->name : $pt['label'];

                                    // Check if the section label matches the post type name (plural form)
                                    if (strtolower($section_label) === strtolower($type_label) ||
                                        strtolower($section_label) === strtolower($pt['label'])) {
                                        $pt['in_llms'] = $count;
                                        $matched = true;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // Ensure all post types have the in_llms field set
        foreach ($post_type_breakdown as &$pt) {
            if (!isset($pt['in_llms'])) {
                $pt['in_llms'] = 0;
            }
        }

        // Get image data
        $image_data = null;
        if (!empty($settings['llm_response_image_id'])) {
            $image_data = $this->alt_text_manager->get_image_data($settings['llm_response_image_id']);
        }

        // Get bot visits for the last 30 days
        $bot_visits_30_days = 0;
        if ($this->analytics) {
            $bot_visits_30_days = $this->analytics->get_total_bot_visits_last_days(30);
        }

        // Get bot clicks for the last 30 days
        $bot_clicks_30_days = 0;
        if ($this->analytics) {
            $bot_clicks_30_days = $this->analytics->get_total_bot_clicks_last_days(30);
        }

        // Get visibility score data
        $visibility_score_data = null;
        global $llmagnet_visibility_score;
        if ($llmagnet_visibility_score) {
            $visibility_score_data = $llmagnet_visibility_score->get_score_breakdown(30);
        } else {
            // Create instance if not available globally
            $visibility_score = new Visibility_Score();
            $visibility_score_data = $visibility_score->get_score_breakdown(30);
        }

        $current_user = wp_get_current_user();
        $first_name   = trim( (string) get_user_meta( $current_user->ID, 'first_name', true ) );
        $last_name    = trim( (string) get_user_meta( $current_user->ID, 'last_name', true ) );
        if ( $first_name !== '' && $last_name !== '' ) {
            $onboarding_user_display_name = $first_name . ' ' . $last_name;
        } else {
            $onboarding_user_display_name = $current_user->user_login;
        }

        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('llmagnet_ai_seo_nonce'),
            'rootPath' => esc_html($root_path),
            'isWritable' => $is_writable,
            'lastGenerated' => $last_generated_formatted,
            'llmsTxtExists' => $llms_txt_exists,
            'llmsTxtUrl' => $llms_txt_url,
            'llmsTxtSize' => $llms_txt_size,
            'postsCount' => $posts_count,
            'markdownCount' => $markdown_count,
            'itemsInLlms' => $items_in_llms,
            'totalAllPostTypes' => $total_all_post_types,
            'botVisits30Days' => $bot_visits_30_days,
            'botClicks30Days' => $bot_clicks_30_days,
            'visibilityScore' => $visibility_score_data,
            'imageCount' => get_option('llmagnet_ai_seo_optimizer_image_count', 0),
            'imagesWithoutAlt' => get_option('llmagnet_ai_seo_optimizer_images_without_alt', []),
            'settings' => $settings,
            'postTypes' => $post_types_for_js,
            'isPremium' => $plan_data['is_premium'],
            'planData' => $plan_data,
            'imageData' => $image_data,
            'pluginVersion' => LLMAGNET_AISEO_VERSION,
            'wordpressVersion' => get_bloginfo('version'),
            'pluginUrl' => LLMAGNET_AISEO_PLUGIN_URL,
            'siteName' => get_bloginfo('name'),
            'woocommerce' => WooCommerce::get_localized_data(),
            'llmsFullInfo' => $this->generator->get_llms_full_info(),
            'robotsTxtStatus' => ( new Robots_Txt() )->get_status(),
            'onboarding' => $this->onboarding->build_onboarding_payload(),
            'onboardingUserDisplayName' => $onboarding_user_display_name,
            'analyticsData' => $this->get_analytics_data(),
        ];

    }

    /**
     * Localized globals for a given plugin admin page (merged into window by admin-shell.js).
     *
     * @param string $wp_page Page slug (?page= value).
     * @return array<string, mixed>
     */
    private function get_admin_shell_globals_for_wp_page( string $wp_page ): array {
        switch ( $wp_page ) {
            case 'llmagnet-ai-seo-optimizer':
            case 'llmagnet-ai-seo-overview':
                return [
                    'llmagnetDashboardData' => $this->build_llmagnet_dashboard_localize_data(),
                ];
            case 'llmagnet-ai-seo-pages':
                return [
                    'llmagnetPagesData' => $this->build_pages_localize_data(),
                ];
            case 'llmagnet-ai-seo-bot-analytics':
                return [
                    'llmagnetBotAnalyticsData' => $this->build_bot_analytics_localize_data(),
                ];
            case 'llmagnet-ai-seo-products':
                return [
                    'llmagnetProductsData' => $this->build_products_localize_data(),
                ];
            case 'llmagnet-ai-seo-reports':
                return [
                    'llmagnetReportsData' => $this->build_reports_localize_data(),
                ];
            case 'llmagnet-ai-seo-content-settings':
                return [
                    'llmagnetContentSettingsData' => $this->build_content_settings_localize_data(),
                ];
            case 'llmagnet-ai-seo-schema-jsonld':
                return [
                    'llmagnetSchemaData' => $this->build_schema_jsonld_localize_data(),
                ];
            case 'llmagnet-ai-seo-system-information':
                return [
                    'llmagnetSystemInfoData' => $this->build_system_information_localize_data(),
                ];
            case 'llmagnet-ai-seo-agent-ready':
                return [
                    'llmagnetAgentReadyData' => $this->build_agent_ready_localize_data(),
                ];
            case 'llmagnet-ai-seo-mcp':
                return [
                    'llmagnetMcpData' => $this->build_mcp_localize_data(),
                ];
            default:
                return [];
        }
    }

    /**
     * Pages analytics window payload.
     *
     * @return array
     */
    private function build_pages_localize_data(): array {
        $plan_data = $this->get_plan_data();
        return [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'llmagnet_ai_seo_nonce' ),
            'pluginUrl'     => LLMAGNET_AISEO_PLUGIN_URL,
            'siteName'      => get_bloginfo( 'name' ),
            'planData'      => $plan_data,
            'analyticsData' => $this->get_analytics_data(),
        ];
    }

    /**
     * Bot analytics window payload.
     *
     * @return array
     */
    private function build_bot_analytics_localize_data(): array {
        $plan_data = $this->get_plan_data();
        return [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'llmagnet_ai_seo_nonce' ),
            'pluginUrl'     => LLMAGNET_AISEO_PLUGIN_URL,
            'siteName'      => get_bloginfo( 'name' ),
            'planData'      => $plan_data,
            'analyticsData' => $this->get_analytics_data(),
        ];
    }

    /**
     * Products analytics window payload.
     *
     * @return array
     */
    private function build_products_localize_data(): array {
        $plan_data = $this->get_plan_data();
        return [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'llmagnet_ai_seo_nonce' ),
            'pluginUrl'     => LLMAGNET_AISEO_PLUGIN_URL,
            'siteName'      => get_bloginfo( 'name' ),
            'planData'      => $plan_data,
            'woocommerce' => WooCommerce::get_localized_data(),
            'analyticsData' => $this->get_analytics_data(),
        ];
    }

    /**
     * Reports window payload.
     *
     * @return array
     */
    private function build_reports_localize_data(): array {
        $plan_data = $this->get_plan_data();
        return [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'llmagnet_ai_seo_nonce' ),
            'pluginUrl'     => LLMAGNET_AISEO_PLUGIN_URL,
            'isPremium'     => $plan_data['plan_name'] !== 'free',
            'planData'      => $plan_data,
            'analyticsData' => $this->get_analytics_data(),
        ];
    }

    /**
     * Schema JSON-LD admin payload.
     *
     * @return array<string, mixed>
     */
    private function build_schema_jsonld_localize_data(): array {
        $plan_data = $this->get_plan_data();
        $published = get_option( Schema_Jsonld::OPTION_PUBLISHED, '' );
        $decoded   = is_string( $published ) && '' !== $published ? json_decode( $published, true ) : null;
        $settings  = get_option( Schema_Jsonld::OPTION_SETTINGS, [] );
        $enabled   = ! is_array( $settings ) || ! array_key_exists( 'enabled', $settings ) || ! empty( $settings['enabled'] );

        return [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'llmagnet_ai_seo_nonce' ),
            'pluginUrl'     => LLMAGNET_AISEO_PLUGIN_URL,
            'siteName'      => get_bloginfo( 'name' ),
            'siteUrl'       => home_url( '/' ),
            'siteLogoUrl'   => Schema_Jsonld::get_site_logo_url(),
            'planData'      => $plan_data,
            'analyticsData' => $this->get_analytics_data(),
            'wooCommerceActive' => class_exists( 'WooCommerce' ),
            'publishedGraph'  => is_array( $decoded ) ? $decoded : null,
            'draftGraph'      => Schema_Jsonld::get_draft_graph(),
            'savedWizard'     => Schema_Jsonld::get_saved_wizard_form(),
            'schemaEnabled'   => $enabled,
            'canFix'          => Schema_Jsonld::can_use_schema_tools(),
            'canStoreSchema'  => Schema_Jsonld::can_use_commerce_schema(),
            'restNamespace'   => 'llm-analytics/v1',
            'elementorActive' => Elementor_Integration::is_active(),
            'seoSchemaOwner'  => Seo_Plugin_Detector::get_json_ld_owner_label(),
        ];
    }

    /**
     * Agent Ready window payload.
     *
     * @return array<string, mixed>
     */
    private function build_agent_ready_localize_data(): array {
        $plan_data = $this->get_plan_data();
        return [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'llmagnet_ai_seo_nonce' ),
            'pluginUrl'     => LLMAGNET_AISEO_PLUGIN_URL,
            'siteName'      => get_bloginfo( 'name' ),
            'planData'      => $plan_data,
            'analyticsData' => $this->get_analytics_data(),
            'restNamespace' => 'llm-analytics/v1',
        ];
    }

    /**
     * MCP & AI window payload.
     *
     * The page fetches live status from GET /mcp/status on mount; this payload
     * only carries the shell-standard keys (plan, telemetry consent, REST ns).
     *
     * @return array<string, mixed>
     */
    private function build_mcp_localize_data(): array {
        $plan_data = $this->get_plan_data();
        return [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'llmagnet_ai_seo_nonce' ),
            'pluginUrl'     => LLMAGNET_AISEO_PLUGIN_URL,
            'siteName'      => get_bloginfo( 'name' ),
            'planData'      => $plan_data,
            'analyticsData' => $this->get_analytics_data(),
            'restNamespace' => 'llm-analytics/v1',
        ];
    }
    /**
     * Content settings (LLMs.txt) window payload.
     *
     * @return array
     */
    private function build_content_settings_localize_data(): array {
        $plan_data = $this->get_plan_data();
        $settings  = get_option(
            'llmagnet_ai_seo_optimizer_settings',
            [
                'post_types'          => [ 'post', 'page' ],
                'full_content'        => false,
                'days_to_include'     => 0,
                'delete_on_uninstall' => false,
                'include_images'      => false,
            ]
        );

        $post_types_for_js = [];
        $post_types        = get_post_types( [ 'public' => true ], 'objects' );
        foreach ( $post_types as $post_type ) {
            if ( $post_type->name !== 'attachment' ) {
                $post_types_for_js[] = [
                    'name'  => $post_type->name,
                    'label' => $post_type->labels->name,
                ];
            }
        }

        $llms_txt_path    = $this->generator->get_root_path() . 'llms.txt';
        $llms_txt_preview = '';
        if ( file_exists( $llms_txt_path ) ) {
            $llms_txt_content = file_get_contents( $llms_txt_path );
            if ( false !== $llms_txt_content ) {
                $llms_txt_preview = mb_substr( $llms_txt_content, 0, 500, 'UTF-8' );
                if ( mb_strlen( $llms_txt_content, 'UTF-8' ) > 500 ) {
                    $llms_txt_preview .= '...';
                }
            }
        }

        $woo_active     = WooCommerce::is_active();
        $llms_full_info = $this->generator->get_llms_full_info();

        return [
            'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
            'nonce'             => wp_create_nonce( 'llmagnet_ai_seo_nonce' ),
            'pluginUrl'         => LLMAGNET_AISEO_PLUGIN_URL,
            'settings'          => $settings,
            'postTypes'         => $post_types_for_js,
            'planData'          => $plan_data,
            'llmsTxtPreview'    => $llms_txt_preview,
            'llmsTxtUrl'        => home_url( '/llms.txt' ),
            'wooCommerceActive' => $woo_active,
            'llmsFullInfo'      => $llms_full_info,
            'robotsTxtInject'   => (bool) get_option( 'llmagnet_robots_txt_inject', true ),
            'robotsTxtStatus'   => ( new Robots_Txt() )->get_status(),
            'analyticsData'     => $this->get_analytics_data(),
        ];
    }

    /**
     * System information window payload.
     *
     * @return array
     */
    private function build_system_information_localize_data(): array {
        $root_path    = $this->generator->get_root_path();
        $is_writable  = wp_is_writable( $root_path );
        $llms_txt_path = trailingslashit( $root_path ) . 'llms.txt';
        $llms_txt_exists = file_exists( $llms_txt_path );
        $llms_txt_size   = $llms_txt_exists ? $this->format_bytes( filesize( $llms_txt_path ) ) : null;
        $last_generated  = get_option( 'llmagnet_last_generated' );
        $last_generated_formatted = $last_generated
            ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_generated )
            : null;

        $posts_count   = wp_count_posts( 'post' )->publish;
        $pages_count   = wp_count_posts( 'page' )->publish;
        $markdown_dir  = trailingslashit( $root_path ) . 'llms-docs';
        $markdown_count = 0;
        if ( is_dir( $markdown_dir ) ) {
            $markdown_files = glob( $markdown_dir . '/*.md' );
            $markdown_count = $markdown_files ? count( $markdown_files ) : 0;
        }

        $image_count = get_option( 'llmagnet_ai_seo_optimizer_image_count', 0 );
        $plan_data   = $this->get_plan_data();

        return [
            'wordpressVersion' => get_bloginfo( 'version' ),
            'pluginVersion'      => LLMAGNET_AISEO_VERSION,
            'pluginUrl'          => LLMAGNET_AISEO_PLUGIN_URL,
            'planData'           => $plan_data,
            'rootPath'           => esc_html( $root_path ),
            'llmsTxtExists'      => $llms_txt_exists,
            'llmsTxtSize'        => $llms_txt_size,
            'postsCount'         => $posts_count + $pages_count,
            'markdownCount'      => $markdown_count,
            'imageCount'         => $image_count,
            'isWritable'         => $is_writable,
            'lastGenerated'      => $last_generated_formatted,
            'analyticsData'      => $this->get_analytics_data(),
        ];
    }

    /**
     * Enqueue a single React bundle for all plugin admin pages (persistent shell).
     *
     * @param string $hook    Admin hook.
     * @param string $wp_page Page slug (?page= value).
     * @return void
     */
    private function enqueue_llmagnet_unified_admin_shell( string $hook, string $wp_page ): void {
        $media_pages = [
            'llmagnet-ai-seo-optimizer',
            'llmagnet-ai-seo-overview',
            'llmagnet-ai-seo-reports',
            'llmagnet-ai-seo-schema-jsonld',
        ];
        if ( in_array( $wp_page, $media_pages, true ) ) {
            wp_enqueue_media();
        }

        $dev_mode = defined( 'LLMAGNET_AISEO_DEV_MODE' ) && LLMAGNET_AISEO_DEV_MODE;

        if ( $dev_mode ) {
            wp_enqueue_script(
                'llmagnet-admin-shell',
                'http://localhost:5173/src/admin-shell-main.tsx',
                [ 'wp-i18n' ],
                LLMAGNET_AISEO_VERSION,
                true
            );
        } else {
            wp_enqueue_style(
                'llmagnet-admin-shell-css',
                LLMAGNET_AISEO_PLUGIN_URL . 'assets/react-build/css/index.css',
                [],
                LLMAGNET_AISEO_VERSION
            );
            wp_enqueue_script(
                'llmagnet-admin-shell',
                LLMAGNET_AISEO_PLUGIN_URL . 'assets/react-build/js/admin-shell.js',
                [ 'wp-i18n' ],
                LLMAGNET_AISEO_VERSION,
                true
            );
            add_filter(
                'script_loader_tag',
                function ( $tag, $handle, $src ) {
                    if ( 'llmagnet-admin-shell' === $handle ) {
                        return '<script type="module" src="' . esc_url( $src ) . '" id="' . esc_attr( $handle ) . '-js"></script>' . "\n";
                    }
                    return $tag;
                },
                10,
                3
            );
        }

        $globals = $this->get_admin_shell_globals_for_wp_page( $wp_page );
        wp_localize_script(
            'llmagnet-admin-shell',
            'llmagnetAdminShell',
            [
                'wpPage'        => $wp_page,
                'globals'       => $globals,
                // Direct plugin URLs for lazy-loaded page bundles. Hosts such as
                // Elementor Cloud serve enqueued scripts via index.php?dynamic_asset=…
                // which breaks relative dynamic import() resolution in ES modules.
                'assetsBaseUrl' => React_Build_Assets::js_base_url(),
                'pageScripts'   => React_Build_Assets::admin_page_scripts(),
                'sharedChunks'  => [
                    'bot-traffic-chart' => React_Build_Assets::js_file_url( 'bot-traffic-chart' ),
                ],
                'version'       => LLMAGNET_AISEO_VERSION,
            ]
        );
        wp_localize_script(
            'llmagnet-admin-shell',
            'wpApiSettings',
            [
                'root'  => esc_url_raw( rest_url() ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
            ]
        );

        // JS translation files for wp.i18n (__()) strings in the bundle
        // (improvement plan P2-4.2; harmless until .json files ship).
        wp_set_script_translations( 'llmagnet-admin-shell', 'llmagnet-llm-txt-generator' );
        // ES modules drop core's inline setLocaleData — inject manually on wp-i18n.
        I18n_Script_Translations::inject_module_translations();
    }

    /**
     * REST: JSON payload for client-side navigation inside the admin shell.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function rest_admin_shell_bootstrap( \WP_REST_Request $request ) {
        $wp_page = sanitize_text_field( (string) $request->get_param( 'wp_page' ) );
        if ( ! Admin_WP_Helper::is_plugin_wp_page( $wp_page ) ) {
            return new \WP_Error( 'invalid_page', 'Invalid wp_page', [ 'status' => 400 ] );
        }
        return new \WP_REST_Response(
            [
                'wpPage'        => $wp_page,
                'globals'       => $this->get_admin_shell_globals_for_wp_page( $wp_page ),
                'wpApiSettings' => [
                    'root'  => esc_url_raw( rest_url() ),
                    'nonce' => wp_create_nonce( 'wp_rest' ),
                ],
                'assetsBaseUrl' => React_Build_Assets::js_base_url(),
                'pageScripts'   => React_Build_Assets::admin_page_scripts(),
                'sharedChunks'  => [
                    'bot-traffic-chart' => React_Build_Assets::js_file_url( 'bot-traffic-chart' ),
                ],
                'version'       => LLMAGNET_AISEO_VERSION,
            ],
            200
        );
    }
}
