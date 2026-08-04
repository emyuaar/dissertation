<?php
/**
 * OAuth 2.1 authorization server for the LLMagnet MCP endpoint.
 *
 * ChatGPT custom connectors (and other strict MCP clients) cannot send custom
 * headers, so the managed Bearer tokens in {@see MCP_Tokens} are unusable from
 * them — the only way to connect *privately* (authenticated) is OAuth. This
 * class turns the site into a minimal OAuth 2.1 authorization server, so the
 * existing MCP resource server ({@see MCP}) can accept OAuth-issued bearer
 * access tokens with no transport changes.
 *
 * Implements the pieces the MCP authorization spec requires of an AS:
 * - RFC 9728 — Protected Resource Metadata (/.well-known/oauth-protected-resource)
 * - RFC 8414 — Authorization Server Metadata (/.well-known/oauth-authorization-server)
 * - RFC 7591 — Dynamic Client Registration (POST /oauth/register)
 * - RFC 7636 — PKCE (S256, mandatory)
 * - Authorization Code + Refresh Token grants (token endpoint), public clients.
 *
 * Flow:
 *  1. Client hits the MCP endpoint, gets 401 + WWW-Authenticate pointing here.
 *  2. Client reads the two metadata docs, registers (DCR), then redirects the
 *     user to the authorization endpoint with PKCE.
 *  3. We render a branded consent page (LLMagnet + the client's logo). The
 *     signed-in admin chooses a scope and approves → single-use code.
 *  4. Client exchanges the code (with the PKCE verifier) for an access +
 *     refresh token at the token endpoint.
 *  5. Client calls the MCP endpoint with the access token as a Bearer.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\MCP_Tokens' ) ) {
	require_once __DIR__ . '/class-mcp-tokens.php';
}

/**
 * Minimal OAuth 2.1 authorization server scoped to the MCP resource.
 */
class MCP_OAuth {

	/** REST namespace for the machine endpoints (token, register). */
	const REST_NAMESPACE = 'llmagnet/mcp';

	/** Well-known path: protected resource metadata (RFC 9728). */
	const PRM_PATH = '.well-known/oauth-protected-resource';

	/** Well-known path: authorization server metadata (RFC 8414). */
	const ASM_PATH = '.well-known/oauth-authorization-server';

	/** Query var that activates the authorization/consent page on the front end. */
	const AUTHORIZE_QUERY_VAR = 'llmagnet_oauth';

	/** Option storing registered (DCR) OAuth clients (autoload off). */
	const CLIENTS_OPTION = 'llmagnet_mcp_oauth_clients';

	/** Maximum registered clients retained (oldest pruned). */
	const MAX_CLIENTS = 50;

	/** Max dynamic-client registrations allowed per IP within the window. */
	const REGISTER_RATE_LIMIT = 10;

	/** Rate-limit window for /oauth/register (seconds). */
	const REGISTER_RATE_WINDOW = 3600;

	/** Authorization code lifetime (seconds). */
	const CODE_TTL = 600;

	/** Scopes this server understands. */
	const SCOPES = [ 'read', 'write' ];

	/** @var MCP_Tokens */
	private $tokens;

	/**
	 * @param MCP_Tokens|null $tokens Shared token manager (issues/verifies grants).
	 */
	public function __construct( $tokens = null ) {
		$this->tokens = $tokens instanceof MCP_Tokens ? $tokens : new MCP_Tokens();
	}

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'llmagnet_register_well_known_providers', [ $this, 'register_well_known' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		// Authorization endpoint is a front-end page (needs the login cookie and
		// renders HTML), routed by a query var so it works on any permalink mode.
		add_action( 'template_redirect', [ $this, 'maybe_handle_authorize' ], 0 );
	}

	/**
	 * Whether the on-plugin OAuth server is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = MCP::get_settings();
		return ! empty( $settings['oauth_enabled'] );
	}

	// ── Discovery metadata ──────────────────────────────────────────────────────

	/**
	 * Register the two `.well-known` metadata documents with the router.
	 *
	 * @return void
	 */
	public function register_well_known() {
		if ( ! self::is_enabled() ) {
			return;
		}
		Well_Known::register( self::PRM_PATH, [ $this, 'render_protected_resource_metadata' ], 'application/json; charset=utf-8', [ 'cache_max_age' => 3600 ] );
		Well_Known::register( self::ASM_PATH, [ $this, 'render_authorization_server_metadata' ], 'application/json; charset=utf-8', [ 'cache_max_age' => 3600 ] );
	}

	/**
	 * Absolute URL of the protected-resource metadata document. Advertised in
	 * the MCP server's 401 `WWW-Authenticate` header.
	 *
	 * @return string
	 */
	public static function protected_resource_metadata_url() {
		return self::to_request_host( home_url( '/' . self::PRM_PATH ) );
	}

	/**
	 * The OAuth issuer (base URL where `.well-known` documents live).
	 *
	 * @return string
	 */
	private static function issuer() {
		$origin = self::request_origin();
		return '' !== $origin ? $origin : untrailingslashit( home_url() );
	}

	/**
	 * The MCP resource URL these tokens are scoped to.
	 *
	 * @return string
	 */
	private static function resource_url() {
		return self::to_request_host( rest_url( 'llmagnet/mcp/v1' ) );
	}

	/**
	 * Absolute authorization endpoint URL (front-end consent page).
	 *
	 * @return string
	 */
	public static function authorize_url() {
		return self::to_request_host( home_url( '/?' . self::AUTHORIZE_QUERY_VAR . '=authorize' ) );
	}

	/**
	 * Explicitly configured public base origin for OAuth discovery, if any.
	 *
	 * This is the reliable knob for local-dev testing through a tunnel: set it
	 * to the public URL (e.g. `https://abc123.ngrok-free.app`) and every
	 * advertised OAuth URL uses it, independent of forwarded headers. Settable
	 * via the `oauth_base_url` MCP setting or the `llmagnet_mcp_oauth_base_url`
	 * filter.
	 *
	 * @return string Origin (scheme://host[:port], no trailing slash), or ''.
	 */
	private static function configured_base() {
		$settings = MCP::get_settings();
		$base     = isset( $settings['oauth_base_url'] ) ? (string) $settings['oauth_base_url'] : '';

		/**
		 * Filter the public base URL used for OAuth discovery documents.
		 *
		 * @param string $base Configured base URL (may be empty).
		 */
		$base = (string) apply_filters( 'llmagnet_mcp_oauth_base_url', $base );

		if ( '' === $base ) {
			return '';
		}

		$parts = wp_parse_url( $base );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$origin = strtolower( $parts['scheme'] ) . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}
		return $origin;
	}

	/**
	 * Scheme + host the current request actually arrived on.
	 *
	 * The site's configured home/site URL is often a private dev host
	 * (e.g. `https://llmagnet.local`) that the MCP client can't reach. When the
	 * site is exposed through a tunnel / reverse proxy (ngrok, Cloudflare
	 * Tunnel, Local "Live Link", …), the client reaches it on a different,
	 * public host — so every advertised OAuth URL must use *that* host, or the
	 * client will discover endpoints it can't reach. Prefers an explicitly
	 * configured base, then forwarded host/proto headers, then the Host header.
	 *
	 * @return string Origin like `https://abc123.ngrok-free.app`, or '' when unavailable.
	 */
	private static function request_origin() {
		$configured = self::configured_base();
		if ( '' !== $configured ) {
			return $configured;
		}

		$host = '';
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ) {
			$host = (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_HOST'] );
			$host = trim( explode( ',', $host )[0] );
		} elseif ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
			$host = (string) wp_unslash( $_SERVER['HTTP_HOST'] );
		}
		$host = sanitize_text_field( $host );

		// Strict allowlist of characters valid in a host[:port].
		if ( '' === $host || ! preg_match( '/^[A-Za-z0-9.\-:]+$/', $host ) ) {
			return '';
		}

		$proto = '';
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
			$proto = strtolower( trim( explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) )[0] ) );
		}
		// A forwarded host without a proto hint means a public tunnel, which is
		// always HTTPS for MCP clients; otherwise trust the local scheme.
		if ( 'https' === $proto ) {
			$scheme = 'https';
		} elseif ( 'http' === $proto ) {
			$scheme = 'http';
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ) {
			$scheme = 'https';
		} else {
			$scheme = is_ssl() ? 'https' : 'http';
		}

		return $scheme . '://' . $host;
	}

	/**
	 * Rewrite an absolute site URL onto the host the current request arrived on,
	 * preserving its path + query (so it works behind a tunnel / reverse proxy).
	 *
	 * @param string $url Absolute URL generated via home_url()/rest_url().
	 * @return string
	 */
	private static function to_request_host( $url ) {
		$origin = self::request_origin();
		if ( '' === $origin ) {
			return $url;
		}
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '';
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		return $origin . $path . $query;
	}

	/**
	 * RFC 9728 protected-resource metadata body.
	 *
	 * @return string
	 */
	public function render_protected_resource_metadata() {
		if ( ! self::is_enabled() ) {
			return null;
		}
		return wp_json_encode( [
			'resource'                 => self::resource_url(),
			'authorization_servers'    => [ self::issuer() ],
			'scopes_supported'         => self::SCOPES,
			'bearer_methods_supported' => [ 'header' ],
			'resource_name'            => get_bloginfo( 'name' ) . ' — LLMagnet MCP',
		] );
	}

	/**
	 * RFC 8414 authorization-server metadata body.
	 *
	 * @return string
	 */
	public function render_authorization_server_metadata() {
		if ( ! self::is_enabled() ) {
			return null;
		}
		return wp_json_encode( [
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => self::authorize_url(),
			'token_endpoint'                        => self::to_request_host( rest_url( self::REST_NAMESPACE . '/oauth/token' ) ),
			'registration_endpoint'                 => self::to_request_host( rest_url( self::REST_NAMESPACE . '/oauth/register' ) ),
			'scopes_supported'                      => self::SCOPES,
			'response_types_supported'              => [ 'code' ],
			'response_modes_supported'              => [ 'query' ],
			'grant_types_supported'                 => [ 'authorization_code', 'refresh_token' ],
			'token_endpoint_auth_methods_supported' => [ 'none' ],
			'code_challenge_methods_supported'      => [ 'S256' ],
		] );
	}

	// ── REST endpoints: register (DCR) + token ───────────────────────────────────

	/**
	 * Register the token and dynamic-client-registration routes.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route( self::REST_NAMESPACE, '/oauth/register', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_register' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::REST_NAMESPACE, '/oauth/token', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_token' ],
			'permission_callback' => '__return_true',
		] );
	}

	/**
	 * Dynamic Client Registration (RFC 7591). Public clients only.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_register( \WP_REST_Request $request ) {
		if ( ! self::is_enabled() ) {
			return $this->oauth_error( 'access_denied', 'OAuth is disabled for this server.', 403 );
		}

		// Dynamic client registration is public (RFC 7591), so throttle it per
		// IP to keep an attacker from flooding wp_options with bogus clients.
		if ( $this->is_register_rate_limited() ) {
			$response = $this->oauth_error( 'too_many_requests', 'Too many registration attempts. Try again later.', 429 );
			$response->header( 'Retry-After', (string) self::REGISTER_RATE_WINDOW );
			return $response;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$redirect_uris = isset( $params['redirect_uris'] ) ? $params['redirect_uris'] : [];
		if ( ! is_array( $redirect_uris ) ) {
			$redirect_uris = [ $redirect_uris ];
		}

		$clean = [];
		foreach ( $redirect_uris as $uri ) {
			// Normalize the same way the authorize endpoint normalizes the
			// incoming redirect_uri, so the later exact-match check is reliable.
			$normalized = is_string( $uri ) ? esc_url_raw( $uri ) : '';
			if ( '' !== $normalized && $this->is_valid_redirect_uri( $normalized ) ) {
				$clean[] = $normalized;
			}
		}
		if ( empty( $clean ) ) {
			return $this->oauth_error( 'invalid_redirect_uri', 'At least one valid https redirect_uri is required.', 400 );
		}

		$client_name = isset( $params['client_name'] ) ? sanitize_text_field( (string) $params['client_name'] ) : '';
		if ( '' === $client_name ) {
			$client_name = __( 'MCP client', 'llmagnet-llm-txt-generator' );
		}

		$now    = time();
		$record = [
			'client_id'                  => 'cid_' . bin2hex( random_bytes( 16 ) ),
			'client_name'                => $client_name,
			'redirect_uris'              => array_values( array_unique( $clean ) ),
			'token_endpoint_auth_method' => 'none',
			'grant_types'                => [ 'authorization_code', 'refresh_token' ],
			'response_types'             => [ 'code' ],
			'created_at'                 => $now,
		];

		$clients   = $this->get_clients();
		$clients[] = $record;
		update_option( self::CLIENTS_OPTION, $this->prune_clients( $clients ), false );

		$response = new \WP_REST_Response( [
			'client_id'                  => $record['client_id'],
			'client_id_issued_at'        => $now,
			'client_name'                => $record['client_name'],
			'redirect_uris'              => $record['redirect_uris'],
			'token_endpoint_auth_method' => 'none',
			'grant_types'                => $record['grant_types'],
			'response_types'             => $record['response_types'],
		], 201 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Token endpoint: authorization_code and refresh_token grants.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_token( \WP_REST_Request $request ) {
		if ( ! self::is_enabled() ) {
			return $this->oauth_error( 'access_denied', 'OAuth is disabled for this server.', 403 );
		}

		$grant_type = (string) $request->get_param( 'grant_type' );

		if ( 'authorization_code' === $grant_type ) {
			return $this->token_authorization_code( $request );
		}
		if ( 'refresh_token' === $grant_type ) {
			return $this->token_refresh( $request );
		}

		return $this->oauth_error( 'unsupported_grant_type', 'grant_type must be authorization_code or refresh_token.', 400 );
	}

	/**
	 * authorization_code grant with PKCE verification.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	private function token_authorization_code( \WP_REST_Request $request ) {
		$code          = (string) $request->get_param( 'code' );
		$redirect_uri  = (string) $request->get_param( 'redirect_uri' );
		$client_id     = (string) $request->get_param( 'client_id' );
		$code_verifier = (string) $request->get_param( 'code_verifier' );

		if ( '' === $code || '' === $code_verifier ) {
			return $this->oauth_error( 'invalid_request', 'Missing code or code_verifier.', 400 );
		}

		$stored = $this->consume_code( $code );
		if ( null === $stored ) {
			return $this->oauth_error( 'invalid_grant', 'Authorization code is invalid or expired.', 400 );
		}

		// The code is bound to the client and redirect_uri it was issued for.
		if ( $client_id !== $stored['client_id'] || $redirect_uri !== $stored['redirect_uri'] ) {
			return $this->oauth_error( 'invalid_grant', 'client_id / redirect_uri mismatch.', 400 );
		}

		// PKCE S256: BASE64URL(SHA256(verifier)) must equal the stored challenge.
		$expected = self::base64url( hash( 'sha256', $code_verifier, true ) );
		if ( ! hash_equals( (string) $stored['code_challenge'], $expected ) ) {
			return $this->oauth_error( 'invalid_grant', 'PKCE verification failed.', 400 );
		}

		$grant = $this->tokens->issue_oauth_grant(
			$stored['client_id'],
			$stored['client_name'],
			(int) $stored['user_id'],
			$stored['scope']
		);

		return $this->token_response( $grant );
	}

	/**
	 * refresh_token grant (with rotation).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	private function token_refresh( \WP_REST_Request $request ) {
		$refresh = (string) $request->get_param( 'refresh_token' );
		if ( '' === $refresh ) {
			return $this->oauth_error( 'invalid_request', 'Missing refresh_token.', 400 );
		}

		$grant = $this->tokens->refresh_oauth_grant( $refresh );
		if ( null === $grant ) {
			return $this->oauth_error( 'invalid_grant', 'Refresh token is invalid or expired.', 400 );
		}

		return $this->token_response( $grant );
	}

	/**
	 * Successful token response envelope.
	 *
	 * @param array $grant Issued grant.
	 * @return \WP_REST_Response
	 */
	private function token_response( array $grant ) {
		$response = new \WP_REST_Response( [
			'access_token'  => $grant['access_token'],
			'token_type'    => 'Bearer',
			'expires_in'    => $grant['expires_in'],
			'refresh_token' => $grant['refresh_token'],
			'scope'         => $grant['scope'],
		], 200 );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * RFC 6749 error envelope.
	 *
	 * @param string $code    OAuth error code.
	 * @param string $message Human description.
	 * @param int    $status  HTTP status.
	 * @return \WP_REST_Response
	 */
	private function oauth_error( $code, $message, $status ) {
		$response = new \WP_REST_Response( [
			'error'             => $code,
			'error_description' => $message,
		], $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Per-IP throttle for the public /oauth/register endpoint.
	 *
	 * Increments a transient counter keyed by the requester IP and returns true
	 * once the configured limit is exceeded within the window.
	 *
	 * @return bool
	 */
	private function is_register_rate_limited() {
		$ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		if ( '' === $ip ) {
			$ip = 'unknown';
		}

		$key   = 'llmagnet_oauth_reg_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= self::REGISTER_RATE_LIMIT ) {
			return true;
		}

		set_transient( $key, $count + 1, self::REGISTER_RATE_WINDOW );
		return false;
	}

	// ── Authorization endpoint + consent page ────────────────────────────────────

	/**
	 * Handle the authorization endpoint when the activating query var is set.
	 *
	 * @return void
	 */
	public function maybe_handle_authorize() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check; the consent POST is nonce-verified below.
		if ( ! isset( $_GET[ self::AUTHORIZE_QUERY_VAR ] ) || 'authorize' !== $_GET[ self::AUTHORIZE_QUERY_VAR ] ) {
			return;
		}

		if ( ! self::is_enabled() ) {
			$this->render_error_page( __( 'Sign-in connections are disabled', 'llmagnet-llm-txt-generator' ), __( 'OAuth is turned off for this site. Enable it on the MCP & AI settings page.', 'llmagnet-llm-txt-generator' ) );
		}

		// Require a logged-in administrator: only they can authorize MCP access.
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->render_error_page(
				__( 'Insufficient permissions', 'llmagnet-llm-txt-generator' ),
				__( 'Only an administrator can authorize an AI assistant to connect to this site.', 'llmagnet-llm-txt-generator' )
			);
		}

		$req = $this->collect_authorize_params();

		// Validate the client + redirect_uri BEFORE we are willing to redirect
		// anywhere (RFC 6749 §3.1.2.4 / §4.1.2.1).
		$client = $this->find_client( $req['client_id'] );
		if ( null === $client || '' === $req['redirect_uri'] || ! in_array( $req['redirect_uri'], $client['redirect_uris'], true ) ) {
			$this->render_error_page(
				__( 'Invalid connection request', 'llmagnet-llm-txt-generator' ),
				__( 'The requesting application is not registered or its redirect URL does not match. Start the connection again from your AI assistant.', 'llmagnet-llm-txt-generator' )
			);
		}

		// From here, errors can be reported back to the client via redirect.
		if ( 'code' !== $req['response_type'] ) {
			$this->redirect_with_error( $req['redirect_uri'], 'unsupported_response_type', $req['state'] );
		}
		if ( '' === $req['code_challenge'] || 'S256' !== $req['code_challenge_method'] ) {
			$this->redirect_with_error( $req['redirect_uri'], 'invalid_request', $req['state'] );
		}

		// Handle the consent decision (POST), else render the consent page (GET).
		if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET' ) ) {
			$this->process_consent( $req, $client );
			exit;
		}

		$this->render_consent_page( $req, $client );
		exit;
	}

	/**
	 * Read and sanitize the authorization request parameters.
	 *
	 * Both GET (initial request) and POST (consent submit, carrying the same
	 * values as hidden fields) feed this. Everything is re-validated against the
	 * registered client regardless of where it came from.
	 *
	 * @return array
	 */
	private function collect_authorize_params() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- the consent action itself is nonce-protected in process_consent(); these reads are re-validated against stored client data.
		// On the consent POST the OAuth protocol params ride in the form action's
		// query string ($_GET) while the consent fields are in $_POST, so merge
		// both ($_POST wins on key collisions).
		$src = array_merge( $_GET, $_POST );

		$get = static function ( $key ) use ( $src ) {
			return isset( $src[ $key ] ) ? sanitize_text_field( wp_unslash( $src[ $key ] ) ) : '';
		};

		$scope = $get( 'scope' );

		return [
			'client_id'             => $get( 'client_id' ),
			'redirect_uri'          => esc_url_raw( $get( 'redirect_uri' ) ),
			'response_type'         => $get( 'response_type' ) ?: 'code',
			'state'                 => $get( 'state' ),
			'scope'                 => $scope,
			'code_challenge'        => $get( 'code_challenge' ),
			'code_challenge_method' => $get( 'code_challenge_method' ) ?: 'S256',
		];
		// phpcs:enable
	}

	/**
	 * Process the approve/deny consent submission.
	 *
	 * @param array $req    Validated authorization params.
	 * @param array $client Registered client.
	 * @return void
	 */
	private function process_consent( array $req, array $client ) {
		// CSRF: the consent form carries a nonce tied to this client.
		$nonce = isset( $_POST['llmagnet_oauth_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['llmagnet_oauth_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'llmagnet_oauth_consent_' . $req['client_id'] ) ) {
			$this->render_error_page(
				__( 'Session expired', 'llmagnet-llm-txt-generator' ),
				__( 'This authorization form expired. Please start the connection again from your AI assistant.', 'llmagnet-llm-txt-generator' )
			);
		}

		$decision = isset( $_POST['llmagnet_oauth_decision'] ) ? sanitize_text_field( wp_unslash( $_POST['llmagnet_oauth_decision'] ) ) : 'deny';
		if ( 'approve' !== $decision ) {
			$this->redirect_with_error( $req['redirect_uri'], 'access_denied', $req['state'] );
		}

		// Scope the user actually granted (chosen on the consent screen).
		$granted_scope = isset( $_POST['llmagnet_oauth_scope'] ) ? sanitize_text_field( wp_unslash( $_POST['llmagnet_oauth_scope'] ) ) : 'read';
		$granted_scope = ( 'write' === $granted_scope ) ? 'write' : 'read';

		$code = $this->issue_code( [
			'client_id'      => $req['client_id'],
			'client_name'    => $client['client_name'],
			'redirect_uri'   => $req['redirect_uri'],
			'scope'          => $granted_scope,
			'code_challenge' => $req['code_challenge'],
			'user_id'        => get_current_user_id(),
		] );

		$args = [ 'code' => $code ];
		if ( '' !== $req['state'] ) {
			$args['state'] = $req['state'];
		}
		wp_redirect( add_query_arg( array_map( 'rawurlencode', $args ), $req['redirect_uri'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- redirect_uri is validated against the registered client allowlist.
		exit;
	}

	/**
	 * Redirect back to the client with an OAuth error.
	 *
	 * @param string $redirect_uri Validated redirect URI.
	 * @param string $error        OAuth error code.
	 * @param string $state        Opaque client state.
	 * @return void
	 */
	private function redirect_with_error( $redirect_uri, $error, $state ) {
		$args = [ 'error' => $error ];
		if ( '' !== $state ) {
			$args['state'] = $state;
		}
		wp_redirect( add_query_arg( array_map( 'rawurlencode', $args ), $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- redirect_uri is validated against the registered client allowlist.
		exit;
	}

	// ── Authorization-code storage (single-use, short-lived) ─────────────────────

	/**
	 * Persist a fresh authorization code, returning the plaintext code.
	 *
	 * @param array $data Code payload.
	 * @return string
	 */
	private function issue_code( array $data ) {
		$code = bin2hex( random_bytes( 32 ) );
		set_transient( $this->code_key( $code ), $data, self::CODE_TTL );
		return $code;
	}

	/**
	 * Fetch and delete an authorization code (single use).
	 *
	 * @param string $code Plaintext code.
	 * @return array|null
	 */
	private function consume_code( $code ) {
		$key  = $this->code_key( $code );
		$data = get_transient( $key );
		if ( false === $data || ! is_array( $data ) ) {
			return null;
		}
		delete_transient( $key );
		return $data;
	}

	/**
	 * Transient key for an authorization code (the code itself is never stored).
	 *
	 * @param string $code Plaintext code.
	 * @return string
	 */
	private function code_key( $code ) {
		return 'llmagnet_oauth_code_' . hash( 'sha256', $code );
	}

	// ── Client storage ───────────────────────────────────────────────────────────

	/**
	 * Registered OAuth clients.
	 *
	 * @return array[]
	 */
	private function get_clients() {
		$clients = get_option( self::CLIENTS_OPTION, [] );
		return is_array( $clients ) ? array_values( $clients ) : [];
	}

	/**
	 * Find a registered client by id.
	 *
	 * @param string $client_id Client id.
	 * @return array|null
	 */
	private function find_client( $client_id ) {
		if ( '' === $client_id ) {
			return null;
		}
		foreach ( $this->get_clients() as $c ) {
			if ( isset( $c['client_id'] ) && hash_equals( (string) $c['client_id'], (string) $client_id ) ) {
				if ( empty( $c['redirect_uris'] ) || ! is_array( $c['redirect_uris'] ) ) {
					$c['redirect_uris'] = [];
				}
				return $c;
			}
		}
		return null;
	}

	/**
	 * Cap the number of retained clients, keeping the most recent.
	 *
	 * @param array[] $clients Clients.
	 * @return array[]
	 */
	private function prune_clients( $clients ) {
		if ( count( $clients ) > self::MAX_CLIENTS ) {
			$clients = array_slice( $clients, -self::MAX_CLIENTS );
		}
		return array_values( $clients );
	}

	/**
	 * Whether a redirect URI is acceptable: absolute https, or http on loopback.
	 *
	 * @param string $uri Candidate URI.
	 * @return bool
	 */
	private function is_valid_redirect_uri( $uri ) {
		$parts = wp_parse_url( $uri );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( false !== strpos( $uri, '#' ) ) {
			return false; // No fragments per RFC 6749 §3.1.2.
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'https' === $scheme ) {
			return true;
		}
		if ( 'http' === $scheme ) {
			$host = strtolower( $parts['host'] );
			return in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true );
		}
		return false;
	}

	// ── HTML pages (consent + error) ─────────────────────────────────────────────

	/**
	 * Render the branded consent page and exit.
	 *
	 * @param array $req    Validated authorization params.
	 * @param array $client Registered client.
	 * @return void
	 */
	private function render_consent_page( array $req, array $client ) {
		$user        = wp_get_current_user();
		$site_name   = get_bloginfo( 'name' );
		$client_name = $client['client_name'];
		$is_chatgpt  = ( false !== stripos( $client_name, 'chatgpt' ) || false !== stripos( $client_name, 'openai' ) );

		// The client may request a scope; default the selection to read (safer),
		// and only expose "write" as a choice when the request asked for it or
		// asked for nothing (so the admin can decide).
		$requested = preg_split( '/\s+/', trim( (string) $req['scope'] ) );
		$wants_write = in_array( 'write', (array) $requested, true );

		$logo_llm    = LLMAGNET_AISEO_PLUGIN_URL . 'assets/react-build/assets/llmmagnetlogo.svg';
		$logo_client = $is_chatgpt ? ( LLMAGNET_AISEO_PLUGIN_URL . 'assets/react-build/assets/chatgpt-icon-0ec18379.svg' ) : '';

		$action = esc_url( add_query_arg(
			array_map( 'rawurlencode', [
				'client_id'             => $req['client_id'],
				'redirect_uri'          => $req['redirect_uri'],
				'response_type'         => 'code',
				'state'                 => $req['state'],
				'scope'                 => $req['scope'],
				'code_challenge'        => $req['code_challenge'],
				'code_challenge_method' => 'S256',
			] ),
			self::authorize_url()
		) );

		$nonce = wp_create_nonce( 'llmagnet_oauth_consent_' . $req['client_id'] );

		$this->page_head( sprintf(
			/* translators: %s: site name. */
			__( 'Connect to %s', 'llmagnet-llm-txt-generator' ),
			$site_name
		) );
		?>
		<div class="card" role="main">
			<div class="logos">
				<span class="logo"><img src="<?php echo esc_url( $logo_llm ); ?>" alt="LLMagnet" /></span>
				<span class="link" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#9aa1b2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7"/><path d="M8 7h9v9"/></svg>
				</span>
				<span class="logo">
					<?php if ( '' !== $logo_client ) : ?>
						<img src="<?php echo esc_url( $logo_client ); ?>" alt="<?php echo esc_attr( $client_name ); ?>" />
					<?php else : ?>
						<span class="logo-fallback"><?php echo esc_html( strtoupper( substr( $client_name, 0, 1 ) ) ); ?></span>
					<?php endif; ?>
				</span>
			</div>

			<h1><?php echo esc_html( sprintf(
				/* translators: 1: AI client name, 2: site name. */
				__( '%1$s wants to connect to %2$s', 'llmagnet-llm-txt-generator' ),
				$client_name,
				$site_name
			) ); ?></h1>

			<p class="sub"><?php echo esc_html( sprintf(
				/* translators: %s: WordPress display name. */
				__( 'Signed in as %s', 'llmagnet-llm-txt-generator' ),
				$user->display_name
			) ); ?></p>

			<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped via esc_url above. ?>">
				<input type="hidden" name="llmagnet_oauth_nonce" value="<?php echo esc_attr( $nonce ); ?>" />

				<fieldset class="scopes">
					<legend><?php esc_html_e( 'This connection will be able to:', 'llmagnet-llm-txt-generator' ); ?></legend>

					<label class="scope">
						<input type="radio" name="llmagnet_oauth_scope" value="read" checked />
						<span>
							<strong><?php esc_html_e( 'Read only', 'llmagnet-llm-txt-generator' ); ?></strong>
							<small><?php esc_html_e( 'View your content, SEO and AI-visibility analytics. Recommended.', 'llmagnet-llm-txt-generator' ); ?></small>
						</span>
					</label>

					<label class="scope">
						<input type="radio" name="llmagnet_oauth_scope" value="write" <?php checked( $wants_write ); ?> />
						<span>
							<strong><?php esc_html_e( 'Read and write', 'llmagnet-llm-txt-generator' ); ?></strong>
							<small><?php esc_html_e( 'Also let it make changes (edit content and settings exposed via MCP).', 'llmagnet-llm-txt-generator' ); ?></small>
						</span>
					</label>
				</fieldset>

				<div class="actions">
					<button type="submit" name="llmagnet_oauth_decision" value="approve" class="btn primary"><?php esc_html_e( 'Allow connection', 'llmagnet-llm-txt-generator' ); ?></button>
					<button type="submit" name="llmagnet_oauth_decision" value="deny" class="btn ghost"><?php esc_html_e( 'Cancel', 'llmagnet-llm-txt-generator' ); ?></button>
				</div>
			</form>

			<p class="foot"><?php esc_html_e( 'You can revoke this connection anytime from the MCP & AI page in your dashboard.', 'llmagnet-llm-txt-generator' ); ?></p>
		</div>
		<?php
		$this->page_foot();
	}

	/**
	 * Render a standalone error page and exit.
	 *
	 * @param string $title   Heading.
	 * @param string $message Body.
	 * @return void
	 */
	private function render_error_page( $title, $message ) {
		$this->page_head( $title );
		?>
		<div class="card" role="main">
			<div class="logos">
				<span class="logo"><img src="<?php echo esc_url( LLMAGNET_AISEO_PLUGIN_URL . 'assets/react-build/assets/llmmagnetlogo.svg' ); ?>" alt="LLMagnet" /></span>
			</div>
			<h1><?php echo esc_html( $title ); ?></h1>
			<p class="sub"><?php echo esc_html( $message ); ?></p>
			<div class="actions">
				<a class="btn ghost" href="<?php echo esc_url( admin_url() ); ?>"><?php esc_html_e( 'Back to dashboard', 'llmagnet-llm-txt-generator' ); ?></a>
			</div>
		</div>
		<?php
		$this->page_foot();
		exit;
	}

	/**
	 * Shared page header + inline styles.
	 *
	 * @param string $title Document title.
	 * @return void
	 */
	private function page_head( $title ) {
		nocache_headers();
		status_header( 200 );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'X-Robots-Tag: noindex' );
		}
		$is_rtl = is_rtl();
		?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>"<?php echo $is_rtl ? ' dir="rtl"' : ''; ?>>
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex" />
<title><?php echo esc_html( $title ); ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;
background:linear-gradient(135deg,#eef1f7 0%,#e6ecf6 100%);color:#1c2024;
font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.card{background:#fff;border:1px solid #e6e8ee;border-radius:18px;padding:32px;max-width:440px;width:100%;
box-shadow:0 12px 40px rgba(16,24,40,.10)}
.logos{display:flex;align-items:center;justify-content:center;gap:14px;margin-bottom:22px}
.logo{width:56px;height:56px;border-radius:14px;background:#f4f5f8;border:1px solid #eceef3;
display:flex;align-items:center;justify-content:center;overflow:hidden}
.logo img{width:40px;height:40px;object-fit:contain;display:block}
.logo-fallback{font-size:22px;font-weight:800;color:#4f46e5}
.link{display:flex;align-items:center;justify-content:center}
h1{font-size:20px;line-height:1.3;font-weight:700;margin:0 0 6px;text-align:center}
.sub{font-size:13px;color:#6b7280;text-align:center;margin:0 0 22px}
.scopes{border:0;margin:0;padding:0}
.scopes legend{font-size:12px;font-weight:700;color:#8a90a2;text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px;padding:0}
.scope{display:flex;gap:12px;align-items:flex-start;border:1px solid #e6e8ee;border-radius:12px;padding:14px;margin-bottom:10px;cursor:pointer;transition:border-color .15s,background .15s}
.scope:has(input:checked){border-color:#4f46e5;background:#f5f4ff}
.scope input{margin-top:3px;flex:0 0 auto}
.scope strong{display:block;font-size:14px}
.scope small{display:block;color:#6b7280;font-size:12.5px;margin-top:2px}
.actions{display:flex;gap:10px;margin-top:20px}
.btn{appearance:none;cursor:pointer;border:0;border-radius:10px;font:600 14px/1 inherit;padding:12px 18px;flex:1;text-align:center;text-decoration:none;transition:background .15s,opacity .15s}
.btn.primary{background:#4f46e5;color:#fff}
.btn.primary:hover{background:#4338ca}
.btn.ghost{background:#f1f2f6;color:#3a4150}
.btn.ghost:hover{background:#e6e8ee}
.foot{font-size:12px;color:#9aa1b2;text-align:center;margin:18px 0 0}
</style>
</head>
<body>
		<?php
	}

	/**
	 * Shared page footer.
	 *
	 * @return void
	 */
	private function page_foot() {
		echo '</body></html>';
		exit;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────────

	/**
	 * URL-safe, unpadded base64 (RFC 7636 / RFC 4648 §5).
	 *
	 * @param string $bin Raw bytes.
	 * @return string
	 */
	private static function base64url( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}
}
