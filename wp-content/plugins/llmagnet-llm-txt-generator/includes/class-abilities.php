<?php
/**
 * WordPress Abilities API integration (abilities-api-plan §3, Phase D Lane D2).
 *
 * Registers LLMagnet's MCP tools as WordPress abilities on WP 6.9+.
 *
 * IMPORTANT (master plan dependency D1): ability registrations are GENERATED
 * from the shared tool registry (`class-mcp-tools.php`) — the single source of
 * truth for tool ids, descriptions, schemas, and annotations. Do not hand-write
 * ability registrations here; add/adjust tools in the registry (or via the
 * `llmagnet_mcp_tools` filter) and they flow through automatically.
 *
 * Name mapping: registry snake_case id -> `llmagnet/kebab-case` ability name
 * (e.g. `get_site_info` -> `llmagnet/get-site-info`).
 *
 * Scope (Phase 2, FC-2): every available registry tool is registered — read
 * AND write. Permission model:
 * - All abilities require a logged-in WP user (core REST auth happens first).
 * - Read abilities: `manage_options` (or `edit_post` for per-post tools).
 * - Write abilities: `manage_options` only; the registry execution context
 *   carries scope 'write' ONLY for `manage_options` users, so the registry's
 *   own scope gate independently rejects anyone else (defense in depth).
 * - Write abilities set `meta.mcp.public => false` (abilities-api-plan §6:
 *   action abilities need an individual review before MCP Adapter exposure)
 *   and honest annotations (`readonly => false`), which makes core enforce
 *   POST-method run semantics on the REST run route.
 *
 * On WordPress < 6.9 (no Abilities API) this class is a clean no-op.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates WordPress Abilities API registrations from the MCP tool registry.
 */
class Abilities {

	/**
	 * Ability namespace (also the ability category slug).
	 */
	const ABILITY_NAMESPACE = 'llmagnet';

	/**
	 * Shared tool registry.
	 *
	 * @var MCP_Tools|null
	 */
	private $registry;

	/**
	 * Tools that accept an alternative per-post capability check:
	 * a user who can edit the targeted post may run them without
	 * `manage_options` (abilities-api-plan §2, get-page-visibility-score row).
	 *
	 * @var string[]
	 */
	private $per_post_tools = [ 'get_page_visibility' ];

	/**
	 * Constructor.
	 *
	 * @param MCP_Tools|null $registry Shared tool registry. When null, a
	 *                                 standalone instance is lazily built
	 *                                 (all registry deps have no-arg constructors).
	 */
	public function __construct( $registry = null ) {
		$this->registry = $registry;
	}

	/**
	 * Hook into the Abilities API. Clean no-op on WP < 6.9.
	 *
	 * @return void
	 */
	public function init() {
		// Abilities API ships in WP >= 6.9 only.
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_categories' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * Register the `llmagnet` ability category.
	 *
	 * Runs on `wp_abilities_api_categories_init` (registering elsewhere
	 * triggers `_doing_it_wrong`).
	 *
	 * @return void
	 */
	public function register_categories() {
		wp_register_ability_category(
			self::ABILITY_NAMESPACE,
			[
				'label'       => __( 'LLMagnet AI SEO', 'llmagnet-llm-txt-generator' ),
				'description' => __( 'AI visibility, LLM bot analytics, and llms.txt management for this site.', 'llmagnet-llm-txt-generator' ),
			]
		);
	}

	/**
	 * Register every available registry tool as an ability (read AND write —
	 * FC-2; write tools get `meta.mcp.public => false` and POST run semantics).
	 *
	 * Runs on `wp_abilities_api_init`.
	 *
	 * @return void
	 */
	public function register_abilities() {
		$registry = $this->registry();

		foreach ( $registry->get_definitions() as $id => $def ) {
			// Only known scopes flow through (a malformed filter addition
			// without a scope must not silently become a write ability).
			if ( ! isset( $def['scope'] ) || ! in_array( $def['scope'], [ 'read', 'write' ], true ) ) {
				continue;
			}

			// Skip tools not available on this site (e.g. Woo-only tools).
			if ( ! $registry->is_available( $id ) ) {
				continue;
			}

			wp_register_ability( $this->ability_name( $id ), $this->build_ability_args( $id, $def ) );
		}
	}

	/**
	 * Map a registry tool id to its ability name.
	 *
	 * @param string $id Registry tool id (snake_case).
	 * @return string Ability name, e.g. `llmagnet/get-site-info`.
	 */
	public function ability_name( $id ) {
		return self::ABILITY_NAMESPACE . '/' . str_replace( '_', '-', $id );
	}

	// ── Registration internals ────────────────────────────────────────────────

	/**
	 * Build the `wp_register_ability()` args for one registry definition.
	 *
	 * @param string $id  Registry tool id.
	 * @param array  $def Registry tool definition.
	 * @return array
	 */
	private function build_ability_args( $id, array $def ) {
		$input_schema = $this->normalize_schema( isset( $def['input_schema'] ) ? $def['input_schema'] : [] );

		/*
		 * Readonly abilities run via GET with no body; core passes `null` input,
		 * which `WP_Ability::normalize_input()` replaces with the schema default.
		 * Without this, `rest_validate_value_from_schema( null, {type:object} )`
		 * would reject every no-argument call.
		 */
		if ( is_array( $input_schema ) && ! array_key_exists( 'default', $input_schema ) ) {
			$input_schema['default'] = [];
		}

		return [
			'label'               => isset( $def['title'] ) ? $def['title'] : $id,
			'description'         => isset( $def['description'] ) ? $def['description'] : '',
			'category'            => self::ABILITY_NAMESPACE,
			'input_schema'        => $input_schema,
			'output_schema'       => $this->normalize_schema( isset( $def['output_schema'] ) ? $def['output_schema'] : [] ),
			'execute_callback'    => function ( $input = null ) use ( $id ) {
				return $this->execute_tool( $id, is_array( $input ) ? $input : [] );
			},
			'permission_callback' => function ( $input = null ) use ( $id ) {
				return $this->ability_permission( $id, is_array( $input ) ? $input : [] );
			},
			'meta'                => [
				'show_in_rest' => true,
				// Honest annotations mapped from the registry's MCP annotations.
				// Core defaults `destructive` to true — read tools must say otherwise.
				'annotations'  => $this->map_annotations( isset( $def['annotations'] ) ? $def['annotations'] : [] ),
				// MCP Adapter exposure (WordPress/mcp-adapter): read tools opt in;
				// write tools stay OFF until individually reviewed (abilities plan §6).
				'mcp'          => [
					'public' => isset( $def['scope'] ) && 'read' === $def['scope'],
					'type'   => 'tool',
				],
			],
		];
	}

	/**
	 * Map MCP annotation hints to Abilities API annotations.
	 *
	 * Deliberately conservative defaults when a hint is missing: not readonly,
	 * destructive, not idempotent (matching core's own pessimistic defaults).
	 *
	 * @param array $mcp_annotations Registry annotations (readOnlyHint etc.).
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	private function map_annotations( array $mcp_annotations ) {
		return [
			'readonly'    => isset( $mcp_annotations['readOnlyHint'] ) ? (bool) $mcp_annotations['readOnlyHint'] : false,
			'destructive' => isset( $mcp_annotations['destructiveHint'] ) ? (bool) $mcp_annotations['destructiveHint'] : true,
			'idempotent'  => isset( $mcp_annotations['idempotentHint'] ) ? (bool) $mcp_annotations['idempotentHint'] : false,
		];
	}

	/**
	 * Deep-convert a registry JSON Schema to plain arrays.
	 *
	 * The registry expresses empty `properties` as `new \stdClass()` (correct
	 * for MCP JSON output), but `rest_validate_value_from_schema()` requires
	 * array access on `properties` — a stdClass there causes a fatal under
	 * `additionalProperties: false`.
	 *
	 * @param mixed $schema Schema node.
	 * @return mixed
	 */
	private function normalize_schema( $schema ) {
		if ( $schema instanceof \stdClass ) {
			$schema = (array) $schema;
		}
		if ( ! is_array( $schema ) ) {
			return $schema;
		}
		foreach ( $schema as $key => $value ) {
			$schema[ $key ] = $this->normalize_schema( $value );
		}
		return $schema;
	}

	// ── Permission & execution ────────────────────────────────────────────────

	/**
	 * Permission callback for one ability.
	 *
	 * Layered checks (abilities-api-plan §3.2 rule 4 / §6):
	 * 1. Real WP capability check: `manage_options`, or — for per-post tools —
	 *    `edit_post` on the targeted post. Write tools never take the per-post
	 *    branch (none are in `$per_post_tools`), so they strictly require
	 *    `manage_options`.
	 * 2. The registry's own pre-flight (`can_execute`): availability, scope
	 *    gate, and the definition's `permission` callable when present.
	 *
	 * Freemius plan failures (`plan_upgrade_required`) deliberately PASS here:
	 * execution then surfaces the registry's WP_Error with its upgrade hint
	 * (abilities plan §7.6 wants a clear message, not a generic
	 * `ability_invalid_permissions`).
	 *
	 * Returns bool (never WP_Error) so callers without permission get core's
	 * standard `ability_invalid_permissions` error.
	 *
	 * @param string $id    Registry tool id.
	 * @param array  $input Ability input.
	 * @return bool
	 */
	public function ability_permission( $id, array $input ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$has_capability = current_user_can( 'manage_options' );

		if ( ! $has_capability && in_array( $id, $this->per_post_tools, true ) && ! empty( $input['post_id'] ) ) {
			$has_capability = current_user_can( 'edit_post', (int) $input['post_id'] );
		}

		if ( ! $has_capability ) {
			return false;
		}

		$can = $this->registry()->can_execute( $id, $this->execution_context() );
		if ( is_wp_error( $can ) && 'plan_upgrade_required' === $can->get_error_code() ) {
			return true;
		}

		return true === $can;
	}

	/**
	 * Execute callback for one ability: delegate to the shared registry.
	 *
	 * Registry executors return plain arrays or WP_Error and may throw;
	 * exceptions are converted to WP_Error (abilities never echo or die).
	 *
	 * @param string $id   Registry tool id.
	 * @param array  $args Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_tool( $id, array $args ) {
		try {
			return $this->registry()->execute( $id, $args, $this->execution_context() );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'llmagnet_ability_execution_failed',
				sprintf(
					/* translators: %s: internal tool id. */
					__( 'LLMagnet tool "%s" failed unexpectedly.', 'llmagnet-llm-txt-generator' ),
					$id
				)
			);
		}
	}

	/**
	 * Registry execution context for ability calls.
	 *
	 * Abilities always run as an authenticated WP user (cookie session or
	 * Application Password — core REST auth happens before the ability runs).
	 *
	 * Scope mirrors the MCP transport's rule for WP-user auth (mcp-ai-spec
	 * §B2): a `manage_options` user gets scope 'write', everyone else is
	 * pinned to 'read'. The registry's scope gate therefore independently
	 * rejects write tools for non-admins even if a permission callback were
	 * ever bypassed, and the registry's own `write_tool_permission` re-checks
	 * `manage_options` on the user id as a third layer.
	 *
	 * @return array
	 */
	private function execution_context() {
		$user = wp_get_current_user();

		$auth = 'session';
		if ( function_exists( 'wp_is_application_password_authenticated' ) && wp_is_application_password_authenticated() ) {
			$auth = 'app_password';
		}

		return [
			'auth'        => $auth,
			'scope'       => current_user_can( 'manage_options' ) ? 'write' : 'read',
			'access_mode' => 'private',
			'user_id'     => (int) $user->ID,
			'token_id'    => '',
			'label'       => 'abilities:' . ( $user->exists() ? $user->user_login : 'unknown' ),
			'ip'          => '',
		];
	}

	// ── Internals ─────────────────────────────────────────────────────────────

	/**
	 * The shared tool registry (lazily built when not injected).
	 *
	 * @return MCP_Tools
	 */
	private function registry() {
		if ( ! $this->registry instanceof MCP_Tools ) {
			if ( ! class_exists( __NAMESPACE__ . '\\MCP_Tools' ) ) {
				require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-mcp-tools.php';
			}
			$this->registry = new MCP_Tools();
		}
		return $this->registry;
	}
}
