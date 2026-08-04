<?php
/**
 * Admin REST backend for the "MCP & AI" admin page (mcp-ai-spec §8, Phase E Lane E2).
 *
 * Namespace `llm-analytics/v1`, all routes `manage_options` + REST nonce
 * (standard cookie auth) — same as the other admin endpoints.
 *
 * Routes:
 * - GET    /mcp/status                — settings, endpoint URL, protocol versions,
 *                                       tools (with scope/plan/annotations/schema),
 *                                       abilities/adapter detection, legacy-token flag.
 * - GET    /mcp/settings              — current `llmagnet_mcp_settings`.
 * - POST   /mcp/settings              — update `enabled` / `access_mode`.
 * - GET    /mcp/tokens                — token list (never hashes or secrets).
 * - POST   /mcp/tokens                — create token; plaintext secret returned ONCE.
 * - DELETE /mcp/tokens/{id}           — revoke ('legacy' deletes the legacy CLI token).
 * - GET    /mcp/activity              — last N activity-log rows (default 50).
 * - POST   /mcp/self-test             — server-side loopback through the MCP handler:
 *                                       initialize + tools/list + one tools/call.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoints consumed by the MCP & AI React page.
 */
class MCP_Admin_REST {

	/** REST namespace shared with the other admin endpoints. */
	const REST_NAMESPACE = 'llm-analytics/v1';

	/** Default / maximum rows returned by the activity endpoint. */
	const ACTIVITY_DEFAULT_LIMIT = 50;

	/** Tool exercised by the self-test's tools/call step (always registered, read scope). */
	const SELF_TEST_TOOL = 'get_site_info';

	/**
	 * MCP transport (provides the shared tool registry and token manager).
	 *
	 * @var MCP
	 */
	private $mcp;

	/**
	 * Constructor.
	 *
	 * @param MCP|null $mcp MCP server instance. When null a standalone instance
	 *                      is built (all dependencies have no-arg constructors),
	 *                      but passing Main's shared instance is preferred.
	 */
	public function __construct( $mcp = null ) {
		$this->mcp = $mcp instanceof MCP ? $mcp : new MCP();
	}

	/**
	 * Hook route registration.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	/**
	 * Register all /mcp/* admin routes.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		$permission = function () {
			return current_user_can( 'manage_options' );
		};

		register_rest_route( self::REST_NAMESPACE, '/mcp/status', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_get_status' ],
			'permission_callback' => $permission,
		] );

		register_rest_route( self::REST_NAMESPACE, '/mcp/settings', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_get_settings' ],
				'permission_callback' => $permission,
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_update_settings' ],
				'permission_callback' => $permission,
				'args'                => [
					'enabled'     => [
						'required' => false,
						'type'     => 'boolean',
					],
					'access_mode'   => [
						'required' => false,
						'type'     => 'string',
						'enum'     => [ 'private', 'public_content', 'public_read' ],
					],
					'oauth_enabled'  => [
						'required' => false,
						'type'     => 'boolean',
					],
					'oauth_base_url' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					],
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/mcp/tokens', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_list_tokens' ],
				'permission_callback' => $permission,
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_create_token' ],
				'permission_callback' => $permission,
				'args'                => [
					'label' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'scope' => [
						'required' => false,
						'type'     => 'string',
						'enum'     => [ 'read', 'write' ],
						'default'  => 'read',
					],
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/mcp/tokens/(?P<id>[a-z0-9_]+)', [
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => [ $this, 'rest_revoke_token' ],
			'permission_callback' => $permission,
			'args'                => [
				'id' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/mcp/connections', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_list_connections' ],
			'permission_callback' => $permission,
		] );

		register_rest_route( self::REST_NAMESPACE, '/mcp/connections/(?P<id>[a-z0-9_]+)', [
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => [ $this, 'rest_revoke_connection' ],
			'permission_callback' => $permission,
			'args'                => [
				'id' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/mcp/activity', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_get_activity' ],
			'permission_callback' => $permission,
			'args'                => [
				'limit' => [
					'required'          => false,
					'type'              => 'integer',
					'default'           => self::ACTIVITY_DEFAULT_LIMIT,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/mcp/self-test', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rest_self_test' ],
			'permission_callback' => $permission,
		] );
	}

	// ── /mcp/status ───────────────────────────────────────────────────────────

	/**
	 * Full page bootstrap snapshot: settings + endpoint + tools + ecosystem.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_get_status() {
		$settings = MCP::get_settings();
		$registry = $this->mcp->get_tools_registry();
		$tokens   = $this->mcp->get_tokens_manager();

		$tools          = [];
		$count_read     = 0;
		$count_write    = 0;
		$count_public_c = 0;
		$count_public_r = 0;

		foreach ( $registry->get_definitions() as $id => $def ) {
			if ( ! $registry->is_available( $id ) ) {
				continue;
			}

			$scope           = isset( $def['scope'] ) ? $def['scope'] : 'read';
			$public_eligible = isset( $def['public_eligible'] ) ? $def['public_eligible'] : false;

			if ( 'write' === $scope ) {
				$count_write++;
			} else {
				$count_read++;
			}
			// Anonymous tool sets per access mode (mcp-ai-spec §A6).
			if ( 'content' === $public_eligible ) {
				$count_public_c++;
				$count_public_r++;
			} elseif ( 'read' === $public_eligible ) {
				$count_public_r++;
			}

			$tools[] = [
				'name'            => $id,
				'title'           => isset( $def['title'] ) ? $def['title'] : $id,
				'description'     => isset( $def['description'] ) ? $def['description'] : '',
				'scope'           => $scope,
				'plan'            => isset( $def['plan'] ) ? $def['plan'] : 'free',
				'public_eligible' => $public_eligible,
				'annotations'     => isset( $def['annotations'] ) ? $def['annotations'] : [],
				'input_schema'    => isset( $def['input_schema'] ) ? $def['input_schema'] : null,
			];
		}

		$abilities_available = function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
		$adapter_detected    = $this->is_mcp_adapter_active();

		return new \WP_REST_Response( [
			'settings'          => $settings,
			'endpoint'          => rest_url( 'llmagnet/mcp/v1' ),
			'pretty_permalinks' => (bool) get_option( 'permalink_structure' ),
			'protocol_versions' => MCP::PROTOCOL_VERSIONS,
			'plugin_version'    => LLMAGNET_AISEO_VERSION,
			'tools'             => $tools,
			'tool_counts'       => [
				'total'          => count( $tools ),
				'read'           => $count_read,
				'write'          => $count_write,
				'public_content' => $count_public_c,
				'public_read'    => $count_public_r,
			],
			'tokens_count'      => count( $tokens->list_tokens() ),
			'has_legacy_token'  => $tokens->has_legacy_token(),
			'oauth'             => [
				'enabled'             => ! empty( $settings['oauth_enabled'] ),
				'authorize_url'       => MCP_OAuth::authorize_url(),
				'metadata_url'        => MCP_OAuth::protected_resource_metadata_url(),
				'connections_count'   => count( $tokens->list_oauth_connections() ),
			],
			'abilities'         => [
				'available'  => $abilities_available,
				'wp_version' => get_bloginfo( 'version' ),
				// Abilities Phase 2 registers EVERY available registry tool,
				// read AND write (FC-2) — count without the scope filter.
				'count'      => $abilities_available ? count( $tools ) : 0,
			],
			'adapter'           => [
				'detected' => $adapter_detected,
				'endpoint' => $adapter_detected ? rest_url( 'mcp/mcp-adapter-default-server' ) : null,
			],
		], 200 );
	}

	/**
	 * Whether the official WordPress MCP Adapter plugin is active.
	 *
	 * @return bool
	 */
	private function is_mcp_adapter_active() {
		if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) || defined( 'MCP_ADAPTER_VERSION' ) ) {
			return true;
		}

		$active = (array) get_option( 'active_plugins', [] );
		foreach ( $active as $plugin ) {
			if ( is_string( $plugin ) && 0 === strpos( $plugin, 'mcp-adapter/' ) ) {
				return true;
			}
		}

		return false;
	}

	// ── /mcp/settings ─────────────────────────────────────────────────────────

	/**
	 * Current MCP settings (merged with defaults).
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_get_settings() {
		return new \WP_REST_Response( MCP::get_settings(), 200 );
	}

	/**
	 * Update MCP settings. Only whitelisted keys; partial updates allowed.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_update_settings( \WP_REST_Request $request ) {
		$settings = MCP::get_settings();

		if ( null !== $request->get_param( 'enabled' ) ) {
			$settings['enabled'] = rest_sanitize_boolean( $request->get_param( 'enabled' ) );
		}

		$access_mode = $request->get_param( 'access_mode' );
		if ( is_string( $access_mode ) && in_array( $access_mode, [ 'private', 'public_content', 'public_read' ], true ) ) {
			$settings['access_mode'] = $access_mode;
		}

		if ( null !== $request->get_param( 'oauth_enabled' ) ) {
			$settings['oauth_enabled'] = rest_sanitize_boolean( $request->get_param( 'oauth_enabled' ) );
		}

		$oauth_base = $request->get_param( 'oauth_base_url' );
		if ( null !== $oauth_base ) {
			$settings['oauth_base_url'] = esc_url_raw( (string) $oauth_base );
		}

		update_option( MCP::SETTINGS_OPTION, $settings, false );

		return new \WP_REST_Response( MCP::get_settings(), 200 );
	}

	// ── /mcp/tokens ───────────────────────────────────────────────────────────

	/**
	 * Token list (sans secrets/hashes) + legacy-token flag.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_list_tokens() {
		$tokens = $this->mcp->get_tokens_manager();

		return new \WP_REST_Response( [
			'tokens'           => $tokens->list_tokens(),
			'has_legacy_token' => $tokens->has_legacy_token(),
		], 200 );
	}

	/**
	 * Create a token. The plaintext secret appears in this response ONCE and
	 * is never stored or retrievable again.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_create_token( \WP_REST_Request $request ) {
		$result = $this->mcp->get_tokens_manager()->create(
			(string) $request->get_param( 'label' ),
			(string) $request->get_param( 'scope' ),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			$result->add_data( [ 'status' => 400 ] );
			return $result;
		}

		return new \WP_REST_Response( $result, 201 );
	}

	/**
	 * Revoke a managed token, or delete the legacy CLI token (id `legacy`).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_revoke_token( \WP_REST_Request $request ) {
		$id     = (string) $request->get_param( 'id' );
		$tokens = $this->mcp->get_tokens_manager();

		if ( 'legacy' === $id ) {
			if ( ! $tokens->has_legacy_token() ) {
				return new \WP_Error(
					'llmagnet_mcp_no_legacy_token',
					__( 'No legacy token exists.', 'llmagnet-llm-txt-generator' ),
					[ 'status' => 404 ]
				);
			}
			$tokens->delete_legacy_token();
			return new \WP_REST_Response( [ 'deleted' => true, 'id' => 'legacy' ], 200 );
		}

		if ( ! $tokens->revoke( $id ) ) {
			return new \WP_Error(
				'llmagnet_mcp_token_not_found',
				__( 'Token not found or already revoked.', 'llmagnet-llm-txt-generator' ),
				[ 'status' => 404 ]
			);
		}

		return new \WP_REST_Response( [ 'revoked' => true, 'id' => $id ], 200 );
	}

	// ── /mcp/connections (OAuth) ──────────────────────────────────────────────

	/**
	 * List active OAuth connections (apps a user authorized via the consent page).
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_list_connections() {
		$tokens = $this->mcp->get_tokens_manager();
		return new \WP_REST_Response( [
			'connections' => $tokens->list_oauth_connections(),
		], 200 );
	}

	/**
	 * Revoke an OAuth connection by grant id.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_revoke_connection( \WP_REST_Request $request ) {
		$id     = (string) $request->get_param( 'id' );
		$tokens = $this->mcp->get_tokens_manager();

		if ( ! $tokens->revoke_oauth_connection( $id ) ) {
			return new \WP_Error(
				'llmagnet_mcp_connection_not_found',
				__( 'Connection not found or already revoked.', 'llmagnet-llm-txt-generator' ),
				[ 'status' => 404 ]
			);
		}

		return new \WP_REST_Response( [ 'revoked' => true, 'id' => $id ], 200 );
	}

	// ── /mcp/activity ─────────────────────────────────────────────────────────

	/**
	 * Last N activity-log entries, newest first. The stored `ip_hash` is for
	 * server-side grouping only and is not exposed to the UI.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_get_activity( \WP_REST_Request $request ) {
		$limit = (int) $request->get_param( 'limit' );
		if ( $limit < 1 || $limit > MCP::ACTIVITY_MAX ) {
			$limit = self::ACTIVITY_DEFAULT_LIMIT;
		}

		$log = get_option( MCP::ACTIVITY_OPTION, [] );
		if ( ! is_array( $log ) ) {
			$log = [];
		}

		$entries = [];
		foreach ( array_slice( array_reverse( $log ), 0, $limit ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$entries[] = [
				'ts'   => isset( $row['ts'] ) ? (int) $row['ts'] : 0,
				'auth' => isset( $row['auth'] ) ? (string) $row['auth'] : '',
				'tool' => isset( $row['tool'] ) ? (string) $row['tool'] : '',
				'ok'   => ! empty( $row['ok'] ),
				'ms'   => isset( $row['ms'] ) ? (int) $row['ms'] : 0,
			];
		}

		return new \WP_REST_Response( [ 'entries' => $entries ], 200 );
	}

	// ── /mcp/self-test ────────────────────────────────────────────────────────

	/**
	 * Server-side loopback test: dispatch initialize + tools/list + one
	 * tools/call through the MCP handler and report pass/fail per step.
	 *
	 * The constructed requests carry the current admin's REST nonce, so they
	 * authenticate via the same cookie+nonce path an external same-origin
	 * client would (no Origin header = same-origin per A2).
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_self_test() {
		$steps = [];

		// Step 1 — initialize.
		$reply   = $this->loopback( [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => [
				'protocolVersion' => '2025-06-18',
				'capabilities'    => new \stdClass(),
				'clientInfo'      => [
					'name'    => 'LLMagnet Self-Test',
					'version' => LLMAGNET_AISEO_VERSION,
				],
			],
		] );
		$version = is_array( $reply ) && isset( $reply['result']['protocolVersion'] ) ? (string) $reply['result']['protocolVersion'] : '';
		$steps[] = $this->step_result(
			'initialize',
			__( 'Initialize handshake', 'llmagnet-llm-txt-generator' ),
			'' !== $version,
			'' !== $version
				/* translators: %s: negotiated MCP protocol version. */
				? sprintf( __( 'Negotiated protocol version %s.', 'llmagnet-llm-txt-generator' ), $version )
				: $this->error_message_from_reply( $reply )
		);

		// Step 2 — tools/list.
		$reply      = $this->loopback( [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/list',
		] );
		$tool_count = is_array( $reply ) && isset( $reply['result']['tools'] ) && is_array( $reply['result']['tools'] )
			? count( $reply['result']['tools'] )
			: -1;
		$steps[]    = $this->step_result(
			'tools_list',
			__( 'List tools', 'llmagnet-llm-txt-generator' ),
			$tool_count > 0,
			$tool_count > 0
				/* translators: %d: number of MCP tools. */
				? sprintf( __( '%d tools available.', 'llmagnet-llm-txt-generator' ), $tool_count )
				: ( 0 === $tool_count
					? __( 'tools/list returned an empty tool set.', 'llmagnet-llm-txt-generator' )
					: $this->error_message_from_reply( $reply ) )
		);

		// Step 3 — tools/call on an always-available read tool.
		$reply   = $this->loopback( [
			'jsonrpc' => '2.0',
			'id'      => 3,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => self::SELF_TEST_TOOL,
				'arguments' => new \stdClass(),
			],
		] );
		$call_ok = is_array( $reply )
			&& isset( $reply['result']['content'] )
			&& empty( $reply['result']['isError'] );
		$steps[] = $this->step_result(
			'tools_call',
			/* translators: %s: tool name. */
			sprintf( __( 'Call tool %s', 'llmagnet-llm-txt-generator' ), self::SELF_TEST_TOOL ),
			$call_ok,
			$call_ok
				? __( 'Tool executed successfully.', 'llmagnet-llm-txt-generator' )
				: $this->error_message_from_reply( $reply )
		);

		$all_ok = true;
		foreach ( $steps as $step ) {
			if ( ! $step['ok'] ) {
				$all_ok = false;
				break;
			}
		}

		return new \WP_REST_Response( [
			'ok'    => $all_ok,
			'steps' => $steps,
		], 200 );
	}

	/**
	 * Dispatch one JSON-RPC payload through the MCP handler as a same-origin,
	 * nonce-authenticated request, returning the decoded reply payload.
	 *
	 * @param array $payload JSON-RPC message.
	 * @return array|null Reply payload (jsonrpc envelope) or null when the
	 *                    transport returned a non-JSON-RPC response.
	 */
	private function loopback( array $payload ) {
		$request = new \WP_REST_Request( 'POST', '/llmagnet/mcp/v1' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body( (string) wp_json_encode( $payload ) );

		try {
			$response = $this->mcp->handle_request( $request );
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( ! $response instanceof \WP_REST_Response ) {
			return null;
		}

		$data = $response->get_data();
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Shape one self-test step result.
	 *
	 * @param string $id      Step id.
	 * @param string $label   Human label.
	 * @param bool   $ok      Pass/fail.
	 * @param string $message Detail message.
	 * @return array
	 */
	private function step_result( $id, $label, $ok, $message ) {
		return [
			'id'      => $id,
			'label'   => $label,
			'ok'      => (bool) $ok,
			'message' => (string) $message,
		];
	}

	/**
	 * Safe failure message from a JSON-RPC reply (or its absence).
	 *
	 * @param array|null $reply Decoded reply payload.
	 * @return string
	 */
	private function error_message_from_reply( $reply ) {
		if ( is_array( $reply ) && isset( $reply['error']['message'] ) && is_string( $reply['error']['message'] ) ) {
			return $reply['error']['message'];
		}
		if ( is_array( $reply ) && ! empty( $reply['result']['isError'] ) && isset( $reply['result']['content'][0]['text'] ) ) {
			return (string) $reply['result']['content'][0]['text'];
		}
		return __( 'No valid JSON-RPC response from the MCP handler.', 'llmagnet-llm-txt-generator' );
	}
}
