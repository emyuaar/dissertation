<?php
/**
 * Brand Agent — plain WordPress (non-commerce) connect.
 *
 * Provisions the per-store HMAC secret for a plain WordPress site without the
 * WooCommerce wc-auth grant. Because the plugin runs in wp-admin under a
 * privileged user, the connect handshake is a direct server-to-server call to the
 * Clarity dashboard, which mints the secret, registers the advertiser with the
 * BrandAgent backend (Platform=WordPress) and returns the secret to the plugin.
 *
 * Store ownership is proven with a one-time nonce loopback: connect stores a nonce
 * here (admin-privileged) and sends it to the dashboard, which calls back to
 * connect-verify below before minting. A forged connect naming another site cannot
 * pass because only that site's plugin holds the matching nonce.
 *
 * @package MicrosoftClarity
 */

defined( 'ABSPATH' ) || exit;

/**
 * Run the plain-WordPress connect handshake.
 *
 * @return array Result with at least a boolean 'success' key.
 */
function brandagent_wordpress_connect() {
	$store_url  = home_url();
	$project_id = get_option( 'clarity_project_id', '' );
	$wp_site_id = get_option( 'clarity_wordpress_site_id', '' );

	$clarity_server_url = BrandAgent_Config::get_clarity_server_url();
	if ( empty( $clarity_server_url ) ) {
		return array( 'success' => false, 'error' => 'clarity_server_url not configured' );
	}

	$connect_url = trailingslashit( $clarity_server_url ) . 'wordpress/connect';

	// Store-ownership proof: mint a one-time nonce that the dashboard verifies by calling
	// back to connect-verify before it issues the HMAC secret. Persist only the hash, so a
	// read of wp_options/object cache never exposes a usable nonce. Short-lived + one-time.
	$connect_nonce = wp_generate_password( 64, false );
	set_transient( 'brandagent_connect_nonce', hash( 'sha256', $connect_nonce ), 10 * MINUTE_IN_SECONDS );

	$body = wp_json_encode( array(
		'storeUrl'         => $store_url,
		'clarityProjectId' => $project_id,
		'wordpressSiteId'  => $wp_site_id,
		'connectNonce'     => $connect_nonce,
	) );

	brandagent_log( 'BrandAgent WordPress Connect: starting', array( 'store_url' => $store_url, 'endpoint' => $connect_url ) );

	$response = wp_remote_post( $connect_url, array(
		'timeout' => 30,
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => $body,
	) );

	if ( is_wp_error( $response ) ) {
		brandagent_log( 'BrandAgent WordPress Connect: transport error', array( 'error' => $response->get_error_message() ) );
		return array( 'success' => false, 'error' => $response->get_error_message() );
	}

	$code = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code !== 200 || empty( $data['hmac_secret'] ) ) {
		brandagent_log( 'BrandAgent WordPress Connect: unexpected response', array( 'status' => $code ) );
		return array( 'success' => false, 'error' => 'connect failed (status ' . $code . ')' );
	}

	brandagent_store_hmac_secret( $data['hmac_secret'] );
	update_option( 'BAOauthSuccess', true );
	brandagent_log( 'BrandAgent WordPress Connect: success', array( 'store_url' => $store_url ) );

	return array(
		'success'      => true,
		'advertiserId' => isset( $data['advertiserId'] ) ? $data['advertiserId'] : null,
	);
}

/**
 * REST route to trigger the plain-WordPress connect from the admin UI (admin-only).
 * POST /wp-json/adsagent/v1/wordpress/connect
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'adsagent/v1', '/wordpress/connect', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'callback'            => function () {
			$result = brandagent_wordpress_connect();
			return new WP_REST_Response( $result, ! empty( $result['success'] ) ? 200 : 502 );
		},
	) );
} );

/**
 * Store-ownership challenge for the plain-WordPress connect. The Clarity dashboard calls
 * this back with the nonce from the connect request; a match proves the connect was
 * initiated by this site's admin-privileged plugin, not forged elsewhere for this URL.
 * Public route by design — at connect time no shared secret exists yet, so the one-time
 * nonce (64 chars, 10-min TTL, consumed on match) is the proof.
 * POST /?rest_route=/adsagent/v1/wordpress/connect-verify
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'adsagent/v1', '/wordpress/connect-verify', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $request ) {
			$received = (string) $request->get_param( 'connectNonce' );
			$stored   = get_transient( 'brandagent_connect_nonce' );

			if ( ! empty( $received ) && ! empty( $stored ) && hash_equals( (string) $stored, hash( 'sha256', $received ) ) ) {
				delete_transient( 'brandagent_connect_nonce' );
				return new WP_REST_Response( array( 'verified' => true ), 200 );
			}

			return new WP_REST_Response( array( 'verified' => false ), 401 );
		},
	) );
} );
