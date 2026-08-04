<?php
/**
 * Admin class
 *
 * Admin menu/pages, settings registration, form + core AJAX handlers
 * (generate now / save settings) and admin notices. The image/alt-text
 * AJAX backend, onboarding REST endpoints and React-shell
 * enqueue/payload logic were extracted to class-alt-text-manager.php,
 * class-onboarding.php and class-admin-assets.php (improvement plan
 * P2-1); hook names, AJAX actions and REST routes are unchanged.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

// Require WooCommerce class for is_active() check
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-woocommerce.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-admin-wp-helper.php';
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-settings-sanitizer.php';

/**
 * Admin class for settings page and admin functionality
 */
class Admin {
    /**
     * Generator instance
     *
     * @var Generator
     */
    private $generator;

    /**
     * Constructor
     *
     * @param Generator $generator Generator instance
     */
    public function __construct(Generator $generator) {
        $this->generator = $generator;

        // Ensure WordPress user functions are available
        if (!function_exists('wp_get_current_user') || !function_exists('current_user_can')) {
            require_once(ABSPATH . 'wp-includes/pluggable.php');
        }

        $this->init();
    }

    /**
     * Initialize admin functionality
     *
     * @return void
     */
    public function init() {
        // Add settings page
        add_action('admin_menu', [$this, 'add_settings_page']);

        // Register settings
        add_action('admin_init', [$this, 'register_settings']);

        // Handle form submissions
        add_action('admin_init', [$this, 'handle_form_submissions']);

        // Add AJAX handler for manual generation
        add_action('wp_ajax_llmagnet_ai_seo_generate_now', [$this, 'ajax_generate_now']);

        // Add AJAX handler for saving settings
        add_action('wp_ajax_llmagnet_ai_seo_save_settings', [$this, 'ajax_save_settings']);

        // Add admin notices
        add_action('admin_notices', [$this, 'admin_notices']);
    }

    /**
     * Add main menu page
     *
     * @return void
     */
    public function add_settings_page() {
        // Get SVG icon
        $icon_svg = $this->get_menu_icon();
        
        // Add main menu page
        add_menu_page(
            esc_html__('LLMagnet AI SEO', 'llmagnet-llm-txt-generator'), // Page title
            esc_html__('LLMagnet', 'llmagnet-llm-txt-generator'),        // Menu title
            'manage_options',                                             // Capability
            'llmagnet-ai-seo-optimizer',                                 // Menu slug
            [$this, 'render_main_page'],                                // Callback
            $icon_svg,                                                   // Icon
            26                                                           // Position (below Comments=25)
        );
        
        // Add submenu pages
        add_submenu_page(
            'llmagnet-ai-seo-optimizer',                                // Parent slug
            esc_html__('Dashboard', 'llmagnet-llm-txt-generator'),      // Page title
            esc_html__('Dashboard', 'llmagnet-llm-txt-generator'),      // Menu title
            'manage_options',                                            // Capability
            'llmagnet-ai-seo-optimizer',                                // Menu slug (same as parent for main page)
            [$this, 'render_main_page']                                 // Callback
        );
        
        // Analytics page removed - functionality integrated into main dashboard
        
        // Pages analytics page
        add_submenu_page(
            'llmagnet-ai-seo-optimizer',                                // Parent slug
            esc_html__('Pages', 'llmagnet-llm-txt-generator'),          // Page title
            esc_html__('Pages', 'llmagnet-llm-txt-generator'),          // Menu title
            'manage_options',                                            // Capability
            'llmagnet-ai-seo-pages',                                    // Menu slug
            [$this, 'render_pages_page']                                // Callback
        );
        
        // Bot Analytics page
        add_submenu_page(
            'llmagnet-ai-seo-optimizer',                                // Parent slug
            esc_html__('Bot Analytics', 'llmagnet-llm-txt-generator'), // Page title
            esc_html__('Bot Analytics', 'llmagnet-llm-txt-generator'), // Menu title
            'manage_options',                                            // Capability
            'llmagnet-ai-seo-bot-analytics',                           // Menu slug
            [$this, 'render_bot_analytics_page']                       // Callback
        );
        
        // Products page (WooCommerce only)
        // Use direct plugin check since admin_menu runs early
        $active_plugins = get_option('active_plugins', []);
        $is_woo_active = in_array('woocommerce/woocommerce.php', $active_plugins) || class_exists('WooCommerce');
        if ($is_woo_active) {
            add_submenu_page(
                'llmagnet-ai-seo-optimizer',                                // Parent slug
                esc_html__('Products', 'llmagnet-llm-txt-generator'),       // Page title
                esc_html__('Products', 'llmagnet-llm-txt-generator'),       // Menu title
                'manage_options',                                            // Capability
                'llmagnet-ai-seo-products',                                 // Menu slug
                [$this, 'render_products_page']                             // Callback
            );
        }
        
        // Reports page
        add_submenu_page(
            'llmagnet-ai-seo-optimizer',                                // Parent slug
            esc_html__('Reports', 'llmagnet-llm-txt-generator'),        // Page title
            esc_html__('Reports', 'llmagnet-llm-txt-generator'),        // Menu title
            'manage_options',                                            // Capability
            'llmagnet-ai-seo-reports',                                  // Menu slug
            [$this, 'render_reports_page']                              // Callback
        );
        
        // LLMs.txt page
        add_submenu_page(
            'llmagnet-ai-seo-optimizer',                                // Parent slug
            esc_html__('LLMs.txt', 'llmagnet-llm-txt-generator'),       // Page title
            esc_html__('LLMs.txt', 'llmagnet-llm-txt-generator'),       // Menu title
            'manage_options',                                            // Capability
            'llmagnet-ai-seo-content-settings',                        // Menu slug
            [$this, 'render_content_settings_page']                     // Callback
        );

        add_submenu_page(
            'llmagnet-ai-seo-optimizer',
            esc_html__( 'Schema JSON-LD', 'llmagnet-llm-txt-generator' ),
            esc_html__( 'Schema JSON-LD', 'llmagnet-llm-txt-generator' ),
            'manage_options',
            'llmagnet-ai-seo-schema-jsonld',
            [ $this, 'render_schema_jsonld_page' ]
        );

        // Agent Ready page (agent-readiness Phase 0.3 stub)
        add_submenu_page(
            'llmagnet-ai-seo-optimizer',                                    // Parent slug
            esc_html__( 'Agent Ready', 'llmagnet-llm-txt-generator' ),      // Page title
            esc_html__( 'Agent Ready', 'llmagnet-llm-txt-generator' ),      // Menu title
            'manage_options',                                                // Capability
            'llmagnet-ai-seo-agent-ready',                                  // Menu slug
            [ $this, 'render_agent_ready_page' ]                            // Callback
        );

        // MCP & AI page (mcp-ai-spec §6.1, Phase E Lane E2)
        add_submenu_page(
            'llmagnet-ai-seo-optimizer',                                // Parent slug
            esc_html__( 'MCP & AI', 'llmagnet-llm-txt-generator' ),     // Page title
            esc_html__( 'MCP & AI', 'llmagnet-llm-txt-generator' ),     // Menu title
            'manage_options',                                            // Capability
            'llmagnet-ai-seo-mcp',                                      // Menu slug
            [ $this, 'render_mcp_page' ]                                // Callback
        );

        // Overview page removed - now integrated as main dashboard
        
        // Settings page
        add_submenu_page(
            'llmagnet-ai-seo-optimizer',                                // Parent slug
            esc_html__('Settings', 'llmagnet-llm-txt-generator'), // Page title
            esc_html__('Settings', 'llmagnet-llm-txt-generator'), // Menu title
            'manage_options',                                            // Capability
            'llmagnet-ai-seo-system-information',                      // Menu slug
            [$this, 'render_system_information_page']                    // Callback
        );
        
        // Settings page removed - all functionality moved to main dashboard
    }

    /**
     * Get menu icon SVG
     *
     * @return string
     */
    private function get_menu_icon() {
        // Read SVG from llmagnet-icon.svg file
        $icon_path = LLMAGNET_AISEO_PLUGIN_DIR . 'assets/llmagnet-icon.svg';
        
        if (file_exists($icon_path)) {
            $svg = file_get_contents($icon_path);
            // Replace fill="white" with fill="currentColor" for WordPress admin menu compatibility
            $svg = str_replace('fill="white"', 'fill="currentColor"', $svg);
            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        }
        
        // Fallback to a simple icon if file doesn't exist
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                  <circle cx="10" cy="10" r="8" fill="currentColor" opacity="0.3"/>
                  <circle cx="10" cy="10" r="4" fill="currentColor"/>
                </svg>';
        
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Handle form submissions
     *
     * @return void
     */
    public function handle_form_submissions() {
        // Handle generate now button from dashboard
        if (isset($_POST['action']) && $_POST['action'] === 'generate_now' && 
            isset($_POST['llmagnet_nonce']) && 
            wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['llmagnet_nonce'])), 'llmagnet_generate_now')) {
            
            if (current_user_can('manage_options')) {
                $result = $this->generator->generate_all();
                
                if ($result) {
                    add_action('admin_notices', function() {
                        echo '<div class="notice notice-success is-dismissible"><p>';
                        echo esc_html__('LLMS.txt generated successfully!', 'llmagnet-llm-txt-generator');
                        echo '</p></div>';
                    });
                } else {
                    add_action('admin_notices', function() {
                        echo '<div class="notice notice-error is-dismissible"><p>';
                        echo esc_html__('Error generating LLMS.txt. Please check server permissions.', 'llmagnet-llm-txt-generator');
                        echo '</p></div>';
                    });
                }
                
                // Redirect to prevent form resubmission
                wp_redirect(admin_url('admin.php?page=llmagnet-ai-seo-optimizer'));
                exit;
            }
        }
    }

    /**
     * Register settings
     *
     * @return void
     */
    public function register_settings() {
        register_setting(
            'llmagnet_ai_seo_settings',
            Generator::OPTION_NAME,
            [$this, 'sanitize_settings']
        );
    }

    /**
     * Sanitize settings (Settings API callback)
     *
     * Delegates to the canonical Settings_Sanitizer so every write path of
     * the main settings option shares one implementation (P2-1.5).
     *
     * @param array $input Settings input
     * @return array
     */
    public function sanitize_settings($input) {
        return Settings_Sanitizer::sanitize($input);
    }

    /**
     * Render main dashboard page
     *
     * @return void
     */
    public function render_main_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Add CSS to hide WordPress admin notices within our app
        ?>
        <style type="text/css">
            /* Hide WordPress admin notices within our dashboard */
            .llmagnet-dashboard-container .notice,
            .llmagnet-dashboard-container .updated,
            .llmagnet-dashboard-container .update-nag,
            .llmagnet-dashboard-container .error,
            .llmagnet-dashboard-container .is-dismissible {
                display: none !important;
            }
            
            /* Hide all admin notices that might appear above our container */
            .wrap > .notice,
            .wrap > .updated,
            .wrap > .update-nag,
            .wrap > .error,
            .wrap > .is-dismissible {
                display: none !important;
            }
        </style>
        
        <div class="llmagnet-dashboard-container">
            <?php $this->render_react_app_mount_placeholder('llms-admin-shell-root'); ?>
        </div>
        
        <!-- Dashboard data is now loaded via wp_localize_script in enqueue_assets() -->
        <?php
    }

    /**
     * Render overview page (for development/testing)
     *
     * @return void
     */
    public function render_overview_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Add CSS to hide WordPress admin notices within our app
        ?>
        <style type="text/css">
            /* Hide WordPress admin notices within our overview */
            .llmagnet-overview-container .notice,
            .llmagnet-overview-container .updated,
            .llmagnet-overview-container .update-nag,
            .llmagnet-overview-container .error,
            .llmagnet-overview-container .is-dismissible {
                display: none !important;
            }
            
            /* Hide all admin notices that might appear above our container */
            .wrap > .notice,
            .wrap > .updated,
            .wrap > .update-nag,
            .wrap > .error,
            .wrap > .is-dismissible {
                display: none !important;
            }
        </style>
        
        <div class="wrap llmagnet-overview-container">
            <?php $this->render_react_app_mount_placeholder('llms-admin-shell-root'); ?>
        </div>
        
        <!-- Overview data is now loaded via wp_localize_script in enqueue_assets() -->
        <?php
    }

    /**
     * HTML inside the React root before JS runs (replaced on mount). No per-page copy.
     *
     * @param string $container_id DOM id for React root.
     * @param string $variant      'default' | 'full' — full uses viewport height.
     * @return void
     */
    private function render_react_app_mount_placeholder($container_id, $variant = 'default') {
        $classes = 'llmagnet-react-shell';
        if ('full' === $variant) {
            $classes .= ' llmagnet-react-shell--full';
        }
        ?>
        <div id="<?php echo esc_attr($container_id); ?>" class="<?php echo esc_attr($classes); ?>">
            <div class="lrs-topbar" aria-hidden="true">
                <div class="lrs-sk lrs-sk-logo"></div>
                <div class="lrs-topbar-right">
                    <div class="lrs-sk lrs-sk-icon"></div>
                    <div class="lrs-sk lrs-sk-chip"></div>
                </div>
            </div>
            <div class="lrs-body" aria-hidden="true">
                <div class="lrs-row">
                    <div class="lrs-sk lrs-card"></div>
                    <div class="lrs-sk lrs-card"></div>
                    <div class="lrs-sk lrs-card"></div>
                    <div class="lrs-sk lrs-card"></div>
                </div>
                <div class="lrs-row lrs-row-charts">
                    <div class="lrs-sk lrs-chart-main"></div>
                    <div class="lrs-sk lrs-chart-side"></div>
                </div>
                <div class="lrs-row">
                    <div class="lrs-sk lrs-fullblock"></div>
                </div>
            </div>
            <span class="screen-reader-text"><?php esc_html_e('Loading…', 'llmagnet-llm-txt-generator'); ?></span>
        </div>
        <?php
    }

    /**
     * AJAX handler for manual generation
     *
     * @return void
     */
    public function ajax_generate_now() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'llmagnet_ai_seo_nonce')) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'llmagnet-llm-txt-generator')]);
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('You do not have permission to perform this action.', 'llmagnet-llm-txt-generator')]);
        }
        
        // Generate files
        $result = $this->generator->generate_all();
        
        if ($result) {
            wp_send_json_success([
                'message' => esc_html__('LLMS.txt generated successfully!', 'llmagnet-llm-txt-generator'),
                'timestamp' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), time()),
            ]);
        } else {
            wp_send_json_error([
                'message' => esc_html__('Error generating LLMS.txt. Please check server permissions.', 'llmagnet-llm-txt-generator'),
            ]);
        }
    }

    /**
     * AJAX handler for saving settings
     *
     * @return void
     */
    public function ajax_save_settings() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'llmagnet_ai_seo_nonce')) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'llmagnet-llm-txt-generator')]);
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('You do not have permission to perform this action.', 'llmagnet-llm-txt-generator')]);
        }
        
        // Get settings from POST data
        $settings = isset($_POST['settings']) ? json_decode(sanitize_textarea_field(wp_unslash($_POST['settings'])), true) : [];
        
        if (empty($settings) || !is_array($settings)) {
            wp_send_json_error(['message' => esc_html__('Invalid settings data.', 'llmagnet-llm-txt-generator')]);
        }
        
        // Handle robots_txt_inject as a separate option
        if ( isset( $settings['robots_txt_inject'] ) ) {
            update_option( 'llmagnet_robots_txt_inject', (bool) $settings['robots_txt_inject'] );
        }

        // Sanitize and save settings
        $sanitized_settings = $this->sanitize_settings($settings);
        $result = $this->generator->update_settings($sanitized_settings);
        
        if ($result) {
            wp_send_json_success([
                'message' => esc_html__('Settings saved successfully.', 'llmagnet-llm-txt-generator'),
            ]);
        } else {
            wp_send_json_error([
                'message' => esc_html__('Error saving settings.', 'llmagnet-llm-txt-generator'),
            ]);
        }
    }

    /**
     * Display admin notices
     *
     * @return void
     */
    public function admin_notices() {
        // Show llms-full.txt file size warning
        $llms_full_size_warning = get_option('llmagnet_llms_full_size_warning', null);
        if ($llms_full_size_warning) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <?php
                    printf(
                        /* translators: %s: file size in MB */
                        esc_html__('llms-full.txt is %sMB. Consider reducing max posts to decrease file size.', 'llmagnet-llm-txt-generator'),
                        esc_html($llms_full_size_warning)
                    );
                    ?>
                </p>
            </div>
            <?php
        }

        // Check if root directory is writable and we're on the settings page
        $current_screen = get_current_screen();
        if (!$this->generator->is_root_writable() && $current_screen && 'settings_page_llmagnet-ai-seo-optimizer' === $current_screen->id) {
            ?>
            <div class="notice notice-error">
                <p>
                    <?php 
                    echo wp_kses(
                        sprintf(
                            /* translators: %s: WordPress root directory path */
                            __('LLMS.txt Generator cannot write to your WordPress root directory (%s). Please check file permissions.', 'llmagnet-llm-txt-generator'),
                            '<code>' . esc_html($this->generator->get_root_path()) . '</code>'
                        ),
                        [
                            'code' => [],
                        ]
                    );
                    ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Render analytics page - now redirects to main dashboard
     *
     * @return void
     */
    public function render_analytics_page() {
        // Redirect to the main dashboard
        wp_safe_redirect(admin_url('admin.php?page=llmagnet-ai-seo-optimizer'));
        exit;
    }
    
    /**
     * Render pages analytics page
     *
     * @return void
     */
    public function render_pages_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        ?>
        <div class="llmagnet-pages-container">
            <?php $this->render_react_app_mount_placeholder('llms-admin-shell-root', 'full'); ?>
        </div>
        <?php
    }
    
    /**
     * Render bot analytics page
     *
     * @return void
     */
    public function render_bot_analytics_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        ?>
        <div class="llmagnet-bot-analytics-container">
            <?php $this->render_react_app_mount_placeholder('llms-admin-shell-root'); ?>
        </div>
        <?php
    }

    /**
     * Render products analytics page (WooCommerce only)
     *
     * @return void
     */
    public function render_products_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Double-check WooCommerce is active
        if (!WooCommerce::is_active()) {
            ?>
            <div class="wrap">
                <h1><?php echo esc_html__('Products Analytics', 'llmagnet-llm-txt-generator'); ?></h1>
                <div class="notice notice-warning">
                    <p><?php echo esc_html__('WooCommerce is required for Products Analytics. Please install and activate WooCommerce.', 'llmagnet-llm-txt-generator'); ?></p>
                </div>
            </div>
            <?php
            return;
        }
        
        ?>
        <div class="llmagnet-products-container">
            <?php $this->render_react_app_mount_placeholder('llms-admin-shell-root'); ?>
        </div>
        <?php
    }
    
    /**
     * Render reports page
     *
     * @return void
     */
    public function render_reports_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        ?>
        <div class="llmagnet-reports-container">
            <?php $this->render_react_app_mount_placeholder('llms-admin-shell-root'); ?>
        </div>
        <?php
    }
    
    /**
     * Render content settings page
     *
     * @return void
     */
    public function render_content_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        ?>
        <div class="llmagnet-content-settings-container">
            <?php $this->render_react_app_mount_placeholder('llms-admin-shell-root'); ?>
        </div>
        <?php
    }

    /**
     * Render Schema JSON-LD page
     *
     * @return void
     */
    public function render_schema_jsonld_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="llmagnet-schema-jsonld-container">
            <?php $this->render_react_app_mount_placeholder( 'llms-admin-shell-root' ); ?>
        </div>
        <?php
    }

    /**
     * Render Agent Ready page
     *
     * @return void
     */
    public function render_agent_ready_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="llmagnet-agent-ready-container">
            <?php $this->render_react_app_mount_placeholder( 'llms-admin-shell-root' ); ?>
        </div>
        <?php
    }

    /**
     * Render MCP & AI page
     *
     * @return void
     */
    public function render_mcp_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="llmagnet-mcp-container">
            <?php $this->render_react_app_mount_placeholder( 'llms-admin-shell-root' ); ?>
        </div>
        <?php
    }

    /**
     * Render system information page
     *
     * @return void
     */
    public function render_system_information_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        ?>
        <div class="llmagnet-system-information-container">
            <?php $this->render_react_app_mount_placeholder('llms-admin-shell-root'); ?>
        </div>
        <?php
    }
}
