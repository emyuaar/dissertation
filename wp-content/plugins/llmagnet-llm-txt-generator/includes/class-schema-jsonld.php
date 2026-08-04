<?php
/**
 * Schema.org JSON-LD: scan, validation, recommendations, generate, publish.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON-LD Schema services and REST API.
 */
class Schema_Jsonld {

	public const OPTION_SETTINGS     = 'llmagnet_schema_settings';
	public const OPTION_PUBLISHED    = 'llmagnet_schema_published_ld';
	public const OPTION_DRAFT        = 'llmagnet_schema_draft_ld';
	public const OPTION_WIZARD_FORM  = 'llmagnet_schema_wizard_form';
	public const OPTION_LAST_SCAN    = 'llmagnet_schema_last_scan';
	public const OPTION_SCAN_HISTORY = 'llmagnet_schema_scan_history';
	public const OPTION_SCAN_JOB     = 'llmagnet_schema_scan_job';

	/** Bump when scan URL set or row shape changes (invalidates cached scans). */
	private const SCAN_VERSION = 4;

	private const SCAN_BATCH_SIZE = 6;

	private const BATCH_EVENT = 'llmagnet_schema_scan_batch';

	/**
	 * Schema types considered equivalent when checking coverage.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const TYPE_ALIASES = [
		'Article'          => [ 'Article', 'BlogPosting', 'NewsArticle' ],
		'WebSite'          => [ 'WebSite' ],
		'Organization'     => [ 'Organization', 'Corporation' ],
		'LocalBusiness'    => [ 'LocalBusiness', 'Store', 'Restaurant' ],
		'Product'          => [ 'Product' ],
		'FAQPage'          => [ 'FAQPage' ],
		'AboutPage'        => [ 'AboutPage' ],
		'Service'          => [ 'Service' ],
		'Event'            => [ 'Event' ],
		'VideoObject'      => [ 'VideoObject' ],
		'BreadcrumbList'   => [ 'BreadcrumbList' ],
		'Review'           => [ 'Review' ],
		'AggregateRating'  => [ 'AggregateRating' ],
	];

	/**
	 * Bootstrap hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'wp_head', [ $this, 'output_published_jsonld' ], 99 );
		add_action( self::BATCH_EVENT, [ $this, 'run_scan_batch' ] );
	}

	/**
	 * Output one merged JSON-LD block: site graph + Elementor page schema.
	 *
	 * Skips front-end output when a known SEO plugin already owns JSON-LD
	 * (avoid duplicating Yoast / Rank Math / etc.).
	 *
	 * @return void
	 */
	public function output_published_jsonld(): void {
		if ( is_admin() ) {
			return;
		}

		if ( Seo_Plugin_Detector::owns_json_ld() ) {
			return;
		}

		$nodes = $this->collect_output_graph_nodes();
		if ( empty( $nodes ) ) {
			return;
		}

		$graph = [
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $nodes ),
		];

		$json = wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		if ( ! is_string( $json ) ) {
			return;
		}

		echo "\n<!-- LLMagnet Schema JSON-LD -->\n<script type=\"application/ld+json\">\n";
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD is machine-readable; sanitized via json_encode from structured array.
		echo $json;
		echo "\n</script>\n";
	}

	/**
	 * Build the entity list for the current request.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_output_graph_nodes(): array {
		$nodes = [];

		if ( $this->should_emit_site_graph() ) {
			$nodes = $this->merge_schema_nodes( $nodes, $this->get_published_site_nodes() );
		}

		if ( Elementor_Integration::is_active() ) {
			$post_id = get_the_ID();
			if ( $post_id ) {
				$nodes = $this->merge_schema_nodes(
					$nodes,
					Elementor_Schema::get_page_schema_entities( (int) $post_id )
				);
			}
		}

		/**
		 * Filter graph nodes before the unified JSON-LD block is printed.
		 *
		 * @param array<int, array<string, mixed>> $nodes Schema.org entities.
		 */
		$nodes = apply_filters( 'llmagnet_schema_output_graph_nodes', $nodes );

		return is_array( $nodes ) ? $nodes : [];
	}

	/**
	 * Whether the site-wide wizard graph should be included in output.
	 *
	 * @return bool
	 */
	private function should_emit_site_graph(): bool {
		$settings = $this->get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return false;
		}
		$published = get_option( self::OPTION_PUBLISHED, '' );
		return is_string( $published ) && '' !== $published;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function get_published_site_nodes(): array {
		$published = get_option( self::OPTION_PUBLISHED, '' );
		if ( ! is_string( $published ) || '' === $published ) {
			return [];
		}
		$data = json_decode( $published, true );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return [];
		}
		return self::data_to_entities( $data );
	}

	/**
	 * Normalize a JSON-LD document to a flat list of entities.
	 *
	 * @param array<string, mixed> $data Parsed JSON-LD.
	 * @return array<int, array<string, mixed>>
	 */
	public static function data_to_entities( array $data ): array {
		$entities = [];
		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			foreach ( $data['@graph'] as $node ) {
				if ( is_array( $node ) ) {
					$entities[] = $node;
				}
			}
			return $entities;
		}
		if ( isset( $data['@type'] ) ) {
			return [ $data ];
		}
		if ( isset( $data[0] ) && is_array( $data[0] ) ) {
			foreach ( $data as $node ) {
				if ( is_array( $node ) && isset( $node['@type'] ) ) {
					$entities[] = $node;
				}
			}
		}
		return $entities;
	}

	/**
	 * Merge schema nodes without duplicating site-wide or @id entities.
	 *
	 * @param array<int, array<string, mixed>> $base     Existing nodes.
	 * @param array<int, array<string, mixed>> $incoming Nodes to merge in.
	 * @return array<int, array<string, mixed>>
	 */
	private function merge_schema_nodes( array $base, array $incoming ): array {
		if ( empty( $incoming ) ) {
			return $base;
		}
		if ( empty( $base ) ) {
			return $incoming;
		}

		$result        = [];
		$index_by_id   = [];
		$index_by_type = [];

		$site_wide_types = [
			'organization',
			'localbusiness',
			'website',
			'corporation',
		];

		$add = function ( array $node ) use ( &$result, &$index_by_id, &$index_by_type, $site_wide_types ): void {
			if ( empty( $node['@type'] ) ) {
				$result[] = $node;
				return;
			}

			$id = isset( $node['@id'] ) && is_string( $node['@id'] ) ? trim( $node['@id'] ) : '';
			if ( '' !== $id ) {
				if ( isset( $index_by_id[ $id ] ) ) {
					$result[ $index_by_id[ $id ] ] = $this->deep_merge_schema_entity(
						$result[ $index_by_id[ $id ] ],
						$node
					);
				} else {
					$index_by_id[ $id ] = count( $result );
					$result[]           = $node;
				}
				return;
			}

			$type     = $node['@type'];
			$type_key = strtolower( is_array( $type ) ? (string) reset( $type ) : (string) $type );

			if ( isset( $index_by_type[ $type_key ] ) ) {
				$idx = $index_by_type[ $type_key ];
				if ( in_array( $type_key, $site_wide_types, true ) ) {
					$result[ $idx ] = $this->deep_merge_schema_entity( $result[ $idx ], $node );
				} else {
					$result[ $idx ] = $this->deep_merge_schema_entity( $node, $result[ $idx ] );
				}
				return;
			}

			$index_by_type[ $type_key ] = count( $result );
			$result[]                   = $node;
		};

		foreach ( $base as $node ) {
			if ( is_array( $node ) ) {
				$add( $node );
			}
		}
		foreach ( $incoming as $node ) {
			if ( is_array( $node ) ) {
				$add( $node );
			}
		}

		return array_values( $result );
	}

	/**
	 * @param array<string, mixed> $base     Base entity.
	 * @param array<string, mixed> $incoming Incoming fields (non-empty wins).
	 * @return array<string, mixed>
	 */
	private function deep_merge_schema_entity( array $base, array $incoming ): array {
		foreach ( $incoming as $key => $value ) {
			if ( null === $value || '' === $value || [] === $value ) {
				continue;
			}
			if (
				is_array( $value )
				&& isset( $base[ $key ] )
				&& is_array( $base[ $key ] )
				&& ! isset( $value['@type'] )
			) {
				$base[ $key ] = $this->deep_merge_schema_entity( $base[ $key ], $value );
				continue;
			}
			$base[ $key ] = $value;
		}
		return $base;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_settings(): array {
		$defaults = [
			'enabled' => true,
		];
		$stored = get_option( self::OPTION_SETTINGS, [] );
		return is_array( $stored ) ? array_merge( $defaults, $stored ) : $defaults;
	}

	/**
	 * Plan: Pro, Plus, Enterprise, or trial — can generate/publish non-commerce schema.
	 */
	public static function can_use_schema_tools(): bool {
		if ( function_exists( 'lltg_fs' ) ) {
			$fs = lltg_fs();
			return (bool) ( $fs->can_use_premium_code() || $fs->is_trial() );
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Plus / Enterprise / trial — commerce-related schema pack.
	 */
	public static function can_use_commerce_schema(): bool {
		if ( function_exists( 'lltg_fs' ) ) {
			$fs = lltg_fs();
			if ( $fs->is_trial() ) {
				return true;
			}
			return $fs->is_plan( 'plus' ) || $fs->is_plan( 'enterprise' );
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'llm-analytics/v1',
			'/schema/scan',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_scan' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => [
					'refresh' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);

		register_rest_route(
			'llm-analytics/v1',
			'/schema/recommendations',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_recommendations' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);

		register_rest_route(
			'llm-analytics/v1',
			'/schema/preview',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_preview' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);

		register_rest_route(
			'llm-analytics/v1',
			'/schema/history',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_history' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);

		register_rest_route(
			'llm-analytics/v1',
			'/schema/generate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_generate' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);

		register_rest_route(
			'llm-analytics/v1',
			'/schema/publish',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_publish' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_scan( \WP_REST_Request $request ): \WP_REST_Response {
		$raw     = $request->get_param( 'refresh' );
		$refresh = filter_var( $raw, FILTER_VALIDATE_BOOLEAN ) || '1' === (string) $raw || 'true' === (string) $raw;

		$job = get_option( self::OPTION_SCAN_JOB, null );
		if ( $refresh && is_array( $job ) && 'running' === ( $job['status'] ?? '' ) ) {
			wp_clear_scheduled_hook( self::BATCH_EVENT );
			delete_option( self::OPTION_SCAN_JOB );
			$job = null;
		}

		if ( is_array( $job ) && 'running' === ( $job['status'] ?? '' ) ) {
			if ( ! wp_next_scheduled( self::BATCH_EVENT ) ) {
				wp_schedule_single_event( time() + 2, self::BATCH_EVENT );
			}
			return new \WP_REST_Response( $this->build_scan_response_from_job( $job ), 200 );
		}

		if ( ! $refresh ) {
			$cached = get_option( self::OPTION_LAST_SCAN, null );
			if ( is_array( $cached ) && ! empty( $cached['pages'] ) && $this->is_scan_cache_current( $cached ) ) {
				$cached['pages']       = array_map( [ $this, 'enrich_scan_page_row' ], $cached['pages'] );
				$cached['scan_status'] = 'complete';
				return new \WP_REST_Response( $cached, 200 );
			}
		}

		$this->start_background_scan();
		$job = get_option( self::OPTION_SCAN_JOB, [] );
		return new \WP_REST_Response(
			$this->build_scan_response_from_job( is_array( $job ) ? $job : [] ),
			200
		);
	}

	/**
	 * @return \WP_REST_Response
	 */
	public function rest_recommendations(): \WP_REST_Response {
		$scan = get_option( self::OPTION_LAST_SCAN, null );
		if ( ! is_array( $scan ) || empty( $scan['pages'] ) ) {
			$job = get_option( self::OPTION_SCAN_JOB, null );
			if ( is_array( $job ) && ! empty( $job['pages'] ) ) {
				$scan = $this->build_scan_response_from_job( $job );
			} else {
				$this->start_background_scan();
				$job  = get_option( self::OPTION_SCAN_JOB, [] );
				$scan = $this->build_scan_response_from_job( is_array( $job ) ? $job : [] );
			}
		}
		return new \WP_REST_Response(
			[
				'recommendations' => isset( $scan['recommendations'] ) ? $scan['recommendations'] : [],
				'score'           => isset( $scan['overall_score'] ) ? $scan['overall_score'] : null,
				'scannedAt'       => isset( $scan['scanned_at'] ) ? $scan['scanned_at'] : null,
			],
			200
		);
	}

	/**
	 * @return \WP_REST_Response
	 */
	public function rest_preview(): \WP_REST_Response {
		$published = get_option( self::OPTION_PUBLISHED, '' );
		$decoded   = is_string( $published ) && '' !== $published ? json_decode( $published, true ) : null;
		$draft_raw = get_option( self::OPTION_DRAFT, '' );
		$draft     = is_string( $draft_raw ) && '' !== $draft_raw ? json_decode( $draft_raw, true ) : null;
		$settings  = $this->get_settings();
		return new \WP_REST_Response(
			[
				'published'   => is_array( $decoded ) ? $decoded : null,
				'draft'       => is_array( $draft ) ? $draft : null,
				'savedWizard' => self::get_saved_wizard_form(),
				'enabled'     => ! empty( $settings['enabled'] ),
				'canFix'      => self::can_use_schema_tools(),
				'canStore'    => self::can_use_commerce_schema(),
			],
			200
		);
	}

	/**
	 * @return \WP_REST_Response
	 */
	public function rest_history(): \WP_REST_Response {
		$hist = get_option( self::OPTION_SCAN_HISTORY, [] );
		if ( ! is_array( $hist ) ) {
			$hist = [];
		}
		return new \WP_REST_Response( [ 'items' => array_slice( $hist, 0, 10 ) ], 200 );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_generate( \WP_REST_Request $request ) {
		if ( ! self::can_use_schema_tools() ) {
			return new \WP_Error(
				'schema_plan',
				__( 'Schema generation is available on Pro, Plus, and Enterprise.', 'llmagnet-llm-txt-generator' ),
				[ 'status' => 403 ]
			);
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = [];
		}
		$graph = $this->build_graph_from_wizard( $body );
		if ( is_wp_error( $graph ) ) {
			return $graph;
		}
		$this->save_wizard_form( $body );
		$this->save_draft_graph( $graph );
		$json = wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		return new \WP_REST_Response(
			[
				'graph'     => $graph,
				'json'      => is_string( $json ) ? $json : '',
				'canStore'  => self::can_use_commerce_schema(),
				'published' => false,
				'message'   => __( 'Settings saved. Preview updated.', 'llmagnet-llm-txt-generator' ),
			],
			200
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_publish( \WP_REST_Request $request ) {
		if ( ! self::can_use_schema_tools() ) {
			return new \WP_Error(
				'schema_plan',
				__( 'Publishing schema is available on Pro, Plus, and Enterprise.', 'llmagnet-llm-txt-generator' ),
				[ 'status' => 403 ]
			);
		}
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = [];
		}
		$graph = $this->build_graph_from_wizard( $body );
		if ( is_wp_error( $graph ) ) {
			return $graph;
		}
		$this->save_wizard_form( $body );
		$this->save_draft_graph( $graph );
		$encoded = wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		update_option( self::OPTION_PUBLISHED, is_string( $encoded ) ? $encoded : '', false );
		$settings = $this->get_settings();
		$settings['enabled'] = true;
		update_option( self::OPTION_SETTINGS, $settings, false );
		return new \WP_REST_Response(
			[
				'success'   => true,
				'graph'     => $graph,
				'enabled'   => $settings['enabled'],
				'message'   => __( 'Schema saved and will appear in your site HTML.', 'llmagnet-llm-txt-generator' ),
			],
			200
		);
	}

	/**
	 * Run scan across key URLs.
	 *
	 * @return array<string, mixed>
	 */
	private function run_full_scan(): array {
		$urls   = $this->collect_sample_urls();
		$pages  = [];
		$types_seen = [];

		foreach ( $urls as $item ) {
			$row = $this->scan_url( $item['url'], $item );
			$pages[] = $row;
			foreach ( $row['types_found'] as $t ) {
				$types_seen[ $t ] = true;
			}
		}

		$recommendations = $this->compile_recommendations( $pages, $types_seen );
		$score           = $this->compute_overall_score( $pages );

		return [
			'scan_version'     => self::SCAN_VERSION,
			'scanned_at'       => current_time( 'mysql', true ),
			'overall_score'    => $score,
			'pages'            => $pages,
			'page_count'       => count( $pages ),
			'recommendations'  => $recommendations,
			'home_url'         => home_url( '/' ),
			'wooCommerceActive' => class_exists( 'WooCommerce' ),
		];
	}

	/**
	 * Whether a stored scan matches the current scanner version/shape.
	 *
	 * @param array<string, mixed> $cached Cached scan.
	 * @return bool
	 */
	private function is_scan_cache_current( array $cached ): bool {
		return (int) ( $cached['scan_version'] ?? 0 ) >= self::SCAN_VERSION;
	}

	/**
	 * Backfill title, post_type, and post_id for legacy or partial scan rows.
	 *
	 * @param array<string, mixed> $row Scan row.
	 * @return array<string, mixed>
	 */
	private function enrich_scan_page_row( array $row ): array {
		if ( ! isset( $row['missing_entities'] ) || ! is_array( $row['missing_entities'] ) ) {
			$row['missing_entities'] = [];
		}

		$url = isset( $row['url'] ) ? (string) $row['url'] : '';

		if ( ! empty( $row['post_type'] ) && 'unknown' !== $row['post_type'] ) {
			if ( empty( $row['title'] ) && ! empty( $row['label'] ) ) {
				$row['title'] = (string) $row['label'];
			}
			return $row;
		}

		if ( '' !== $url && untrailingslashit( $url ) === untrailingslashit( home_url( '/' ) ) ) {
			$row['post_type'] = 'home';
			$row['post_id']   = 0;
			$row['title']     = ! empty( $row['title'] )
				? (string) $row['title']
				: ( get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : __( 'Homepage', 'llmagnet-llm-txt-generator' ) );
			return $row;
		}

		if ( '' !== $url ) {
			$post_id = url_to_postid( $url );
			if ( $post_id > 0 ) {
				$post = get_post( $post_id );
				if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
					$row['post_id']   = $post_id;
					$row['post_type'] = $post->post_type;
					$row['title']     = get_the_title( $post );
					return $row;
				}
			}
		}

		$label = strtolower( (string) ( $row['label'] ?? '' ) );
		if ( str_contains( $label, 'product' ) ) {
			$row['post_type'] = 'product';
		} elseif ( str_contains( $label, 'recent post' ) || preg_match( '/\bpost\b/', $label ) ) {
			$row['post_type'] = 'post';
		} elseif ( str_contains( $label, 'homepage' ) ) {
			$row['post_type'] = 'home';
		} elseif ( str_contains( $label, 'page' ) ) {
			$row['post_type'] = 'page';
		}

		if ( empty( $row['title'] ) && ! empty( $row['label'] ) ) {
			$row['title'] = (string) $row['label'];
		}

		return $row;
	}

	/**
	 * @return array<int, array{url:string,label:string,title:string,post_type:string,post_id:int}>
	 */
	private function collect_sample_urls(): array {
		$out  = [];
		$seen = [];

		$add = function ( array $item ) use ( &$out, &$seen ): void {
			$url = isset( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : '';
			if ( '' === $url || isset( $seen[ $url ] ) ) {
				return;
			}
			$seen[ $url ] = true;
			$out[]        = [
				'url'       => $url,
				'label'     => isset( $item['label'] ) ? (string) $item['label'] : $url,
				'title'     => isset( $item['title'] ) ? (string) $item['title'] : ( isset( $item['label'] ) ? (string) $item['label'] : $url ),
				'post_type' => isset( $item['post_type'] ) ? sanitize_key( (string) $item['post_type'] ) : 'unknown',
				'post_id'   => isset( $item['post_id'] ) ? (int) $item['post_id'] : 0,
			];
		};

		$add(
			[
				'url'       => home_url( '/' ),
				'label'     => __( 'Homepage', 'llmagnet-llm-txt-generator' ),
				'title'     => get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : __( 'Homepage', 'llmagnet-llm-txt-generator' ),
				'post_type' => 'home',
				'post_id'   => 0,
			]
		);

		$pages = get_posts(
			[
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);
		foreach ( $pages as $page ) {
			$add(
				[
					'url'       => get_permalink( $page ),
					'label'     => get_the_title( $page ),
					'title'     => get_the_title( $page ),
					'post_type' => 'page',
					'post_id'   => (int) $page->ID,
				]
			);
		}

		$posts = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			]
		);
		foreach ( $posts as $post ) {
			$add(
				[
					'url'       => get_permalink( $post ),
					'label'     => get_the_title( $post ),
					'title'     => get_the_title( $post ),
					'post_type' => 'post',
					'post_id'   => (int) $post->ID,
				]
			);
		}

		if ( class_exists( 'WooCommerce' ) ) {
			$products = get_posts(
				[
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 50,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				]
			);
			foreach ( $products as $product ) {
				$add(
					[
						'url'       => get_permalink( $product ),
						'label'     => get_the_title( $product ),
						'title'     => get_the_title( $product ),
						'post_type' => 'product',
						'post_id'   => (int) $product->ID,
					]
				);
			}
		}

		return $out;
	}

	/**
	 * @param string               $url  URL.
	 * @param array<string, mixed> $meta Scan target metadata.
	 * @return array<string, mixed>
	 */
	private function scan_url( string $url, array $meta ): array {
		$label     = isset( $meta['label'] ) ? (string) $meta['label'] : $url;
		$title     = isset( $meta['title'] ) ? (string) $meta['title'] : $label;
		$post_type = isset( $meta['post_type'] ) ? sanitize_key( (string) $meta['post_type'] ) : 'unknown';
		$post_id   = isset( $meta['post_id'] ) ? (int) $meta['post_id'] : 0;
		$base      = [
			'title'     => $title,
			'post_type' => $post_type,
			'post_id'   => $post_id,
		];
		$response = wp_remote_get(
			$url,
			[
				'timeout'    => 20,
				'user-agent' => 'LLMagnetSchemaScanner/1.0; ' . home_url( '/' ),
				'sslverify'  => true,
			]
		);

		if ( is_wp_error( $response ) ) {
			return array_merge(
				$base,
				[
					'label'        => $label,
					'url'          => $url,
					'ok'           => false,
					'error'        => $response->get_error_message(),
					'blocks'       => [],
					'types_found'  => [],
					'issues'       => [
						[
							'severity' => 'error',
							'code'     => 'fetch_failed',
							'message'  => __( 'Could not load this page to read structured data.', 'llmagnet-llm-txt-generator' ),
						],
					],
					'block_count'  => 0,
					'missing_entities' => $this->detect_missing_entities( '', $meta, [] ),
				]
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( 200 !== (int) $code || ! is_string( $body ) ) {
			return array_merge(
				$base,
				[
					'label'       => $label,
					'url'         => $url,
					'ok'          => false,
					'error'       => sprintf( /* translators: %d HTTP status */ __( 'Unexpected response (%d)', 'llmagnet-llm-txt-generator' ), (int) $code ),
					'blocks'      => [],
					'types_found' => [],
					'issues'      => [],
					'block_count' => 0,
					'missing_entities' => $this->detect_missing_entities( '', $meta, [] ),
				]
			);
		}

		$blocks = $this->extract_jsonld_blocks( $body );
		$all_issues = [];
		$types      = [];

		foreach ( $blocks as $idx => $block ) {
			$parsed = $this->parse_jsonld_block( $block['raw'] );
			$blocks[ $idx ]['valid_json'] = $parsed['ok'];
			$blocks[ $idx ]['entities']  = $parsed['entities'];
			if ( ! $parsed['ok'] ) {
				$all_issues[] = [
					'severity' => 'error',
					'code'     => 'invalid_json',
					'message'  => __( 'A JSON-LD block contains invalid JSON.', 'llmagnet-llm-txt-generator' ),
				];
				continue;
			}
			foreach ( $parsed['entities'] as $entity ) {
				$type = isset( $entity['@type'] ) ? $entity['@type'] : null;
				if ( is_string( $type ) ) {
					$types[ $type ] = true;
				} elseif ( is_array( $type ) ) {
					foreach ( $type as $t ) {
						if ( is_string( $t ) ) {
							$types[ $t ] = true;
						}
					}
				}
				$all_issues = array_merge( $all_issues, $this->validate_entity( $entity ) );
			}
		}

		return array_merge(
			$base,
			[
				'label'        => $label,
				'url'          => $url,
				'ok'           => true,
				'blocks'       => $blocks,
				'types_found'  => array_keys( $types ),
				'issues'       => $all_issues,
				'block_count'  => count( $blocks ),
				'duplicate_entity_warning' => $this->build_duplicate_block_warning( $blocks ),
				'missing_entities' => $this->detect_missing_entities( $body, $meta, array_keys( $types ) ),
			]
		);
	}

	/**
	 * @param string $html HTML.
	 * @return array<int, array{raw:string, snippet:string, source:string}>
	 */
	private function extract_jsonld_blocks( string $html ): array {
		$out = [];
		if ( ! preg_match_all(
			'/(?:<!--\s*([\s\S]*?)\s*-->\s*)?<script[^>]*type\s*=\s*["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',
			$html,
			$matches,
			PREG_SET_ORDER
		) ) {
			return $out;
		}
		foreach ( $matches as $match ) {
			$comment = isset( $match[1] ) ? (string) $match[1] : '';
			$raw     = html_entity_decode( trim( (string) $match[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( '' === $raw ) {
				continue;
			}
			$snippet = mb_substr( preg_replace( '/\s+/', ' ', $raw ), 0, 200 );
			$out[]   = [
				'raw'     => $raw,
				'snippet' => $snippet . ( mb_strlen( $raw ) > 200 ? '…' : '' ),
				'source'  => $this->classify_jsonld_block_source( $comment, $raw ),
			];
		}
		return $out;
	}

	/**
	 * @param string $comment HTML comment before the script tag.
	 * @param string $raw     Raw JSON-LD.
	 * @return string
	 */
	private function classify_jsonld_block_source( string $comment, string $raw ): string {
		$hint = strtolower( $comment . ' ' . mb_substr( $raw, 0, 400 ) );

		if ( str_contains( $hint, 'llmagnet' ) ) {
			return 'llmagnet';
		}
		if ( str_contains( $hint, 'yoast' ) || str_contains( $hint, 'yoast-schema' ) ) {
			return 'yoast';
		}
		if ( str_contains( $hint, 'rank math' ) || str_contains( $hint, 'rank-math' ) ) {
			return 'rankmath';
		}
		if ( str_contains( $hint, 'aioseo' ) || str_contains( $hint, 'all in one seo' ) ) {
			return 'aioseo';
		}
		if ( str_contains( $hint, 'seopress' ) ) {
			return 'seopress';
		}
		if ( str_contains( $hint, 'seo framework' ) || str_contains( $hint, 'the_seo_framework' ) ) {
			return 'tsf';
		}
		if ( str_contains( $hint, 'woocommerce' ) ) {
			return 'woocommerce';
		}

		$detected = Seo_Plugin_Detector::get_active();
		if ( ! empty( $detected ) ) {
			return (string) array_key_first( $detected );
		}

		return 'unknown';
	}

	/**
	 * Warn when multiple JSON-LD producers conflict — not for our own merged block.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return string|null
	 */
	private function build_duplicate_block_warning( array $blocks ): ?string {
		if ( count( $blocks ) <= 1 ) {
			return null;
		}

		$sources = [];
		foreach ( $blocks as $block ) {
			$source = isset( $block['source'] ) ? (string) $block['source'] : 'unknown';
			$sources[ $source ] = ( $sources[ $source ] ?? 0 ) + 1;
		}

		unset( $sources['llmagnet'] );

		// Only LLMagnet blocks (legacy multi-block) — no warning after unified output.
		if ( empty( $sources ) ) {
			return null;
		}

		$labels = [];
		foreach ( array_keys( $sources ) as $slug ) {
			$active = Seo_Plugin_Detector::get_active();
			if ( isset( $active[ $slug ] ) ) {
				$labels[] = $active[ $slug ];
			} elseif ( 'woocommerce' === $slug ) {
				$labels[] = 'WooCommerce';
			} elseif ( 'unknown' !== $slug ) {
				$labels[] = ucfirst( $slug );
			}
		}
		$labels = array_values( array_unique( $labels ) );

		if ( ! empty( $labels ) ) {
			return sprintf(
				/* translators: %s: comma-separated SEO plugin names */
				__( 'Multiple JSON-LD blocks detected. %s already outputs structured data — avoid duplicating Organization, WebSite, or Product entities. Use Fix for templates, or extend schema inside that plugin.', 'llmagnet-llm-txt-generator' ),
				implode( ', ', $labels )
			);
		}

		return __( 'Multiple JSON-LD blocks from different sources were detected. Avoid duplicating the same business or product entities across blocks.', 'llmagnet-llm-txt-generator' );
	}

	/**
	 * @param string $raw Raw JSON.
	 * @return array{ok:bool, entities:array<int, array<string, mixed>>}
	 */
	private function parse_jsonld_block( string $raw ): array {
		$data = json_decode( $raw, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return [ 'ok' => false, 'entities' => [] ];
		}
		$entities = [];
		if ( is_array( $data ) && isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			foreach ( $data['@graph'] as $node ) {
				if ( is_array( $node ) ) {
					$entities[] = $node;
				}
			}
		} elseif ( is_array( $data ) ) {
			if ( isset( $data['@type'] ) ) {
				$entities[] = $data;
			} elseif ( isset( $data[0] ) && is_array( $data[0] ) ) {
				foreach ( $data as $node ) {
					if ( is_array( $node ) && isset( $node['@type'] ) ) {
						$entities[] = $node;
					}
				}
			}
		}
		return [ 'ok' => true, 'entities' => $entities ];
	}

	/**
	 * Basic field completeness checks.
	 *
	 * @param array<string, mixed> $entity Entity.
	 * @return array<int, array{severity:string,code:string,message:string}>
	 */
	private function validate_entity( array $entity ): array {
		$issues = [];
		$type   = isset( $entity['@type'] ) ? $entity['@type'] : '';
		if ( is_array( $type ) ) {
			$type = implode( ', ', $type );
		}

		$check_required = function ( array $fields, string $friendly ) use ( &$issues, $entity ) {
			foreach ( $fields as $f ) {
				if ( ! isset( $entity[ $f ] ) || '' === $entity[ $f ] ) {
					$issues[] = [
						'severity' => 'info',
						'code'     => 'missing_' . $f,
						'message'  => sprintf(
							/* translators: 1: schema type, 2: field name */
							__( 'Consider adding "%2$s" to your %1$s schema so machines can read key facts reliably.', 'llmagnet-llm-txt-generator' ),
							$friendly,
							$f
						),
					];
				}
			}
		};

		if ( is_string( $type ) && false !== strpos( strtolower( $type ), 'organization' ) ) {
			$check_required( [ 'name', 'url' ], __( 'Organization', 'llmagnet-llm-txt-generator' ) );
		}
		if ( is_string( $type ) && false !== strpos( strtolower( $type ), 'localbusiness' ) ) {
			$check_required( [ 'name', 'address', 'telephone' ], __( 'LocalBusiness', 'llmagnet-llm-txt-generator' ) );
		}
		if ( is_string( $type ) && false !== strpos( strtolower( $type ), 'product' ) ) {
			$check_required( [ 'name', 'image', 'offers' ], __( 'Product', 'llmagnet-llm-txt-generator' ) );
		}
		if ( is_string( $type ) && false !== strpos( strtolower( $type ), 'article' ) ) {
			$check_required( [ 'headline', 'author', 'datePublished' ], __( 'Article', 'llmagnet-llm-txt-generator' ) );
		}
		if ( is_string( $type ) && false !== strpos( strtolower( $type ), 'faqpage' ) ) {
			if ( empty( $entity['mainEntity'] ) || ! is_array( $entity['mainEntity'] ) ) {
				$issues[] = [
					'severity' => 'warning',
					'code'     => 'faq_questions',
					'message'  => __( 'FAQ schema should list question/answer pairs so assistants can reuse them.', 'llmagnet-llm-txt-generator' ),
				];
			}
		}

		return $issues;
	}

	/**
	 * @param array<int, mixed> $pages Pages.
	 * @param array<string, bool> $types_seen Types.
	 * @return array<int, array{severity:string,title:string,detail:string,cta?:string}>
	 */
	private function compile_recommendations( array $pages, array $types_seen ): array {
		$rec = [];

		$seo_owner = Seo_Plugin_Detector::get_json_ld_owner_label();
		if ( $seo_owner ) {
			$rec[] = [
				'severity' => 'high',
				'title'    => sprintf(
					/* translators: %s: SEO plugin name */
					__( '%s already outputs JSON-LD', 'llmagnet-llm-txt-generator' ),
					$seo_owner
				),
				'detail'   => __( 'LLMagnet will not print a second schema block on the front end. Use Fix for copy-ready templates and extend schema inside your SEO plugin (or Elementor page settings) instead of duplicating entities.', 'llmagnet-llm-txt-generator' ),
				'cta'      => __( 'Review scan results', 'llmagnet-llm-txt-generator' ),
			];
		}

		$has_org = ! empty( $types_seen['Organization'] ) || ! empty( $types_seen['LocalBusiness'] );
		if ( ! $has_org ) {
			$rec[] = [
				'severity' => 'high',
				'title'    => __( 'Add business identity to your site', 'llmagnet-llm-txt-generator' ),
				'detail'   => __( 'Organization or LocalBusiness schema helps AI and search engines understand who you are and how to cite you.', 'llmagnet-llm-txt-generator' ),
				'cta'      => __( 'Use Fix & Publish', 'llmagnet-llm-txt-generator' ),
			];
		}

		$has_product_schema = ! empty( $types_seen['Product'] );
		if ( class_exists( 'WooCommerce' ) && ! $has_product_schema ) {
			$rec[] = [
				'severity' => 'medium',
				'title'    => __( 'Product pages may be missing Product schema', 'llmagnet-llm-txt-generator' ),
				'detail'   => __( 'For stores, Product and Offer schema improves how prices and availability appear. This pack is included on Plus and Enterprise.', 'llmagnet-llm-txt-generator' ),
				'cta'      => __( 'Learn about store schema', 'llmagnet-llm-txt-generator' ),
			];
		}

		$has_web = ! empty( $types_seen['WebSite'] ) || ! empty( $types_seen['WebPage'] );
		if ( ! $has_web ) {
			$rec[] = [
				'severity' => 'low',
				'title'    => __( 'Clarify site-wide context', 'llmagnet-llm-txt-generator' ),
				'detail'   => __( 'WebSite or WebPage JSON-LD connects your brand, search box, and key pages into one machine-readable graph.', 'llmagnet-llm-txt-generator' ),
			];
		}

		$bad_json = false;
		foreach ( $pages as $p ) {
			foreach ( $p['issues'] as $issue ) {
				if ( 'invalid_json' === ( $issue['code'] ?? '' ) ) {
					$bad_json = true;
				}
			}
		}
		if ( $bad_json ) {
			$rec[] = [
				'severity' => 'high',
				'title'    => __( 'Fix invalid JSON-LD', 'llmagnet-llm-txt-generator' ),
				'detail'   => __( 'Broken JSON-LD blocks are ignored by crawlers. Use the preview in Fix & Publish to validate before going live.', 'llmagnet-llm-txt-generator' ),
			];
		}

		return $rec;
	}

	/**
	 * @param array<int, mixed> $pages Pages.
	 * @return int 0-100
	 */
	private function compute_overall_score( array $pages ): int {
		if ( empty( $pages ) ) {
			return 0;
		}
		$sum = 0;
		foreach ( $pages as $p ) {
			$block_score = isset( $p['block_count'] ) && $p['block_count'] > 0 ? 50 : 0;
			$issue_pen   = 0;
			foreach ( $p['issues'] as $issue ) {
				if ( 'error' === ( $issue['severity'] ?? '' ) ) {
					$issue_pen += 25;
				} elseif ( 'warning' === ( $issue['severity'] ?? '' ) ) {
					$issue_pen += 10;
				} else {
					$issue_pen += 3;
				}
			}
			$page_score = max( 0, min( 100, $block_score + 50 - min( 50, $issue_pen ) ) );
			$sum       += $page_score;
		}
		return (int) round( $sum / count( $pages ) );
	}

	/**
	 * @param array<string, mixed> $scan Scan.
	 * @return void
	 */
	private function push_history( array $scan ): void {
		$hist = get_option( self::OPTION_SCAN_HISTORY, [] );
		if ( ! is_array( $hist ) ) {
			$hist = [];
		}
		array_unshift(
			$hist,
			[
				'at'    => isset( $scan['scanned_at'] ) ? $scan['scanned_at'] : current_time( 'mysql', true ),
				'score' => isset( $scan['overall_score'] ) ? $scan['overall_score'] : null,
			]
		);
		$hist = array_slice( $hist, 0, 10 );
		update_option( self::OPTION_SCAN_HISTORY, $hist, false );
	}

	/**
	 * WordPress site logo URL from theme customizer (custom_logo).
	 *
	 * @return string
	 */
	public static function get_site_logo_url(): string {
		$custom_logo_id = get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id ) {
			$url = wp_get_attachment_image_url( (int) $custom_logo_id, 'full' );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		return '';
	}

	/**
	 * Default wizard form values from site settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_wizard_form(): array {
		return [
			'siteType'              => 'business',
			'organizationName'      => get_bloginfo( 'name' ),
			'organizationUrl'       => home_url( '/' ),
			'description'           => '',
			'logoUrl'               => self::get_site_logo_url(),
			'sameAs'                => [],
			'telephone'             => '',
			'streetAddress'         => '',
			'addressLocality'       => '',
			'addressRegion'         => '',
			'postalCode'            => '',
			'addressCountry'        => '',
			'includeProductExample' => false,
		];
	}

	/**
	 * Sanitize wizard payload from the admin UI.
	 *
	 * @param array<string, mixed> $body Raw body.
	 * @return array<string, mixed>
	 */
	public static function sanitize_wizard_form( array $body ): array {
		$site_type = isset( $body['siteType'] ) ? sanitize_text_field( (string) $body['siteType'] ) : 'business';
		if ( ! in_array( $site_type, [ 'business', 'blog', 'local_business' ], true ) ) {
			$site_type = 'business';
		}

		$same_as = [];
		if ( isset( $body['sameAs'] ) && is_array( $body['sameAs'] ) ) {
			foreach ( $body['sameAs'] as $url ) {
				$same_as[] = sanitize_text_field( (string) $url );
			}
		}

		return [
			'siteType'              => $site_type,
			'organizationName'      => isset( $body['organizationName'] ) ? sanitize_text_field( (string) $body['organizationName'] ) : '',
			'organizationUrl'       => isset( $body['organizationUrl'] ) ? esc_url_raw( (string) $body['organizationUrl'] ) : '',
			'description'           => isset( $body['description'] ) ? sanitize_textarea_field( (string) $body['description'] ) : '',
			'logoUrl'               => isset( $body['logoUrl'] ) ? esc_url_raw( (string) $body['logoUrl'] ) : '',
			'sameAs'                => $same_as,
			'telephone'             => isset( $body['telephone'] ) ? sanitize_text_field( (string) $body['telephone'] ) : '',
			'streetAddress'         => isset( $body['streetAddress'] ) ? sanitize_text_field( (string) $body['streetAddress'] ) : '',
			'addressLocality'       => isset( $body['addressLocality'] ) ? sanitize_text_field( (string) $body['addressLocality'] ) : '',
			'addressRegion'         => isset( $body['addressRegion'] ) ? sanitize_text_field( (string) $body['addressRegion'] ) : '',
			'postalCode'            => isset( $body['postalCode'] ) ? sanitize_text_field( (string) $body['postalCode'] ) : '',
			'addressCountry'        => isset( $body['addressCountry'] ) ? sanitize_text_field( (string) $body['addressCountry'] ) : '',
			'includeProductExample' => ! empty( $body['includeProductExample'] ),
		];
	}

	/**
	 * Load saved wizard form merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_saved_wizard_form(): array {
		$stored = get_option( self::OPTION_WIZARD_FORM, [] );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return self::default_wizard_form();
		}
		return array_merge( self::default_wizard_form(), self::sanitize_wizard_form( $stored ) );
	}

	/**
	 * Persist wizard form fields.
	 *
	 * @param array<string, mixed> $body Raw body.
	 * @return void
	 */
	private function save_wizard_form( array $body ): void {
		update_option( self::OPTION_WIZARD_FORM, self::sanitize_wizard_form( $body ), false );
	}

	/**
	 * Persist generated preview graph (draft, not yet live).
	 *
	 * @param array<string, mixed> $graph Graph.
	 * @return void
	 */
	private function save_draft_graph( array $graph ): void {
		$encoded = wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		update_option( self::OPTION_DRAFT, is_string( $encoded ) ? $encoded : '', false );
	}

	/**
	 * Load saved draft preview graph.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_draft_graph(): ?array {
		$draft_raw = get_option( self::OPTION_DRAFT, '' );
		if ( ! is_string( $draft_raw ) || '' === $draft_raw ) {
			return null;
		}
		$decoded = json_decode( $draft_raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Build @graph from wizard payload.
	 *
	 * @param array<string, mixed> $body Body.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private function build_graph_from_wizard( array $body ) {
		$site_type   = isset( $body['siteType'] ) ? sanitize_text_field( (string) $body['siteType'] ) : 'business';
		$org_name    = isset( $body['organizationName'] ) ? sanitize_text_field( (string) $body['organizationName'] ) : get_bloginfo( 'name' );
		$org_url     = isset( $body['organizationUrl'] ) ? esc_url_raw( (string) $body['organizationUrl'] ) : home_url( '/' );
		$description = isset( $body['description'] ) ? sanitize_textarea_field( (string) $body['description'] ) : get_bloginfo( 'description' );
		$logo        = isset( $body['logoUrl'] ) ? esc_url_raw( (string) $body['logoUrl'] ) : '';
		if ( ! $logo ) {
			$logo = self::get_site_logo_url();
		}
		$same_as     = isset( $body['sameAs'] ) && is_array( $body['sameAs'] ) ? $body['sameAs'] : [];
		$same_clean  = [];
		foreach ( $same_as as $u ) {
			$u = esc_url_raw( (string) $u );
			if ( $u ) {
				$same_clean[] = $u;
			}
		}

		$include_product = ! empty( $body['includeProductExample'] );
		if ( $include_product && ! self::can_use_commerce_schema() ) {
			return new \WP_Error(
				'schema_commerce',
				__( 'Store/Product schema templates are available on Plus and Enterprise.', 'llmagnet-llm-txt-generator' ),
				[ 'status' => 403 ]
			);
		}

		$graph = [];

		$has_address        = ! empty( $body['streetAddress'] );
		$use_local_business = 'local_business' === $site_type || $has_address;

		$org_id = trailingslashit( $org_url ) . '#organization';
		$org    = [
			'@type' => $use_local_business ? 'LocalBusiness' : 'Organization',
			'@id'   => $org_id,
			'name'  => $org_name,
			'url'   => $org_url,
		];
		if ( '' !== $description ) {
			$org['description'] = $description;
		}
		if ( $logo ) {
			$org['logo'] = $logo;
		}
		if ( ! empty( $same_clean ) ) {
			$org['sameAs'] = $same_clean;
		}
		if ( ! empty( $body['telephone'] ) ) {
			$org['telephone'] = sanitize_text_field( (string) $body['telephone'] );
		}
		if ( $has_address ) {
			$org['address'] = [
				'@type'           => 'PostalAddress',
				'streetAddress'   => sanitize_text_field( (string) $body['streetAddress'] ),
				'addressLocality' => isset( $body['addressLocality'] ) ? sanitize_text_field( (string) $body['addressLocality'] ) : '',
				'addressRegion'   => isset( $body['addressRegion'] ) ? sanitize_text_field( (string) $body['addressRegion'] ) : '',
				'postalCode'      => isset( $body['postalCode'] ) ? sanitize_text_field( (string) $body['postalCode'] ) : '',
				'addressCountry'  => isset( $body['addressCountry'] ) ? sanitize_text_field( (string) $body['addressCountry'] ) : '',
			];
		}
		$graph[] = $org;

		$web_id      = home_url( '/' ) . '#website';
		$website     = [
			'@type'     => 'WebSite',
			'@id'       => $web_id,
			'url'       => home_url( '/' ),
			'name'      => get_bloginfo( 'name' ),
			'publisher' => [ '@id' => $org_id ],
		];
		$graph[]     = $website;

		if ( 'blog' === $site_type ) {
			$graph[] = [
				'@type' => 'WebPage',
				'@id'   => home_url( '/' ) . '#webpage',
				'url'   => home_url( '/' ),
				'name'  => get_bloginfo( 'name' ),
				'isPartOf' => [ '@id' => $web_id ],
			];
		}

		if ( $include_product && class_exists( 'WooCommerce' ) ) {
			$sample_product = get_posts(
				[
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				]
			);
			if ( ! empty( $sample_product ) ) {
				$pid   = $sample_product[0]->ID;
				$purl  = get_permalink( $pid );
				$image = get_the_post_thumbnail_url( $pid, 'full' );
				$product = wc_get_product( $pid );
				$price   = $product ? $product->get_price() : '';

				$graph[] = [
					'@type'      => 'Product',
					'@id'        => $purl . '#product',
					'name'       => get_the_title( $pid ),
					'description'=> wp_strip_all_tags( get_post_field( 'post_excerpt', $pid ) ? get_post_field( 'post_excerpt', $pid ) : get_post_field( 'post_content', $pid ) ),
					'image'      => $image ? [ $image ] : [],
					'brand'      => [ '@type' => 'Brand', 'name' => $org_name ],
					'offers'     => [
						'@type'           => 'Offer',
						'priceCurrency'   => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
						'price'           => $price,
						'url'             => $purl,
						'availability'    => 'https://schema.org/InStock',
					],
				];
			} else {
				$graph[] = [
					'@type'      => 'Product',
					'@id'        => $org_url . '#demo-product',
					'name'       => __( 'Example product', 'llmagnet-llm-txt-generator' ),
					'description'=> __( 'Publish a WooCommerce product to replace this placeholder in your graph.', 'llmagnet-llm-txt-generator' ),
					'offers'     => [
						'@type'         => 'Offer',
						'priceCurrency' => 'USD',
						'price'         => '0',
						'availability'  => 'https://schema.org/InStock',
					],
				];
			}
		}

		return [
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		];
	}

	/**
	 * Kick off a background scan job (processes URLs in batches via cron).
	 *
	 * @return void
	 */
	private function start_background_scan(): void {
		$urls = $this->collect_sample_urls();
		$job  = [
			'status'       => 'running',
			'started_at'   => current_time( 'mysql', true ),
			'urls'         => $urls,
			'cursor'       => 0,
			'pages'        => [],
			'total'        => count( $urls ),
			'scan_version' => self::SCAN_VERSION,
		];
		update_option( self::OPTION_SCAN_JOB, $job, false );
		$this->run_scan_batch();
	}

	/**
	 * Process the next batch of URLs in an active scan job.
	 *
	 * @return void
	 */
	public function run_scan_batch(): void {
		$job = get_option( self::OPTION_SCAN_JOB, null );
		if ( ! is_array( $job ) || 'running' !== ( $job['status'] ?? '' ) ) {
			return;
		}

		$batch_size = max( 1, (int) apply_filters( 'llmagnet_schema_scan_batch_size', self::SCAN_BATCH_SIZE ) );
		$urls       = isset( $job['urls'] ) && is_array( $job['urls'] ) ? $job['urls'] : [];
		$cursor     = (int) ( $job['cursor'] ?? 0 );
		$pages      = isset( $job['pages'] ) && is_array( $job['pages'] ) ? $job['pages'] : [];
		$end        = min( $cursor + $batch_size, count( $urls ) );

		for ( $i = $cursor; $i < $end; $i++ ) {
			$item    = $urls[ $i ];
			$pages[] = $this->scan_url( (string) $item['url'], $item );
		}

		$job['cursor'] = $end;
		$job['pages']  = $pages;

		if ( $end >= count( $urls ) ) {
			$this->finalize_scan_job( $pages, $job );
			return;
		}

		update_option( self::OPTION_SCAN_JOB, $job, false );

		if ( ! wp_next_scheduled( self::BATCH_EVENT ) ) {
			wp_schedule_single_event( time() + 2, self::BATCH_EVENT );
		}
	}

	/**
	 * Persist completed scan and clear the in-progress job.
	 *
	 * @param array<int, mixed>     $pages Scanned rows.
	 * @param array<string, mixed>  $job   Job metadata.
	 * @return void
	 */
	private function finalize_scan_job( array $pages, array $job ): void {
		$types_seen = [];
		foreach ( $pages as $row ) {
			if ( ! is_array( $row ) || empty( $row['types_found'] ) ) {
				continue;
			}
			foreach ( $row['types_found'] as $t ) {
				$types_seen[ (string) $t ] = true;
			}
		}

		$result = [
			'scan_version'      => self::SCAN_VERSION,
			'scanned_at'        => current_time( 'mysql', true ),
			'overall_score'     => $this->compute_overall_score( $pages ),
			'pages'             => $pages,
			'page_count'        => count( $pages ),
			'recommendations'   => $this->compile_recommendations( $pages, $types_seen ),
			'home_url'          => home_url( '/' ),
			'wooCommerceActive' => class_exists( 'WooCommerce' ),
			'scan_status'       => 'complete',
		];

		update_option( self::OPTION_LAST_SCAN, $result, false );
		$this->push_history( $result );
		delete_option( self::OPTION_SCAN_JOB );
	}

	/**
	 * Build REST payload from an in-progress or partial scan job.
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return array<string, mixed>
	 */
	private function build_scan_response_from_job( array $job ): array {
		$pages = isset( $job['pages'] ) && is_array( $job['pages'] ) ? $job['pages'] : [];
		$pages = array_map( [ $this, 'enrich_scan_page_row' ], $pages );

		$types_seen = [];
		foreach ( $pages as $row ) {
			if ( ! is_array( $row ) || empty( $row['types_found'] ) ) {
				continue;
			}
			foreach ( $row['types_found'] as $t ) {
				$types_seen[ (string) $t ] = true;
			}
		}

		$done  = count( $pages );
		$total = (int) ( $job['total'] ?? $done );

		return [
			'scan_status'       => $job['status'] ?? 'running',
			'scan_progress'     => [
				'done'    => $done,
				'total'   => $total,
				'percent' => $total > 0 ? (int) round( ( $done / $total ) * 100 ) : 0,
			],
			'scanned_at'        => $job['started_at'] ?? null,
			'overall_score'     => $done > 0 ? $this->compute_overall_score( $pages ) : null,
			'pages'             => $pages,
			'page_count'        => $done,
			'recommendations'   => $done > 0 ? $this->compile_recommendations( $pages, $types_seen ) : [],
			'home_url'          => home_url( '/' ),
			'wooCommerceActive' => class_exists( 'WooCommerce' ),
			'scan_version'      => self::SCAN_VERSION,
		];
	}

	/**
	 * Whether any found type satisfies an expected schema type.
	 *
	 * @param array<int, string> $types_found Found @type values.
	 * @param string             $expected    Expected schema type.
	 * @return bool
	 */
	private function has_schema_type( array $types_found, string $expected ): bool {
		$aliases = self::TYPE_ALIASES[ $expected ] ?? [ $expected ];
		foreach ( $types_found as $found ) {
			$found_lower = strtolower( (string) $found );
			foreach ( $aliases as $alias ) {
				if ( false !== strpos( $found_lower, strtolower( $alias ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Detect entities represented on the page that lack matching JSON-LD.
	 *
	 * @param string               $html        Page HTML.
	 * @param array<string, mixed> $meta        Scan target metadata.
	 * @param array<int, string>   $types_found Parsed JSON-LD types.
	 * @return array<int, array{type:string,label:string,source:string,reason:string,template_json:string}>
	 */
	private function detect_missing_entities( string $html, array $meta, array $types_found ): array {
		$expected = $this->collect_expected_entities( $html, $meta );
		$missing  = [];

		foreach ( $expected as $item ) {
			$type = $item['type'];
			if ( $this->has_schema_type( $types_found, $type ) ) {
				continue;
			}
			$template = $this->build_entity_template( $type, $meta, $html, $item );
			if ( null === $template ) {
				continue;
			}
			$missing[] = [
				'type'           => $type,
				'label'          => $item['label'],
				'source'         => $item['source'],
				'reason'         => $item['reason'],
				'template_json'  => $this->format_ld_json_script( $template ),
				'template_body'  => $this->format_ld_json_body( $template ),
			];
		}

		return $missing;
	}

	/**
	 * Collect schema entities this page or its widgets should expose.
	 *
	 * @param string               $html Page HTML.
	 * @param array<string, mixed> $meta Scan metadata.
	 * @return array<int, array{type:string,label:string,source:string,reason:string}>
	 */
	private function collect_expected_entities( string $html, array $meta ): array {
		$post_type = isset( $meta['post_type'] ) ? sanitize_key( (string) $meta['post_type'] ) : 'unknown';
		$url       = isset( $meta['url'] ) ? (string) $meta['url'] : '';
		$slug      = $this->url_slug_hint( $url, $meta );
		$expected  = [];

		$add = function ( string $type, string $label, string $source, string $reason ) use ( &$expected ): void {
			foreach ( $expected as $item ) {
				if ( $item['type'] === $type && $item['source'] === $source ) {
					return;
				}
			}
			$expected[] = [
				'type'   => $type,
				'label'  => $label,
				'source' => $source,
				'reason' => $reason,
			];
		};

		if ( 'home' === $post_type ) {
			$add(
				'WebSite',
				__( 'WebSite', 'llmagnet-llm-txt-generator' ),
				'page',
				__( 'Homepages should declare WebSite schema so search engines understand site-wide context.', 'llmagnet-llm-txt-generator' )
			);
			$add(
				'Organization',
				__( 'Organization', 'llmagnet-llm-txt-generator' ),
				'page',
				__( 'Homepages should include Organization schema describing your brand.', 'llmagnet-llm-txt-generator' )
			);
		} elseif ( 'post' === $post_type ) {
			$add(
				'Article',
				__( 'Article / BlogPosting', 'llmagnet-llm-txt-generator' ),
				'page',
				__( 'Blog posts should include Article or BlogPosting schema with title, author, and dates.', 'llmagnet-llm-txt-generator' )
			);
		} elseif ( 'product' === $post_type ) {
			$add(
				'Product',
				__( 'Product', 'llmagnet-llm-txt-generator' ),
				'page',
				__( 'Product pages should include Product schema with name, image, and offers.', 'llmagnet-llm-txt-generator' )
			);
		} elseif ( 'page' === $post_type ) {
			if ( preg_match( '/\b(about|about-us|our-story|team)\b/i', $slug ) ) {
				$add(
					'AboutPage',
					__( 'AboutPage', 'llmagnet-llm-txt-generator' ),
					'page',
					__( 'About pages should use AboutPage schema.', 'llmagnet-llm-txt-generator' )
				);
			}
			if ( preg_match( '/\b(faq|faqs|frequently-asked)\b/i', $slug ) ) {
				$add(
					'FAQPage',
					__( 'FAQPage', 'llmagnet-llm-txt-generator' ),
					'page',
					__( 'FAQ pages should list questions and answers in FAQPage schema.', 'llmagnet-llm-txt-generator' )
				);
			}
			if ( preg_match( '/\b(service|services|what-we-do)\b/i', $slug ) ) {
				$add(
					'Service',
					__( 'Service', 'llmagnet-llm-txt-generator' ),
					'page',
					__( 'Service pages should describe offerings with Service schema.', 'llmagnet-llm-txt-generator' )
				);
			}
			if ( preg_match( '/\b(event|events|webinar|conference)\b/i', $slug ) ) {
				$add(
					'Event',
					__( 'Event', 'llmagnet-llm-txt-generator' ),
					'page',
					__( 'Event pages should include Event schema with dates and location.', 'llmagnet-llm-txt-generator' )
				);
			}
			if ( preg_match( '/\b(contact|location|directions|find-us)\b/i', $slug ) || $this->html_suggests_local_business( $html ) ) {
				$add(
					'LocalBusiness',
					__( 'LocalBusiness', 'llmagnet-llm-txt-generator' ),
					'page',
					__( 'Local business pages should include address and contact details in LocalBusiness schema.', 'llmagnet-llm-txt-generator' )
				);
			}
		}

		if ( '' !== $html ) {
			if ( $this->html_suggests_faq( $html ) ) {
				$add(
					'FAQPage',
					__( 'FAQ widget', 'llmagnet-llm-txt-generator' ),
					'widget',
					__( 'FAQ content detected on the page — add FAQPage schema with question/answer pairs.', 'llmagnet-llm-txt-generator' )
				);
			}
			if ( $this->html_suggests_reviews( $html ) ) {
				$add(
					'Review',
					__( 'Reviews / Testimonials', 'llmagnet-llm-txt-generator' ),
					'widget',
					__( 'Review or testimonial content detected — add Review schema (often nested under Product).', 'llmagnet-llm-txt-generator' )
				);
			}
			if ( $this->html_suggests_product( $html ) && 'product' !== $post_type ) {
				$add(
					'Product',
					__( 'Product widget', 'llmagnet-llm-txt-generator' ),
					'widget',
					__( 'Product-like content detected in a widget — add Product schema.', 'llmagnet-llm-txt-generator' )
				);
			}
			if ( $this->html_suggests_video( $html ) ) {
				$add(
					'VideoObject',
					__( 'Video', 'llmagnet-llm-txt-generator' ),
					'widget',
					__( 'Embedded video detected — add VideoObject schema.', 'llmagnet-llm-txt-generator' )
				);
			}
			if ( $this->html_suggests_breadcrumbs( $html ) ) {
				$add(
					'BreadcrumbList',
					__( 'Breadcrumbs', 'llmagnet-llm-txt-generator' ),
					'widget',
					__( 'Breadcrumb navigation detected — add BreadcrumbList schema.', 'llmagnet-llm-txt-generator' )
				);
			}
			if ( $this->html_suggests_event( $html ) && ! preg_match( '/\b(event|events|webinar|conference)\b/i', $slug ) ) {
				$add(
					'Event',
					__( 'Event widget', 'llmagnet-llm-txt-generator' ),
					'widget',
					__( 'Event content detected — add Event schema with schedule and location.', 'llmagnet-llm-txt-generator' )
				);
			}
			if ( $this->html_suggests_service( $html ) && ! preg_match( '/\b(service|services|what-we-do)\b/i', $slug ) ) {
				$add(
					'Service',
					__( 'Service widget', 'llmagnet-llm-txt-generator' ),
					'widget',
					__( 'Service offering content detected — add Service schema.', 'llmagnet-llm-txt-generator' )
				);
			}
			if ( $this->html_suggests_business_info( $html ) && 'home' !== $post_type ) {
				$add(
					'Organization',
					__( 'Business information', 'llmagnet-llm-txt-generator' ),
					'widget',
					__( 'Business contact details detected — add Organization or LocalBusiness schema.', 'llmagnet-llm-txt-generator' )
				);
			}
		}

		return $expected;
	}

	/**
	 * @param string               $url  Page URL.
	 * @param array<string, mixed> $meta Metadata.
	 * @return string
	 */
	private function url_slug_hint( string $url, array $meta ): string {
		$post_id = isset( $meta['post_id'] ) ? (int) $meta['post_id'] : 0;
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post instanceof \WP_Post ) {
				return (string) $post->post_name;
			}
		}
		if ( '' === $url ) {
			return '';
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}
		$parts = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );
		return ! empty( $parts ) ? (string) end( $parts ) : '';
	}

	/**
	 * @param string $html HTML.
	 * @return bool
	 */
	private function html_suggests_faq( string $html ): bool {
		if ( preg_match( '/class=["\'][^"\']*faq[^"\']*["\']/i', $html ) ) {
			return true;
		}
		if ( preg_match( '/itemtype=["\']https?:\/\/schema\.org\/FAQPage["\']/i', $html ) ) {
			return true;
		}
		if ( preg_match_all( '/<details\b/i', $html, $matches ) && count( $matches[0] ) >= 2 ) {
			return true;
		}
		if ( preg_match( '/elementor-widget-accordion/i', $html ) && preg_match( '/\?(?:what|how|why|when|where|can|do|is|are)\s/i', $html ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @param string $html HTML.
	 * @return bool
	 */
	private function html_suggests_reviews( string $html ): bool {
		if ( preg_match( '/class=["\'][^"\']*(?:review|testimonial|rating)[^"\']*["\']/i', $html ) ) {
			return true;
		}
		if ( preg_match( '/itemprop=["\'](?:reviewRating|reviewBody)["\']/i', $html ) ) {
			return true;
		}
		if ( preg_match( '/class=["\'][^"\']*star-rating[^"\']*["\']/i', $html ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @param string $html HTML.
	 * @return bool
	 */
	private function html_suggests_product( string $html ): bool {
		if ( preg_match( '/class=["\'][^"\']*(?:product|woocommerce)[^"\']*["\']/i', $html ) ) {
			return true;
		}
		if ( preg_match( '/itemtype=["\']https?:\/\/schema\.org\/Product["\']/i', $html ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @param string $html HTML.
	 * @return bool
	 */
	private function html_suggests_video( string $html ): bool {
		if ( preg_match( '/<video\b/i', $html ) ) {
			return true;
		}
		if ( preg_match( '/(?:youtube\.com\/embed|youtu\.be\/|vimeo\.com\/(?:video\/)?)/i', $html ) ) {
			return true;
		}
		if ( preg_match( '/itemtype=["\']https?:\/\/schema\.org\/VideoObject["\']/i', $html ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @param string $html HTML.
	 * @return bool
	 */
	private function html_suggests_breadcrumbs( string $html ): bool {
		if ( preg_match( '/class=["\'][^"\']*breadcrumb[^"\']*["\']/i', $html ) ) {
			return true;
		}
		if ( preg_match( '/itemtype=["\']https?:\/\/schema\.org\/BreadcrumbList["\']/i', $html ) ) {
			return true;
		}
		if ( preg_match( '/"@type"\s*:\s*"BreadcrumbList"/i', $html ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @param string $html HTML.
	 * @return bool
	 */
	private function html_suggests_event( string $html ): bool {
		if ( preg_match( '/class=["\'][^"\']*(?:event|tribe-events|calendar)[^"\']*["\']/i', $html ) ) {
			return true;
		}
		if ( preg_match( '/itemtype=["\']https?:\/\/schema\.org\/Event["\']/i', $html ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @param string $html HTML.
	 * @return bool
	 */
	private function html_suggests_service( string $html ): bool {
		if ( preg_match( '/class=["\'][^"\']*service[^"\']*["\']/i', $html ) ) {
			return true;
		}
		if ( preg_match( '/itemtype=["\']https?:\/\/schema\.org\/Service["\']/i', $html ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @param string $html HTML.
	 * @return bool
	 */
	private function html_suggests_business_info( string $html ): bool {
		$has_phone = preg_match( '/(?:tel:|itemprop=["\']telephone["\']|class=["\'][^"\']*phone[^"\']*["\'])/i', $html );
		$has_addr  = preg_match( '/(?:itemprop=["\']address["\']|class=["\'][^"\']*address[^"\']*["\']|<address\b)/i', $html );
		return (bool) ( $has_phone && $has_addr );
	}

	/**
	 * @param string $html HTML.
	 * @return bool
	 */
	private function html_suggests_local_business( string $html ): bool {
		return $this->html_suggests_business_info( $html );
	}

	/**
	 * Build a JSON-LD template for a missing entity.
	 *
	 * @param string               $type     Schema type.
	 * @param array<string, mixed> $meta     Page metadata.
	 * @param string               $html     Page HTML.
	 * @param array<string, mixed> $expected Expected entity descriptor.
	 * @return array<string, mixed>|null
	 */
	private function build_entity_template( string $type, array $meta, string $html, array $expected ): ?array {
		switch ( $type ) {
			case 'Article':
				return $this->template_article( $meta );
			case 'WebSite':
				return $this->template_website( $meta );
			case 'Organization':
				return $this->template_organization( $meta );
			case 'LocalBusiness':
				return $this->template_local_business( $meta );
			case 'Product':
				return $this->template_product( $meta );
			case 'FAQPage':
				return $this->template_faq_page( $meta );
			case 'AboutPage':
				return $this->template_about_page( $meta );
			case 'Service':
				return $this->template_service( $meta );
			case 'Event':
				return $this->template_event( $meta );
			case 'VideoObject':
				return $this->template_video( $meta, $html );
			case 'BreadcrumbList':
				return $this->template_breadcrumbs( $meta );
			case 'Review':
				return $this->template_reviews( $meta );
			default:
				return null;
		}
	}

	/**
	 * @param array<string, mixed> $meta Metadata.
	 * @return array<string, mixed>
	 */
	private function template_article( array $meta ): array {
		$post_id = isset( $meta['post_id'] ) ? (int) $meta['post_id'] : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		$url     = isset( $meta['url'] ) ? (string) $meta['url'] : '';
		$title   = $post instanceof \WP_Post ? get_the_title( $post ) : ( isset( $meta['title'] ) ? (string) $meta['title'] : '' );
		$image   = $post_id > 0 ? get_the_post_thumbnail_url( $post_id, 'full' ) : '';
		$author  = $post instanceof \WP_Post ? get_the_author_meta( 'display_name', (int) $post->post_author ) : '';
		$desc    = $post instanceof \WP_Post ? wp_strip_all_tags( get_the_excerpt( $post ) ) : '';

		$schema = [
			'@context'         => 'https://schema.org',
			'@type'            => 'Article',
			'headline'         => $title,
			'mainEntityOfPage' => $url,
		];
		if ( $image ) {
			$schema['image'] = $image;
		}
		if ( $author ) {
			$schema['author'] = [
				'@type' => 'Person',
				'name'  => $author,
			];
		}
		if ( $post instanceof \WP_Post ) {
			$schema['datePublished'] = get_the_date( 'c', $post );
			$schema['dateModified']  = get_the_modified_date( 'c', $post );
		}
		if ( $desc ) {
			$schema['description'] = $desc;
		}
		return $schema;
	}

	/**
	 * @param array<string, mixed> $meta Metadata.
	 * @return array<string, mixed>
	 */
	private function template_website( array $meta ): array {
		unset( $meta );
		return [
			'@context' => 'https://schema.org',
			'@type'    => 'WebSite',
			'name'     => get_bloginfo( 'name' ),
			'url'      => home_url( '/' ),
		];
	}

	/**
	 * @param array<string, mixed> $meta Metadata.
	 * @return array<string, mixed>
	 */
	private function template_organization( array $meta ): array {
		unset( $meta );
		$schema = [
			'@context' => 'https://schema.org',
			'@type'    => 'Organization',
			'name'     => get_bloginfo( 'name' ),
			'url'      => home_url( '/' ),
		];
		$logo = self::get_site_logo_url();
		if ( $logo ) {
			$schema['logo'] = $logo;
		}
		$desc = get_bloginfo( 'description' );
		if ( $desc ) {
			$schema['description'] = $desc;
		}
		return $schema;
	}

	/**
	 * @param array<string, mixed> $meta Metadata.
	 * @return array<string, mixed>
	 */
	private function template_local_business( array $meta ): array {
		$schema = [
			'@context' => 'https://schema.org',
			'@type'    => 'LocalBusiness',
			'name'     => get_bloginfo( 'name' ),
			'url'      => isset( $meta['url'] ) ? (string) $meta['url'] : home_url( '/' ),
		];
		$logo = self::get_site_logo_url();
		if ( $logo ) {
			$schema['image'] = $logo;
		}
		$schema['address'] = [
			'@type'           => 'PostalAddress',
			'streetAddress'   => '{{street_address}}',
			'addressLocality' => '{{city}}',
			'addressRegion'   => '{{region}}',
			'postalCode'      => '{{postal_code}}',
			'addressCountry'  => '{{country}}',
		];
		$schema['telephone'] = '{{phone_number}}';
		return $schema;
	}

	/**
	 * @param array<string, mixed> $meta Metadata.
	 * @return array<string, mixed>
	 */
	private function template_product( array $meta ): array {
		$post_id = isset( $meta['post_id'] ) ? (int) $meta['post_id'] : 0;
		$url     = isset( $meta['url'] ) ? (string) $meta['url'] : '';
		$name    = isset( $meta['title'] ) ? (string) $meta['title'] : '';
		$image   = $post_id > 0 ? get_the_post_thumbnail_url( $post_id, 'full' ) : '';
		$desc    = '';
		$sku     = '';
		$price   = '';

		if ( $post_id > 0 && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );
			if ( $product ) {
				$name  = $product->get_name() ?: $name;
				$desc  = wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() );
				$sku   = $product->get_sku();
				$price = $product->get_price();
				if ( ! $image ) {
					$image_id = $product->get_image_id();
					if ( $image_id ) {
						$image = wp_get_attachment_image_url( $image_id, 'full' ) ?: '';
					}
				}
			}
		}

		$schema = [
			'@context'    => 'https://schema.org',
			'@type'       => 'Product',
			'name'        => $name ?: '{{product_name}}',
			'description' => $desc ?: '{{product_description}}',
			'offers'      => [
				'@type'         => 'Offer',
				'price'         => $price ?: '{{price}}',
				'priceCurrency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
				'availability'  => 'https://schema.org/InStock',
				'url'           => $url ?: '{{product_url}}',
			],
		];
		if ( $image ) {
			$schema['image'] = $image;
		}
		if ( $sku ) {
			$schema['sku'] = $sku;
		}
		return $schema;
	}

	/**
	 * @param array<string, mixed> $meta Metadata.
	 * @return array<string, mixed>
	 */
	private function template_faq_page( array $meta ): array {
		unset( $meta );
		return [
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => [
				[
					'@type'          => 'Question',
					'name'           => 'What is LLM visibility?',
					'acceptedAnswer' => [
						'@type' => 'Answer',
						'text'  => 'LLM visibility measures how often and how well a brand appears in AI-generated answers.',
					],
				],
			],
		];
	}

	/**
	 * @param array<string, mixed> $meta Metadata.
	 * @return array<string, mixed>
	 */
	private function template_about_page( array $meta ): array {
		$url   = isset( $meta['url'] ) ? (string) $meta['url'] : '';
		$title = isset( $meta['title'] ) ? (string) $meta['title'] : get_bloginfo( 'name' );
		return [
			'@context' => 'https://schema.org',
			'@type'    => 'AboutPage',
			'name'     => $title,
			'url'      => $url,
		];
	}

	/**
	 * @param array<string, mixed> $meta Metadata.
	 * @return array<string, mixed>
	 */
	private function template_service( array $meta ): array {
		$title = isset( $meta['title'] ) ? (string) $meta['title'] : '{{service_name}}';
		return [
			'@context'    => 'https://schema.org',
			'@type'       => 'Service',
			'name'        => $title,
			'description' => '{{service_description}}',
			'provider'    => [
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			],
			'areaServed'  => '{{service_area}}',
			'serviceType' => '{{service_type}}',
		];
	}

	/**
	 * @param array<string, mixed> $meta Metadata.
	 * @return array<string, mixed>
	 */
	private function template_event( array $meta ): array {
		$title = isset( $meta['title'] ) ? (string) $meta['title'] : '{{event_name}}';
		return [
			'@context'              => 'https://schema.org',
			'@type'                 => 'Event',
			'name'                  => $title,
			'startDate'             => '{{start_date}}',
			'endDate'               => '{{end_date}}',
			'eventAttendanceMode'   => 'https://schema.org/OfflineEventAttendanceMode',
			'location'              => [
				'@type'   => 'Place',
				'name'    => '{{venue_name}}',
				'address' => '{{event_address}}',
			],
			'image'                 => '{{event_image}}',
			'description'           => '{{event_description}}',
		];
	}

	/**
	 * @param array<string, mixed> $meta Metadata.
	 * @param string               $html HTML.
	 * @return array<string, mixed>
	 */
	private function template_video( array $meta, string $html ): array {
		$title = isset( $meta['title'] ) ? (string) $meta['title'] : '{{video_title}}';
		$embed = '';
		if ( preg_match( '/src=["\']([^"\']*(?:youtube\.com\/embed|youtu\.be\/|vimeo\.com)[^"\']*)["\']/i', $html, $m ) ) {
			$embed = $m[1];
		}
		return [
			'@context'     => 'https://schema.org',
			'@type'        => 'VideoObject',
			'name'         => $title,
			'description'  => '{{video_description}}',
			'thumbnailUrl' => '{{thumbnail_url}}',
			'uploadDate'   => '{{upload_date}}',
			'contentUrl'   => '{{video_url}}',
			'embedUrl'     => $embed ?: '{{embed_url}}',
		];
	}

	/**
	 * @param array<string, mixed> $meta Metadata.
	 * @return array<string, mixed>
	 */
	private function template_breadcrumbs( array $meta ): array {
		$url   = isset( $meta['url'] ) ? (string) $meta['url'] : '';
		$title = isset( $meta['title'] ) ? (string) $meta['title'] : '{{post_title}}';
		return [
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => [
				[
					'@type'    => 'ListItem',
					'position' => 1,
					'name'     => 'Home',
					'item'     => home_url( '/' ),
				],
				[
					'@type'    => 'ListItem',
					'position' => 2,
					'name'     => $title,
					'item'     => $url ?: '{{post_url}}',
				],
			],
		];
	}

	/**
	 * @param array<string, mixed> $meta Metadata.
	 * @return array<string, mixed>
	 */
	private function template_reviews( array $meta ): array {
		$name = isset( $meta['title'] ) ? (string) $meta['title'] : get_bloginfo( 'name' );
		return [
			'@context' => 'https://schema.org',
			'@type'    => 'Product',
			'name'     => $name,
			'review'   => [
				[
					'@type'        => 'Review',
					'author'       => [
						'@type' => 'Person',
						'name'  => 'John Smith',
					],
					'reviewRating' => [
						'@type'       => 'Rating',
						'ratingValue' => '5',
						'bestRating'  => '5',
					],
					'reviewBody'   => 'Great tool for tracking AI visibility.',
				],
			],
		];
	}

	/**
	 * Wrap JSON-LD in a script tag for copy/paste.
	 *
	 * @param array<string, mixed> $schema Schema object.
	 * @return string
	 */
	private function format_ld_json_script( array $schema ): string {
		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		if ( ! is_string( $json ) ) {
			return '';
		}
		return "<script type=\"application/ld+json\">\n" . $json . "\n</script>";
	}

	/**
	 * Pretty JSON body only (for Elementor and other builders without script tags).
	 *
	 * @param array<string, mixed> $schema Schema object.
	 * @return string
	 */
	private function format_ld_json_body( array $schema ): string {
		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		return is_string( $json ) ? $json : '';
	}
}
