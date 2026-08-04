<?php
/**
 * Shared MCP tool registry (mcp-ai-spec §E1 — dependency D1).
 *
 * Single source of truth for every LLMagnet tool exposed to AI agents.
 * Consumers:
 * - `MCP` (class-mcp.php) — the Streamable HTTP transport (`tools/list` / `tools/call`).
 * - `Abilities` (Phase D) — generates WP Abilities API registrations from it.
 * - `/.well-known/mcp.json` (Phase D) and WebMCP (Phase E) read the same registry.
 *
 * ## Tool definition shape
 *
 * Each definition (returned by {@see MCP_Tools::get_definitions()}) is an array:
 *
 * - `id`              (string)  snake_case tool name (MCP tool name).
 * - `title`           (string)  Human-readable title.
 * - `description`     (string)  Agent-facing description (what the data means, when to use it).
 * - `annotations`     (array)   MCP annotations: title, readOnlyHint, destructiveHint,
 *                               idempotentHint, openWorldHint.
 * - `input_schema`    (array)   JSON Schema for arguments (`additionalProperties: false`).
 * - `output_schema`   (array)   JSON Schema describing the result payload.
 * - `scope`           (string)  'read' | 'write' — minimum token scope required.
 * - `public_eligible` (string|false) 'content' | 'read' | false — which anonymous access
 *                               modes may call it (see mcp-ai-spec §A6).
 * - `plan`            (string)  'free' (Phase 1 tools are all free).
 * - `available`       (callable|null) (): bool — whether the tool is registered at all
 *                               (e.g. Woo active). Null = always available.
 * - `permission`      (callable|null) ( array $context ): true|WP_Error — extra per-call check.
 * - `execute`         (callable) ( array $args, array $context ): array|WP_Error — runs the
 *                               tool. Returning WP_Error produces an MCP `isError` result
 *                               (tool failure), not a protocol error.
 *
 * ## Execution context shape
 *
 * The `$context` array passed to permission/execute callables and the query helpers:
 *
 * - `auth`        (string) 'token' | 'legacy' | 'app_password' | 'session' | 'anonymous'.
 * - `scope`       (string) 'read' | 'write' | 'none' (anonymous = 'none').
 * - `access_mode` (string) 'private' | 'public_content' | 'public_read'.
 * - `user_id`     (int)    WP user ID (0 for token/anonymous callers).
 * - `token_id`    (string) Token id when auth is token/legacy, '' otherwise.
 * - `label`       (string) Display label for activity logging.
 *
 * Extend via the `llmagnet_mcp_tools` filter (used by agent-readiness E3-10 etc.).
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry of MCP tool definitions and their executors.
 */
class MCP_Tools {

	/** @var Analytics|null */
	private $analytics;

	/** @var Visibility_Score|null */
	private $visibility_score;

	/** @var Generator|null */
	private $generator;

	/** @var Robots_Txt|null */
	private $robots_txt;

	/** @var Page_Details|null */
	private $page_details;

	/** @var Attribution|null */
	private $attribution;

	/** @var Email_Reports|null */
	private $email_reports;

	/** @var Product_Analytics|null */
	private $product_analytics;

	/** @var array|null Cached, filtered definitions. */
	private $definitions;

	/**
	 * All dependencies are optional; missing ones are lazily constructed
	 * (every dependency has a no-argument constructor; Email_Reports falls
	 * back to the `$llmagnet_email_reports` global Main already exposes).
	 *
	 * @param Analytics|null        $analytics        Analytics instance.
	 * @param Visibility_Score|null $visibility_score Visibility score instance.
	 * @param Generator|null        $generator        Generator instance.
	 * @param Robots_Txt|null       $robots_txt       Robots.txt integration instance.
	 * @param Page_Details|null     $page_details     Page details instance.
	 * @param Attribution|null      $attribution      Attribution instance.
	 * @param Email_Reports|null    $email_reports    Email reports instance (write tool `send_report_email`).
	 */
	public function __construct( $analytics = null, $visibility_score = null, $generator = null, $robots_txt = null, $page_details = null, $attribution = null, $email_reports = null ) {
		$this->analytics        = $analytics;
		$this->visibility_score = $visibility_score;
		$this->generator        = $generator;
		$this->robots_txt       = $robots_txt;
		$this->page_details     = $page_details;
		$this->attribution      = $attribution;
		$this->email_reports    = $email_reports;
	}

	// ── Public registry API ───────────────────────────────────────────────────

	/**
	 * All tool definitions keyed by tool id, after the `llmagnet_mcp_tools` filter.
	 *
	 * Unavailable tools (per their `available` callback) are NOT filtered out here;
	 * use {@see MCP_Tools::is_available()} or the context-aware helpers.
	 *
	 * @return array<string, array>
	 */
	public function get_definitions() {
		if ( null !== $this->definitions ) {
			return $this->definitions;
		}

		$definitions = $this->build_definitions();

		/**
		 * Filter the MCP tool registry.
		 *
		 * @param array<string, array> $definitions Tool definitions keyed by tool id.
		 * @param MCP_Tools            $registry    Registry instance.
		 */
		$definitions = apply_filters( 'llmagnet_mcp_tools', $definitions, $this );

		$this->definitions = is_array( $definitions ) ? $definitions : [];
		return $this->definitions;
	}

	/**
	 * One tool definition.
	 *
	 * @param string $id Tool id.
	 * @return array|null
	 */
	public function get_definition( $id ) {
		$definitions = $this->get_definitions();
		return isset( $definitions[ $id ] ) ? $definitions[ $id ] : null;
	}

	/**
	 * Whether a tool is currently available (registered at all).
	 *
	 * @param string $id Tool id.
	 * @return bool
	 */
	public function is_available( $id ) {
		$def = $this->get_definition( $id );
		if ( null === $def ) {
			return false;
		}
		if ( isset( $def['available'] ) && is_callable( $def['available'] ) ) {
			return (bool) call_user_func( $def['available'] );
		}
		return true;
	}

	/**
	 * Tool ids invocable in a given execution context (availability + scope + access mode).
	 *
	 * @param array $context Execution context (see class docblock).
	 * @return string[]
	 */
	public function tool_ids_for_context( array $context ) {
		$ids = [];
		foreach ( $this->get_definitions() as $id => $def ) {
			if ( ! $this->is_available( $id ) ) {
				continue;
			}
			if ( true === $this->check_access( $def, $context ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * MCP-shaped tool list for `tools/list`, filtered to what the context may invoke.
	 *
	 * @param array $context Execution context.
	 * @return array[]
	 */
	public function list_tools( array $context ) {
		$tools = [];
		foreach ( $this->tool_ids_for_context( $context ) as $id ) {
			$def     = $this->get_definition( $id );
			$tools[] = [
				'name'         => $id,
				'title'        => $def['title'],
				'description'  => $def['description'],
				'inputSchema'  => $def['input_schema'],
				'outputSchema' => $def['output_schema'],
				'annotations'  => $def['annotations'],
			];
		}
		return $tools;
	}

	/**
	 * Pre-flight check: can this context call this tool right now?
	 *
	 * @param string $id      Tool id.
	 * @param array  $context Execution context.
	 * @return true|\WP_Error True, or WP_Error with code:
	 *                        'unknown_tool' | 'tool_unavailable' | 'auth_required' |
	 *                        'scope_denied' | 'permission_denied'.
	 */
	public function can_execute( $id, array $context ) {
		$def = $this->get_definition( $id );
		if ( null === $def ) {
			return new \WP_Error( 'unknown_tool', sprintf( 'Unknown tool: %s', $id ) );
		}
		if ( ! $this->is_available( $id ) ) {
			return new \WP_Error( 'tool_unavailable', sprintf( 'Tool not available on this site: %s', $id ) );
		}

		$access = $this->check_access( $def, $context );
		if ( true !== $access ) {
			return $access;
		}

		if ( isset( $def['permission'] ) && is_callable( $def['permission'] ) ) {
			$permitted = call_user_func( $def['permission'], $context );
			if ( is_wp_error( $permitted ) ) {
				return $permitted;
			}
			if ( true !== $permitted ) {
				return new \WP_Error( 'permission_denied', sprintf( 'Permission denied for tool: %s', $id ) );
			}
		}

		return true;
	}

	/**
	 * Execute a tool.
	 *
	 * Content-exposure rules are enforced inside the executors themselves, so any
	 * consumer (MCP transport, Abilities, WebMCP) gets identical behavior.
	 *
	 * @param string $id      Tool id.
	 * @param array  $args    Tool arguments (already JSON-decoded).
	 * @param array  $context Execution context.
	 * @return array|\WP_Error Result payload, or WP_Error for a tool-level failure.
	 *                         May throw — transports must wrap in try/catch.
	 */
	public function execute( $id, array $args, array $context ) {
		$can = $this->can_execute( $id, $context );
		if ( is_wp_error( $can ) ) {
			return $can;
		}

		$def = $this->get_definition( $id );
		return call_user_func( $def['execute'], $args, $context );
	}

	// ── Access rules ──────────────────────────────────────────────────────────

	/**
	 * Scope / access-mode gate for one definition.
	 *
	 * @param array $def     Tool definition.
	 * @param array $context Execution context.
	 * @return true|\WP_Error
	 */
	private function check_access( array $def, array $context ) {
		$auth  = isset( $context['auth'] ) ? $context['auth'] : 'anonymous';
		$scope = isset( $context['scope'] ) ? $context['scope'] : 'none';

		if ( 'anonymous' === $auth ) {
			$mode     = isset( $context['access_mode'] ) ? $context['access_mode'] : 'private';
			$eligible = isset( $def['public_eligible'] ) ? $def['public_eligible'] : false;

			$allowed = ( 'public_content' === $mode && 'content' === $eligible )
				|| ( 'public_read' === $mode && in_array( $eligible, [ 'content', 'read' ], true ) );

			if ( ! $allowed ) {
				return new \WP_Error( 'auth_required', 'Authentication required for this tool.' );
			}
			return true;
		}

		// Write tools always require write scope — no access mode bypasses this.
		if ( 'write' === $def['scope'] && 'write' !== $scope ) {
			return new \WP_Error(
				'scope_denied',
				sprintf( 'This token is read-only; tool %s requires a read/write token.', $def['id'] )
			);
		}

		return true;
	}

	// ── Definitions ───────────────────────────────────────────────────────────

	/**
	 * Build the built-in tool definitions.
	 *
	 * @return array<string, array>
	 */
	private function build_definitions() {
		$read_annotations = function ( $title ) {
			return [
				'title'           => $title,
				'readOnlyHint'    => true,
				'destructiveHint' => false,
				'idempotentHint'  => true,
				'openWorldHint'   => false,
			];
		};

		$no_args_schema = [
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
		];

		$definitions = [];

		// ── Existing 6 tools (migrated from class-mcp.php) ────────────────────

		$definitions['get_site_info'] = [
			'id'              => 'get_site_info',
			'title'           => __( 'Get Site Info', 'llmagnet-llm-txt-generator' ),
			'description'     => 'Returns basic information about the WordPress site: name, URL, plugin version, llms.txt status, MCP endpoint, and whether WooCommerce is active. Use this first to orient yourself on what site you are talking to.',
			'annotations'     => $read_annotations( __( 'Get Site Info', 'llmagnet-llm-txt-generator' ) ),
			'input_schema'    => $no_args_schema,
			'output_schema'   => [
				'type'       => 'object',
				'properties' => [
					'site_name'          => [ 'type' => 'string', 'description' => 'Site title.' ],
					'site_url'           => [ 'type' => 'string', 'description' => 'Site URL.' ],
					'plugin_version'     => [ 'type' => 'string', 'description' => 'LLMagnet plugin version.' ],
					'wordpress_version'  => [ 'type' => 'string', 'description' => 'WordPress core version.' ],
					'llms_txt_exists'    => [ 'type' => 'boolean', 'description' => 'Whether llms.txt exists at the site root.' ],
					'llms_txt_size'      => [ 'type' => 'integer', 'description' => 'Size of llms.txt in bytes (0 if missing).' ],
					'woocommerce_active' => [ 'type' => 'boolean', 'description' => 'Whether WooCommerce is active.' ],
					'mcp_endpoint'       => [ 'type' => 'string', 'description' => 'URL of this MCP endpoint.' ],
				],
			],
			'scope'           => 'read',
			'public_eligible' => 'content',
			'plan'            => 'free',
			'available'       => null,
			'permission'      => null,
			'execute'         => [ $this, 'tool_get_site_info' ],
		];

		$definitions['get_visibility_score'] = [
			'id'              => 'get_visibility_score',
			'title'           => __( 'Get AI Visibility Score', 'llmagnet-llm-txt-generator' ),
			'description'     => 'Returns the current site-wide AI visibility score (0-100) and its breakdown by component (frequency, visit type, page coverage, URL types, etc.). Use it to assess how discoverable the site is to AI assistants and crawlers.',
			'annotations'     => $read_annotations( __( 'Get AI Visibility Score', 'llmagnet-llm-txt-generator' ) ),
			'input_schema'    => [
				'type'                 => 'object',
				'properties'           => [
					'range_days' => [
						'type'        => 'integer',
						'description' => 'Number of days to analyze. Default: 30.',
					],
				],
				'additionalProperties' => false,
			],
			'output_schema'   => [
				'type'        => 'object',
				'description' => 'Score data including overall score (0-100) and per-component breakdown.',
			],
			'scope'           => 'read',
			'public_eligible' => 'read',
			'plan'            => 'free',
			'available'       => null,
			'permission'      => null,
			'execute'         => [ $this, 'tool_get_visibility_score' ],
		];

		$definitions['get_bot_traffic'] = [
			'id'              => 'get_bot_traffic',
			'title'           => __( 'Get AI Bot Traffic', 'llmagnet-llm-txt-generator' ),
			'description'     => 'Returns AI bot visit statistics: total visits per bot (all time) and a recent per-day visit history. Use it to see which AI crawlers (GPTBot, ClaudeBot, PerplexityBot, ...) are visiting the site and how often.',
			'annotations'     => $read_annotations( __( 'Get AI Bot Traffic', 'llmagnet-llm-txt-generator' ) ),
			'input_schema'    => [
				'type'                 => 'object',
				'properties'           => [
					'days' => [
						'type'        => 'integer',
						'description' => 'Number of days of recent history to return. Default: 30.',
					],
				],
				'additionalProperties' => false,
			],
			'output_schema'   => [
				'type'       => 'object',
				'properties' => [
					'total_by_bot'  => [ 'type' => 'object', 'description' => 'All-time visit totals keyed by bot name.' ],
					'recent_visits' => [ 'type' => 'array', 'description' => 'Rows of {bot_name, date, visits} for the requested window.' ],
					'days_range'    => [ 'type' => 'integer', 'description' => 'The window that was analyzed, in days.' ],
				],
			],
			'scope'           => 'read',
			'public_eligible' => 'read',
			'plan'            => 'free',
			'available'       => null,
			'permission'      => null,
			'execute'         => [ $this, 'tool_get_bot_traffic' ],
		];

		$definitions['get_top_pages'] = [
			'id'              => 'get_top_pages',
			'title'           => __( 'Get Top Pages for AI Traffic', 'llmagnet-llm-txt-generator' ),
			'description'     => 'Returns per-page analytics showing which pages AI bots have visited, along with click counts and CTR. Use it to find the site content AI assistants engage with most.',
			'annotations'     => $read_annotations( __( 'Get Top Pages for AI Traffic', 'llmagnet-llm-txt-generator' ) ),
			'input_schema'    => $no_args_schema,
			'output_schema'   => [
				'type'       => 'object',
				'properties' => [
					'pages' => [
						'type'        => 'array',
						'description' => 'Per-page rows with path, impressions (bot visits), clicks and CTR.',
					],
				],
			],
			'scope'           => 'read',
			'public_eligible' => 'read',
			'plan'            => 'free',
			'available'       => null,
			'permission'      => null,
			'execute'         => [ $this, 'tool_get_top_pages' ],
		];

		$definitions['get_bot_stats_table'] = [
			'id'              => 'get_bot_stats_table',
			'title'           => __( 'Get Bot Stats Table', 'llmagnet-llm-txt-generator' ),
			'description'     => 'Returns a summary table of all detected AI bots with impressions, clicks, CTR, and trend direction. Use it for a quick per-bot comparison.',
			'annotations'     => $read_annotations( __( 'Get Bot Stats Table', 'llmagnet-llm-txt-generator' ) ),
			'input_schema'    => $no_args_schema,
			'output_schema'   => [
				'type'       => 'object',
				'properties' => [
					'bots' => [
						'type'        => 'array',
						'description' => 'Per-bot rows with impressions, clicks, CTR and trend.',
					],
				],
			],
			'scope'           => 'read',
			'public_eligible' => 'read',
			'plan'            => 'free',
			'available'       => null,
			'permission'      => null,
			'execute'         => [ $this, 'tool_get_bot_stats_table' ],
		];

		$definitions['get_recommendations'] = [
			'id'              => 'get_recommendations',
			'title'           => __( 'Get AI Visibility Recommendations', 'llmagnet-llm-txt-generator' ),
			'description'     => 'Analyzes the site visibility data and returns a prioritized list of actionable recommendations for improving AI discoverability (llms.txt, robots.txt, bot coverage).',
			'annotations'     => $read_annotations( __( 'Get AI Visibility Recommendations', 'llmagnet-llm-txt-generator' ) ),
			'input_schema'    => [
				'type'                 => 'object',
				'properties'           => [
					'range_days' => [
						'type'        => 'integer',
						'description' => 'Number of days to analyze. Default: 30.',
					],
				],
				'additionalProperties' => false,
			],
			'output_schema'   => [
				'type'       => 'object',
				'properties' => [
					'score'           => [ 'type' => 'integer', 'description' => 'Current AI visibility score (0-100).' ],
					'range_days'      => [ 'type' => 'integer', 'description' => 'Analyzed window in days.' ],
					'recommendations' => [ 'type' => 'array', 'description' => 'Rows of {priority, area, message, action}.' ],
					'admin_url'       => [ 'type' => 'string', 'description' => 'Deep link to the LLMagnet dashboard where the user can fix these items.' ],
				],
			],
			'scope'           => 'read',
			'public_eligible' => 'read',
			'plan'            => 'free',
			'available'       => null,
			'permission'      => null,
			'execute'         => [ $this, 'tool_get_recommendations' ],
		];

		// ── New read tools (mcp-ai-spec Workstream C, Phase 1) ────────────────

		$definitions['get_llms_txt_status'] = [
			'id'              => 'get_llms_txt_status',
			'title'           => __( 'Get llms.txt Status', 'llmagnet-llm-txt-generator' ),
			'description'     => 'Reports the state of the site\'s llms.txt and llms-full.txt files: whether they exist, sizes, when they were last generated, which post types are included, and how many markdown docs exist in /llms-docs/. Use it to verify the site\'s LLM content index is present and fresh.',
			'annotations'     => $read_annotations( __( 'Get llms.txt Status', 'llmagnet-llm-txt-generator' ) ),
			'input_schema'    => $no_args_schema,
			'output_schema'   => [
				'type'       => 'object',
				'properties' => [
					'llms_txt'            => [ 'type' => 'object', 'description' => '{exists, size_bytes, url} for llms.txt.' ],
					'llms_full_txt'       => [ 'type' => 'object', 'description' => '{exists, size_bytes, post_count} for llms-full.txt.' ],
					'last_generated'      => [ 'type' => [ 'string', 'null' ], 'description' => 'ISO 8601 timestamp of the last generation run, or null if never recorded.' ],
					'included_post_types' => [ 'type' => 'array', 'description' => 'Post types included in llms.txt per current settings.' ],
					'docs_count'          => [ 'type' => 'integer', 'description' => 'Number of per-post markdown files in /llms-docs/.' ],
					'posts_in_scope'      => [ 'type' => 'integer', 'description' => 'Number of published posts matching the export settings.' ],
				],
			],
			'scope'           => 'read',
			'public_eligible' => 'content',
			'plan'            => 'free',
			'available'       => null,
			'permission'      => null,
			'execute'         => [ $this, 'tool_get_llms_txt_status' ],
		];

		$definitions['get_schema_status'] = [
			'id'              => 'get_schema_status',
			'title'           => __( 'Get Schema JSON-LD Status', 'llmagnet-llm-txt-generator' ),
			'description'     => 'Summarizes the site\'s structured data (schema.org JSON-LD) state from the most recent LLMagnet scan: overall score, detected types per scanned page, recommendations, and whether LLMagnet-published schema is active. Use it to judge whether AI systems can extract structured facts from the site.',
			'annotations'     => $read_annotations( __( 'Get Schema JSON-LD Status', 'llmagnet-llm-txt-generator' ) ),
			'input_schema'    => $no_args_schema,
			'output_schema'   => [
				'type'       => 'object',
				'properties' => [
					'scanned'          => [ 'type' => 'boolean', 'description' => 'Whether a scan result is available.' ],
					'scanned_at'       => [ 'type' => [ 'string', 'null' ], 'description' => 'UTC timestamp of the last scan.' ],
					'overall_score'    => [ 'type' => [ 'integer', 'null' ], 'description' => 'Overall schema score from the last scan.' ],
					'detected_types'   => [ 'type' => 'object', 'description' => 'Counts of schema.org types found across scanned pages.' ],
					'pages_scanned'    => [ 'type' => 'integer', 'description' => 'Number of sample pages in the last scan.' ],
					'recommendations'  => [ 'type' => 'array', 'description' => 'Recommendations from the last scan.' ],
					'published_schema' => [ 'type' => 'object', 'description' => '{enabled, types} for schema published by LLMagnet.' ],
				],
			],
			'scope'           => 'read',
			'public_eligible' => 'read',
			'plan'            => 'free',
			'available'       => null,
			'permission'      => null,
			'execute'         => [ $this, 'tool_get_schema_status' ],
		];

		$definitions['get_page_visibility'] = [
			'id'              => 'get_page_visibility',
			'title'           => __( 'Get Page Visibility Score', 'llmagnet-llm-txt-generator' ),
			'description'     => 'Returns the per-page AI-readiness score (0-100) with its bot-visibility and content-quality breakdown plus an issue/recommendation list. Identify the page by post_id or url. Use it to diagnose why a specific page is (in)visible to AI assistants.',
			'annotations'     => $read_annotations( __( 'Get Page Visibility Score', 'llmagnet-llm-txt-generator' ) ),
			'input_schema'    => [
				'type'                 => 'object',
				'properties'           => [
					'post_id'    => [
						'type'        => 'integer',
						'description' => 'WordPress post ID. Provide either post_id or url.',
					],
					'url'        => [
						'type'        => 'string',
						'description' => 'Full permalink of the page. Provide either post_id or url.',
					],
					'range_days' => [
						'type'        => 'integer',
						'description' => 'Number of days of bot traffic to analyze. Default: 30.',
					],
				],
				'additionalProperties' => false,
			],
			'output_schema'   => [
				'type'       => 'object',
				'properties' => [
					'post_id'         => [ 'type' => 'integer', 'description' => 'Resolved post ID.' ],
					'title'           => [ 'type' => 'string', 'description' => 'Post title.' ],
					'url'             => [ 'type' => 'string', 'description' => 'Permalink.' ],
					'score'           => [ 'type' => 'integer', 'description' => 'Overall page AI-readiness score (0-100).' ],
					'bot_visibility'  => [ 'type' => 'object', 'description' => 'Bot visibility sub-score and components.' ],
					'content_quality' => [ 'type' => 'object', 'description' => 'Content quality sub-score and components.' ],
					'recommendations' => [ 'type' => 'array', 'description' => 'Page-specific improvement recommendations.' ],
				],
			],
			'scope'           => 'read',
			'public_eligible' => 'read',
			'plan'            => 'free',
			'available'       => null,
			'permission'      => null,
			'execute'         => [ $this, 'tool_get_page_visibility' ],
		];

		$definitions['get_attribution_stats'] = [
			'id'              => 'get_attribution_stats',
			'title'           => __( 'Get AI Referral Attribution Stats', 'llmagnet-llm-txt-generator' ),
			'description'     => 'Returns human-visitor sessions referred FROM AI assistants (ChatGPT, Perplexity, Claude, ...) — total sessions, conversions, conversion rate, and a per-source breakdown. This measures humans arriving via AI answers, not bot crawls.',
			'annotations'     => $read_annotations( __( 'Get AI Referral Attribution Stats', 'llmagnet-llm-txt-generator' ) ),
			'input_schema'    => [
				'type'                 => 'object',
				'properties'           => [
					'days' => [
						'type'        => 'integer',
						'description' => 'Number of days to look back. Default: 30.',
					],
				],
				'additionalProperties' => false,
			],
			'output_schema'   => [
				'type'       => 'object',
				'properties' => [
					'total_sessions'     => [ 'type' => 'integer', 'description' => 'AI-referred sessions in the window.' ],
					'converted_sessions' => [ 'type' => 'integer', 'description' => 'Sessions that converted (e.g. placed an order).' ],
					'conversion_rate'    => [ 'type' => 'number', 'description' => 'Conversion rate percentage.' ],
					'by_source'          => [ 'type' => 'array', 'description' => 'Rows of {bot_source, sessions, conversions}.' ],
					'days'               => [ 'type' => 'integer', 'description' => 'Analyzed window in days.' ],
				],
			],
			'scope'           => 'read',
			'public_eligible' => 'read',
			'plan'            => 'free',
			'available'       => null,
			'permission'      => null,
			'execute'         => [ $this, 'tool_get_attribution_stats' ],
		];

		$definitions['get_robots_txt_status'] = [
			'id'              => 'get_robots_txt_status',
			'title'           => __( 'Get robots.txt AI Crawler Status', 'llmagnet-llm-txt-generator' ),
			'description'     => 'Reports which known AI crawlers are allowed or blocked by the site\'s robots.txt, whether the file references llms.txt, and whether LLMagnet\'s robots.txt injection is enabled. Use it to spot crawler-blocking misconfigurations.',
			'annotations'     => $read_annotations( __( 'Get robots.txt AI Crawler Status', 'llmagnet-llm-txt-generator' ) ),
			'input_schema'    => $no_args_schema,
			'output_schema'   => [
				'type'       => 'object',
				'properties' => [
					'robots_txt_url'     => [ 'type' => 'string', 'description' => 'Public robots.txt URL.' ],
					'has_physical_file'  => [ 'type' => 'boolean', 'description' => 'Whether a physical robots.txt file exists.' ],
					'has_llms_reference' => [ 'type' => 'boolean', 'description' => 'Whether robots.txt references llms.txt.' ],
					'injection_method'   => [ 'type' => 'string', 'description' => 'How LLMagnet injects its block: file, filter, or none.' ],
					'default_policy'     => [ 'type' => 'string', 'description' => 'Effective policy of the wildcard (*) user-agent group: allowed, blocked, or partial.' ],
					'ai_crawlers'        => [ 'type' => 'array', 'description' => 'Rows of {crawler, status, matched_group} for known AI crawler user agents.' ],
				],
			],
			'scope'           => 'read',
			'public_eligible' => 'read',
			'plan'            => 'free',
			'available'       => null,
			'permission'      => null,
			'execute'         => [ $this, 'tool_get_robots_txt_status' ],
		];

		$definitions['get_content_markdown'] = [
			'id'              => 'get_content_markdown',
			'title'           => __( 'Get Page Content as Markdown', 'llmagnet-llm-txt-generator' ),
			'description'     => 'Returns a published post or page converted to clean markdown — title, metadata, and body with HTML removed. Identify the content by post_id or url. Only published, public, non-password-protected content is served. Use it to actually read the site\'s content.',
			'annotations'     => $read_annotations( __( 'Get Page Content as Markdown', 'llmagnet-llm-txt-generator' ) ),
			'input_schema'    => [
				'type'                 => 'object',
				'properties'           => [
					'post_id' => [
						'type'        => 'integer',
						'description' => 'WordPress post ID. Provide either post_id or url.',
					],
					'url'     => [
						'type'        => 'string',
						'description' => 'Full permalink of the content. Provide either post_id or url.',
					],
				],
				'additionalProperties' => false,
			],
			'output_schema'   => [
				'type'       => 'object',
				'properties' => [
					'post_id'   => [ 'type' => 'integer', 'description' => 'Post ID.' ],
					'title'     => [ 'type' => 'string', 'description' => 'Post title.' ],
					'url'       => [ 'type' => 'string', 'description' => 'Permalink.' ],
					'type'      => [ 'type' => 'string', 'description' => 'Post type.' ],
					'published' => [ 'type' => 'string', 'description' => 'Publish date (Y-m-d).' ],
					'modified'  => [ 'type' => 'string', 'description' => 'Last modified date (Y-m-d).' ],
					'markdown'  => [ 'type' => 'string', 'description' => 'The content converted to markdown.' ],
				],
			],
			'scope'           => 'read',
			'public_eligible' => 'content',
			'plan'            => 'free',
			'available'       => null,
			'permission'      => null,
			'execute'         => [ $this, 'tool_get_content_markdown' ],
		];

		$definitions['search_content'] = [
			'id'              => 'search_content',
			'title'           => __( 'Search Site Content', 'llmagnet-llm-txt-generator' ),
			'description'     => 'Searches the site\'s published content and returns matching posts/pages with id, title, url, excerpt, and how many AI bot visits each received in the last 30 days. Use it to find relevant content before fetching it with get_content_markdown.',
			'annotations'     => $read_annotations( __( 'Search Site Content', 'llmagnet-llm-txt-generator' ) ),
			'input_schema'    => [
				'type'                 => 'object',
				'properties'           => [
					'query' => [
						'type'        => 'string',
						'description' => 'Search keywords.',
					],
					'limit' => [
						'type'        => 'integer',
						'description' => 'Maximum number of results (1-20). Default: 10.',
					],
				],
				'required'             => [ 'query' ],
				'additionalProperties' => false,
			],
			'output_schema'   => [
				'type'       => 'object',
				'properties' => [
					'query'   => [ 'type' => 'string', 'description' => 'The search query that ran.' ],
					'results' => [ 'type' => 'array', 'description' => 'Rows of {post_id, title, url, type, excerpt, ai_visits_30d}.' ],
				],
			],
			'scope'           => 'read',
			'public_eligible' => 'content',
			'plan'            => 'free',
			'available'       => null,
			'permission'      => null,
			'execute'         => [ $this, 'tool_search_content' ],
		];

		$definitions['get_ai_visit_trends'] = [
			'id'              => 'get_ai_visit_trends',
			'title'           => __( 'Get AI Visit Trends', 'llmagnet-llm-txt-generator' ),
			'description'     => 'Returns AI bot visits per bot per day for the requested window — suitable for charting and period-over-period comparison. Optionally filter to a single bot name.',
			'annotations'     => $read_annotations( __( 'Get AI Visit Trends', 'llmagnet-llm-txt-generator' ) ),
			'input_schema'    => [
				'type'                 => 'object',
				'properties'           => [
					'days' => [
						'type'        => 'integer',
						'description' => 'Number of days to return. Default: 30.',
					],
					'bot'  => [
						'type'        => 'string',
						'description' => 'Optional bot name to filter by (e.g. "ChatGPT", "Claude", "Perplexity").',
					],
				],
				'additionalProperties' => false,
			],
			'output_schema'   => [
				'type'       => 'object',
				'properties' => [
					'days'   => [ 'type' => 'integer', 'description' => 'Analyzed window in days.' ],
					'bot'    => [ 'type' => [ 'string', 'null' ], 'description' => 'Bot filter applied, or null for all bots.' ],
					'series' => [ 'type' => 'array', 'description' => 'Rows of {bot_name, date, visits} ordered by date descending.' ],
				],
			],
			'scope'           => 'read',
			'public_eligible' => 'read',
			'plan'            => 'free',
			'available'       => null,
			'permission'      => null,
			'execute'         => [ $this, 'tool_get_ai_visit_trends' ],
		];

		// ── Write tools (mcp-ai-spec Workstream C, Phase 2 — FC-1) ────────────
		//
		// Honest annotations per tool; scope `write`; NEVER public_eligible —
		// `check_access()` denies anonymous callers in every access mode, and a
		// read-scope token gets `scope_denied`. Session / App-Password callers
		// additionally require `manage_options` (defense in depth via the
		// `permission` callable — the MCP transport only mints those contexts
		// for admins, but other registry consumers build their own contexts).

		$write_permission = [ $this, 'write_tool_permission' ];

		$definitions['regenerate_llms_txt'] = [
			'id'              => 'regenerate_llms_txt',
			'title'           => __( 'Regenerate llms.txt', 'llmagnet-llm-txt-generator' ),
			'description'     => 'Regenerates the site\'s llms.txt and llms-full.txt files right now from the current content and settings, and queues the per-post markdown docs for background regeneration. Idempotent: running it twice produces the same files. Use it after publishing or updating content so AI crawlers see a fresh index.',
			'annotations'     => [
				'title'           => __( 'Regenerate llms.txt', 'llmagnet-llm-txt-generator' ),
				'readOnlyHint'    => false,
				'destructiveHint' => false,
				'idempotentHint'  => true,
				'openWorldHint'   => false,
			],
			'input_schema'    => $no_args_schema,
			'output_schema'   => [
				'type'       => 'object',
				'properties' => [
					'success'       => [ 'type' => 'boolean', 'description' => 'Whether the regeneration ran.' ],
					'generated_at'  => [ 'type' => 'string', 'description' => 'ISO 8601 timestamp of this generation run.' ],
					'llms_txt'      => [ 'type' => 'object', 'description' => '{exists, size_bytes, url} for the regenerated llms.txt.' ],
					'llms_full_txt' => [ 'type' => 'object', 'description' => '{exists, size_bytes, post_count} for llms-full.txt (written on paid plans).' ],
					'note'          => [ 'type' => 'string', 'description' => 'Details about background markdown batching.' ],
				],
			],
			'scope'           => 'write',
			'public_eligible' => false,
			'plan'            => 'free',
			'available'       => null,
			'permission'      => $write_permission,
			'execute'         => [ $this, 'tool_regenerate_llms_txt' ],
		];

		$definitions['recalculate_visibility_score'] = [
			'id'              => 'recalculate_visibility_score',
			'title'           => __( 'Recalculate AI Visibility Score', 'llmagnet-llm-txt-generator' ),
			'description'     => 'Forces a fresh computation of the site-wide AI visibility score instead of returning the cached value, and saves it when it changed. Idempotent for unchanged data. Use it after making visibility improvements (llms.txt, robots.txt) to verify their effect immediately.',
			'annotations'     => [
				'title'           => __( 'Recalculate AI Visibility Score', 'llmagnet-llm-txt-generator' ),
				'readOnlyHint'    => false,
				'destructiveHint' => false,
				'idempotentHint'  => true,
				'openWorldHint'   => false,
			],
			'input_schema'    => [
				'type'                 => 'object',
				'properties'           => [
					'range_days' => [
						'type'        => 'integer',
						'description' => 'Number of days to analyze. Default: 30.',
					],
				],
				'additionalProperties' => false,
			],
			'output_schema'   => [
				'type'       => 'object',
				'properties' => [
					'success'     => [ 'type' => 'boolean', 'description' => 'Whether the recalculation ran.' ],
					'range_days'  => [ 'type' => 'integer', 'description' => 'Analyzed window in days.' ],
					'was_updated' => [ 'type' => 'boolean', 'description' => 'Whether the stored score changed and was saved.' ],
					'score_data'  => [ 'type' => 'object', 'description' => 'Freshly computed score data including the overall score (0-100) and per-component breakdown.' ],
				],
			],
			'scope'           => 'write',
			'public_eligible' => false,
			'plan'            => 'free',
			'available'       => null,
			'permission'      => $write_permission,
			'execute'         => [ $this, 'tool_recalculate_visibility_score' ],
		];

		$definitions['send_report_email'] = [
			'id'              => 'send_report_email',
			'title'           => __( 'Send Analytics Report Email', 'llmagnet-llm-txt-generator' ),
			'description'     => 'Sends the LLM bot analytics report email to the configured recipients right now (a confirmable side effect — an email is sent on every call, so it is NOT idempotent). Requires a paid LLMagnet plan and at least one configured recipient. The result includes the recipients the report was sent to.',
			'annotations'     => [
				'title'           => __( 'Send Analytics Report Email', 'llmagnet-llm-txt-generator' ),
				'readOnlyHint'    => false,
				'destructiveHint' => false,
				'idempotentHint'  => false,
				'openWorldHint'   => false,
			],
			'input_schema'    => $no_args_schema,
			'output_schema'   => [
				'type'       => 'object',
				'properties' => [
					'success'    => [ 'type' => 'boolean', 'description' => 'Whether the email was sent to all recipients.' ],
					'recipients' => [ 'type' => 'array', 'description' => 'Email addresses the report was sent to.' ],
					'template'   => [ 'type' => 'string', 'description' => 'Email template used (classic, minimal, gradient, professional).' ],
					'sent_at'    => [ 'type' => 'string', 'description' => 'ISO 8601 timestamp of the send.' ],
				],
			],
			'scope'           => 'write',
			'public_eligible' => false,
			'plan'            => 'pro',
			'available'       => null,
			'permission'      => [ $this, 'send_report_email_permission' ],
			'execute'         => [ $this, 'tool_send_report_email' ],
		];

		// ── WooCommerce tools (mcp-ai-spec Phase 2.5 — FC-3, plan-gated) ──────
		//
		// Registered (available) only when WooCommerce is active AND the plan
		// includes commerce analytics (trial / Plus / Enterprise — mirrors the
		// UI's hasCommerceAccess() gate). Read scope, but NOT public_eligible:
		// revenue figures stay behind authentication even in public_read mode.

		$woo_available = [ $this, 'woo_tools_available' ];

		$definitions['get_product_ai_stats'] = [
			'id'              => 'get_product_ai_stats',
			'title'           => __( 'Get Product AI Traffic Stats', 'llmagnet-llm-txt-generator' ),
			'description'     => 'Returns the WooCommerce products most visited by AI bots in the requested window: per-product visit counts (impressions) with a per-bot breakdown, plus name, URL and price. Use it to see which products AI assistants are reading and recommending.',
			'annotations'     => $read_annotations( __( 'Get Product AI Traffic Stats', 'llmagnet-llm-txt-generator' ) ),
			'input_schema'    => [
				'type'                 => 'object',
				'properties'           => [
					'days'  => [
						'type'        => 'integer',
						'description' => 'Number of days to analyze. Default: 30.',
					],
					'limit' => [
						'type'        => 'integer',
						'description' => 'Maximum number of products to return (1-20). Default: 10.',
					],
				],
				'additionalProperties' => false,
			],
			'output_schema'   => [
				'type'       => 'object',
				'properties' => [
					'days'     => [ 'type' => 'integer', 'description' => 'Analyzed window in days.' ],
					'products' => [ 'type' => 'array', 'description' => 'Rows of {product_id, product_name, product_url, price, impressions, bots[]} ordered by AI visits descending.' ],
					'has_data' => [ 'type' => 'boolean', 'description' => 'Whether any product received AI bot visits in the window.' ],
				],
			],
			'scope'           => 'read',
			'public_eligible' => false,
			'plan'            => 'plus',
			'available'       => $woo_available,
			'permission'      => null,
			'execute'         => [ $this, 'tool_get_product_ai_stats' ],
		];

		$definitions['get_ai_revenue_funnel'] = [
			'id'              => 'get_ai_revenue_funnel',
			'title'           => __( 'Get AI Revenue Funnel', 'llmagnet-llm-txt-generator' ),
			'description'     => 'Returns the AI-attributed commerce funnel for the requested window: AI bot product views, add-to-cart events and purchases attributed to AI sources, conversion rates between the stages, and the attributed revenue total. Use it to quantify how much revenue AI assistant traffic drives.',
			'annotations'     => $read_annotations( __( 'Get AI Revenue Funnel', 'llmagnet-llm-txt-generator' ) ),
			'input_schema'    => [
				'type'                 => 'object',
				'properties'           => [
					'range_days' => [
						'type'        => 'integer',
						'description' => 'Number of days to analyze. Default: 30.',
					],
				],
				'additionalProperties' => false,
			],
			'output_schema'   => [
				'type'       => 'object',
				'properties' => [
					'range_days'  => [ 'type' => 'integer', 'description' => 'Analyzed window in days.' ],
					'views'       => [ 'type' => 'object', 'description' => '{count} — AI bot visits to product pages.' ],
					'add_to_cart' => [ 'type' => 'object', 'description' => '{count} — AI-attributed add-to-cart events.' ],
					'purchases'   => [ 'type' => 'object', 'description' => '{count} — AI-attributed orders.' ],
					'conversion'  => [ 'type' => 'object', 'description' => '{view_to_cart_pct, cart_to_purchase_pct} conversion rates.' ],
					'revenue'     => [ 'type' => 'object', 'description' => '{amount, currency} — AI-attributed revenue.' ],
					'empty'       => [ 'type' => 'boolean', 'description' => 'True when no AI commerce activity was recorded in the window.' ],
				],
			],
			'scope'           => 'read',
			'public_eligible' => false,
			'plan'            => 'plus',
			'available'       => $woo_available,
			'permission'      => null,
			'execute'         => [ $this, 'tool_get_ai_revenue_funnel' ],
		];

		return $definitions;
	}

	// ── Tool executors ────────────────────────────────────────────────────────

	/**
	 * get_site_info executor.
	 *
	 * @return array
	 */
	public function tool_get_site_info() {
		$llms_txt_path = ABSPATH . 'llms.txt';
		return [
			'site_name'          => get_bloginfo( 'name' ),
			'site_url'           => get_site_url(),
			'plugin_version'     => LLMAGNET_AISEO_VERSION,
			'wordpress_version'  => get_bloginfo( 'version' ),
			'llms_txt_exists'    => file_exists( $llms_txt_path ),
			'llms_txt_size'      => file_exists( $llms_txt_path ) ? filesize( $llms_txt_path ) : 0,
			'woocommerce_active' => class_exists( 'WooCommerce' ),
			'mcp_endpoint'       => rest_url( 'llmagnet/mcp/v1' ),
		];
	}

	/**
	 * get_visibility_score executor.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	public function tool_get_visibility_score( array $args = [] ) {
		$range_days = isset( $args['range_days'] ) ? max( 1, (int) $args['range_days'] ) : 30;
		$vs         = $this->visibility_score();
		$score_data = $vs->get_latest_score( $range_days );
		if ( ! $score_data ) {
			$score_data = $vs->compute_visibility_score( $range_days );
		}
		return is_array( $score_data ) ? $score_data : [];
	}

	/**
	 * get_bot_traffic executor.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	public function tool_get_bot_traffic( array $args = [] ) {
		$days = isset( $args['days'] ) ? max( 1, (int) $args['days'] ) : 30;
		return [
			'total_by_bot'  => $this->analytics()->get_total_bot_visits(),
			'recent_visits' => $this->analytics()->get_recent_bot_visits( $days ),
			'days_range'    => $days,
		];
	}

	/**
	 * get_top_pages executor.
	 *
	 * @return array
	 */
	public function tool_get_top_pages() {
		$stats = $this->analytics()->get_page_stats();
		return [ 'pages' => is_array( $stats ) ? array_values( $stats ) : [] ];
	}

	/**
	 * get_bot_stats_table executor.
	 *
	 * @return array
	 */
	public function tool_get_bot_stats_table() {
		$stats = $this->analytics()->get_bot_stats_for_table();
		return [ 'bots' => is_array( $stats ) ? array_values( $stats ) : [] ];
	}

	/**
	 * get_recommendations executor.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	public function tool_get_recommendations( array $args = [] ) {
		$range_days = isset( $args['range_days'] ) ? max( 1, (int) $args['range_days'] ) : 30;

		$vs         = $this->visibility_score();
		$score_data = $vs->get_latest_score( $range_days );
		if ( ! $score_data ) {
			$score_data = $vs->compute_visibility_score( $range_days );
		}
		$total_visits = $this->analytics()->get_total_bot_visits();

		$recommendations = [];
		$score           = isset( $score_data['score'] ) ? (int) $score_data['score'] : 0;

		if ( $score < 30 ) {
			$recommendations[] = [
				'priority' => 'critical',
				'area'     => 'visibility',
				'message'  => "Your AI visibility score is very low ({$score}/100). AI crawlers are barely discovering your content.",
				'action'   => 'Regenerate your llms.txt from the LLMagnet dashboard and verify robots.txt is not blocking AI crawlers.',
			];
		} elseif ( $score < 60 ) {
			$recommendations[] = [
				'priority' => 'medium',
				'area'     => 'visibility',
				'message'  => "Your AI visibility score is {$score}/100. There is significant room for improvement.",
				'action'   => 'Review the score breakdown to identify the weakest sub-scores and address them first.',
			];
		} else {
			$recommendations[] = [
				'priority' => 'low',
				'area'     => 'visibility',
				'message'  => "Good AI visibility score: {$score}/100. Keep your llms.txt up to date as you publish new content.",
				'action'   => 'Schedule regular llms.txt regeneration (weekly recommended).',
			];
		}

		if ( ! file_exists( ABSPATH . 'llms.txt' ) ) {
			$recommendations[] = [
				'priority' => 'critical',
				'area'     => 'llms_txt',
				'message'  => 'No llms.txt file found at the site root.',
				'action'   => 'Generate your llms.txt immediately from the LLMagnet dashboard.',
			];
		}

		$total_count = 0;
		if ( is_array( $total_visits ) ) {
			foreach ( $total_visits as $data ) {
				if ( is_array( $data ) && isset( $data['count'] ) ) {
					$total_count += (int) $data['count'];
				} elseif ( is_numeric( $data ) ) {
					$total_count += (int) $data;
				}
			}
		}

		if ( 0 === $total_count ) {
			$recommendations[] = [
				'priority' => 'critical',
				'area'     => 'bot_traffic',
				'message'  => 'No AI bot visits have been detected at all.',
				'action'   => 'Verify robots.txt allows AI crawlers, the site is publicly accessible, and the plugin is tracking correctly.',
			];
		}

		$detected   = is_array( $total_visits ) ? array_keys( $total_visits ) : [];
		$watch_bots = [ 'GPTBot', 'ClaudeBot', 'PerplexityBot' ];
		foreach ( $watch_bots as $bot ) {
			if ( ! in_array( $bot, $detected, true ) ) {
				$recommendations[] = [
					'priority' => 'high',
					'area'     => 'bot_coverage',
					'message'  => "{$bot} has not been detected on your site.",
					'action'   => "Check that robots.txt allows {$bot} and that your llms.txt is publicly accessible.",
				];
			}
		}

		return [
			'score'           => $score,
			'range_days'      => $range_days,
			'recommendations' => $recommendations,
			'admin_url'       => admin_url( 'admin.php?page=llmagnet-ai-seo-optimizer' ),
		];
	}

	/**
	 * get_llms_txt_status executor.
	 *
	 * @return array
	 */
	public function tool_get_llms_txt_status() {
		$generator = $this->generator();
		$settings  = $generator->get_settings();
		$root      = $generator->get_root_path();

		$llms_path   = $root . 'llms.txt';
		$llms_exists = file_exists( $llms_path );

		$full_info = $generator->get_llms_full_info();

		$last = $generator->get_last_generated_time();

		$docs_files = glob( $root . 'llms-docs/*.md' );

		$post_types = isset( $settings['post_types'] ) && is_array( $settings['post_types'] )
			? array_values( array_diff( $settings['post_types'], [ 'attachment' ] ) )
			: [ 'post', 'page' ];

		return [
			'llms_txt'            => [
				'exists'     => $llms_exists,
				'size_bytes' => $llms_exists ? (int) filesize( $llms_path ) : 0,
				'url'        => home_url( '/llms.txt' ),
			],
			'llms_full_txt'       => [
				'exists'     => ! empty( $full_info['exists'] ),
				'size_bytes' => isset( $full_info['size'] ) ? (int) $full_info['size'] : 0,
				'post_count' => isset( $full_info['post_count'] ) ? (int) $full_info['post_count'] : 0,
			],
			'last_generated'      => $last ? gmdate( 'c', (int) $last ) : null,
			'included_post_types' => $post_types,
			'docs_count'          => is_array( $docs_files ) ? count( $docs_files ) : 0,
			'posts_in_scope'      => (int) $generator->count_posts_for_export( $settings ),
		];
	}

	/**
	 * get_schema_status executor.
	 *
	 * Reads the cached scan only — never triggers a live multi-URL crawl.
	 *
	 * @return array
	 */
	public function tool_get_schema_status() {
		$scan = get_option( Schema_Jsonld::OPTION_LAST_SCAN, null );

		$schema_settings = get_option( Schema_Jsonld::OPTION_SETTINGS, [] );
		$published_raw   = get_option( Schema_Jsonld::OPTION_PUBLISHED, '' );
		$published_types = [];
		if ( is_string( $published_raw ) && '' !== $published_raw ) {
			$decoded = json_decode( $published_raw, true );
			$published_types = $this->collect_schema_types( is_array( $decoded ) ? $decoded : [] );
		}

		$published_schema = [
			'enabled' => is_array( $schema_settings ) ? ! empty( $schema_settings['enabled'] ) : true,
			'types'   => array_values( array_unique( $published_types ) ),
		];

		if ( ! is_array( $scan ) || empty( $scan['pages'] ) ) {
			return [
				'scanned'          => false,
				'scanned_at'       => null,
				'overall_score'    => null,
				'detected_types'   => new \stdClass(),
				'pages_scanned'    => 0,
				'recommendations'  => [],
				'published_schema' => $published_schema,
				'note'             => 'No schema scan has run yet. Run a scan from the LLMagnet Schema JSON-LD page to populate this data.',
			];
		}

		$type_counts = [];
		foreach ( (array) $scan['pages'] as $page ) {
			if ( ! isset( $page['types_found'] ) || ! is_array( $page['types_found'] ) ) {
				continue;
			}
			foreach ( $page['types_found'] as $type ) {
				$type            = (string) $type;
				$type_counts[ $type ] = isset( $type_counts[ $type ] ) ? $type_counts[ $type ] + 1 : 1;
			}
		}

		return [
			'scanned'          => true,
			'scanned_at'       => isset( $scan['scanned_at'] ) ? $scan['scanned_at'] : null,
			'overall_score'    => isset( $scan['overall_score'] ) ? $scan['overall_score'] : null,
			'detected_types'   => empty( $type_counts ) ? new \stdClass() : $type_counts,
			'pages_scanned'    => count( (array) $scan['pages'] ),
			'recommendations'  => isset( $scan['recommendations'] ) && is_array( $scan['recommendations'] ) ? $scan['recommendations'] : [],
			'published_schema' => $published_schema,
		];
	}

	/**
	 * get_page_visibility executor.
	 *
	 * @param array $args    Tool arguments.
	 * @param array $context Execution context.
	 * @return array|\WP_Error
	 */
	public function tool_get_page_visibility( array $args, array $context = [] ) {
		$range_days = isset( $args['range_days'] ) ? max( 1, (int) $args['range_days'] ) : 30;

		$post = $this->resolve_post( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		// Non-exposable posts (draft/private/password/non-public type) are visible
		// only to WP-user sessions that can edit the post.
		if ( ! $this->is_post_exposable( $post ) ) {
			$auth    = isset( $context['auth'] ) ? $context['auth'] : 'anonymous';
			$user_id = isset( $context['user_id'] ) ? (int) $context['user_id'] : 0;
			$can_edit = in_array( $auth, [ 'session', 'app_password' ], true )
				&& $user_id
				&& user_can( $user_id, 'edit_post', $post->ID );
			if ( ! $can_edit ) {
				return new \WP_Error( 'post_not_found', 'Post not found.' );
			}
		}

		$score = $this->page_details()->calculate_visibility_score( $post->ID, $range_days );
		if ( is_wp_error( $score ) ) {
			return $score;
		}

		return array_merge(
			[
				'post_id' => $post->ID,
				'title'   => get_the_title( $post ),
				'url'     => get_permalink( $post ),
			],
			is_array( $score ) ? $score : []
		);
	}

	/**
	 * get_attribution_stats executor.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	public function tool_get_attribution_stats( array $args = [] ) {
		$days  = isset( $args['days'] ) ? max( 1, (int) $args['days'] ) : 30;
		$stats = $this->attribution()->get_attribution_stats( $days );
		if ( ! is_array( $stats ) ) {
			$stats = [];
		}
		$stats['days'] = $days;
		return $stats;
	}

	/**
	 * get_robots_txt_status executor.
	 *
	 * @return array
	 */
	public function tool_get_robots_txt_status() {
		$status = $this->robots_txt()->get_status();

		$physical_path = ABSPATH . 'robots.txt';
		$content       = '';
		if ( ! empty( $status['has_physical_file'] ) ) {
			$raw = file_get_contents( $physical_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local root file read.
			if ( is_string( $raw ) ) {
				$content = $raw;
			}
		} else {
			$content = (string) apply_filters( 'robots_txt', '', (bool) get_option( 'blog_public' ) );
		}

		$analysis = $this->analyze_robots_txt( $content );

		return [
			'robots_txt_url'     => $status['robots_txt_url'],
			'has_physical_file'  => $status['has_physical_file'],
			'has_llms_reference' => $status['has_llms_reference'],
			'injection_method'   => $status['injection_method'],
			'default_policy'     => $analysis['default_policy'],
			'ai_crawlers'        => $analysis['crawlers'],
		];
	}

	/**
	 * get_content_markdown executor.
	 *
	 * Content-exposure rules (mcp-ai-spec §10.4) enforced unconditionally:
	 * publish status, public post type included in llms.txt settings, no password.
	 *
	 * @param array $args Tool arguments.
	 * @return array|\WP_Error
	 */
	public function tool_get_content_markdown( array $args ) {
		$post = $this->resolve_post( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! $this->is_post_exposable( $post ) ) {
			return new \WP_Error( 'post_not_found', 'Post not found.' );
		}

		$html     = apply_filters( 'the_content', $post->post_content );
		$markdown = $this->html_to_markdown( (string) $html );

		return [
			'post_id'   => $post->ID,
			'title'     => get_the_title( $post ),
			'url'       => get_permalink( $post ),
			'type'      => $post->post_type,
			'published' => get_the_date( 'Y-m-d', $post ),
			'modified'  => get_the_modified_date( 'Y-m-d', $post ),
			'markdown'  => $markdown,
		];
	}

	/**
	 * search_content executor.
	 *
	 * Same exposure rules as get_content_markdown — applied in the query itself.
	 *
	 * @param array $args Tool arguments.
	 * @return array|\WP_Error
	 */
	public function tool_search_content( array $args ) {
		$query = isset( $args['query'] ) ? sanitize_text_field( (string) $args['query'] ) : '';
		if ( '' === $query ) {
			return new \WP_Error( 'invalid_arguments', 'The "query" argument is required.' );
		}
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 10;
		$limit = max( 1, min( 20, $limit ) );

		$wp_query = new \WP_Query(
			[
				's'                   => $query,
				'post_type'           => $this->exposable_post_types(),
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => $limit,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			]
		);

		$results = [];
		$paths   = [];
		foreach ( $wp_query->posts as $post ) {
			$permalink = get_permalink( $post );
			$path      = $this->permalink_to_path( $permalink );
			$paths[ $post->ID ] = $path;

			$results[] = [
				'post_id'       => $post->ID,
				'title'         => get_the_title( $post ),
				'url'           => $permalink,
				'type'          => $post->post_type,
				'excerpt'       => wp_strip_all_tags( get_the_excerpt( $post ) ),
				'ai_visits_30d' => 0,
			];
		}

		$visit_counts = $this->ai_visit_counts_for_paths( array_values( $paths ), 30 );
		foreach ( $results as &$row ) {
			$path = $paths[ $row['post_id'] ];
			if ( isset( $visit_counts[ $path ] ) ) {
				$row['ai_visits_30d'] = $visit_counts[ $path ];
			}
		}
		unset( $row );

		return [
			'query'   => $query,
			'results' => $results,
		];
	}

	/**
	 * get_ai_visit_trends executor.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	public function tool_get_ai_visit_trends( array $args = [] ) {
		$days = isset( $args['days'] ) ? max( 1, (int) $args['days'] ) : 30;
		$bot  = isset( $args['bot'] ) ? sanitize_text_field( (string) $args['bot'] ) : '';

		$series = $this->analytics()->get_recent_bot_visits( $days );
		if ( ! is_array( $series ) ) {
			$series = [];
		}

		if ( '' !== $bot ) {
			$needle = strtolower( $bot );
			$series = array_values(
				array_filter(
					$series,
					static function ( $row ) use ( $needle ) {
						return isset( $row['bot_name'] ) && strtolower( (string) $row['bot_name'] ) === $needle;
					}
				)
			);
		}

		return [
			'days'   => $days,
			'bot'    => '' !== $bot ? $bot : null,
			'series' => $series,
		];
	}

	// ── Write tool executors (mcp-ai-spec C Phase 2 — FC-1) ───────────────────

	/**
	 * Shared permission gate for write tools (defense in depth).
	 *
	 * Token contexts are already scope-gated by {@see MCP_Tools::check_access()}
	 * (write tools demand a write-scope token); WP-user contexts (cookie session
	 * or Application Password) must additionally hold `manage_options`
	 * (mcp-ai-spec §B2). Anonymous contexts never reach this point.
	 *
	 * @param array $context Execution context.
	 * @return true|\WP_Error
	 */
	public function write_tool_permission( array $context ) {
		$auth = isset( $context['auth'] ) ? $context['auth'] : 'anonymous';

		if ( in_array( $auth, [ 'session', 'app_password' ], true ) ) {
			$user_id = isset( $context['user_id'] ) ? (int) $context['user_id'] : 0;
			if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
				return new \WP_Error( 'permission_denied', 'Write tools require an administrator (manage_options).' );
			}
		}

		return true;
	}

	/**
	 * send_report_email permission: write gate + Freemius plan gate.
	 *
	 * Plan failures return a clean error with an upgrade hint (tool rule 5).
	 *
	 * @param array $context Execution context.
	 * @return true|\WP_Error
	 */
	public function send_report_email_permission( array $context ) {
		$write = $this->write_tool_permission( $context );
		if ( true !== $write ) {
			return $write;
		}

		if ( ! Email_Reports::can_send_scheduled_email_reports() ) {
			return new \WP_Error(
				'plan_upgrade_required',
				'Email reports require a paid LLMagnet plan. Upgrade from the LLMagnet dashboard (Reports page) to enable analytics report emails.'
			);
		}

		return true;
	}

	/**
	 * regenerate_llms_txt executor.
	 *
	 * Reuses the Generator's batched entry point (B1-1): llms.txt and
	 * llms-full.txt are written synchronously, per-post markdown docs are
	 * processed in cursor-tracked background batches.
	 *
	 * @return array|\WP_Error
	 */
	public function tool_regenerate_llms_txt() {
		$generator = $this->generator();

		$started = $generator->start_batched_generation();
		if ( ! $started ) {
			return new \WP_Error( 'generation_failed', 'Could not write llms.txt — the WordPress filesystem is not writable.' );
		}

		clearstatcache();

		$root        = $generator->get_root_path();
		$llms_path   = $root . 'llms.txt';
		$llms_exists = file_exists( $llms_path );
		$full_info   = $generator->get_llms_full_info();
		$last        = $generator->get_last_generated_time();

		return [
			'success'       => true,
			'generated_at'  => $last ? gmdate( 'c', (int) $last ) : gmdate( 'c' ),
			'llms_txt'      => [
				'exists'     => $llms_exists,
				'size_bytes' => $llms_exists ? (int) filesize( $llms_path ) : 0,
				'url'        => home_url( '/llms.txt' ),
			],
			'llms_full_txt' => [
				'exists'     => ! empty( $full_info['exists'] ),
				'size_bytes' => isset( $full_info['size'] ) ? (int) $full_info['size'] : 0,
				'post_count' => isset( $full_info['post_count'] ) ? (int) $full_info['post_count'] : 0,
			],
			'note'          => 'Per-post markdown docs in /llms-docs/ are regenerated in background batches and may take a few minutes to complete.',
		];
	}

	/**
	 * recalculate_visibility_score executor.
	 *
	 * Mirrors the `/visibility-score/calculate` REST behavior: always compute
	 * fresh, save only when the score changed.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	public function tool_recalculate_visibility_score( array $args = [] ) {
		$range_days = isset( $args['range_days'] ) ? max( 1, (int) $args['range_days'] ) : 30;

		$vs         = $this->visibility_score();
		$score_data = $vs->compute_visibility_score( $range_days );
		$last_score = $vs->get_latest_score( $range_days );

		$was_updated = ( ! $last_score || ! isset( $last_score['score'] ) || ! isset( $score_data['score'] ) || $last_score['score'] !== $score_data['score'] );
		if ( $was_updated && is_array( $score_data ) ) {
			$vs->save_score( $score_data );
		}

		return [
			'success'     => true,
			'range_days'  => $range_days,
			'was_updated' => (bool) $was_updated,
			'score_data'  => is_array( $score_data ) ? $score_data : [],
		];
	}

	/**
	 * send_report_email executor.
	 *
	 * Confirmable side effect — an email is sent on every successful call.
	 *
	 * @return array|\WP_Error
	 */
	public function tool_send_report_email() {
		// Resolve recipients the same way Email_Reports does, so the result can
		// name them (spec: "include recipient in result") and missing config
		// fails cleanly instead of silently returning false.
		$emails_string = get_option( 'llmagnet_report_email', get_bloginfo( 'admin_email' ) );
		$recipients    = [];
		foreach ( array_map( 'trim', explode( ',', (string) $emails_string ) ) as $email ) {
			$email = sanitize_email( $email );
			if ( is_email( $email ) ) {
				$recipients[] = $email;
			}
		}

		if ( empty( $recipients ) ) {
			return new \WP_Error( 'no_recipients', 'No report recipients are configured. Set a recipient email on the LLMagnet Reports page first.' );
		}

		$sent = $this->email_reports()->send_scheduled_report();
		if ( ! $sent ) {
			return new \WP_Error( 'send_failed', 'The report email could not be sent to all recipients. Check the site\'s mail configuration.' );
		}

		return [
			'success'    => true,
			'recipients' => $recipients,
			'template'   => (string) get_option( 'llmagnet_report_template', 'classic' ),
			'sent_at'    => gmdate( 'c' ),
		];
	}

	// ── WooCommerce tool executors (mcp-ai-spec Phase 2.5 — FC-3) ─────────────

	/**
	 * Availability gate for the WooCommerce tools: Woo active AND the plan
	 * includes commerce analytics (trial / Plus / Enterprise — the same tiers
	 * the React UI's hasCommerceAccess() unlocks).
	 *
	 * @return bool
	 */
	public function woo_tools_available() {
		if ( ! class_exists( __NAMESPACE__ . '\\WooCommerce' ) || ! WooCommerce::is_active() ) {
			return false;
		}
		if ( ! function_exists( 'lltg_fs' ) ) {
			return false;
		}
		$fs = lltg_fs();
		if ( ! $fs ) {
			return false;
		}
		return (bool) ( $fs->is_trial() || $fs->is_plan( 'plus' ) || $fs->is_plan( 'enterprise' ) );
	}

	/**
	 * get_product_ai_stats executor.
	 *
	 * Reuses Product_Analytics' top-products data source (`/top-products`)
	 * through a synthetic REST request — one implementation, two surfaces.
	 *
	 * @param array $args Tool arguments.
	 * @return array|\WP_Error
	 */
	public function tool_get_product_ai_stats( array $args = [] ) {
		$days  = isset( $args['days'] ) ? max( 1, (int) $args['days'] ) : 30;
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 10;
		$limit = max( 1, min( 20, $limit ) );

		$request = new \WP_REST_Request( 'GET', '/llm-analytics/v1/top-products' );
		$request->set_param( 'days', $days );
		$request->set_param( 'limit', $limit );

		$response = $this->product_analytics()->get_top_products( $request );
		$data     = $response instanceof \WP_REST_Response ? $response->get_data() : [];
		if ( ! is_array( $data ) ) {
			$data = [];
		}

		return [
			'days'     => $days,
			'products' => isset( $data['products'] ) && is_array( $data['products'] ) ? $data['products'] : [],
			'has_data' => ! empty( $data['has_data'] ),
		];
	}

	/**
	 * get_ai_revenue_funnel executor.
	 *
	 * Reuses the existing `/overview/ai-revenue-funnel` data source. The
	 * `revenue.formatted` key is stripped: it contains wc_price() HTML and
	 * tool output must be HTML-free (mcp-ai-spec tool rule 3).
	 *
	 * @param array $args Tool arguments.
	 * @return array|\WP_Error
	 */
	public function tool_get_ai_revenue_funnel( array $args = [] ) {
		$range_days = isset( $args['range_days'] ) ? max( 1, (int) $args['range_days'] ) : 30;

		$request = new \WP_REST_Request( 'GET', '/llm-analytics/v1/overview/ai-revenue-funnel' );
		$request->set_param( 'range_days', $range_days );

		$response = $this->product_analytics()->get_ai_revenue_funnel( $request );
		$data     = $response instanceof \WP_REST_Response ? $response->get_data() : [];
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'funnel_unavailable', 'AI revenue funnel data is not available.' );
		}

		if ( isset( $data['error'] ) ) {
			return new \WP_Error( 'funnel_unavailable', (string) $data['error'] );
		}

		if ( isset( $data['revenue'] ) && is_array( $data['revenue'] ) ) {
			unset( $data['revenue']['formatted'] );
		}

		return $data;
	}

	// ── Content-exposure helpers (shared with future WebMCP consumers) ────────

	/**
	 * Post types that content tools may serve: public post types that are also
	 * included in the llms.txt export settings (minus attachments).
	 *
	 * @return string[]
	 */
	public function exposable_post_types() {
		$settings   = $this->generator()->get_settings();
		$configured = isset( $settings['post_types'] ) && is_array( $settings['post_types'] )
			? $settings['post_types']
			: [ 'post', 'page' ];

		$public = get_post_types( [ 'public' => true ] );

		$allowed = array_values( array_intersect( $configured, array_keys( $public ) ) );
		$allowed = array_values( array_diff( $allowed, [ 'attachment' ] ) );

		return ! empty( $allowed ) ? $allowed : [ 'post', 'page' ];
	}

	/**
	 * Whether a post may be exposed through public content tools:
	 * published, no password, public post type included in llms.txt settings.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	public function is_post_exposable( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		if ( '' !== $post->post_password ) {
			return false;
		}
		return in_array( $post->post_type, $this->exposable_post_types(), true );
	}

	// ── Internals ─────────────────────────────────────────────────────────────

	/**
	 * Resolve a post from post_id or url arguments.
	 *
	 * @param array $args Tool arguments.
	 * @return \WP_Post|\WP_Error
	 */
	private function resolve_post( array $args ) {
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;

		if ( ! $post_id && ! empty( $args['url'] ) && is_string( $args['url'] ) ) {
			$post_id = url_to_postid( esc_url_raw( $args['url'] ) );
		}

		if ( ! $post_id ) {
			return new \WP_Error( 'invalid_arguments', 'Provide a valid "post_id" or "url" argument.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', 'Post not found.' );
		}

		return $post;
	}

	/**
	 * Convert a permalink to the path form stored in the bot visits table.
	 *
	 * @param string $permalink Permalink.
	 * @return string
	 */
	private function permalink_to_path( $permalink ) {
		$path = str_replace( home_url(), '', (string) $permalink );
		$path = strtok( $path, '?' );
		return is_string( $path ) && '' !== $path ? $path : '/';
	}

	/**
	 * AI bot visit counts per page path over a window.
	 *
	 * @param string[] $paths Page paths.
	 * @param int      $days  Window in days.
	 * @return array<string, int> Counts keyed by path.
	 */
	private function ai_visit_counts_for_paths( array $paths, $days ) {
		global $wpdb;

		$paths = array_values( array_unique( array_filter( $paths ) ) );
		if ( empty( $paths ) ) {
			return [];
		}

		$table        = $wpdb->prefix . 'llm_bot_visits';
		$placeholders = implode( ',', array_fill( 0, count( $paths ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + placeholder list built safely above.
		$sql = $wpdb->prepare(
			"SELECT page_path, COUNT(*) AS visits FROM {$table}
			WHERE page_path IN ({$placeholders}) AND visit_time >= DATE_SUB(NOW(), INTERVAL %d DAY)
			GROUP BY page_path",
			array_merge( $paths, [ (int) $days ] )
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.

		$counts = [];
		foreach ( (array) $rows as $row ) {
			$counts[ $row['page_path'] ] = (int) $row['visits'];
		}
		return $counts;
	}

	/**
	 * Collect schema.org @type values from decoded JSON-LD (handles @graph and lists).
	 *
	 * @param array $data Decoded JSON-LD.
	 * @return string[]
	 */
	private function collect_schema_types( array $data ) {
		$types = [];

		if ( isset( $data['@type'] ) ) {
			foreach ( (array) $data['@type'] as $t ) {
				if ( is_string( $t ) ) {
					$types[] = $t;
				}
			}
		}

		$children = [];
		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			$children = $data['@graph'];
		} elseif ( array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			$children = $data; // Plain list of schema objects.
		}

		foreach ( $children as $child ) {
			if ( is_array( $child ) ) {
				$types = array_merge( $types, $this->collect_schema_types( $child ) );
			}
		}

		return $types;
	}

	/**
	 * Known AI crawler user-agent tokens for robots.txt analysis.
	 *
	 * @return string[]
	 */
	private function ai_crawler_user_agents() {
		$crawlers = [
			'GPTBot',
			'ChatGPT-User',
			'OAI-SearchBot',
			'ClaudeBot',
			'Claude-Web',
			'anthropic-ai',
			'PerplexityBot',
			'Perplexity-User',
			'Google-Extended',
			'CCBot',
			'Bytespider',
			'meta-externalagent',
			'Applebot-Extended',
			'Amazonbot',
			'cohere-ai',
		];

		/**
		 * Filter the AI crawler user-agent tokens checked against robots.txt.
		 *
		 * @param string[] $crawlers User-agent tokens.
		 */
		return apply_filters( 'llmagnet_mcp_robots_ai_crawlers', $crawlers );
	}

	/**
	 * Parse robots.txt content and classify known AI crawlers as allowed/blocked.
	 *
	 * @param string $content robots.txt content.
	 * @return array { default_policy: string, crawlers: array[] }
	 */
	private function analyze_robots_txt( $content ) {
		// Parse into user-agent groups: [ 'agent (lowercase)' => [ 'disallow' => [], 'allow' => [] ] ].
		$groups        = [];
		$current_agents = [];
		$last_was_agent = false;

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $content ) as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field = strtolower( $field );

			if ( 'user-agent' === $field ) {
				if ( ! $last_was_agent ) {
					$current_agents = [];
				}
				$agent = strtolower( $value );
				$current_agents[] = $agent;
				if ( ! isset( $groups[ $agent ] ) ) {
					$groups[ $agent ] = [ 'disallow' => [], 'allow' => [] ];
				}
				$last_was_agent = true;
				continue;
			}

			$last_was_agent = false;
			if ( 'disallow' === $field || 'allow' === $field ) {
				foreach ( $current_agents as $agent ) {
					$groups[ $agent ][ $field ][] = $value;
				}
			}
		}

		$classify = static function ( $rules ) {
			if ( in_array( '/', $rules['disallow'], true ) ) {
				return in_array( '/', $rules['allow'], true ) ? 'allowed' : 'blocked';
			}
			$has_disallow = count( array_filter( $rules['disallow'] ) ) > 0;
			return $has_disallow ? 'partial' : 'allowed';
		};

		$default_policy = isset( $groups['*'] ) ? $classify( $groups['*'] ) : 'allowed';

		$crawlers = [];
		foreach ( $this->ai_crawler_user_agents() as $crawler ) {
			$key = strtolower( $crawler );
			if ( isset( $groups[ $key ] ) ) {
				$crawlers[] = [
					'crawler'       => $crawler,
					'status'        => $classify( $groups[ $key ] ),
					'matched_group' => $crawler,
				];
			} else {
				$crawlers[] = [
					'crawler'       => $crawler,
					'status'        => $default_policy,
					'matched_group' => '*',
				];
			}
		}

		return [
			'default_policy' => $default_policy,
			'crawlers'       => $crawlers,
		];
	}

	/**
	 * HTML → markdown conversion for content tools.
	 *
	 * Delegates to the shared `Markdown_Converter` (agent-readiness F5,
	 * dependency D4) — the shared implementation IS the former MCP one
	 * (plus optional shortcode handling), so output is unchanged.
	 *
	 * @param string $html Rendered post HTML.
	 * @return string
	 */
	private function html_to_markdown( $html ) {
		if ( ! class_exists( __NAMESPACE__ . '\\Markdown_Converter' ) ) {
			require_once __DIR__ . '/class-markdown-converter.php';
		}
		// false: MCP passes `the_content`-filtered HTML (shortcodes already done).
		return Markdown_Converter::convert( (string) $html, false );
	}

	// ── Lazy dependencies ─────────────────────────────────────────────────────

	/** @return Analytics */
	private function analytics() {
		if ( ! $this->analytics ) {
			$this->analytics = new Analytics();
		}
		return $this->analytics;
	}

	/** @return Visibility_Score */
	private function visibility_score() {
		if ( ! $this->visibility_score ) {
			$this->visibility_score = new Visibility_Score();
		}
		return $this->visibility_score;
	}

	/** @return Generator */
	private function generator() {
		if ( ! $this->generator ) {
			$this->generator = new Generator();
		}
		return $this->generator;
	}

	/** @return Robots_Txt */
	private function robots_txt() {
		if ( ! $this->robots_txt ) {
			$this->robots_txt = new Robots_Txt();
		}
		return $this->robots_txt;
	}

	/** @return Page_Details */
	private function page_details() {
		if ( ! $this->page_details ) {
			$this->page_details = new Page_Details();
		}
		return $this->page_details;
	}

	/** @return Attribution */
	private function attribution() {
		if ( ! $this->attribution ) {
			$this->attribution = new Attribution();
		}
		return $this->attribution;
	}

	/**
	 * Email reports machinery (FC-1 `send_report_email`).
	 *
	 * Null-safe resolution order: injected instance → Main's
	 * `$llmagnet_email_reports` global (set in `init_components()`) → fresh
	 * instance wired to the registry's Analytics. Works with or without the
	 * optional class-main injection (see docs/handoffs/fc-main-snippet.md).
	 *
	 * @return Email_Reports
	 */
	private function email_reports() {
		if ( $this->email_reports instanceof Email_Reports ) {
			return $this->email_reports;
		}

		global $llmagnet_email_reports;
		if ( $llmagnet_email_reports instanceof Email_Reports ) {
			$this->email_reports = $llmagnet_email_reports;
			return $this->email_reports;
		}

		if ( ! class_exists( __NAMESPACE__ . '\\Email_Reports' ) ) {
			require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-email-reports.php';
		}
		$this->email_reports = new Email_Reports( $this->analytics() );
		return $this->email_reports;
	}

	/**
	 * Product analytics data source (FC-3 Woo tools). No-arg constructor;
	 * only its REST-callback data methods are reused — no hooks registered.
	 *
	 * @return Product_Analytics
	 */
	private function product_analytics() {
		if ( ! $this->product_analytics instanceof Product_Analytics ) {
			if ( ! class_exists( __NAMESPACE__ . '\\Product_Analytics' ) ) {
				require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-product-analytics.php';
			}
			$this->product_analytics = new Product_Analytics();
		}
		return $this->product_analytics;
	}
}
