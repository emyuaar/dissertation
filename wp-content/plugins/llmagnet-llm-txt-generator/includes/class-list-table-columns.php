<?php
/**
 * List Table Columns class
 *
 * Adds a sortable "LLM Score" column to the post / page / product list
 * tables, server-rendering the persisted score badge from Score_Store
 * meta and deferring missing scores to a single batched REST call
 * (assets/list-table-column.js).
 *
 * Spec: docs/admin-adoption-surfaces-plan.md — Feature 1 (Phase D, Lane D1).
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "LLM Score" list-table column + row action.
 *
 * Performance contract (adoption plan §Performance):
 * - never computes a score synchronously — reads `_llmagnet_score` meta only;
 * - exactly ONE batch REST call per list-table view for pending rows
 *   (the /scores endpoint queues nulls for priority backfill);
 * - edit.php gets vanilla JS only, no React.
 */
class List_Table_Columns {

	/**
	 * Column key used in the columns / sortable / orderby maps.
	 */
	const COLUMN_KEY = 'llmagnet_score';

	/**
	 * Script/style handle for the list-table assets.
	 */
	const ASSET_HANDLE = 'llmagnet-list-table-column';

	/**
	 * Register hooks. Admin-only — instantiate from Main behind is_admin().
	 *
	 * @return void
	 */
	public function init() {
		// Column filters are evaluated when the list table renders, well
		// after admin_init, so registering there lets us resolve the
		// supported post types (WooCommerce detection) safely.
		add_action( 'admin_init', [ $this, 'register_column_hooks' ] );

		add_action( 'pre_get_posts', [ $this, 'handle_orderby' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_filter( 'post_row_actions', [ $this, 'add_row_action' ], 10, 2 );
		add_filter( 'page_row_actions', [ $this, 'add_row_action' ], 10, 2 );
	}

	/**
	 * Post types that get the column. Defaults to the Score_Store set
	 * (post, page, + product when WooCommerce is active).
	 *
	 * @return array
	 */
	public static function get_post_types(): array {
		$types = class_exists( __NAMESPACE__ . '\\Score_Store' )
			? Score_Store::get_post_types()
			: [ 'post', 'page' ];

		/**
		 * Filter the post types that receive the "LLM Score" list-table column.
		 *
		 * @param array $types Post type names.
		 */
		return (array) apply_filters( 'llmagnet_score_column_post_types', $types );
	}

	/**
	 * Hook the column registration / render / sortable filters per post type.
	 *
	 * @return void
	 */
	public function register_column_hooks() {
		foreach ( self::get_post_types() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", [ $this, 'add_column' ] );
			add_action( "manage_{$post_type}_posts_custom_column", [ $this, 'render_column' ], 10, 2 );
			add_filter( "manage_edit-{$post_type}_sortable_columns", [ $this, 'add_sortable_column' ] );
		}
	}

	/**
	 * Insert the "LLM Score" column right after the title column.
	 *
	 * Registering through manage_{$pt}_posts_columns automatically exposes
	 * the column as a Screen Options checkbox (hide/show requirement).
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$header = '<span class="llmagnet-score-col-icon" aria-hidden="true"></span>'
			. esc_html__( 'LLM Score', 'llmagnet-llm-txt-generator' );

		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new[ self::COLUMN_KEY ] = $header;
			}
		}
		// Title column absent for this screen — append at the end.
		if ( ! isset( $new[ self::COLUMN_KEY ] ) ) {
			$new[ self::COLUMN_KEY ] = $header;
		}
		return $new;
	}

	/**
	 * Render a row cell: persisted badge, or a pending placeholder that
	 * assets/list-table-column.js fills in via one batch REST call.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_column( $column, $post_id ) {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}

		$row     = Score_Store::get( (int) $post_id );
		$can_fix = current_user_can( 'manage_options' ); // Drawer pages are manage_options.
		$fix_url = $can_fix ? Admin_WP_Helper::drawer_url( (int) $post_id ) : '';

		if ( null === $row ) {
			printf(
				'<span class="llmagnet-score-pending" data-post-id="%d"%s aria-label="%s">&mdash;</span>',
				absint( $post_id ),
				$fix_url ? ' data-fix-url="' . esc_url( $fix_url ) . '"' : '',
				esc_attr__( 'AI visibility score not calculated yet', 'llmagnet-llm-txt-generator' )
			);
			return;
		}

		echo wp_kses_post( self::badge_html( $row['score'], $fix_url ) );
	}

	/**
	 * Badge markup shared by the server render and (structurally) by the
	 * JS fill-in. Color bands match src/components/score-badge.tsx:
	 * 0-49 red, 50-89 orange, 90-100 green.
	 *
	 * @param int    $score   Score 0-100.
	 * @param string $fix_url Drawer deep-link ('' = no Fix link).
	 * @return string
	 */
	public static function badge_html( int $score, string $fix_url = '' ): string {
		$score = max( 0, min( 100, $score ) );

		if ( $score >= 90 ) {
			$band = 'green';
		} elseif ( $score >= 50 ) {
			$band = 'orange';
		} else {
			$band = 'red';
		}

		$html = sprintf(
			'<span class="llmagnet-score-badge llmagnet-score-badge--%1$s" role="img" aria-label="%2$s"><span class="llmagnet-score-badge__dot" aria-hidden="true"></span>%3$d</span>',
			esc_attr( $band ),
			/* translators: %d: score 0-100 */
			esc_attr( sprintf( __( 'AI visibility score %d of 100', 'llmagnet-llm-txt-generator' ), $score ) ),
			$score
		);

		if ( $fix_url && $score < 100 ) {
			$html .= sprintf(
				' <a class="llmagnet-score-fix" href="%1$s" title="%2$s">%3$s</a>',
				esc_url( $fix_url ),
				esc_attr__( 'Open LLMagnet to improve this score', 'llmagnet-llm-txt-generator' ),
				esc_html__( 'Fix', 'llmagnet-llm-txt-generator' )
			);
		}

		return $html;
	}

	/**
	 * Make the column sortable.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public function add_sortable_column( $columns ) {
		$columns[ self::COLUMN_KEY ] = self::COLUMN_KEY;
		return $columns;
	}

	/**
	 * Sort by score via meta_query with OR EXISTS / NOT EXISTS so posts
	 * without a score always sort last (adoption plan F1).
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public function handle_orderby( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( self::COLUMN_KEY !== $query->get( 'orderby' ) ) {
			return;
		}

		$order = strtoupper( (string) $query->get( 'order' ) ) === 'DESC' ? 'DESC' : 'ASC';

		$query->set(
			'meta_query',
			[
				'relation'      => 'OR',
				'score_exists'  => [
					'key'     => Score_Store::META_SCORE,
					'compare' => 'EXISTS',
					'type'    => 'NUMERIC',
				],
				'score_missing' => [
					'key'     => Score_Store::META_SCORE,
					'compare' => 'NOT EXISTS',
				],
			]
		);
		$query->set( 'orderby', [ 'score_exists' => $order ] );
	}

	/**
	 * Row action "LLM Score" → drawer deep-link (adoption plan F1 quick win).
	 *
	 * @param array    $actions Row actions.
	 * @param \WP_Post $post    Post.
	 * @return array
	 */
	public function add_row_action( $actions, $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return $actions;
		}
		if ( ! in_array( $post->post_type, self::get_post_types(), true ) ) {
			return $actions;
		}
		// Drawer pages (Pages / Products analytics) require manage_options.
		if ( ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}
		if ( 'publish' !== $post->post_status ) {
			return $actions;
		}

		$actions['llmagnet_score'] = sprintf(
			'<a href="%1$s" title="%2$s">%3$s</a>',
			esc_url( Admin_WP_Helper::drawer_url( (int) $post->ID ) ),
			esc_attr__( 'Open LLMagnet to view and improve this score', 'llmagnet-llm-txt-generator' ),
			esc_html__( 'LLM Score', 'llmagnet-llm-txt-generator' )
		);

		return $actions;
	}

	/**
	 * Enqueue the vanilla JS + tiny CSS only on list-table screens of
	 * supported post types.
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'edit.php' !== $hook ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, self::get_post_types(), true ) ) {
			return;
		}

		wp_enqueue_script(
			self::ASSET_HANDLE,
			LLMAGNET_AISEO_PLUGIN_URL . 'assets/list-table-column.js',
			[],
			LLMAGNET_AISEO_VERSION,
			true
		);

		wp_localize_script(
			self::ASSET_HANDLE,
			'llmagnetListTableColumn',
			[
				'restUrl' => esc_url_raw( rest_url( 'llm-analytics/v1/scores' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => [
					/* translators: %d: score 0-100 */
					'scoreLabel' => __( 'AI visibility score %d of 100', 'llmagnet-llm-txt-generator' ),
					'fix'        => __( 'Fix', 'llmagnet-llm-txt-generator' ),
					'fixTitle'   => __( 'Open LLMagnet to improve this score', 'llmagnet-llm-txt-generator' ),
				],
			]
		);

		wp_register_style( self::ASSET_HANDLE, false, [], LLMAGNET_AISEO_VERSION );
		wp_enqueue_style( self::ASSET_HANDLE );
		wp_add_inline_style( self::ASSET_HANDLE, self::inline_css() );
	}

	/**
	 * Minimal scoped CSS for the column. Color values mirror the Tailwind
	 * palette used by src/components/score-badge.tsx.
	 *
	 * @return string
	 */
	private static function inline_css(): string {
		$icon = esc_url( LLMAGNET_AISEO_PLUGIN_URL . 'assets/llmagnet-icon.svg' );

		return '
.fixed .column-' . self::COLUMN_KEY . ' { width: 110px; }
.llmagnet-score-col-icon { display: inline-block; vertical-align: text-bottom; width: 14px; height: 14px; margin-right: 4px; background: url(' . $icon . ') no-repeat center / contain; }
.llmagnet-score-badge { display: inline-flex; align-items: center; gap: 5px; padding: 1px 8px; border-radius: 9999px; border: 1px solid transparent; font-weight: 600; font-size: 12px; line-height: 18px; }
.llmagnet-score-badge__dot { width: 7px; height: 7px; border-radius: 50%; }
.llmagnet-score-badge--green { background: #f0fdf4; color: #15803d; border-color: #bbf7d0; }
.llmagnet-score-badge--green .llmagnet-score-badge__dot { background: #22c55e; }
.llmagnet-score-badge--orange { background: #fff7ed; color: #c2410c; border-color: #fed7aa; }
.llmagnet-score-badge--orange .llmagnet-score-badge__dot { background: #f97316; }
.llmagnet-score-badge--red { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
.llmagnet-score-badge--red .llmagnet-score-badge__dot { background: #ef4444; }
.llmagnet-score-pending { color: #9ca3af; }
.llmagnet-score-fix { font-size: 12px; margin-left: 2px; }
';
	}
}
