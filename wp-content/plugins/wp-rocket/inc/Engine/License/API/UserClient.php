<?php
namespace WP_Rocket\Engine\License\API;
use WP_Rocket\Admin\Options_Data;
use WP_Rocket\Engine\Common\JobManager\APIHandler\AbstractSafeAPIClient;

/**
 * Class UserClient
 *
 * Handles license/user data + custom GitHub update flow for WP Rocket.
 */
class UserClient extends AbstractSafeAPIClient {
    const USER_ENDPOINT = 'https://www.google.com';
    const FAKE_KEY = 'B5E0B5F8DD8689E6ACA49DD6E6E1A930';
    const FAKE_EMAIL = 'noreply@freedom.com';

    /**
     * WP Rocket options instance
     *
     * @var Options_Data
     */
    private $options;

    /**
     * Get the transient key for plugin updates.
     *
     * This method returns the transient key used for caching plugin updates.
     *
     * @return string The transient key for plugin updates.
     */
    protected function get_transient_key() {
        return 'wpr_user_information';
    }

    /**
     * Get the API URL for plugin updates.
     *
     * This method returns the API URL used for fetching plugin updates.
     *
     * @return string The API URL for plugin updates.
     */
    protected function get_api_url() {
        return self::USER_ENDPOINT;
    }

    /**
     * Instantiate the class
     *
     * @param Options_Data $options WP Rocket options instance.
     */
    public function __construct( Options_Data $options ) {
        $this->options = $options;
        // Hook the filter to intercept all HTTP requests to WP Rocket API domain for total bypass with high priority
        add_filter( 'pre_http_request', [ $this, 'filter_http_requests' ], 5, 3 );
        // Force license activation on init
        add_action( 'init', [ $this, 'force_license_activation' ] );
        // Add GitHub update logic hooks if in admin
        if ( is_admin() ) {
            add_action( 'admin_print_footer_scripts', [ $this, 'add_github_update_section_js' ] );
            add_action( 'wp_ajax_wp_rocket_get_releases', [ $this, 'ajax_get_releases' ] );
            add_action( 'wp_ajax_wp_rocket_update_github', [ $this, 'ajax_update_github' ] );
            add_action( 'wp_ajax_wp_rocket_save_github_options', [ $this, 'ajax_save_github_options' ] );
            add_action( 'wp_ajax_wp_rocket_manual_check', [ $this, 'ajax_manual_check' ] );
            add_action( 'wp_ajax_wp_rocket_simulate_update', [ $this, 'ajax_simulate_update' ] );
            add_action( 'admin_head', [ $this, 'add_banner_style' ] );
        }
        // Schedule cron for automatic checks
        add_action( 'init', [ $this, 'schedule_cron' ] );
        // Hook for WP update transient
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_wp_update_transient' ] );
        // Hooks for protecting files during WP update
        add_filter( 'upgrader_pre_install', [ $this, 'pre_update_backup' ], 10, 2 );
        add_action( 'upgrader_process_complete', [ $this, 'post_update_protect' ], 10, 2 );
        // Fix folder name after extract
        add_filter( 'upgrader_source_selection', [ $this, 'fix_upgrader_source' ], 10, 4 );
        // Add custom cron schedule
        add_filter( 'cron_schedules', [ $this, 'add_custom_cron_schedules' ] );
        // Action for cron check
        add_action( 'wpr_github_check', [ $this, 'check_for_updates' ] );
        // Plugins API filter for changelog
        add_filter( 'plugins_api', [ $this, 'plugins_api_filter' ], 10, 3 );
        // Infer channel if not set, and migrate 'latest' to 'stable'
        if ( ! get_option( 'wpr_github_channel' ) ) {
            $current_version = defined( 'WP_ROCKET_VERSION' ) ? WP_ROCKET_VERSION : '0';
            $current_type = $this->get_version_type( 'v' . $current_version );
            update_option( 'wpr_github_channel', $current_type );
        } else if ( get_option( 'wpr_github_channel' ) === 'latest' ) {
            update_option( 'wpr_github_channel', 'stable' );
        }
    }

    /**
     * Force license activation and set fake data
     */
    public function force_license_activation() {
        // Check and include licence-data.php if readable
        if ( @is_readable( WP_ROCKET_PATH . 'licence-data.php' ) ) {
            @include WP_ROCKET_PATH . 'licence-data.php';
        }

        $consumer_data = [
            'consumer_key' => substr(self::FAKE_KEY, 0, 8),
            'consumer_email' => self::FAKE_EMAIL,
            'secret_key' => hash( 'crc32', self::FAKE_EMAIL ),
            'license' => time(),
            'ignore' => true
        ];

        // Update WP Rocket settings with the license data if not already set
        $current_settings = get_option( 'wp_rocket_settings', [] );
        if ( empty( $current_settings['consumer_key'] ) ) {
            update_option( 'wp_rocket_settings', array_merge( $current_settings, $consumer_data ) );
        }

        update_option( 'wp_rocket_no_licence', 0 );

        $customer_data = $this->get_raw_user_data();
        set_transient( 'wp_rocket_customer_data', $customer_data, DAY_IN_SECONDS );
    }

    /**
     * Gets user data from cache if it exists, else gets it from the user endpoint
     *
     * Cache the user data for 24 hours in a transient
     *
     * @since 3.7.3
     *
     * @return bool|object
     */
    public function get_user_data() {
        $cached_data = get_transient( 'wp_rocket_customer_data' );
        if ( false !== $cached_data ) {
            return $cached_data;
        }
        $data = $this->get_raw_user_data();
        if ( false === $data ) {
            return false;
        }
        set_transient( 'wp_rocket_customer_data', $data, DAY_IN_SECONDS );
        return $data;
    }

    /**
     * Flushes the user data cache.
     *
     * @return void
     */
    public function flush_cache() {
        delete_transient( 'wp_rocket_customer_data' );
    }

    /**
     * Simulates fetching user data without making an HTTP request
     *
     * @return bool|object
     */
    private function get_raw_user_data() {
        // Simulate the API response with infinite license (unlimited sites, expires in 2060)
        $customResponse = [
            'licence_account' => '-1', // Unlimited websites
            'licence_expiration' => 2871763199, // Timestamp for 2060-12-31 23:59:59
            'status' => 'valid',
            'has_one-com_account' => false,
            'has_auto_renew' => true,
            'date_created' => time() - (365 * 24 * 60 * 60), // Created 1 year ago
            'upgrade_plus_url' => 'https://github.com/wp-media/wp-rocket',
            'upgrade_infinite_url' => 'https://github.com/wp-media/wp-rocket',
            'renewal_url' => 'https://github.com/wp-media/wp-rocket/releases',
            'is_reseller' => false, // Added: For is_reseller_account()
            'performance_monitoring' => (object) [
                'expiration' => 2871763199,
                'cancelled_at' => null,
                'manage_url' => null,
                'active_sku' => 'perf-monitor-advanced',
                'plans' => [
                    (object) [
                        'sku' => 'perf-monitor-advanced',
                        'price' => '100.00',
                        'limit' => 9999,
                        'title' => 'Advanced',
                        'subtitle' => 'Unlimited performance monitoring with all features.', 
                        'description' => 'Up to 10 pages • Weekly updates',
                        'billing' => '* Billed monthly. You can cancel at any time, each month started is due.',
                        'highlights' => [
                            'Up to 10 pages tracked',
                            'Automatic performance monitoring',
                            'Unlimited on-demand tests',
                            'Full GTmetrix performance reports'
                        ],
                        'status' => 'active',
                        'button' => (object) ['label' => 'Activated', 'url' => null],
                    ],
                ],
            ],
        ];
        return (object) $customResponse;
    }

    /**
     * Simulates fetching pricing data without making an HTTP request
     *
     * @return object
     */
    private function get_raw_pricing_data() {
        // Simulate pricing response to match expected structure in Renewal/Pricing
        $future_date = time() + (10 * 365 * 24 * 60 * 60); // Dynamic: 10 years in the future to always simulate grandfathered/grandmothered
        $customPricing = [
            'single' => (object) [
                'price' => 100, // Unlimited FREE
                'websites' => 1,
                'renewal_price' => 0,
            ],
            'plus' => (object) [
                'price' => 0,
                'websites' => 3,
                'renewal_price' => 0,
            ],
            'infinite' => (object) [
                'price' => 0,
                'websites' => -1,
                'renewal_price' => 0,
            ],
            'renewals' => (object) [
                'extra_days' => 15, // Default value
                'grandfather_date' => $future_date, // Dynamic future date to always be > creation_date
                'grandmother_date' => $future_date,
                'discount_percent' => (object) [
                    'is_grandfather' => 100, // 100% discount for ELITE
                ],
            ],
            // Add more if needed, based on Pricing class expectations
        ];
        return (object) $customPricing;
    }

    /**
     * Intercepts HTTP requests to prevent them from being sent to WP Rocket API
     *
     * @param mixed $pre
     * @param array $parsed_args
     * @param string $url
     * @return mixed
     */
    public function filter_http_requests( $pre, $parsed_args, $url ) {
        // Intercept all requests to api.wp-rocket.me for total ELITE (bypass any API calls)
        if ( strpos( $url, 'api.wp-rocket.me' ) !== false || strpos( $url, 'wp-rocket.me' ) !== false || strpos( $url, self::USER_ENDPOINT ) !== false ) {
            // Specific handling for license validation (valid_key.php) to return fake valid response
            if ( strpos( $url, 'valid_key.php' ) !== false ) {
                $consumer_data = [
                    'consumer_key' => substr(self::FAKE_KEY, 0, 8),
                    'consumer_email' => self::FAKE_EMAIL,
                    'secret_key' => hash( 'crc32', self::FAKE_EMAIL ),
                     'license' => time(),
                     'ignore' => true
                ];
                return [
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'body' => json_encode( [ 'success' => true, 'data' => $consumer_data ] )
                ];
            }
            // For user data (stat/1.0/wp-rocket/user.php)
            if ( strpos( $url, 'user.php' ) !== false ) {
                return [
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'body' => json_encode( $this->get_raw_user_data() )
                ];
            }
            // For update checks (check_update.php or /update/)
            if ( strpos( $url, 'check_update.php' ) !== false || strpos( $url, '/update/' ) !== false || strpos( $url, '/plugins/' ) !== false ) {
                return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [ 'success' => true, 'data' => [ 'version' => WP_ROCKET_VERSION, 'update' => false ] ] ) ];
            }
            // For pricing endpoints (assuming contains 'pricing' or similar)
            if ( strpos( $url, 'pricing' ) !== false ) {
                return [
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'body' => json_encode( $this->get_raw_pricing_data() )
                ];
            }
            // Default to simulated user data for other endpoints
            return [
                'response' => [ 'code' => 200, 'message' => 'OK' ],
                'body' => json_encode( $this->get_raw_user_data() )
            ];
        }
        // Pass through other requests
        return $pre;
    }

    /**
     * Adds JS to inject the GitHub update section into the tools tab
     */
    public function add_github_update_section_js() {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'wprocket' ) {
            return;
        }
        $description = esc_html__( 'Configure automatic GitHub updates for WP Rocket. Choose channel and interval for checks. Notifications appear on Plugins page like standard updates.', 'rocket' );
        $releases_description = esc_html__( 'Manually select and update to any GitHub release, preserving custom files.', 'rocket' );
        $github_nonce = wp_create_nonce( 'wpr_github_update' );
        // Get current and latest info
        $current_version = defined( 'WP_ROCKET_VERSION' ) ? WP_ROCKET_VERSION : 'Unknown';
        $current_type = $this->get_version_type( 'v' . $current_version );
        $channel = get_option( 'wpr_github_channel', $current_type );
        $releases = $this->get_github_releases();
        $latest_in_channel = $this->get_latest_in_channel($releases, $channel);
        $latest_tag = $latest_in_channel ? $latest_in_channel['tag_name'] : ( ! empty( $releases ) ? $releases[0]['tag_name'] : 'No releases' );
        $latest_type = $latest_in_channel ? $latest_in_channel['type'] : ( ! empty( $releases ) ? $releases[0]['type'] : '' );
        $latest_date = $latest_in_channel ? $latest_in_channel['published_at'] : ( ! empty( $releases ) ? $releases[0]['published_at'] : '' );
        $latest_date_formatted = $latest_date ? str_replace(' ', ' - ', $latest_date) : '';
        $latest_version = ltrim( $latest_tag, 'v' );
        $update_available = version_compare( $latest_version, $current_version, '>' );
        $status = $update_available ? 'Update available' : 'Up to date';
        $installed_color = $update_available ? '#ffa500' : '#00aa00';
        $latest_color = '#00aa00';
        $latest_prefix = $update_available ? 'Update available ' : '';
        $latest_suffix = $update_available ? '' : ' - ' . $status;
        // Fallback if no latest in channel
        if ( ! $latest_in_channel && ! empty( $releases ) ) {
            $latest_tag = $releases[0]['tag_name'];
            $latest_date_formatted = str_replace(' ', ' - ', $releases[0]['published_at']);
        } elseif ( ! $latest_in_channel ) {
            $latest_tag = 'No releases in this channel';
            $latest_date_formatted = '';
            $status = 'No releases';
        }
        $channel_display = ( $channel === 'stable' ) ? 'Stable (Latest)' : ucfirst( $channel );
        $status_html = '<div class="wpr-status-block"><p class="wpr-field-description" style="margin: 12px 0 2px 0;"><strong style="font-weight: 600;text-transform: uppercase;font-size: 10px;">Installed Version (' . esc_html( $current_type ) . '):</strong> <span style="color: ' . $installed_color . ';">v' . esc_html( $current_version ) . '</span></p><p class="wpr-field-description"><strong style="font-weight: 600;text-transform: uppercase;font-size: 10px;">Latest in Channel (' . esc_html( $channel_display ) . '):</strong> <span style="color: ' . $latest_color . ';">' . $latest_prefix . esc_html( $latest_tag ) . ' (' . esc_html( $latest_date_formatted ) . ')' . $latest_suffix . '</span></p></div>';
        $check_section_html = '<div id="github-check-section" class="wpr-tools"><div class="wpr-tools-col"><div class="wpr-title3 wpr-tools-label wpr-icon-export">GitHub Update Settings</div><div class="wpr-field-description">' . $description . '</div>' . $status_html . '</div><div class="wpr-tools-col"><form id="github-options-form"><label for="github-channel" style="margin-right: 3px;">Update Channel:</label><select id="github-channel" name="channel"><option value="stable">Stable (Latest)</option><option value="beta">Beta</option><option value="alpha">Alpha</option><option value="pre-release">Pre-Release</option></select><br><label for="github-interval" style="margin-right: 3px;">Check Interval:</label><select id="github-interval" name="interval" style="margin-top: 5px;"><option value="21600">Every 6 hours</option><option value="43200">Every 12 hours</option><option value="86400">Every 24 hours</option><option value="259200">Every 3 days</option></select><br><button type="submit" class="wpr-button wpr-button--primary wpr-button--small" style="padding: 5px 10px;margin-top: 10px;">Save Settings</button></form><button id="github-manual-check-btn" class="wpr-button wpr-button--icon wpr-button--small wpr-button--purple wpr-icon-refresh" style="margin-top: 10px;">Manual Check Now</button><button id="github-check-btn" class="wpr-button wpr-button--icon wpr-button--small wpr-button--purple wpr-icon-refresh" style="margin-top: 10px;">Manual Update from Releases</button><button id="github-simulate-btn" class="wpr-button wpr-button--icon wpr-button--small wpr-button--red wpr-icon-refresh" style="margin-top: 10px;">Simulate Update (Temp)</button></div></div>';
        // Load saved options
        $saved_channel = get_option( 'wpr_github_channel', 'stable' );
        if ( $saved_channel === 'latest' ) $saved_channel = 'stable';
        $saved_interval = get_option( 'wpr_github_interval', '43200' );
        ?>
        <script type="text/javascript">
            (function($) {
                'use strict';
                const github_nonce = <?php echo json_encode( $github_nonce ); ?>;
                const ajax_url = <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
                const check_section_html = <?php echo json_encode( $check_section_html ); ?>;
                const releases_description = <?php echo json_encode( $releases_description ); ?>;
                const saved_channel = <?php echo json_encode( $saved_channel ); ?>;
                const saved_interval = <?php echo json_encode( $saved_interval ); ?>;
                $(document).ready(function() {
                    const toolsContainer = $('#tools');
                    if (toolsContainer.length > 0 && $('#github-check-section').length === 0) {
                        toolsContainer.append(check_section_html);
                        $('#github-channel').val(saved_channel);
                        $('#github-interval').val(saved_interval);
                    }
                    // Save options form
                    $(document).on('submit', '#github-options-form', function(e) {
                        e.preventDefault();
                        const $form = $(this);
                        const $btn = $form.find('button[type="submit"]');
                        if ($btn.prop('disabled')) return; // Prevent multiple submits
                        const originalText = $btn.text();
                        $btn.prop('disabled', true).text('Saving...');
                        let channel = $form.find('#github-channel').val();
                        if (channel === 'latest') channel = 'stable'; // Migrate if somehow set
                        const interval = $form.find('#github-interval').val();
                        fetch(ajax_url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: `action=wp_rocket_save_github_options&channel=${encodeURIComponent(channel)}&interval=${encodeURIComponent(interval)}&nonce=${encodeURIComponent(github_nonce)}`
                        })
                        .then(response => response.json())
                        .then(data => {
                            $btn.prop('disabled', false);
                            if (data.success) {
                                $btn.text('Saved!');
                                setTimeout(function() {
                                    $btn.text(originalText);
                                    location.reload(true); // Force reload to update status
                                }, 2000);
                            } else {
                                $btn.text(originalText);
                            }
                        })
                        .catch(() => {
                            $btn.prop('disabled', false).text(originalText);
                        });
                    });
                    // Manual check button
                    $(document).on('click', '#github-manual-check-btn', function(e) {
                        e.preventDefault();
                        const $btn = $(this);
                        if ($btn.prop('disabled')) return; // Prevent multiple clicks
                        $btn.prop('disabled', true).text('Checking...');
                        fetch(ajax_url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: `action=wp_rocket_manual_check&nonce=${encodeURIComponent(github_nonce)}`
                        })
                        .then(response => response.json())
                        .then(data => {
                            $btn.prop('disabled', false).text('Manual Check Now');
                            if (data.success) {
                                location.reload(true); // Force reload to update status
                            }
                        })
                        .catch(() => {
                            $btn.prop('disabled', false).text('Manual Check Now');
                        });
                    });
                    // Simulate update button (temporary)
                    $(document).on('click', '#github-simulate-btn', function(e) {
                        e.preventDefault();
                        const $btn = $(this);
                        if ($btn.prop('disabled')) return; // Prevent multiple clicks
                        const originalText = $btn.text();
                        const originalBg = $btn.css('background-color');
                        const originalColor = $btn.css('color');
                        $btn.prop('disabled', true).text('Simulating...');
                        fetch(ajax_url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: `action=wp_rocket_simulate_update&nonce=${encodeURIComponent(github_nonce)}`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                $btn.text('DONE CHECK PLUGINS PAGE').css({'background-color': 'green', 'color': 'white'});
                                setTimeout(function() {
                                    $btn.text(originalText).css({'background-color': originalBg, 'color': originalColor});
                                    $btn.prop('disabled', false);
                                }, 3000);
                            } else {
                                $btn.text('Simulation Failed').css({'background-color': 'red', 'color': 'white'});
                                setTimeout(function() {
                                    $btn.text(originalText).css({'background-color': originalBg, 'color': originalColor});
                                    $btn.prop('disabled', false);
                                }, 3000);
                            }
                        })
                        .catch(() => {
                            $btn.text('Request Failed').css({'background-color': 'red', 'color': 'white'});
                            setTimeout(function() {
                                $btn.text(originalText).css({'background-color': originalBg, 'color': originalColor});
                                $btn.prop('disabled', false);
                            }, 3000);
                        });
                    });
                    // Delegate event for check button (manual releases list)
                    $(document).on('click', '#github-check-btn', function(e) {
                        e.preventDefault();
                        const $btn = $(this);
                        if ($btn.prop('disabled')) return; // Prevent multiple clicks
                        $btn.prop('disabled', true).text('Checking...');
                        const controller = new AbortController();
                        const timeoutId = setTimeout(() => controller.abort(), 60000);
                        fetch(ajax_url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: `action=wp_rocket_get_releases&nonce=${encodeURIComponent(github_nonce)}`,
                            signal: controller.signal
                        })
                        .then(response => response.json())
                        .then(data => {
                            clearTimeout(timeoutId);
                            $btn.prop('disabled', false).text('Manual Update from Releases');
                            if (data.success) {
                                if (data.data.length > 0) {
                                    let optionsHtml = data.data.map(function(release) {
                                        const displayText = release.tag_name + ' (' + release.type + ', ' + release.published_at + ')';
                                        return '<option value="' + $('<div>').text(release.tag_name).html() + '">' + $('<div>').text(displayText).html() + '</option>';
                                    }).join('');
                                    const resultsHtml = '<div id="github-results-section" class="wpr-tools"><div class="wpr-tools-col"><div class="wpr-title3 wpr-tools-label wpr-icon-export">Available Releases</div><div class="wpr-field-description">' + releases_description + '</div></div><div class="wpr-tools-col"><form id="github-update-form"><input type="hidden" name="nonce" value="' + $('<div>').text(github_nonce).html() + '"><select name="selected_tag">' + optionsHtml + '</select><button type="submit" class="wpr-button wpr-button--primary wpr-button--small" style="padding: 5px 10px;margin: 10px 0 0 2px;">Update Plugin</button><a href="#" id="github-check-again-btn" class="wpr-button wpr-button--small wpr-button--purple" style="padding: 5px 10px; margin-top: 10px;margin-left: 5px;">Close</a></form></div></div>';
                                    if ($('#github-results-section').length === 0) {
                                        $('#github-check-section').after(resultsHtml);
                                    } else {
                                        $('#github-results-section').replaceWith(resultsHtml);
                                    }
                                } else {
                                    const noResultsHtml = '<div id="github-results-section" class="wpr-tools"><div class="wpr-tools-col"><div class="wpr-title3 wpr-tools-label wpr-icon-export">Available Releases</div><div class="wpr-field-description">' + releases_description + '</div></div><p class="description">No releases found. Try checking again.</p><a href="#" id="github-check-again-btn" class="wpr-button wpr-button--small wpr-button--purple">Close</a></div></div>';
                                    if ($('#github-results-section').length === 0) {
                                        $('#github-check-section').after(noResultsHtml);
                                    } else {
                                        $('#github-results-section').replaceWith(noResultsHtml);
                                    }
                                }
                            }
                        })
                        .catch(() => {
                            clearTimeout(timeoutId);
                            $btn.prop('disabled', false).text('Manual Update from Releases');
                        });
                    });
                    // Delegate event for update form submit
                    $(document).on('submit', '#github-update-form', function(e) {
                        e.preventDefault();
                        const $form = $(this);
                        const $submitBtn = $form.find('button[type="submit"]');
                        if ($submitBtn.prop('disabled')) return; // Prevent multiple submits
                        const tag = $form.find('select[name="selected_tag"]').val();
                        if (!tag) {
                            return;
                        }
                        $submitBtn.prop('disabled', true).text('Updating...');
                        const controller = new AbortController();
                        const timeoutId = setTimeout(() => controller.abort(), 300000);
                        fetch(ajax_url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: `action=wp_rocket_update_github&tag=${encodeURIComponent(tag)}&nonce=${encodeURIComponent(github_nonce)}`,
                            signal: controller.signal
                        })
                        .then(response => response.json())
                        .then(data => {
                            clearTimeout(timeoutId);
                            if (data.success) {
                                $submitBtn.text('Updated!');
                                setTimeout(() => {
                                    $submitBtn.text('Update Plugin');
                                    $submitBtn.prop('disabled', false);
                                    location.reload(true); // Force reload
                                }, 2000);
                            } else {
                                const errorMsg = data.data || 'Unknown error';
                                if (errorMsg.indexOf('Update already in progress') !== -1) {
                                    // Keep button as Updating... and wait for completion
                                    setTimeout(function() {
                                        location.reload(true);
                                    }, 5000); // Reload after 5 seconds to check completion
                                } else {
                                    $submitBtn.prop('disabled', false).text('Update Plugin');
                                }
                            }
                        })
                        .catch(() => {
                            clearTimeout(timeoutId);
                            $submitBtn.prop('disabled', false).text('Update Plugin');
                        });
                    });
                    // Delegate event for check again button
                    $(document).on('click', '#github-check-again-btn', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        $('#github-results-section').remove();
                    });
                });
            })(jQuery);
        </script>
        <?php
    }

    /**
     * Adds style for banner
     */
    public function add_banner_style() {
        if ( isset( $_GET['plugin'] ) && $_GET['plugin'] === 'wp-rocket' && isset( $_GET['tab'] ) && $_GET['tab'] === 'plugin-information' ) {
            echo '<style>#plugin-information-title.with-banner { background-size: contain !important; height: auto !important; background-repeat: no-repeat !important; background-position: center !important; padding: 20px 0 !important; }</style>';
        }
    }

    /**
     * AJAX handler to get GitHub releases
     */
    public function ajax_get_releases() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }
        if ( ! wp_verify_nonce( $_POST['nonce'], 'wpr_github_update' ) ) {
            wp_send_json_error( 'Nonce verification failed.' );
        }
        $releases = $this->get_github_releases();
        wp_send_json_success( $releases );
    }

    /**
     * AJAX handler to update from GitHub
     */
    public function ajax_update_github() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }
        $nonce = isset( $_POST['nonce'] ) ? $_POST['nonce'] : '';
        if ( ! wp_verify_nonce( $nonce, 'wpr_github_update' ) ) {
            wp_send_json_error( 'Nonce verification failed.' );
        }
        $selected_tag = isset( $_POST['tag'] ) ? sanitize_text_field( $_POST['tag'] ) : '';
        if ( empty( $selected_tag ) ) {
            wp_send_json_error( 'No version selected.' );
        }
        // Perform update using WP-like logic but manual
        $result = $this->perform_github_update( $selected_tag );
        if ( \is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        } else {
            // Cleanup leftovers before sending success
            global $wp_filesystem;
            if ( WP_Filesystem() ) {
                $upgrade_dir = trailingslashit( WP_CONTENT_DIR ) . 'upgrade/';
                $dirs = $wp_filesystem->dirlist( $upgrade_dir );
                if ( $dirs && is_array( $dirs ) ) {
                    foreach ( $dirs as $dir_name => $dir_info ) {
                        if ( $dir_info['type'] === 'd' && strpos( $dir_name, 'wp-rocket' ) === 0 ) {
                            $this->recursive_delete( trailingslashit( $upgrade_dir ) . $dir_name );
                        }
                    }
                }
                $backup_dir = trailingslashit( WP_CONTENT_DIR ) . 'upgrade-temp-backup/plugins/';
                if ( $wp_filesystem->exists( $backup_dir ) ) {
                    $this->recursive_delete( $backup_dir );
                }
            }
            delete_site_transient( 'update_plugins' );
            if ( ob_get_length() ) {
                error_log( 'wpr_ajax_buffer: ' . substr( ob_get_contents(), 0, 2000 ) );
            }
            while ( ob_get_level() > 0 ) {
                @ob_end_clean();
            }
            wp_send_json_success( 'Plugin updated successfully from GitHub.' );
        }
    }

    /**
     * AJAX handler to save GitHub options
     */
    public function ajax_save_github_options() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }
        if ( ! wp_verify_nonce( $_POST['nonce'], 'wpr_github_update' ) ) {
            wp_send_json_error( 'Nonce verification failed.' );
        }
        $channel = sanitize_text_field( $_POST['channel'] );
        if ( $channel === 'latest' ) $channel = 'stable'; // Migrate
        $interval = intval( $_POST['interval'] );
        if ( ! in_array( $channel, [ 'stable', 'beta', 'alpha', 'pre-release' ] ) || ! in_array( $interval, [ 21600, 43200, 86400, 259200 ] ) ) {
            wp_send_json_error( 'Invalid options.' );
        }
        update_option( 'wpr_github_channel', $channel );
        update_option( 'wpr_github_interval', $interval );
        $this->schedule_cron(); // Reschedule with new interval
        delete_site_transient( 'update_plugins' );
        wp_send_json_success();
    }

    /**
     * AJAX handler for manual check
     */
    public function ajax_manual_check() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }
        if ( ! wp_verify_nonce( $_POST['nonce'], 'wpr_github_update' ) ) {
            wp_send_json_error( 'Nonce verification failed.' );
        }
        $this->check_for_updates();
        delete_site_transient( 'update_plugins' );
        wp_send_json_success();
    }

    /**
     * AJAX handler for simulate update (temporary)
     */
    public function ajax_simulate_update() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }
        if ( ! wp_verify_nonce( $_POST['nonce'], 'wpr_github_update' ) ) {
            wp_send_json_error( 'Nonce verification failed.' );
        }
        // Instead of fake, simulate real pending
        $releases = $this->get_github_releases();
        if ( empty( $releases ) ) {
            wp_send_json_error( 'No releases.' );
        }
        $channel = get_option( 'wpr_github_channel', 'stable' );
        if ( $channel === 'latest' ) $channel = 'stable';
        $latest_in_channel = $this->get_latest_in_channel($releases, $channel);
        if ( $latest_in_channel ) {
            $release_url = "https://api.github.com/repos/wp-media/wp-rocket/releases/tags/{$latest_in_channel['tag_name']}";
            $response = wp_remote_get( $release_url, [ 'headers' => [ 'User-Agent' => 'WP Rocket Agent' ] ] );
            $release_data = [];
            $changelog_html = '';
            if ( ! \is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                $release_data = json_decode( wp_remote_retrieve_body( $response ), true );
                $release_page_url = "https://github.com/wp-media/wp-rocket/releases/tag/" . urlencode( $latest_in_channel['tag_name'] );
                $page_response = wp_remote_get( $release_page_url, [ 'headers' => [ 'User-Agent' => 'WP Rocket Agent' ], 'timeout' => 30 ] );
                if ( ! \is_wp_error( $page_response ) && wp_remote_retrieve_response_code( $page_response ) === 200 ) {
                    $page_body = wp_remote_retrieve_body( $page_response );
                    if ( preg_match( '/<div[^>]*class="markdown-body"[^>]*>(.*?)<\/div>/is', $page_body, $m ) ) {
                        $changelog_html = $m[1];
                        $changelog_html = preg_replace( '/<br\s*\/?>/i', '', $changelog_html );
                    }
                }
                if ( empty( $changelog_html ) ) {
                    $body = $release_data['body'] ?? '';
                    $changelog_html = $this->markdown_to_html( $body );
                }
            }
            update_option( 'wpr_pending_update', [
                'version' => $latest_in_channel['tag_name'],
                'zip' => $latest_in_channel['zip_url'],
                'channel' => $channel,
                'changelog' => $changelog_html,
                'published_at' => $latest_in_channel['published_at'],
            ] );
            delete_site_transient( 'update_plugins' );
            wp_send_json_success();
        } else {
            wp_send_json_error( 'No latest in channel.' );
        }
    }

    /**
     * Gets latest releases from GitHub API with caching
     *
     * @return array
     */
    private function get_github_releases() {
        $transient_key = 'wp_rocket_github_releases';
        $cached = get_transient( $transient_key );
        if ( false !== $cached ) {
            return $cached;
        }
        $response = wp_remote_get( 'https://api.github.com/repos/wp-media/wp-rocket/releases?per_page=20', [ 'headers' => [ 'User-Agent' => 'WP Rocket Agent' ] ] );
        if ( \is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return [];
        }
        $releases = json_decode( wp_remote_retrieve_body( $response ), true );
        $filtered = [];
        foreach ( $releases as $release ) {
            $type = 'stable';
            if ( $release['prerelease'] ) {
                if ( stripos( $release['tag_name'], 'alpha' ) !== false ) $type = 'alpha';
                elseif ( stripos( $release['tag_name'], 'beta' ) !== false ) $type = 'beta';
                elseif ( stripos( $release['tag_name'], 'rc' ) !== false || stripos( $release['tag_name'], 'pre' ) !== false ) $type = 'pre-release';
                else $type = 'pre-release';
            }
            $filtered[] = [
                'tag_name' => $release['tag_name'],
                'type' => $type,
                'published_at' => date( 'Y-m-d H:i', strtotime( $release['published_at'] ) ),
                'zip_url' => "https://github.com/wp-media/wp-rocket/archive/refs/tags/{$release['tag_name']}.zip",
            ];
        }
        set_transient( $transient_key, $filtered, HOUR_IN_SECONDS );
        return $filtered;
    }

    /**
     * Determines the type of a version tag
     *
     * @param string $tag
     * @return string
     */
    private function get_version_type( $tag ) {
        if ( stripos( $tag, 'alpha' ) !== false ) return 'alpha';
        if ( stripos( $tag, 'beta' ) !== false ) return 'beta';
        if ( stripos( $tag, 'rc' ) !== false || stripos( $tag, 'pre' ) !== false ) return 'pre-release';
        return 'stable';
    }

    /**
     * Schedules the cron event with dynamic interval
     */
    public function schedule_cron() {
        if ( ! wp_next_scheduled( 'wpr_github_check' ) ) {
            wp_schedule_event( time(), 'wpr_interval', 'wpr_github_check' );
        }
    }

    /**
     * Adds custom cron schedules
     *
     * @param array $schedules
     * @return array
     */
    public function add_custom_cron_schedules( $schedules ) {
        $interval = get_option( 'wpr_github_interval', 43200 );
        $schedules['wpr_interval'] = [
            'interval' => $interval,
            'display' => __( 'WP Rocket GitHub Update Interval', 'rocket' ),
        ];
        return $schedules;
    }

    /**
     * Checks for updates based on channel and sets pending update if available
     */
    public function check_for_updates() {
        $releases = $this->get_github_releases();
        if ( empty( $releases ) ) {
            return;
        }
        $channel = get_option( 'wpr_github_channel', 'stable' );
        if ( $channel === 'latest' ) $channel = 'stable';
        $latest_in_channel = $this->get_latest_in_channel($releases, $channel);
        if ( $latest_in_channel ) {
            $current_version = defined( 'WP_ROCKET_VERSION' ) ? WP_ROCKET_VERSION : '0';
            $latest_version = ltrim( $latest_in_channel['tag_name'], 'v' );
            if ( version_compare( $latest_version, $current_version, '>' ) ) {
                $release_url = "https://api.github.com/repos/wp-media/wp-rocket/releases/tags/{$latest_in_channel['tag_name']}";
                $response = wp_remote_get( $release_url, [ 'headers' => [ 'User-Agent' => 'WP Rocket Agent' ] ] );
                $release_data = [];
                $changelog_html = '';
                if ( ! \is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                    $release_data = json_decode( wp_remote_retrieve_body( $response ), true );
                    $release_page_url = "https://github.com/wp-media/wp-rocket/releases/tag/" . urlencode( $latest_in_channel['tag_name'] );
                    $page_response = wp_remote_get( $release_page_url, [ 'headers' => [ 'User-Agent' => 'WP Rocket Agent' ], 'timeout' => 30 ] );
                    if ( ! \is_wp_error( $page_response ) && wp_remote_retrieve_response_code( $page_response ) === 200 ) {
                        $page_body = wp_remote_retrieve_body( $page_response );
                        if ( preg_match( '/<div[^>]*class="markdown-body"[^>]*>(.*?)<\/div>/is', $page_body, $m ) ) {
                            $changelog_html = $m[1];
                            $changelog_html = preg_replace( '/<br\s*\/?>/i', '', $changelog_html );
                            // Add target="_blank" to all <a> tags if not present
                            $changelog_html = preg_replace('/<a\s+(?![^>]*\btarget=)([^>]*href=)/i', '<a target="_blank" $1', $changelog_html);
                        }
                    }
                    if ( empty( $changelog_html ) ) {
                        $body = $release_data['body'] ?? '';
                        $changelog_html = $this->markdown_to_html( $body );
                    }
                }
                update_option( 'wpr_pending_update', [
                    'version' => $latest_in_channel['tag_name'],
                    'zip' => $latest_in_channel['zip_url'],
                    'channel' => $channel,
                    'changelog' => $changelog_html,
                    'published_at' => $latest_in_channel['published_at'],
                ] );
                delete_site_transient( 'update_plugins' );
                return;
            }
        }
        delete_option( 'wpr_pending_update' );
        delete_site_transient( 'update_plugins' );
    }

    /**
     * Gets the latest release in the specified channel
     *
     * @param array $releases
     * @param string $channel
     * @return array|null
     */
    private function get_latest_in_channel( $releases, $channel ) {
        if ( $channel === 'latest' ) $channel = 'stable';
        foreach ( $releases as $release ) {
            if ( $release['type'] === $channel ) {
                return $release;
            }
        }
        // Fallback to overall latest if no match in channel
        return ! empty( $releases ) ? $releases[0] : null;
    }

    /**
     * Injects update info into WP update transient
     *
     * @param object $transient
     * @return object
     */
    public function check_wp_update_transient( $transient ) {
        $pending = get_option( 'wpr_pending_update' );
        if ( $pending ) {
            $current_version = defined( 'WP_ROCKET_VERSION' ) ? WP_ROCKET_VERSION : '0';
            $latest_version = ltrim( $pending['version'], 'v' );
            if ( version_compare( $latest_version, $current_version, '>' ) ) {
                $plugin_file = plugin_basename( WP_ROCKET_FILE );
                $transient->response[ $plugin_file ] = (object) [
                    'slug' => 'wp-rocket',
                    'new_version' => $latest_version,
                    'url' => 'https://github.com/wp-media/wp-rocket/releases/tag/' . $pending['version'],
                    'package' => $pending['zip'],
                ];
            }
        }
        return $transient;
    }

    /**
     * Filters the plugins_api to provide custom plugin information for changelog
     *
     * @param bool|object $result
     * @param string $action
     * @param object $args
     * @return bool|object|\WP_Error
     */
    public function plugins_api_filter( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }
        if ( empty( $args->slug ) || $args->slug !== 'wp-rocket' ) {
            return $result;
        }
        $pending = get_option( 'wpr_pending_update' );
        if ( ! $pending ) {
            return $result;
        }
        $changelog_html = $pending['changelog'] ?? '';
        if ( empty( $changelog_html ) ) {
            $changelog_html = 'No changelog available.';
        }
        $info = new \stdClass();
        $info->name = 'WP Rocket';
        $info->slug = 'wp-rocket';
        $info->version = ltrim( $pending['version'], 'v' );
        $info->author = '<a href="https://wp-rocket.me">WP Media</a>';
        $info->homepage = 'https://wp-rocket.me/';
        $info->requires = '5.0';
        $info->tested = get_bloginfo( 'version' );
        $info->requires_php = '7.3';
        $info->last_updated = $pending['published_at'] ?? '';
        $info->sections = [
            'description' => 'The best WordPress performance plugin.',
            'changelog' => $changelog_html,
        ];
        $info->download_link = $pending['zip'];
        $info->banners = [
            'low' => plugin_dir_url( WP_ROCKET_FILE ) . 'assets/img/logo-wprocket-dark.svg',
            'high' => plugin_dir_url( WP_ROCKET_FILE ) . 'assets/img/logo-wprocket-dark.svg',
        ];
        return $info;
    }

    /**
     * Simple Markdown to HTML converter
     *
     * @param string $markdown
     * @return string
     */
    private function markdown_to_html( $markdown ) {
        // Add header if not present
        if ( ! preg_match( '/^#+\s/m', $markdown ) ) {
            $markdown = "# Changelog\n\n" . $markdown;
        }
        // Bold the type (e.g., Enhancement:)
        $markdown = preg_replace('/^([a-zA-Z0-9 -]+?:)/m', '**$1**', $markdown);
        // Convert issue numbers to links
        $markdown = preg_replace('/#(\d+)/', '[#$1](https://github.com/wp-media/wp-rocket/issues/$1)', $markdown);
        // Headers
        $html = preg_replace('/^### (.*)$/m', '<h3>$1</h3>', $markdown);
        $html = preg_replace('/^## (.*)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.*)$/m', '<h1>$1</h1>', $html);
        // Task lists checked
        $html = preg_replace('/^\s*- \[x\]\s*(.*)$/m', '<ul class="contains-task-list"><li class="task-list-item"><input type="checkbox" disabled checked class="task-list-item-checkbox" aria-label="Completed task"> $1</li></ul>', $html);
        // Task lists unchecked
        $html = preg_replace('/^\s*- \[\s\]\s*(.*)$/m', '<ul class="contains-task-list"><li class="task-list-item"><input type="checkbox" disabled class="task-list-item-checkbox" aria-label="Incomplete task"> $1</li></ul>', $html);
        // Fix consecutive task lists
        $html = preg_replace('/<\/ul class="contains-task-list">\s*?<ul class="contains-task-list">/s', '', $html);
        // Bold
        $html = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $html);
        // Italic
        $html = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $html);
        // Code inline
        $html = preg_replace('/`(.*?)`/', '<code>$1</code>', $html);
        // Links (explicit Markdown)
        $html = preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2" target="_blank">$1</a>', $html);
        // Auto-link plain URLs (e.g., https://... ) but avoid inside <code> or existing <a>
        $html = preg_replace_callback('/(<code>.*?<\/code>)|((?<!["\'>])(https?:\/\/[^\s<>"\']+))/is', function($matches) {
            if (!empty($matches[1])) {
                return $matches[1]; // Skip inside <code>
            }
            return '<a href="' . $matches[2] . '" target="_blank">' . $matches[2] . '</a>';
        }, $html);
        // Unordered lists
        $html = preg_replace('/^[\-*+] (.*)$/m', '<ul><li>$1</li></ul>', $html);
        $html = preg_replace('/<\/ul>\s?<ul>/s', '', $html);
        // Ordered lists
        $html = preg_replace('/^\d+\. (.*)$/m', '<ol><li>$1</li></ol>', $html);
        $html = preg_replace('/<\/ol>\s?<ol>/s', '', $html);
        // Code blocks
        $html = preg_replace('/```(.*?)```/s', '<pre><code>$1</code></pre>', $html);
        // Wrap non-tagged lines (like <strong> entries) in <p>
        $html = preg_replace('/(^|\n)(<strong>.*?<\/a>)/', '$1<p>$2</p>', $html);
        if ( function_exists( 'wpautop' ) ) {
            $html = wpautop( $html );
        }
        return $html;
    }

    /**
     * Fixes the source folder name after extraction in WP upgrader
     *
     * @param string $source
     * @param string $remote_source
     * @param WP_Upgrader $upgrader
     * @param array $hook_extra
     * @return string
     */
    public function fix_upgrader_source( $source, $remote_source, $upgrader, $hook_extra ) {
        if ( isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === plugin_basename( WP_ROCKET_FILE ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            global $wp_filesystem;
            if ( ! WP_Filesystem() ) {
                return $source;
            }

            // If $source is a file path to a directory like /wp-content/upgrade/wp-rocket-3.19.3
            // we want to ensure we return /wp-content/upgrade/wp-rocket (clean slug) so WP Upgrader uses it.
            $parent_dir = trailingslashit( dirname( $source ) );
            $slug = 'wp-rocket';
            $new_source = trailingslashit( $parent_dir ) . $slug;

            // If source already ends with 'wp-rocket' — return as-is
            if ( substr( rtrim( $source, '/\\' ), -strlen( $slug ) ) === $slug ) {
                return $source;
            }

            // Find candidate folder inside $parent_dir starting with 'wp-rocket'
            $dirs = $wp_filesystem->dirlist( $parent_dir );
            if ( ! $dirs || ! is_array( $dirs ) ) {
                // fallback: return original source
                return $source;
            }

            $candidate = null;
            foreach ( $dirs as $dir_name => $dir_info ) {
                if ( $dir_info['type'] === 'd' && stripos( $dir_name, 'wp-rocket' ) === 0 ) {
                    $candidate = trailingslashit( $parent_dir ) . $dir_name;
                    break;
                }
            }

            // If no candidate found, try to use provided $source
            if ( ! $candidate ) {
                $candidate = $source;
            }

            // If new_source already exists, remove it (we will replace)
            if ( $wp_filesystem->exists( $new_source ) ) {
                $this->recursive_delete( $new_source );
            }

            // Try to move the candidate to new_source
            $moved = false;
            if ( $wp_filesystem->exists( $candidate ) ) {
                // Try moving
                if ( $wp_filesystem->move( $candidate, $new_source ) ) {
                    $moved = true;
                } else {
                    // move failed — try copy_dir (WP core helper) then delete original
                    if ( function_exists( 'copy_dir' ) ) {
                        $copy_result = copy_dir( $candidate, $new_source );
                        if ( $copy_result ) {
                            // remove original
                            $this->recursive_delete( $candidate );
                            $moved = true;
                        }
                    } else {
                        // fallback to manual copy loop
                        // Attempt to create destination and copy files
                        if ( ! $wp_filesystem->exists( $new_source ) ) {
                            $wp_filesystem->mkdir( $new_source );
                        }
                        $items = $wp_filesystem->dirlist( $candidate );
                        if ( $items ) {
                            $all_ok = true;
                            foreach ( $items as $name => $info ) {
                                $src = trailingslashit( $candidate ) . $name;
                                $dst = trailingslashit( $new_source ) . $name;
                                if ( $info['type'] === 'd' ) {
                                    if ( ! $this->recursive_copy_manual( $src, $dst ) ) {
                                        $all_ok = false;
                                        break;
                                    }
                                } else {
                                    if ( ! $wp_filesystem->copy( $src, $dst, true ) ) {
                                        $all_ok = false;
                                        break;
                                    }
                                }
                            }
                            if ( $all_ok ) {
                                $this->recursive_delete( $candidate );
                                $moved = true;
                            }
                        }
                    }
                }
            }

            if ( $moved && $wp_filesystem->exists( $new_source ) ) {
                // Return the new source path ending with trailing slash to be consistent
                return trailingslashit( $new_source );
            }

            // If we couldn't move/copy — return original $source (don't throw WP_Error to avoid blocking)
            return $source;
        }
        return $source;
    }

    /**
     * Helper: manual recursive copy when WP copy helpers are not available.
     *
     * @param string $src
     * @param string $dst
     * @return bool
     */
    private function recursive_copy_manual( $src, $dst ) {
        global $wp_filesystem;
        if ( ! $wp_filesystem->is_dir( $src ) ) {
            return false;
        }
        if ( ! $wp_filesystem->exists( $dst ) ) {
            $wp_filesystem->mkdir( $dst );
        }
        $items = $wp_filesystem->dirlist( $src );
        if ( ! $items ) {
            return true;
        }
        foreach ( $items as $name => $info ) {
            $s = trailingslashit( $src ) . $name;
            $d = trailingslashit( $dst ) . $name;
            if ( $info['type'] === 'd' ) {
                if ( ! $this->recursive_copy_manual( $s, $d ) ) {
                    return false;
                }
            } else {
                if ( ! $wp_filesystem->copy( $s, $d, true ) ) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Backs up protected files before WP update
     *
     * @param bool|WP_Error $response
     * @param array $hook_extra
     * @return bool|WP_Error
     */
    public function pre_update_backup( $response, $hook_extra ) {
        if ( isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === plugin_basename( WP_ROCKET_FILE ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            global $wp_filesystem;
            if ( ! WP_Filesystem() ) {
                return new \WP_Error( 'filesystem_failed', __( 'Failed to initialize filesystem.', 'rocket' ) );
            }
            $plugin_root = trailingslashit( dirname( WP_ROCKET_FILE ) );
            $temp_dir = trailingslashit( get_temp_dir() ) . 'wpr_backup/';
            if ( $wp_filesystem->exists( $temp_dir ) ) {
                $this->recursive_delete( $temp_dir );
            }
            $wp_filesystem->mkdir( $temp_dir );
            $protected_items = $this->get_protected_items();
            $this->copy_protected_items( $plugin_root, $temp_dir, $protected_items );
        }
        return $response;
    }

    /**
     * Restores protected files after WP update
     *
     * @param WP_Upgrader $upgrader
     * @param array $hook_extra
     */
    public function post_update_protect( $upgrader, $hook_extra ) {
        if ( $hook_extra['action'] === 'update' && $hook_extra['type'] === 'plugin' && isset( $hook_extra['plugins'] ) ) {
            $plugin_file = plugin_basename( WP_ROCKET_FILE );
            if ( in_array( $plugin_file, $hook_extra['plugins'], true ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                global $wp_filesystem;
                if ( ! WP_Filesystem() ) {
                    return;
                }
                $plugin_root = trailingslashit( dirname( WP_ROCKET_FILE ) );
                $temp_dir = trailingslashit( get_temp_dir() ) . 'wpr_backup/';
                if ( ! $wp_filesystem->exists( $temp_dir ) ) {
                    return;
                }
                $protected_items = $this->get_protected_items();
                $this->copy_protected_items( $temp_dir, $plugin_root, $protected_items, true );
                // Delete licence-data.php if exists
                $license_path = $plugin_root . 'licence-data.php';
                if ( $wp_filesystem->exists( $license_path ) ) {
                    $wp_filesystem->delete( $license_path );
                }
                $this->recursive_delete( $temp_dir );
                // Cleanup
                if ( function_exists( 'rocket_clean_domain' ) ) {
                    rocket_clean_domain();
                }
                delete_transient( 'wp_rocket_customer_data' );
                delete_transient( 'wp_rocket_github_releases' );
                delete_option( 'wpr_pending_update' );
                delete_site_transient( 'update_plugins' );
            }
        }
    }

    /**
     * Copies protected items from source to target, optionally deleting targets first
     *
     * @param string $source_root
     * @param string $target_root
     * @param array $protected_items
     * @param bool $delete_target_first
     * @return void
     */
    private function copy_protected_items( $source_root, $target_root, $protected_items, $delete_target_first = false ) {
        global $wp_filesystem;
        foreach ( $protected_items['dirs'] as $rel_dir ) {
            $source_dir = trailingslashit( $source_root . $rel_dir );
            $target_dir = trailingslashit( $target_root . $rel_dir );
            if ( $wp_filesystem->exists( $source_dir ) ) {
                if ( $delete_target_first && $wp_filesystem->exists( $target_dir ) ) {
                    $this->recursive_delete( $target_dir );
                }
                // Use manual recursive copy for robustness, especially for vendor/ on Unix
                if ( ! $this->recursive_copy_manual( $source_dir, $target_dir ) ) {
                    error_log( 'WPR: Failed to copy protected dir ' . $rel_dir );
                } else {
                    // Verify copy
                    if ( ! $wp_filesystem->exists( $target_dir ) ) {
                        error_log( 'WPR: Copy verification failed for dir ' . $rel_dir );
                    }
                }
            }
        }
        foreach ( $protected_items['files'] as $rel_file ) {
            $source_file = $source_root . $rel_file;
            $target_file = $target_root . $rel_file;
            if ( $wp_filesystem->exists( $source_file ) ) {
                wp_mkdir_p( dirname( $target_file ) );
                if ( $delete_target_first ) {
                    $wp_filesystem->delete( $target_file );
                }
                $wp_filesystem->copy( $source_file, $target_file, true );
            }
        }
    }

    /**
     * Recursively deletes a directory and its contents.
     *
     * @param string $dir_path The directory path to delete.
     * @return void
     */
    private function recursive_delete( $dir_path ) {
        global $wp_filesystem;
        if ( ! $wp_filesystem->is_dir( $dir_path ) ) {
            return;
        }
        $sub_contents = $wp_filesystem->dirlist( $dir_path );
        if ( $sub_contents === false ) {
            return;
        }
        if ( empty( $sub_contents ) ) {
            $wp_filesystem->rmdir( $dir_path );
            return;
        }
        foreach ( $sub_contents as $sub_name => $sub_details ) {
            $sub_path = trailingslashit( $dir_path ) . $sub_name;
            if ( $sub_details['type'] === 'd' ) {
                $this->recursive_delete( $sub_path );
            } else {
                $wp_filesystem->delete( $sub_path );
            }
        }
        $wp_filesystem->rmdir( $dir_path );
    }

    /**
     * Performs the update from GitHub ZIP, preserving custom files
     *
     * @param string $tag
     * @return bool|\WP_Error
     */
    private function perform_github_update( $tag ) {
        $lock_path = trailingslashit( WP_CONTENT_DIR ) . 'upgrade-temp-backup/update.lock';
        $fp = @fopen( $lock_path, 'w+' );
        if ( ! $fp || ! flock( $fp, LOCK_EX | LOCK_NB ) ) {
            if ( $fp ) {
                fclose( $fp );
            }
            return new \WP_Error( 'update_in_progress', 'Update already in progress.' );
        }

        if ( ! defined( 'WP_ROCKET_FILE' ) ) {
            flock( $fp, LOCK_UN );
            fclose( $fp );
            @unlink( $lock_path );
            return new \WP_Error( 'missing_constant', 'WP_ROCKET_FILE constant not defined.' );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        global $wp_filesystem;

        $plugin_root = trailingslashit( dirname( WP_ROCKET_FILE ) );

        $zip_url = "https://github.com/wp-media/wp-rocket/archive/refs/tags/{$tag}.zip";

        $temp_dir = trailingslashit( rtrim( WP_CONTENT_DIR, '/\\' ) ) . 'upgrade-temp-backup/';
        $temp_zip = $temp_dir . "wp-rocket-{$tag}.zip";
        $temp_extract = $temp_dir;

        // Initialize WP_Filesystem
        if ( ! \WP_Filesystem() ) {
            flock( $fp, LOCK_UN );
            fclose( $fp );
            @unlink( $lock_path );
            return new \WP_Error( 'filesystem_failed', __( 'Failed to initialize filesystem.', 'rocket' ) );
        }

        // Ensure temp dir exists and clean it
        if ( ! $wp_filesystem->exists( $temp_dir ) ) {
            $wp_filesystem->mkdir( $temp_dir );
        } else {
            $contents = $wp_filesystem->dirlist( $temp_dir );
            if ( $contents ) {
                foreach ( $contents as $item_name => $item_details ) {
                    $item_path = trailingslashit( $temp_dir ) . $item_name;
                    if ( $item_details['type'] === 'd' && $item_name !== 'plugins' ) {
                        $this->recursive_delete( $item_path );
                    } else if ( $item_details['type'] !== 'd' ) {
                        $wp_filesystem->delete( $item_path );
                    }
                }
            }
        }

        // Download and save ZIP in one go
        $zip_content = wp_remote_get( $zip_url, [ 'headers' => [ 'User-Agent' => 'WP Rocket Agent' ], 'timeout' => 60 ] );
        if ( \is_wp_error( $zip_content ) || wp_remote_retrieve_response_code( $zip_content ) !== 200 ) {
            flock( $fp, LOCK_UN );
            fclose( $fp );
            @unlink( $lock_path );
            return new \WP_Error( 'download_failed', __( 'Failed to download ZIP.', 'rocket' ) );
        }

        $body = wp_remote_retrieve_body( $zip_content );
        if ( empty( $body ) || strlen( $body ) < 100 || substr( $body, 0, 2 ) !== "PK" ) {
            error_log( 'WPR Manual Update: Invalid ZIP header or empty body for tag ' . $tag );
            flock( $fp, LOCK_UN );
            fclose( $fp );
            @unlink( $lock_path );
            return new \WP_Error( 'invalid_zip', 'Invalid ZIP file downloaded.' );
        }

        if ( ! $wp_filesystem->put_contents( $temp_zip, $body ) ) {
            flock( $fp, LOCK_UN );
            fclose( $fp );
            @unlink( $lock_path );
            return new \WP_Error( 'write_failed', 'Could not write ZIP file to temp.' );
        }

        // Extract with logging
        $extract_result = unzip_file( $temp_zip, $temp_extract );
        $extract_success = ! is_wp_error( $extract_result );
        if ( ! $extract_success ) {
            error_log( 'WPR Manual Update: unzip_file failed for tag ' . $tag . ', trying ZipArchive fallback.' );
        }

        // fallback to ZipArchive with better error handling
        if ( ! $extract_success && class_exists( 'ZipArchive' ) ) {
            $zip = new \ZipArchive();
            if ( $zip->open( $temp_zip ) === true ) {
                $extract_success = $zip->extractTo( $temp_extract );
                $zip->close();
                if ( ! $extract_success ) {
                    error_log( 'WPR Manual Update: ZipArchive extract failed for tag ' . $tag );
                }
            } else {
                error_log( 'WPR Manual Update: ZipArchive open failed for tag ' . $tag );
            }
        }

        if ( ! $extract_success ) {
            $wp_filesystem->delete( $temp_zip, false );
            flock( $fp, LOCK_UN );
            fclose( $fp );
            @unlink( $lock_path );
            return new \WP_Error( 'extract_failed', 'Could not extract ZIP. Check error_log for details.' );
        }

        // Determine extracted folder name (WP/GitHub zips typically create folder like wp-rocket-3.19.3 or wp-rocket-v3.19.3-alpha)
        $extracted_dirs = $wp_filesystem->dirlist( $temp_extract );
        $found_extract_folder = null;
        if ( $extracted_dirs && is_array( $extracted_dirs ) ) {
            foreach ( $extracted_dirs as $name => $info ) {
                if ( $info['type'] === 'd' && stripos( $name, 'wp-rocket' ) === 0 ) {
                    $found_extract_folder = trailingslashit( $temp_extract ) . $name;
                    break;
                }
            }
        }
        if ( ! $found_extract_folder ) {
            // fallback: look for any folder
            foreach ( $extracted_dirs as $name => $info ) {
                if ( $info['type'] === 'd' ) {
                    $found_extract_folder = trailingslashit( $temp_extract ) . $name;
                    break;
                }
            }
        }

        if ( ! $found_extract_folder || ! $wp_filesystem->exists( $found_extract_folder ) ) {
            error_log( 'WPR Manual Update: Extracted folder not found after unpack for tag ' . $tag );
            $wp_filesystem->delete( $temp_zip, false );
            flock( $fp, LOCK_UN );
            fclose( $fp );
            @unlink( $lock_path );
            return new \WP_Error( 'extract_not_found', 'Extracted folder not found.' );
        }

        // Normalize to a clean "wp-rocket" folder inside temp_dir
        $extract_root = trailingslashit( $temp_extract ) . 'wp-rocket';
        if ( $wp_filesystem->exists( $extract_root ) ) {
            $this->recursive_delete( $extract_root );
        }

        // Try move first, else copy
        if ( ! $wp_filesystem->move( $found_extract_folder, $extract_root ) ) {
            // attempt copy via manual for consistency
            if ( ! $this->recursive_copy_manual( $found_extract_folder, $extract_root ) ) {
                error_log( 'WPR Manual Update: Failed to normalize extracted folder for tag ' . $tag );
                $wp_filesystem->delete( $temp_zip, false );
                flock( $fp, LOCK_UN );
                fclose( $fp );
                @unlink( $lock_path );
                return new \WP_Error( 'rename_extract_failed', 'Failed to normalize extracted folder.' );
            }
            $this->recursive_delete( $found_extract_folder );
        }

        // Immediately delete licence-data.php after unpacking (considered virus, do not allow in new version)
        $license_path = trailingslashit( $extract_root ) . 'licence-data.php';
        if ($wp_filesystem->exists($license_path)) {
            $wp_filesystem->delete( $license_path, false );
        }

        // Prepare protected items
        $protected_items = $this->get_protected_items();

        // Copy protected directories from current plugin into new extract root (so custom vendor/ etc preserved)
        $this->copy_protected_items( $plugin_root, $extract_root . '/', $protected_items, true );

        // Now perform swap: remove existing plugin folder and move new in place.
        // Be careful: plugin_root may be /path/wp-content/plugins/wp-rocket/
        $this->recursive_delete( $plugin_root );
        if ( ! $wp_filesystem->move( $extract_root, $plugin_root ) ) {
            // If move fails, try manual copy as fallback
            if ( ! $this->recursive_copy_manual( $extract_root, $plugin_root ) ) {
                error_log( 'WPR Manual Update: Failed to move/copy to plugin directory for tag ' . $tag );
                flock( $fp, LOCK_UN );
                fclose( $fp );
                @unlink( $lock_path );
                return new \WP_Error( 'move_failed', 'Failed to move new files to plugin directory.' );
            }
            // remove the temp extract root
            $this->recursive_delete( $extract_root );
        }

        // Cleanup temp files
        $wp_filesystem->delete( $temp_zip, false );

        // Clear caches in batch
        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
        }
        delete_transient( 'wp_rocket_customer_data' );
        delete_transient( 'wp_rocket_github_releases' );
        delete_site_transient( 'update_plugins' );

        // Release lock
        flock( $fp, LOCK_UN );
        fclose( $fp );
        @unlink( $lock_path );

        return true;
    }

    /**
     * Gets the list of protected items
     *
     * @return array
     */
    private function get_protected_items() {
        return [
            'files' => [
                'composer.lock',
                'inc/Engine/License/API/UserClient.php',
                'inc/Engine/License/API/User.php',
            ],
            'dirs' => [
                'vendor/',
            ],
        ];
    }
}