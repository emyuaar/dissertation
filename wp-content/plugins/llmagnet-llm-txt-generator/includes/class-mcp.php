<?php
/**
 * MCP (Model Context Protocol) server — transport layer.
 *
 * Streamable HTTP (stateless) JSON-RPC endpoint exposing the shared tool
 * registry ({@see MCP_Tools}) to AI assistants.
 *
 * Endpoint: POST /wp-json/llmagnet/mcp/v1
 * Auth:     Managed bearer tokens ({@see MCP_Tokens}), legacy WP-CLI token,
 *           Application Password, or cookie + X-WP-Nonce (same-origin only).
 *           Anonymous access only in the public access modes (mcp-ai-spec §A6).
 *
 * Hardening implemented here (mcp-ai-spec Workstream A):
 * - A1: protocol version negotiation (2024-11-05 / 2025-03-26 / 2025-06-18).
 * - A2: GET/DELETE → 405 + Allow: POST; -32700 on malformed JSON; batch
 *       requests per negotiated version; Origin validation (cookie auth is
 *       never accepted cross-origin).
 * - A3: WWW-Authenticate on 401; try/catch → -32603; tool failures returned
 *       as `isError: true` content blocks.
 * - A5: `llmagnet_mcp_settings.enabled` kill switch.
 * - A6: access modes private / public_content / public_read with anonymous
 *       per-IP rate limiting.
 * - §9: activity log ring buffer in `llmagnet_mcp_activity`.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\MCP_Tools' ) ) {
	require_once __DIR__ . '/class-mcp-tools.php';
}
if ( ! class_exists( __NAMESPACE__ . '\\MCP_Tokens' ) ) {
	require_once __DIR__ . '/class-mcp-tokens.php';
}
if ( ! class_exists( __NAMESPACE__ . '\\MCP_UI' ) ) {
	require_once __DIR__ . '/class-mcp-ui.php';
}

/**
 * MCP server transport: routing, auth, JSON-RPC dispatch.
 */
class MCP {

	/** Settings option (autoload off). */
	const SETTINGS_OPTION = 'llmagnet_mcp_settings';

	/** Activity log option (autoload off). */
	const ACTIVITY_OPTION = 'llmagnet_mcp_activity';

	/** Max entries kept in the activity ring buffer. */
	const ACTIVITY_MAX = 100;

	/** Supported protocol versions, oldest → newest. */
	const PROTOCOL_VERSIONS = [ '2024-11-05', '2025-03-26', '2025-06-18' ];

	/** Anonymous rate limit: calls per window. */
	const ANON_RATE_LIMIT = 60;

	/** Anonymous rate limit window (seconds). */
	const ANON_RATE_WINDOW = 600;

	/** @var MCP_Tools */
	private $tools;

	/** @var MCP_Tokens */
	private $tokens;

	/**
	 * Backward-compatible constructor: existing callers pass
	 * `( $analytics, $visibility_score )`; the registry and token manager are
	 * built internally when not injected.
	 *
	 * @param Analytics|null        $analytics        Analytics instance.
	 * @param Visibility_Score|null $visibility_score Visibility score instance.
	 * @param MCP_Tools|null        $tools            Shared tool registry.
	 * @param MCP_Tokens|null       $tokens           Token manager.
	 */
	public function __construct( $analytics = null, $visibility_score = null, $tools = null, $tokens = null ) {
		$this->tools  = $tools instanceof MCP_Tools ? $tools : new MCP_Tools( $analytics, $visibility_score );
		$this->tokens = $tokens instanceof MCP_Tokens ? $tokens : new MCP_Tokens();
	}

	/**
	 * The shared tool registry (for other consumers wired through Main).
	 *
	 * @return MCP_Tools
	 */
	public function get_tools_registry() {
		return $this->tools;
	}

	/**
	 * The token manager (for the admin REST backend, Workstream D).
	 *
	 * @return MCP_Tokens
	 */
	public function get_tokens_manager() {
		return $this->tokens;
	}

	/**
	 * MCP settings merged with defaults.
	 *
	 * @return array { enabled: bool, access_mode: string, bridge_abilities: bool }
	 */
	public static function get_settings() {
		$defaults = [
			// Opt-in: the MCP endpoint stays disabled until an admin explicitly
			// enables it. Sites upgrading without saved MCP settings get no new
			// attack surface. Sites that previously saved `enabled => true` keep
			// their value via the array_merge below.
			'enabled'          => false,
			'access_mode'      => 'private',
			'bridge_abilities' => false,
			// On-plugin OAuth 2.1 authorization server (enables private ChatGPT /
			// strict-client connections via {@see MCP_OAuth}). Opt-in by default.
			'oauth_enabled'    => false,
			// Public base URL override (scheme://host) for OAuth discovery. Used
			// when the site is reached through a tunnel / reverse proxy whose
			// host differs from home_url() (e.g. ngrok during local testing).
			// Empty = derive from the request / home_url automatically.
			'oauth_base_url'   => '',
		];
		$stored = get_option( self::SETTINGS_OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		$settings = array_merge( $defaults, $stored );

		if ( ! in_array( $settings['access_mode'], [ 'private', 'public_content', 'public_read' ], true ) ) {
			$settings['access_mode'] = 'private';
		}

		return $settings;
	}

	public function init() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		// Core validates JSON bodies before our callback runs; convert its
		// rest_invalid_json error into a JSON-RPC -32700 parse error (A2).
		add_filter( 'rest_request_before_callbacks', [ $this, 'intercept_invalid_json' ], 10, 3 );
		// Core rebuilds the Allow header from all registered route methods;
		// force `Allow: POST` on our 405s (A2).
		add_filter( 'rest_post_dispatch', [ $this, 'fix_allow_header' ], 20, 3 );
	}

	public function register_routes() {
		// GET/DELETE are registered so we can answer 405 + Allow: POST ourselves
		// (Streamable HTTP clients probe them). Auth happens inside the handler:
		// we need custom 401/429 headers and anonymous access in public modes.
		register_rest_route( 'llmagnet/mcp', '/v1', [
			'methods'             => [ 'POST', 'GET', 'DELETE' ],
			'callback'            => [ $this, 'handle_request' ],
			'permission_callback' => '__return_true',
		] );
	}

	/**
	 * Whether a REST request targets the MCP endpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private function is_mcp_route( \WP_REST_Request $request ) {
		return '/llmagnet/mcp/v1' === $request->get_route();
	}

	/**
	 * Replace core's `rest_invalid_json` error with a JSON-RPC -32700 parse error.
	 *
	 * @param mixed            $response Current response (null unless short-circuited).
	 * @param array            $handler  Route handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function intercept_invalid_json( $response, $handler, $request ) {
		if ( $request instanceof \WP_REST_Request
			&& $this->is_mcp_route( $request )
			&& is_wp_error( $response )
			&& $response->get_error_code() === 'rest_invalid_json' ) {
			return $this->jsonrpc_error( null, -32700, 'Parse error: request body is not valid JSON' );
		}
		return $response;
	}

	/**
	 * Force `Allow: POST` on the MCP endpoint's 405 responses.
	 *
	 * @param \WP_HTTP_Response $response Response.
	 * @param \WP_REST_Server   $server   Server.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_HTTP_Response
	 */
	public function fix_allow_header( $response, $server, $request ) {
		if ( $request instanceof \WP_REST_Request
			&& $this->is_mcp_route( $request )
			&& $response instanceof \WP_HTTP_Response
			&& 405 === $response->get_status() ) {
			$response->header( 'Allow', 'POST' );
		}
		return $response;
	}

	// ── Request entry point ───────────────────────────────────────────────────

	/**
	 * Handle an HTTP request to the MCP endpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_request( \WP_REST_Request $request ) {
		$settings = self::get_settings();

		// A5 — kill switch: predictable JSON-RPC error, HTTP 403.
		if ( empty( $settings['enabled'] ) ) {
			return $this->jsonrpc_error( null, -32000, 'MCP server disabled', 403 );
		}

		// A2 — stateless server: only POST carries JSON-RPC.
		if ( 'POST' !== $request->get_method() ) {
			$response = new \WP_REST_Response(
				[ 'error' => 'Method Not Allowed. This MCP server is stateless; send JSON-RPC over POST.' ],
				405
			);
			$response->header( 'Allow', 'POST' );
			return $response;
		}

		// A2 — parse the raw body ourselves so malformed JSON yields -32700.
		$raw  = $request->get_body();
		$body = json_decode( $raw, true );
		if ( null === $body && JSON_ERROR_NONE !== json_last_error() ) {
			return $this->jsonrpc_error( null, -32700, 'Parse error: request body is not valid JSON' );
		}
		if ( ! is_array( $body ) ) {
			return $this->jsonrpc_error( null, -32700, 'Parse error: request body must be a JSON object or array' );
		}

		// A1/A2 — protocol version for this request (header on subsequent
		// requests; initialize negotiates from params).
		$protocol_version = $this->request_protocol_version( $request, $body );

		$is_batch = $this->is_batch( $body );

		// A2 — batching: allowed in 2025-03-26, removed in 2025-06-18.
		if ( $is_batch && '2025-06-18' === $protocol_version ) {
			return $this->jsonrpc_error( null, -32600, 'Batch requests are not supported by protocol version 2025-06-18' );
		}
		if ( $is_batch && empty( $body ) ) {
			return $this->jsonrpc_error( null, -32600, 'Invalid Request: empty batch' );
		}

		// Auth (A3/A6/B3) — resolve the execution context or fail with the
		// proper status + headers.
		$auth = $this->resolve_auth_context( $request, $settings );
		if ( $auth instanceof \WP_REST_Response ) {
			return $auth;
		}

		if ( ! $is_batch ) {
			$reply = $this->dispatch_message( $body, $auth );
			return $this->jsonrpc_response( $reply );
		}

		$replies = [];
		foreach ( $body as $message ) {
			if ( ! is_array( $message ) ) {
				$replies[] = $this->error_payload( null, -32600, 'Invalid Request' );
				continue;
			}
			$reply = $this->dispatch_message( $message, $auth );
			if ( null !== $reply ) {
				$replies[] = $reply;
			}
		}

		return new \WP_REST_Response( $replies, 200 );
	}

	// ── Auth ──────────────────────────────────────────────────────────────────

	/**
	 * Resolve the execution context for this request, or return an HTTP error
	 * response (401 + WWW-Authenticate, 429 + Retry-After).
	 *
	 * @param \WP_REST_Request $request  Request.
	 * @param array            $settings MCP settings.
	 * @return array|\WP_REST_Response Execution context (see MCP_Tools docblock) or error response.
	 */
	private function resolve_auth_context( \WP_REST_Request $request, array $settings ) {
		$ip           = $this->client_ip();
		$cross_origin = $this->is_cross_origin( $request );

		// 1) Bearer token (managed or legacy).
		$auth_header = $request->get_header( 'authorization' );
		if ( is_string( $auth_header ) && preg_match( '/^Bearer\s+(.+)$/i', $auth_header, $m ) ) {
			// B3 — brute-force gate before any verification work.
			if ( $this->tokens->is_blocked( $ip ) ) {
				return $this->too_many_requests( $this->tokens->get_retry_after() );
			}

			$verified = $this->tokens->verify( trim( $m[1] ) );
			if ( null === $verified ) {
				$this->tokens->record_failure( $ip );
				$this->log_activity( 'auth_failed', '', false, 0, $ip );
				return $this->unauthorized( 'Invalid bearer token.' );
			}

			return [
				'auth'        => $verified['type'],
				'scope'       => $verified['scope'],
				'access_mode' => $settings['access_mode'],
				'user_id'     => 0,
				'token_id'    => $verified['id'],
				'label'       => $verified['label'],
				'ip'          => $ip,
			];
		}

		// 2) Application Password (WP core authenticated the user already).
		if ( is_user_logged_in()
			&& current_user_can( 'manage_options' )
			&& function_exists( 'wp_is_application_password_authenticated' )
			&& wp_is_application_password_authenticated() ) {
			$user = wp_get_current_user();
			return [
				'auth'        => 'app_password',
				'scope'       => 'write',
				'access_mode' => $settings['access_mode'],
				'user_id'     => (int) $user->ID,
				'token_id'    => '',
				'label'       => $user->user_login,
				'ip'          => $ip,
			];
		}

		// 3) Cookie session + REST nonce — same-origin only (DNS-rebinding /
		// CSRF defense, mcp-ai-spec §A2/§10.5).
		if ( ! $cross_origin && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( is_string( $nonce ) && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				$user = wp_get_current_user();
				return [
					'auth'        => 'session',
					'scope'       => 'write',
					'access_mode' => $settings['access_mode'],
					'user_id'     => (int) $user->ID,
					'token_id'    => '',
					'label'       => $user->user_login,
					'ip'          => $ip,
				];
			}
		}

		// 4) Anonymous — only in public access modes, rate limited per IP.
		if ( in_array( $settings['access_mode'], [ 'public_content', 'public_read' ], true ) ) {
			$limited = $this->check_anonymous_rate_limit( $ip );
			if ( $limited instanceof \WP_REST_Response ) {
				return $limited;
			}
			return [
				'auth'        => 'anonymous',
				'scope'       => 'none',
				'access_mode' => $settings['access_mode'],
				'user_id'     => 0,
				'token_id'    => '',
				'label'       => 'anonymous',
				'ip'          => $ip,
			];
		}

		return $this->unauthorized( 'Authentication required.' );
	}

	/**
	 * 401 response with WWW-Authenticate (mcp-ai-spec §A3).
	 *
	 * @param string $message Safe message.
	 * @return \WP_REST_Response
	 */
	private function unauthorized( $message ) {
		$response = new \WP_REST_Response(
			[
				'jsonrpc' => '2.0',
				'id'      => null,
				'error'   => [
					'code'    => -32001,
					'message' => $message,
				],
			],
			401
		);

		// Advertise the OAuth protected-resource metadata so MCP clients
		// (ChatGPT, Claude, …) can discover the authorization server and run
		// the OAuth 2.1 flow instead of needing a custom Bearer header.
		$challenge = 'Bearer realm="LLMagnet MCP"';
		if ( class_exists( __NAMESPACE__ . '\\MCP_OAuth' ) && MCP_OAuth::is_enabled() ) {
			$challenge .= ', resource_metadata="' . esc_url_raw( MCP_OAuth::protected_resource_metadata_url() ) . '"';
		}
		$response->header( 'WWW-Authenticate', $challenge );
		return $response;
	}

	/**
	 * 429 response with Retry-After.
	 *
	 * @param int $retry_after Seconds.
	 * @return \WP_REST_Response
	 */
	private function too_many_requests( $retry_after ) {
		$response = new \WP_REST_Response(
			[
				'jsonrpc' => '2.0',
				'id'      => null,
				'error'   => [
					'code'    => -32002,
					'message' => 'Too many requests. Try again later.',
				],
			],
			429
		);
		$response->header( 'Retry-After', (string) max( 1, (int) $retry_after ) );
		return $response;
	}

	/**
	 * Anonymous per-IP rate limiting (mcp-ai-spec §A6).
	 *
	 * @param string $ip Client IP.
	 * @return true|\WP_REST_Response True when allowed, 429 response when exceeded.
	 */
	private function check_anonymous_rate_limit( $ip ) {
		$key   = 'llmagnet_mcp_anon_' . md5( (string) $ip );
		$count = (int) get_transient( $key );

		/**
		 * Filter the anonymous MCP rate limit (calls per 10 minutes per IP).
		 *
		 * @param int $limit Default 60.
		 */
		$limit = (int) apply_filters( 'llmagnet_mcp_anonymous_rate_limit', self::ANON_RATE_LIMIT );

		if ( $count >= $limit ) {
			return $this->too_many_requests( self::ANON_RATE_WINDOW );
		}

		if ( 0 === $count ) {
			set_transient( $key, 1, self::ANON_RATE_WINDOW );
		} else {
			set_transient( $key, $count + 1, self::ANON_RATE_WINDOW );
		}

		return true;
	}

	/**
	 * Whether the request carries an Origin from another site.
	 *
	 * No Origin header (CLI clients, server-to-server) counts as same-origin —
	 * the defense targets browser-based DNS-rebinding, which always sends Origin.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private function is_cross_origin( \WP_REST_Request $request ) {
		$origin = $request->get_header( 'origin' );
		if ( ! is_string( $origin ) || '' === $origin ) {
			return false;
		}

		$origin_parts = wp_parse_url( $origin );
		if ( empty( $origin_parts['host'] ) ) {
			return true;
		}

		foreach ( [ home_url(), site_url() ] as $self_url ) {
			$self = wp_parse_url( $self_url );
			if ( empty( $self['host'] ) ) {
				continue;
			}
			$origin_port = isset( $origin_parts['port'] ) ? (int) $origin_parts['port'] : $this->default_port( isset( $origin_parts['scheme'] ) ? $origin_parts['scheme'] : 'http' );
			$self_port   = isset( $self['port'] ) ? (int) $self['port'] : $this->default_port( isset( $self['scheme'] ) ? $self['scheme'] : 'http' );

			if ( strtolower( $origin_parts['host'] ) === strtolower( $self['host'] )
				&& $origin_port === $self_port
				&& ( ! isset( $origin_parts['scheme'], $self['scheme'] ) || strtolower( $origin_parts['scheme'] ) === strtolower( $self['scheme'] ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Default port for a scheme.
	 *
	 * @param string $scheme URL scheme.
	 * @return int
	 */
	private function default_port( $scheme ) {
		return ( 'https' === strtolower( (string) $scheme ) ) ? 443 : 80;
	}

	/**
	 * Best-effort client IP.
	 *
	 * @return string
	 */
	private function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return is_string( $ip ) ? $ip : '';
	}

	// ── Protocol version negotiation (A1) ─────────────────────────────────────

	/**
	 * Protocol version governing this request.
	 *
	 * Order: `MCP-Protocol-Version` header if supported → initialize params if
	 * supported → 2025-03-26 (pre-header-era default, keeps batching working
	 * for clients that negotiated it).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param array            $body    Decoded body.
	 * @return string
	 */
	private function request_protocol_version( \WP_REST_Request $request, array $body ) {
		$header = $request->get_header( 'mcp-protocol-version' );
		if ( is_string( $header ) && in_array( $header, self::PROTOCOL_VERSIONS, true ) ) {
			return $header;
		}

		if ( ! $this->is_batch( $body )
			&& isset( $body['method'], $body['params']['protocolVersion'] )
			&& 'initialize' === $body['method'] ) {
			$requested = (string) $body['params']['protocolVersion'];
			if ( in_array( $requested, self::PROTOCOL_VERSIONS, true ) ) {
				return $requested;
			}
		}

		return '2025-03-26';
	}

	/**
	 * Version returned by `initialize`: echo the client's requested version if
	 * supported, else the newest we support.
	 *
	 * @param array $params Initialize params.
	 * @return string
	 */
	private function negotiate_initialize_version( $params ) {
		$requested = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		if ( in_array( $requested, self::PROTOCOL_VERSIONS, true ) ) {
			return $requested;
		}
		return self::PROTOCOL_VERSIONS[ count( self::PROTOCOL_VERSIONS ) - 1 ];
	}

	/**
	 * Whether a decoded body is a JSON-RPC batch (list, not object).
	 *
	 * @param array $body Decoded body.
	 * @return bool
	 */
	private function is_batch( array $body ) {
		if ( [] === $body ) {
			return true;
		}
		return array_keys( $body ) === range( 0, count( $body ) - 1 );
	}

	// ── JSON-RPC dispatch ─────────────────────────────────────────────────────

	/**
	 * Dispatch one JSON-RPC message to a reply payload.
	 *
	 * @param array $message JSON-RPC message.
	 * @param array $context Execution context.
	 * @return array|null Reply payload, or null for notifications.
	 */
	private function dispatch_message( array $message, array $context ) {
		$method = isset( $message['method'] ) && is_string( $message['method'] ) ? $message['method'] : '';
		$id     = isset( $message['id'] ) ? $message['id'] : null;
		$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : [];

		switch ( $method ) {
			case 'initialize':
				return $this->success_payload( $id, [
					'protocolVersion' => $this->negotiate_initialize_version( $params ),
					'capabilities'    => [
						'tools'     => new \stdClass(),
						// MCP Apps: UI templates are served as read-only resources.
						'resources' => new \stdClass(),
					],
					'serverInfo'      => [
						'name'       => 'LLMagnet MCP',
						'title'      => 'LLMagnet — AI SEO for WordPress',
						'version'    => LLMAGNET_AISEO_VERSION,
						// SEP-973: branding metadata. Supporting hosts (Claude,
						// ChatGPT, …) show this logo/link instead of a generic
						// tile; others ignore the extra fields.
						'websiteUrl' => 'https://llmagnet.com',
						'icons'      => [
							[
								'src'      => 'https://llmagnet.com/wp-content/uploads/2026/01/log.png',
								'mimeType' => 'image/png',
								'sizes'    => [ 'any' ],
							],
						],
					],
				] );

			case 'notifications/initialized':
				// Notification — no id, no reply.
				return null !== $id ? $this->success_payload( $id, new \stdClass() ) : null;

			case 'ping':
				return $this->success_payload( $id, new \stdClass() );

			case 'tools/list':
				return $this->success_payload( $id, [ 'tools' => $this->decorate_tools_with_ui( $this->tools->list_tools( $context ) ) ] );

			case 'tools/call':
				return $this->handle_tool_call( $id, $params, $context );

			case 'resources/list':
				return $this->success_payload( $id, [ 'resources' => MCP_UI::list_resources() ] );

			case 'resources/templates/list':
				return $this->success_payload( $id, [ 'resourceTemplates' => [] ] );

			case 'resources/read':
				$uri      = isset( $params['uri'] ) && is_string( $params['uri'] ) ? $params['uri'] : '';
				$resource = MCP_UI::read_resource( $uri );
				if ( null === $resource ) {
					return $this->error_payload( $id, -32602, 'Resource not found: ' . $uri );
				}
				return $this->success_payload( $id, $resource );

			case '':
				return $this->error_payload( $id, -32600, 'Invalid Request: missing method' );

			default:
				return $this->error_payload( $id, -32601, 'Method not found: ' . $method );
		}
	}

	/**
	 * Attach MCP Apps `_meta.ui` (and ChatGPT aliases) to each tool descriptor
	 * that has a UI template, so hosts can preload the template before the call.
	 *
	 * @param array[] $tools Tool descriptors from {@see MCP_Tools::list_tools()}.
	 * @return array[]
	 */
	private function decorate_tools_with_ui( array $tools ) {
		foreach ( $tools as &$tool ) {
			if ( ! isset( $tool['name'] ) ) {
				continue;
			}
			$meta = MCP_UI::tool_meta( $tool['name'] );
			if ( is_array( $meta ) ) {
				$tool['_meta'] = isset( $tool['_meta'] ) && is_array( $tool['_meta'] )
					? array_merge( $tool['_meta'], $meta )
					: $meta;
			}
		}
		unset( $tool );
		return $tools;
	}

	/**
	 * tools/call handler (A3 error hygiene).
	 *
	 * @param mixed $id      JSON-RPC id.
	 * @param array $params  Call params.
	 * @param array $context Execution context.
	 * @return array Reply payload.
	 */
	private function handle_tool_call( $id, array $params, array $context ) {
		$name      = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';
		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : [];

		$started = microtime( true );

		$can = $this->tools->can_execute( $name, $context );
		if ( is_wp_error( $can ) ) {
			// Access problems are protocol errors, not tool results.
			$this->log_activity( $context['label'], $name, false, $this->elapsed_ms( $started ), $context['ip'] );
			return $this->error_payload( $id, -32602, $can->get_error_message() );
		}

		try {
			$result = $this->tools->execute( $name, $arguments, $context );
		} catch ( \Throwable $e ) {
			// A3 — internal errors: -32603 with a safe message, never traces/paths.
			$this->log_activity( $context['label'], $name, false, $this->elapsed_ms( $started ), $context['ip'] );
			return $this->error_payload( $id, -32603, 'Internal error while executing tool: ' . $name );
		}

		$ms = $this->elapsed_ms( $started );

		// A3 — tool-level failures: result with isError, not a protocol error.
		if ( is_wp_error( $result ) ) {
			$this->log_activity( $context['label'], $name, false, $ms, $context['ip'] );
			return $this->success_payload( $id, [
				'content' => [ [
					'type' => 'text',
					'text' => $result->get_error_message(),
				] ],
				'isError' => true,
			] );
		}

		$this->log_activity( $context['label'], $name, true, $ms, $context['ip'] );

		$payload = [
			'content' => [ [
				'type' => 'text',
				'text' => wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ),
			] ],
		];

		// Tools advertise an outputSchema, so the MCP spec requires the result to
		// carry a structuredContent object. Without it, strict clients reject the
		// reply with -32600 ("did not return structured content").
		if ( is_array( $result ) ) {
			$payload['structuredContent'] = $result;
		}

		// MCP Apps: link the result to its UI template so supporting hosts
		// (Claude, ChatGPT, Goose, …) render the card/chart from structuredContent.
		// Non-UI hosts ignore _meta and use the text/structured payload above.
		$ui_meta = MCP_UI::tool_meta( $name );
		if ( is_array( $ui_meta ) ) {
			$payload['_meta'] = $ui_meta;
		}

		return $this->success_payload( $id, $payload );
	}

	// ── Activity log (§9) ─────────────────────────────────────────────────────

	/**
	 * Append an entry to the activity ring buffer.
	 *
	 * IP is stored as a salted hash — "same client" grouping without PII.
	 *
	 * @param string $actor Auth label ('anonymous', token label, user login, 'auth_failed').
	 * @param string $tool  Tool name ('' for non-tool events).
	 * @param bool   $ok    Success flag.
	 * @param int    $ms    Duration in milliseconds.
	 * @param string $ip    Client IP (hashed before storage).
	 * @return void
	 */
	private function log_activity( $actor, $tool, $ok, $ms, $ip ) {
		$log = get_option( self::ACTIVITY_OPTION, [] );
		if ( ! is_array( $log ) ) {
			$log = [];
		}

		$log[] = [
			'ts'      => time(),
			'auth'    => (string) $actor,
			'tool'    => (string) $tool,
			'ok'      => (bool) $ok,
			'ms'      => (int) $ms,
			'ip_hash' => hash( 'sha256', (string) $ip . wp_salt() ),
		];

		if ( count( $log ) > self::ACTIVITY_MAX ) {
			$log = array_slice( $log, -self::ACTIVITY_MAX );
		}

		update_option( self::ACTIVITY_OPTION, $log, false );
	}

	/**
	 * Milliseconds elapsed since a microtime(true) mark.
	 *
	 * @param float $started Start mark.
	 * @return int
	 */
	private function elapsed_ms( $started ) {
		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}

	// ── JSON-RPC payload helpers ──────────────────────────────────────────────

	/**
	 * Success payload.
	 *
	 * @param mixed $id     JSON-RPC id.
	 * @param mixed $result Result.
	 * @return array
	 */
	private function success_payload( $id, $result ) {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		];
	}

	/**
	 * Error payload.
	 *
	 * @param mixed  $id      JSON-RPC id.
	 * @param int    $code    JSON-RPC error code.
	 * @param string $message Safe message.
	 * @return array
	 */
	private function error_payload( $id, $code, $message ) {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => [
				'code'    => $code,
				'message' => $message,
			],
		];
	}

	/**
	 * Wrap a single reply payload in a REST response.
	 *
	 * JSON-RPC errors still ride on HTTP 200 (existing convention; transport
	 * succeeded, the RPC failed).
	 *
	 * @param array|null $payload Reply payload (null = notification, empty 202-style body).
	 * @return \WP_REST_Response
	 */
	private function jsonrpc_response( $payload ) {
		if ( null === $payload ) {
			return new \WP_REST_Response( null, 202 );
		}
		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * Standalone JSON-RPC error response with explicit HTTP status.
	 *
	 * @param mixed  $id      JSON-RPC id.
	 * @param int    $code    JSON-RPC error code.
	 * @param string $message Safe message.
	 * @param int    $status  HTTP status (JSON-RPC errors default to 200).
	 * @return \WP_REST_Response
	 */
	private function jsonrpc_error( $id, $code, $message, $status = 200 ) {
		return new \WP_REST_Response( $this->error_payload( $id, $code, $message ), $status );
	}
}
