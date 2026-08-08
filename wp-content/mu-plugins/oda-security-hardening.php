<?php
/**
 * Plugin Name: ODA Security Hardening
 * Description: Local security defaults for this single-site installation.
 * Author: Site Administrator
 */

defined( 'ABSPATH' ) || exit;

// This site does not use pingbacks or XML-RPC integrations.
add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter(
	'wp_headers',
	static function ( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
);

// Do not allow anonymous visitors or plugins to turn public registration on.
add_filter( 'pre_option_users_can_register', static function () {
	return '0';
} );
add_filter( 'pre_option_default_role', static function () {
	return 'subscriber';
} );

// REST batch requests and the users collection are not public site features.
add_filter(
	'rest_pre_dispatch',
	static function ( $result, $server, $request ) {
		$route = trim( (string) $request->get_route(), '/' );

		if ( 'batch/v1' === $route && ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_batch_requires_authentication',
				'Authentication is required for REST batch requests.',
				array( 'status' => 401 )
			);
		}

		if ( preg_match( '#^wp/v2/users(?:/|$)#', $route ) && ! current_user_can( 'list_users' ) ) {
			return new WP_Error(
				'rest_users_forbidden',
				'User enumeration is not available.',
				array( 'status' => 403 )
			);
		}

		return $result;
	},
	10,
	3
);

// These plugins should not run in production: one executes database-stored PHP
// with eval(), and the other is a Google Site Kit development utility.
add_filter(
	'pre_option_active_plugins',
	static function ( $plugins ) {
		if ( ! is_array( $plugins ) ) {
			return $plugins;
		}

		$disabled_plugins = array(
			'insert-php-code-snippet/insert-php-code-snippet.php',
			'google-site-kit-dev-settings/google-site-kit-dev-settings.php',
		);

		return array_values(
			array_filter(
				$plugins,
				static function ( $plugin ) use ( $disabled_plugins ) {
					return ! in_array( $plugin, $disabled_plugins, true );
				}
			)
		);
	},
	10
);
