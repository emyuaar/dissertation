<?php
/**
 * Brand Agent Endpoint Handler
 *
 * Handles proxying requests from the frontend to the BrandAgent backend server
 * with HMAC authentication.
 *
 * @package MicrosoftClarity
 * @since 0.10.21
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Brand Agent Endpoint Handler Class
 */
class BrandAgent_Endpoint {

    /**
     * Handle incoming API requests
     */
    public static function handle_request() {
        $path = get_query_var( 'brandagent_path' );

        if ( $path === 'api/config/read' ) {
            self::handle_config_read();
        }

        if ( $path === 'api/v1/init' ) {
            self::handle_init();
        }

        if ( $path === 'api/config/update' ) {
            self::handle_config_update();
        }

        if ( $path === 'api/config/status' ) {
            self::handle_config_status();
        }

        if ( $path === 'api/content/fetch' ) {
            self::handle_content_fetch();
        }
    }

    /**
     * Handle api/config/read endpoint
     * Proxies config requests to the BrandAgent backend
     */
    private static function handle_config_read() {
        // Get HMAC secret for this specific store (decrypted from wp_options)
        $store_url = home_url();
        $secret_key = brandagent_get_hmac_secret();

        if ( ! $secret_key ) {
            brandagent_log( 'BrandAgent Config Read: HMAC secret missing' );
            wp_send_json_error( array( 'message' => 'HMAC secret not found. Please complete onboarding.' ), 401 );
        }

        // Generate HMAC signature for this request
        $client_id = brandagent_get_client_id();
        if ( ! $client_id ) {
            brandagent_log( 'BrandAgent Config Read: Missing clientId parameter' );
            wp_send_json_error( array( 'message' => 'No clientId provided' ), 400 );
        }

        $timestamp = time();
        $signature = brandagent_generate_hmac_signature( $client_id, $timestamp, $secret_key );

        // Call the config endpoint directly with HMAC parameters
        $config_url = BrandAgent_Config::get_backend_base_url() . '/api/config/read';

        // Pass through all the original request parameters
        $config_query = $_GET;

        // Handle clientInformation separately to avoid double escaping
        $client_info = '';
        if ( isset( $config_query['clientInformation'] ) ) {
            // Get the raw value and clean it up
            $raw_client_info = $config_query['clientInformation'];

            // Remove any existing escaping
            $clean_json = stripslashes( $raw_client_info );

            // Validate it's valid JSON
            $decoded = json_decode( $clean_json, true );
            if ( $decoded !== null ) {
                // Re-encode as clean JSON
                $clean_json = json_encode( $decoded, JSON_UNESCAPED_SLASHES );
            }

            // URL encode it properly
            $client_info = '&clientInformation=' . rawurlencode( $clean_json );
            unset( $config_query['clientInformation'] ); // Remove from main query
        }

        // Build the main query without clientInformation
        $config_url_with_params = $config_url;
        if ( ! empty( $config_query ) ) {
            $config_url_with_params .= '?' . http_build_query( $config_query );
        }
        $config_url_with_params .= $client_info;

        // Set up headers that ConfigController might need
        $config_headers = array(
            'Content-Type'              => 'application/json',
            'Accept'                    => 'application/json',
            'User-Agent'                => 'BrandAgent-WordPress-Plugin/1.0',
            'ngrok-skip-browser-warning' => 'true', // Bypass ngrok browser warning
            'X-WooCommerce-Client-Id'   => $client_id,
            'X-WooCommerce-Store-Url'   => $store_url,
            'X-WooCommerce-Signature'   => $signature,
            'X-WooCommerce-Timestamp'   => (string) $timestamp,
        );

        // Add any existing headers from the original request that might be needed
        if ( isset( $_SERVER['HTTP_ACCEPT'] ) ) {
            $config_headers['Accept'] = $_SERVER['HTTP_ACCEPT'];
        }
        if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
            $config_headers['User-Agent'] = $_SERVER['HTTP_USER_AGENT'];
        }

        $config_response = wp_remote_get(
            $config_url_with_params,
            array(
                'timeout' => 30,
                'headers' => $config_headers,
            )
        );

        if ( is_wp_error( $config_response ) ) {
            brandagent_log( 'BrandAgent Config Read: Failed to get client config', array( 'error' => $config_response->get_error_message() ) );
            wp_send_json_error( array( 'message' => 'Failed to get client configuration' ), 502 );
        }

        $config_status_code = wp_remote_retrieve_response_code( $config_response );
        $config_body = wp_remote_retrieve_body( $config_response );

        if ( $config_status_code === 200 ) {
            // Return the actual client configuration from ConfigController
            header( 'Content-Type: application/json' );
            echo $config_body;
            exit;
        } else {
            brandagent_log( 'BrandAgent Config Read: Backend returned non-success status', array( 'status_code' => $config_status_code ) );
            wp_send_json_error( array( 'message' => 'Failed to retrieve configuration' ), $config_status_code );
        }
    }

    /**
     * Handle api/v1/init endpoint
     * Proxies SSE stream requests to the BrandAgent backend
     */
    private static function handle_init() {
        // Get HMAC secret for this specific store (decrypted from wp_options)
        $store_url = home_url();
        $secret_key = brandagent_get_hmac_secret();

        if ( ! $secret_key ) {
            brandagent_log( 'BrandAgent Init: HMAC secret missing' );
            wp_send_json_error( array( 'message' => 'HMAC secret not found. Please complete onboarding.' ), 401 );
        }

        // Generate HMAC signature for this request
        $client_id = brandagent_get_client_id();
        if ( ! $client_id ) {
            brandagent_log( 'BrandAgent Init: Missing clientId parameter' );
            wp_send_json_error( array( 'message' => 'No clientId provided' ), 400 );
        }

        $timestamp = time();
        $signature = brandagent_generate_hmac_signature( $client_id, $timestamp, $secret_key );

        // Call the init endpoint with HMAC parameters
        $init_url = BrandAgent_Config::get_backend_base_url() . '/api/v1/init';

        // Pass through all the original request parameters
        $init_query = $_GET;

        // Handle clientInformation separately to avoid double escaping
        $client_info = '';
        if ( isset( $init_query['clientInformation'] ) ) {
            // Get the raw value and clean it up
            $raw_client_info = $init_query['clientInformation'];

            // Remove any existing escaping
            $clean_json = stripslashes( $raw_client_info );

            // Validate it's valid JSON
            $decoded = json_decode( $clean_json, true );
            if ( $decoded !== null ) {
                // Re-encode as clean JSON
                $clean_json = json_encode( $decoded, JSON_UNESCAPED_SLASHES );
            }

            // URL encode it properly
            $client_info = '&clientInformation=' . rawurlencode( $clean_json );
            unset( $init_query['clientInformation'] ); // Remove from main query
        }

        // Build the main query without clientInformation
        $init_url_with_params = $init_url;
        if ( ! empty( $init_query ) ) {
            $init_url_with_params .= '?' . http_build_query( $init_query );
        }
        $init_url_with_params .= $client_info;

        // Set up headers for SSE request
        $init_headers = array(
            'Accept'                     => 'text/event-stream',
            'Cache-Control'              => 'no-cache',
            'User-Agent'                 => 'BrandAgent-WordPress-Plugin/1.0',
            'ngrok-skip-browser-warning' => 'true', // Bypass ngrok browser warning
            'X-WooCommerce-Client-Id'    => $client_id,
            'X-WooCommerce-Store-Url'    => $store_url,
            'X-WooCommerce-Signature'    => $signature,
            'X-WooCommerce-Timestamp'    => (string) $timestamp,
        );

        // Add any existing headers from the original request that might be needed
        if ( isset( $_SERVER['HTTP_ACCEPT'] ) ) {
            $init_headers['Accept'] = $_SERVER['HTTP_ACCEPT'];
        }
        if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
            $init_headers['User-Agent'] = $_SERVER['HTTP_USER_AGENT'];
        }

        $init_response = wp_remote_get(
            $init_url_with_params,
            array(
                'timeout' => 30,
                'headers' => $init_headers,
            )
        );

        if ( is_wp_error( $init_response ) ) {
            brandagent_log( 'BrandAgent Init: Failed to initialize chat', array( 'error' => $init_response->get_error_message() ) );
            wp_send_json_error( array( 'message' => 'Failed to initialize chat' ), 502 );
        }

        $init_status_code = wp_remote_retrieve_response_code( $init_response );
        $init_body = wp_remote_retrieve_body( $init_response );

        if ( $init_status_code === 200 ) {
            // Set SSE headers for the response
            header( 'Content-Type: text/event-stream' );
            header( 'Cache-Control: no-cache' );
            header( 'Connection: keep-alive' );

            echo $init_body;
            exit;
        } else {
            brandagent_log( 'BrandAgent Init: Backend returned non-success status', array( 'status_code' => $init_status_code ) );
            wp_send_json_error( array( 'message' => 'Failed to initialize chat' ), $init_status_code );
        }
    }

    /**
     * Handle api/config/update endpoint
     * Receives configuration updates from the backend server
     */
    private static function handle_config_update() {
        // Prevent caching of this state-changing endpoint
        header( 'Cache-Control: no-store' );

        // Get authentication headers
        $signature = isset( $_SERVER['HTTP_X_BA_SIGNATURE'] )
            ? sanitize_text_field( $_SERVER['HTTP_X_BA_SIGNATURE'] )
            : '';
        $timestamp = isset( $_SERVER['HTTP_X_BA_TIMESTAMP'] )
            ? sanitize_text_field( $_SERVER['HTTP_X_BA_TIMESTAMP'] )
            : '';
        $store_url_header = isset( $_SERVER['HTTP_X_BA_STORE_URL'] )
            ? sanitize_text_field( $_SERVER['HTTP_X_BA_STORE_URL'] )
            : '';

        // Validate required headers present
        if ( empty( $signature ) || empty( $timestamp ) || empty( $store_url_header ) ) {
            brandagent_log( 'BrandAgent Config Update: Missing required authentication headers' );
            wp_send_json_error( array( 'message' => 'Missing authentication headers' ), 401 );
        }

        // Verify store URL matches this site
        if ( $store_url_header !== home_url() ) {
            brandagent_log( 'BrandAgent Config Update: Store URL mismatch', array(
                'expected_store_url' => home_url(),
                'received_store_url' => $store_url_header,
            ) );
            wp_send_json_error( array( 'message' => 'Store URL mismatch' ), 403 );
        }

        // Read BAInjectFrontendScript from query parameter (GET) or JSON body (POST, legacy)
        $ba_value = null;
        $hmac_payload = '';
        if ( isset( $_GET['BAInjectFrontendScript'] ) ) {
            $ba_value = sanitize_text_field( $_GET['BAInjectFrontendScript'] );
            // HMAC signs the query string (same string the C# sender hashes)
            $hmac_payload = 'BAInjectFrontendScript=' . $ba_value;
        } elseif ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
            // Legacy POST support for backward compatibility during rollout
            $hmac_payload = file_get_contents( 'php://input' );
            $data = json_decode( $hmac_payload, true );
            if ( json_last_error() === JSON_ERROR_NONE && isset( $data['BAInjectFrontendScript'] ) ) {
                $ba_value = $data['BAInjectFrontendScript'] === true || $data['BAInjectFrontendScript'] === 'true' ? 'true' : 'false';
            }
        }

        if ( $ba_value === null ) {
            brandagent_log( 'BrandAgent Config Update: Missing BAInjectFrontendScript parameter', array( 'method' => $_SERVER['REQUEST_METHOD'] ?? '' ) );
            wp_send_json_error( array( 'message' => 'Missing BAInjectFrontendScript parameter' ), 400 );
        }

        // Verify HMAC signature
        if ( ! brandagent_verify_incoming_hmac_signature( $signature, $timestamp, $hmac_payload ) ) {
            brandagent_log( 'BrandAgent Config Update: HMAC signature verification failed' );
            wp_send_json_error( array( 'message' => 'Invalid signature' ), 401 );
        }

        // Handle BAInjectFrontendScript update
        $new_value = ( $ba_value === 'true' );
        update_option( 'BAInjectFrontendScript', $new_value ? 'true' : 'false' );

        brandagent_log( 'BrandAgent Config Update: BAInjectFrontendScript updated', array(
            'new_value' => $new_value ? 'true' : 'false',
        ) );

        // Create webhooks once when inject=true AND OAuth has succeeded.
        if ( $new_value
             && get_option( 'BAOauthSuccess' ) == 1
             && ! get_option( 'BAWebhooksCreated' ) ) {
            // BA server has already handled complete-onboarding via PublishAgent.
            // The plugin's only job here is to register WooCommerce webhooks.
            if ( class_exists( 'BrandAgent_Webhooks' ) ) {
                $results       = BrandAgent_Webhooks::create_webhooks();
                $webhook_count = is_array( $results ) ? count( $results ) : 0;
                $success_count = is_array( $results ) ? count( array_filter( $results ) ) : 0;
                $failure_count = $webhook_count - $success_count;
                $all_succeeded = ( 0 < $webhook_count && 0 === $failure_count );
                if ( $all_succeeded ) {
                    update_option( 'BAWebhooksCreated', true );
                    brandagent_log( 'BrandAgent Config Update: Webhooks created on store approval', array(
                        'webhook_count' => $webhook_count,
                        'success_count' => $success_count,
                        'failure_count' => $failure_count,
                    ) );
                } else {
                    // Do NOT persist BAWebhooksCreated on partial/failed creation so future attempts can retry.
                    brandagent_log( 'BrandAgent Config Update: Webhook creation incomplete; will retry on next update', array(
                        'webhook_count' => $webhook_count,
                        'success_count' => $success_count,
                        'failure_count' => $failure_count,
                    ) );
                }
            } else {
                brandagent_log( 'BrandAgent Config Update: BrandAgent_Webhooks class not available for store approval webhook creation' );
            }
        } else {
            brandagent_log( 'BrandAgent Config Update: No onboarding side effects required', array(
                'new_value'     => $new_value ? 'true' : 'false',
                'oauth_success' => get_option( 'BAOauthSuccess' ) == 1,
            ) );
        }

        wp_send_json_success( array(
            'message' => 'Configuration updated',
            'BAInjectFrontendScript' => $new_value ? 'true' : 'false'
        ) );
    }

    /**
     * Handle api/content/fetch endpoint
     *
     * Serves the site's own content (posts/pages) to the BrandAgent backend for
     * indexing. Backend-to-plugin call, authenticated with the same X-BA-* inbound
     * HMAC contract as config/update (signature over store_url + timestamp + sha256(raw body)).
     * Content is read server-side via WP_Query, so it is reachable regardless of which
     * post types opt into the public REST API and works on any WordPress (no WooCommerce).
     */
    private static function handle_content_fetch() {
        header( 'Cache-Control: no-store' );

        $signature = isset( $_SERVER['HTTP_X_BA_SIGNATURE'] )
            ? sanitize_text_field( $_SERVER['HTTP_X_BA_SIGNATURE'] )
            : '';
        $timestamp = isset( $_SERVER['HTTP_X_BA_TIMESTAMP'] )
            ? sanitize_text_field( $_SERVER['HTTP_X_BA_TIMESTAMP'] )
            : '';
        $store_url_header = isset( $_SERVER['HTTP_X_BA_STORE_URL'] )
            ? sanitize_text_field( $_SERVER['HTTP_X_BA_STORE_URL'] )
            : '';

        if ( empty( $signature ) || empty( $timestamp ) || empty( $store_url_header ) ) {
            brandagent_log( 'BrandAgent Content Fetch: Missing required authentication headers' );
            wp_send_json_error( array( 'message' => 'Missing authentication headers' ), 401 );
        }

        if ( $store_url_header !== home_url() ) {
            brandagent_log( 'BrandAgent Content Fetch: Store URL mismatch', array(
                'expected_store_url' => home_url(),
                'received_store_url' => $store_url_header,
            ) );
            wp_send_json_error( array( 'message' => 'Store URL mismatch' ), 403 );
        }

        $raw_body = file_get_contents( 'php://input' );
        if ( ! brandagent_verify_incoming_hmac_signature( $signature, $timestamp, (string) $raw_body ) ) {
            brandagent_log( 'BrandAgent Content Fetch: HMAC signature verification failed' );
            wp_send_json_error( array( 'message' => 'Invalid signature' ), 401 );
        }

        $req = json_decode( (string) $raw_body, true );
        if ( ! is_array( $req ) ) {
            $req = array();
        }

        // Restrict to an explicit allowlist so a caller cannot pull private or PII-bearing
        // custom post types through WP_Query. Sites can widen it via the filter.
        $allowed_types   = apply_filters( 'brandagent_content_fetch_allowed_post_types', array( 'post', 'page' ) );
        $requested_types = ( isset( $req['types'] ) && is_array( $req['types'] ) )
            ? array_values( array_map( 'sanitize_key', $req['types'] ) )
            : array();
        $types = array_values( array_intersect( $requested_types, $allowed_types ) );
        if ( empty( $types ) ) {
            $types = $allowed_types;
        }
        $page     = isset( $req['page'] ) ? max( 1, intval( $req['page'] ) ) : 1;
        $per_page = isset( $req['per_page'] ) ? min( 100, max( 1, intval( $req['per_page'] ) ) ) : 50;

        $query = new WP_Query( array(
            'post_type'           => $types,
            'post_status'         => 'publish',
            'posts_per_page'      => $per_page,
            'paged'               => $page,
            'orderby'             => 'ID',
            'order'               => 'ASC',
            'ignore_sticky_posts' => true,
            'has_password'        => false,
        ) );

        $items = array();
        foreach ( $query->posts as $post ) {
            // Raw rendered content (the_content output). Tag-stripping / plain-text extraction is
            // done on the Brand Agents server, so the plugin no longer cleans the content here.
            $rendered = apply_filters( 'the_content', $post->post_content );

            $cat_terms = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'names' ) );
            $tag_terms = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'names' ) );

            // Prefer the post's featured image; fall back to the first inline image in the rendered
            // content so image-rich posts (e.g. recipes) still get a card image when no thumbnail is set.
            $feature_image = get_the_post_thumbnail_url( $post, 'full' );
            if ( ! $feature_image && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $rendered, $img_match ) ) {
                $feature_image = $img_match[1];
            }
            $feature_image = $feature_image ? $feature_image : '';

            $items[] = array(
                'id'            => (int) $post->ID,
                'type'          => $post->post_type,
                'title'         => get_the_title( $post ),
                'url'           => get_permalink( $post ),
                'feature_image' => $feature_image,
                'content_text'  => $rendered,
                'excerpt'       => has_excerpt( $post ) ? get_the_excerpt( $post ) : '',
                'modified'      => get_post_modified_time( 'c', true, $post ),
                'author'        => get_the_author_meta( 'display_name', $post->post_author ),
                'categories'    => is_wp_error( $cat_terms ) ? array() : array_values( $cat_terms ),
                'tags'          => is_wp_error( $tag_terms ) ? array() : array_values( $tag_terms ),
            );
        }

        wp_send_json_success( array(
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'count'       => count( $items ),
            'items'       => $items,
        ) );
    }

    /**
     * Handle api/config/status endpoint
     * Returns current configuration values (read-only, no auth required)
     */
    private static function handle_config_status() {
        $ba_inject_enabled = get_option( 'BAInjectFrontendScript', 'false' );
        $ba_oauth_success = get_option( 'BAOauthSuccess', '0' );

        wp_send_json_success( array(
            'BAInjectFrontendScript' => $ba_inject_enabled,
            'BAOauthSuccess' => $ba_oauth_success,
            'pluginVersion' => get_installed_plugin_version(),
        ) );
    }
}

// Run the handler immediately when file is included
BrandAgent_Endpoint::handle_request();
