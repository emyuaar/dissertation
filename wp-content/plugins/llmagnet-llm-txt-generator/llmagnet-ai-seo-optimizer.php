<?php
/**
 * LLMagnet AI SEO Optimizer
 *
 * @package           LLMagnet_AI_SEO_Optimizer
 * @author            llmagnet
 * @copyright         2025
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       LLMagnet AI SEO Optimizer
 * Plugin URI:        https://llmagnet.com/
 * Description:       Automatically creates and maintains an llms.txt file and associated Markdown files for LLM crawlers.
 * Version:           3.4.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            llmagnet
 * Author URI:        https://llmagnet.com/
 * Text Domain:       llmagnet-llm-txt-generator
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('LLMAGNET_AISEO_VERSION', '3.4.1');
define('LLMAGNET_AISEO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LLMAGNET_AISEO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LLMAGNET_AISEO_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Development mode - set to true when developing with Vite
define('LLMAGNET_AISEO_DEV_MODE', false);

// Vendor Brevo (lifecycle email from LLMagnet). Optional includes/brevo-vendor-key.php overrides if present; wp-config may define LLMAGNET_BREVO_API_KEY first.
$llmagnet_brevo_local_key = LLMAGNET_AISEO_PLUGIN_DIR . 'includes/brevo-vendor-key.php';
if ( is_readable( $llmagnet_brevo_local_key ) ) {
    require_once $llmagnet_brevo_local_key;
}
if ( ! defined( 'LLMAGNET_BREVO_API_KEY' ) ) {
    // Secrets belong in the server environment or wp-config.php, never in a
    // plugin distributed through source control or a webroot backup.
    $llmagnet_brevo_api_key_env = getenv( 'LLMAGNET_BREVO_API_KEY' );
    define(
        'LLMAGNET_BREVO_API_KEY',
        is_string( $llmagnet_brevo_api_key_env ) ? trim( $llmagnet_brevo_api_key_env ) : ''
    );
    unset( $llmagnet_brevo_api_key_env );
}
unset( $llmagnet_brevo_local_key );

// Include test file in development mode
if (defined('LLMAGNET_AISEO_DEV_MODE') && LLMAGNET_AISEO_DEV_MODE && file_exists(LLMAGNET_AISEO_PLUGIN_DIR . 'test-image-feature.php')) {
    require_once LLMAGNET_AISEO_PLUGIN_DIR . 'test-image-feature.php';
}

// Custom error handling removed for production compliance

/**
 * Unified, debug-gated logger.
 *
 * Writes to the PHP error log only when WP_DEBUG is enabled, so production sites
 * stay quiet. Use this instead of bare error_log() for plugin diagnostics.
 *
 * @param string $message Message to log.
 * @return void
 */
if ( ! function_exists( 'llmagnet_aiseo_debug_log' ) ) {
    function llmagnet_aiseo_debug_log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated behind WP_DEBUG.
            error_log( is_string( $message ) ? $message : wp_json_encode( $message ) );
        }
    }
}

// Attempt to load Composer autoloader (required for Freemius SDK when installed via Composer)
$__llmagnet_autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($__llmagnet_autoload)) {
    require_once $__llmagnet_autoload;
}

// Freemius SDK bootstrap (Composer autoload expected)
if ( ! function_exists( 'lltg_fs' ) ) {
    // Ensure Freemius is loaded even without Composer by requiring start.php if available.
    if ( ! function_exists( 'fs_dynamic_init' ) ) {
        $fs_paths = array(

            __DIR__ . '/freemius/start.php',
        );
        foreach ( $fs_paths as $fs_path ) {
            if ( file_exists( $fs_path ) ) {
                require_once $fs_path;
                break;
            }
        }
    }
    // Create a helper function for easy SDK access.
    function lltg_fs() {
        global $lltg_fs;

        if ( ! isset( $lltg_fs ) ) {
            // Activate multisite network integration.
            if ( ! defined( 'WP_FS__PRODUCT_20700_MULTISITE' ) ) {
                define( 'WP_FS__PRODUCT_20700_MULTISITE', true );
            }

            // Include Freemius SDK.
            // SDK is auto-loaded through composer or via the start.php include above.
            if ( ! function_exists( 'fs_dynamic_init' ) ) {
                // Prevent fatal error if SDK is still missing.
                if ( is_admin() ) {
                    add_action( 'admin_notices', function() {
                        echo '<div class="notice notice-warning"><p>' . esc_html__( 'LLMagnet AI SEO Optimizer: Freemius SDK not loaded. Install via Composer or add freemius/start.php.', 'llmagnet-llm-txt-generator' ) . '</p></div>';
                    } );
                }
                return null;
            }

            $lltg_fs = fs_dynamic_init( array(
                'id'                  => '20700',
                'slug'                => 'llmagnet-llm-txt-generator',
                'type'                => 'plugin',
                'public_key'          => 'pk_5449c5c9bb68da59c85168daf6ebd',
                'is_premium'          => false,
                // If your plugin is a serviceware, set this option to false.
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'is_org_compliant'    => true,
                'navigation'          => 'menu',
                'is_live'             => true,
                'permissions'         => ['notices' => true],
                'is_premium_only'     => false,
                'is_activation_mode'  => true,
                'trial'               => array(
                    'days'               => 7,
                    'is_require_payment' => true,
                ),
                'has_affiliation'     => 'all',
                'menu'                => array(
                    'slug'           => 'llmagnet-ai-seo-optimizer',
                    'first-path'     => 'admin.php?page=llmagnet-ai-seo-optimizer',
                    'account'        => true,
                    'contact'        => false,
                    'support'        => false,
                ),
            ) );
        }

        return $lltg_fs;
    }

    // Init Freemius.
    $lltg_fs_instance = lltg_fs();
    if ( $lltg_fs_instance ) {
        $lltg_fs_instance->set_basename( false, __FILE__ );
        do_action( 'lltg_fs_loaded' );
    }
}

/**
 * Simple autoloader for plugin classes to avoid using composer
 */
spl_autoload_register(function ($class) {
    // Project-specific namespace prefix
    $prefix = 'LLMagnet_AI_SEO_Optimizer\\';

    // Base directory for the namespace prefix
    $base_dir = LLMAGNET_AISEO_PLUGIN_DIR . 'includes/';

    // Does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // No, move to the next registered autoloader
        return;
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Replace the namespace prefix with the base directory, replace namespace
    // separators with directory separators, append with .php
    // Add 'class-' prefix and convert to lowercase for WordPress naming convention
    $file = $base_dir . 'class-' . strtolower(str_replace('\\', '/', $relative_class)) . '.php';

    // If the file exists, require it
    if (file_exists($file)) {
        require $file;
    } else {
        // Try without lowercase conversion as a fallback
        $file_alt = $base_dir . 'class-' . str_replace('\\', '/', $relative_class) . '.php';
        if (file_exists($file_alt)) {
            require $file_alt;
        } else {
            // File not found - handled silently in production
        }
    }
});

// Check if all required files exist
$required_files = [
    LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-main.php',
    LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-generator.php',
    LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-admin-wp-helper.php',
    LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-admin.php',
    LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-cron.php',
    LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-cli.php',
    LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-analytics.php',
    LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-email-reports.php',
    LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-visibility-score.php',
    LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-lifecycle-helpers.php',
    LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-brevo-key-store.php',
    LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-brevo-client.php',
    LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-filesystem-helper.php',
    LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-email-state.php',
    LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-email-log.php',
    LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-lifecycle-emails.php',
    LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-page-details.php',
];

$missing_files = [];
foreach ($required_files as $file) {
    if (!file_exists($file)) {
        $missing_files[] = $file;
    }
}

if (!empty($missing_files)) {
    if (!function_exists('deactivate_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    
    deactivate_plugins(plugin_basename(__FILE__));
    
    add_action('admin_notices', function() use ($missing_files) {
        echo '<div class="error"><p><strong>LLMagnet AI SEO Optimizer Error:</strong> The following required files are missing:<br>';
        foreach ($missing_files as $file) {
            echo esc_html($file) . '<br>';
        }
        echo 'Please reinstall the plugin.</p></div>';
    });
    
    return; // Stop execution
}

// Include the main plugin class
require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-main.php';

// Initialize the plugin
function llmagnet_ai_seo_init() {
    try {
        $plugin = new LLMagnet_AI_SEO_Optimizer\Main();
        $plugin->init();
    } catch (Exception $e) {
        // Error handled via admin notices and plugin deactivation
        
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        deactivate_plugins(plugin_basename(__FILE__));
        
        add_action('admin_notices', function() use ($e) {
            echo '<div class="error"><p><strong>LLMagnet AI SEO Optimizer Error:</strong> ' . esc_html($e->getMessage()) . '</p></div>';
        });
    }
}
add_action('init', 'llmagnet_ai_seo_init');

// Textdomain: WP.org language packs auto-load (WP 4.6+); bundled /languages
// translations are loaded via load_plugin_textdomain() in Main::init() (P2-4.4).

// Register activation hook
register_activation_hook(__FILE__, function() {
    // Single-site only (improvement P1-4 Option A): all file output (llms.txt,
    // llms-full.txt, llms-docs/, robots.txt rules) targets ABSPATH, so on
    // multisite every subsite would overwrite the same files. Block activation.
    if (is_multisite()) {
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        deactivate_plugins(plugin_basename(__FILE__), true);
        wp_die(
            esc_html__('LLMagnet AI SEO Optimizer is a single-site plugin and does not support WordPress multisite. The plugin writes llms.txt and related files to the site root directory, which would conflict between subsites, so it has been deactivated. Please use it on a standalone (single-site) WordPress installation.', 'llmagnet-llm-txt-generator'),
            esc_html__('LLMagnet AI SEO Optimizer — multisite not supported', 'llmagnet-llm-txt-generator'),
            ['back_link' => true]
        );
    }

    try {
        // Redirect to the Freemius opt-in screen after activation
        if (function_exists('lltg_fs')) {
            lltg_fs()->add_action('after_plugin_activation', function() {
                if (!is_network_admin()) {
                    wp_redirect(admin_url('admin.php?page=llmagnet-ai-seo-optimizer'));
                }
            });
        }
        
        // Create database table for LLM bot visits
        LLMagnet_AI_SEO_Optimizer\Analytics::create_db_table();
        
        // Run migration to add new columns if needed
        LLMagnet_AI_SEO_Optimizer\Analytics::migrate_db_table();
        
        // Create database table for visibility scores
        LLMagnet_AI_SEO_Optimizer\Visibility_Score::create_db_table();
        
        // Create database tables for WooCommerce integration
        require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-woocommerce.php';
        LLMagnet_AI_SEO_Optimizer\WooCommerce::create_db_tables();
        
        // Activate generator
        LLMagnet_AI_SEO_Optimizer\Generator::activate();
        
        // Set up weekly email report cron job
        if (!wp_next_scheduled('llmagnet_weekly_analytics_report')) {
            // Schedule for Monday at 2:00 AM
            $next_monday = strtotime('next monday midnight') + 7200; // +2 hours (2:00 AM)
            wp_schedule_event($next_monday, 'weekly', 'llmagnet_weekly_analytics_report');
        }
        
        // Schedule daily visibility score calculation
        LLMagnet_AI_SEO_Optimizer\Cron::schedule_event();

        if (!get_option('llmagnet_install_timestamp')) {
            update_option('llmagnet_install_timestamp', time());
        }

        require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-lifecycle-helpers.php';
        require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-brevo-key-store.php';
        require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-brevo-client.php';
        require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-email-state.php';
        require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-email-log.php';
        require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-lifecycle-emails.php';

        $lifecycle_emails = new \LLMagnet\Lifecycle\Lifecycle_Emails(
            new \LLMagnet\Lifecycle\Brevo_Client(),
            new \LLMagnet\Lifecycle\Email_State(),
            new \LLMagnet\Lifecycle\Email_Log()
        );
        $lifecycle_emails->maybe_sync_install_contact( true );
    } catch (Exception $e) {
        wp_die('Error activating LLMagnet AI SEO Optimizer: ' . esc_html($e->getMessage()));
    }
});

// Register deactivation hook
register_deactivation_hook(__FILE__, function() {
    // Clear scheduled cron jobs
    wp_clear_scheduled_hook('llmagnet_weekly_analytics_report');
    
    // Clear all scheduled events from Cron class
    LLMagnet_AI_SEO_Optimizer\Cron::clear_scheduled_event();
    
    // Run generator deactivation
    LLMagnet_AI_SEO_Optimizer\Generator::deactivate();
});

/**
 * Uninstall cleanup function for Freemius
 *
 * Runs on the Freemius `after_uninstall` hook (the SDK's documented pattern —
 * Freemius-deployed plugins must NOT ship an uninstall.php, as it would
 * suppress the SDK's uninstall event tracking). Removes plugin options, MCP
 * tokens/settings/activity + dynamic transients (mcp-ai-spec §B4 / §10.9),
 * adoption-plan score artifacts, agent-readiness options, and optionally the
 * generated files.
 */
function lltg_fs_uninstall_cleanup() {
    // Get plugin settings
    $settings = get_option('llmagnet_ai_seo_optimizer_settings', []);

    // Delete files if setting is enabled
    if (isset($settings['delete_on_uninstall']) && $settings['delete_on_uninstall']) {
        // Initialize WordPress filesystem
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        if ($wp_filesystem) {
            // Delete llms.txt file
            $llms_txt_path = trailingslashit(ABSPATH) . 'llms.txt';
            if ($wp_filesystem->exists($llms_txt_path)) {
                $wp_filesystem->delete($llms_txt_path);
            }

            // Delete llms-full.txt file
            $llms_full_path = trailingslashit(ABSPATH) . 'llms-full.txt';
            if ($wp_filesystem->exists($llms_full_path)) {
                $wp_filesystem->delete($llms_full_path);
            }

            // Delete llms-docs directory
            $docs_dir = trailingslashit(ABSPATH) . 'llms-docs';
            if ($wp_filesystem->exists($docs_dir)) {
                $wp_filesystem->delete($docs_dir, true);
            }
        }
    }

    // Delete plugin options
    $options = [
        // Core.
        'llmagnet_ai_seo_optimizer_settings',
        'llmagnet_brevo_api_key_enc',

        // MCP (mcp-ai-spec §9 / §B4).
        'llmagnet_mcp_api_token',   // Legacy WP-CLI token.
        'llmagnet_mcp_settings',    // enabled / access_mode / bridge_abilities.
        'llmagnet_mcp_tokens',      // Managed token records (hashes only).
        'llmagnet_mcp_activity',    // Activity ring buffer.

        // MCP OAuth 2.1 authorization server (class-mcp-oauth / class-mcp-tokens).
        'llmagnet_mcp_oauth_clients', // Dynamic client registration (DCR) records.
        'llmagnet_mcp_oauth_grants',  // Issued authorization grants (hashes only).

        // Per-post score store (adoption plan Phase 0).
        'llmagnet_score_priority_queue',

        // Agent-readiness (agent-readiness spec Phase 0).
        'llmagnet_agent_readiness',
        'llmagnet_agent_card',
        'llmagnet_agent_headers',
        'llmagnet_agent_audit_last',
        'llmagnet_agent_audit_history',
        'llmagnet_indexnow_key',
        'llmagnet_well_known_rules_hash',

        // Agent-readiness Phase D (Lane D3): robots AI rules + well-known providers.
        'llmagnet_robots_ai_bots',
        'llmagnet_robots_content_signal',
        'llmagnet_security_txt_expires',

        // Agent-readiness Phase E (Lane E3): WebMCP add-to-cart opt-in.
        'llmagnet_webmcp_add_to_cart',

        // Phase F (Lane F-A): markdown endpoints, discovery, OG, security
        // headers, IndexNow.
        'llmagnet_markdown_conneg',       // F5 Accept-negotiation opt-in.
        'llmagnet_restore_feed_links',    // F6.3 feed-link restoration.
        'llmagnet_schemamap_cache',       // F6.2 generated schemamap entries.
        'llmagnet_canonical_fill',        // F7 canonical fill opt-in.
        'llmagnet_meta_description_fill', // F7 meta-description fill opt-in.
        'llmagnet_security_headers',      // F8.1 per-header flags + csp_policy.
        'llmagnet_indexnow_queue',        // F8.2 pending ping queue.
        'llmagnet_indexnow_log',          // F8.2 last-20 ping log.
    ];
    foreach ($options as $option) {
        delete_option($option);
    }

    delete_transient('llmagnet_ai_seo_optimizer_last_run');
    delete_transient('llmagnet_seo_plugins_active');
    delete_transient('llmagnet_glance_visits_30d'); // Dashboard At a Glance cache (Lane D1).
    delete_transient('llmagnet_admin_bar_site_score'); // Admin-bar site score cache (Lane F-B).

    // Clear cron hooks (normally cleared on deactivation; belt and braces).
    wp_clear_scheduled_hook('llmagnet_score_backfill');
    wp_clear_scheduled_hook('llmagnet_agent_audit_weekly'); // Lane E3 (Agent-Ready audit).
    wp_clear_scheduled_hook('llmagnet_indexnow_ping'); // Lane F-A (debounced IndexNow single event).

    global $wpdb;

    // Plugin post meta (score store + llms.txt exclude toggle + publish nudge).
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s, %s)",
            '_llmagnet_score',
            '_llmagnet_score_updated',
            '_llmagnet_exclude_from_llms',
            '_llmagnet_publish_nudge'
        )
    );

    // Publish-nudge per-user ring buffer (Lane F-B).
    delete_metadata('user', 0, 'llmagnet_nudge_shown_posts', '', true);

    // MCP transients (dynamic per-IP keys: brute-force + anonymous rate limit).
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_llmagnet_mcp_fail_') . '%',
            $wpdb->esc_like('_transient_timeout_llmagnet_mcp_fail_') . '%',
            $wpdb->esc_like('_transient_llmagnet_mcp_anon_') . '%',
            $wpdb->esc_like('_transient_timeout_llmagnet_mcp_anon_') . '%'
        )
    );

    // WebMCP public-route per-IP rate-limit transients (Lane E3; 60s TTL, self-expire).
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_llmagnet_pubrl_') . '%',
            $wpdb->esc_like('_transient_timeout_llmagnet_pubrl_') . '%'
        )
    );

    // OAuth authorization-code (short-lived) + DCR register rate-limit transients
    // (class-mcp-oauth; dynamic per-code / per-IP keys).
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_llmagnet_oauth_code_') . '%',
            $wpdb->esc_like('_transient_timeout_llmagnet_oauth_code_') . '%',
            $wpdb->esc_like('_transient_llmagnet_oauth_reg_') . '%',
            $wpdb->esc_like('_transient_timeout_llmagnet_oauth_reg_') . '%'
        )
    );
}

// Hook the uninstall function to Freemius after_uninstall action
if (function_exists('lltg_fs')) {
    lltg_fs()->add_action('after_uninstall', 'lltg_fs_uninstall_cleanup');
}
