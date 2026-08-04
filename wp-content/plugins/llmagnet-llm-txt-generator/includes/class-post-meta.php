<?php
/**
 * Post Meta class
 *
 * Registers plugin post meta keys that need REST exposure — currently the
 * per-post llms.txt include/exclude toggle — plus the classic-editor meta
 * box, Quick Edit field and bulk actions for that toggle.
 *
 * Spec: docs/admin-adoption-surfaces-plan.md — §2.1 (meta), §2.2 classic
 * fallback (E1-5) and §5.3 Quick Edit + bulk actions (E1-7).
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin post meta registration + classic admin surfaces for the toggle.
 */
class Post_Meta {

	/**
	 * Meta key: '1' = excluded from llms.txt / llms-full.txt / markdown
	 * exports. Absence = included, so the default needs no migration and
	 * no meta rows for the common case.
	 */
	const EXCLUDE_META_KEY = '_llmagnet_exclude_from_llms';

	/**
	 * Meta box id (classic editor fallback).
	 */
	const META_BOX_ID = 'llmagnet_post_meta_box';

	/**
	 * Nonce action/name pairs for the classic save paths.
	 */
	const NONCE_META_BOX   = 'llmagnet_post_meta_box';
	const NONCE_QUICK_EDIT = 'llmagnet_quick_edit';

	/**
	 * Bulk action keys (E1-7).
	 */
	const BULK_INCLUDE = 'llmagnet_include_llms';
	const BULK_EXCLUDE = 'llmagnet_exclude_llms';

	/**
	 * Quick Edit script handle.
	 */
	const QUICK_EDIT_HANDLE = 'llmagnet-quick-edit';

	/**
	 * Generator instance (optional). Used to re-sync the export files after
	 * a Gutenberg (REST) save: core fires `save_post` BEFORE REST meta is
	 * written, so the Generator's own save_post regeneration runs against
	 * the previous toggle value — `rest_after_insert_*` corrects that.
	 *
	 * @var Generator|null
	 */
	private $generator;

	/**
	 * Constructor.
	 *
	 * @param Generator|null $generator Generator instance.
	 */
	public function __construct( $generator = null ) {
		$this->generator = $generator;
	}

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', [ $this, 'register_meta' ] );
		add_action( 'init', [ $this, 'register_rest_sync_hooks' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		if ( is_admin() ) {
			add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ], 10, 2 );
			// Priority 5: write the toggle BEFORE the Generator's save_post
			// regeneration (priority 10) reads it.
			add_action( 'save_post', [ $this, 'handle_classic_save' ], 5, 2 );
			add_action( 'save_post', [ $this, 'clear_score_cache_on_save' ], 5, 2 );

			add_action( 'admin_init', [ $this, 'register_list_table_hooks' ] );
			add_action( 'quick_edit_custom_box', [ $this, 'render_quick_edit_field' ], 10, 2 );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_quick_edit_assets' ] );
			add_action( 'admin_notices', [ $this, 'render_bulk_action_notice' ] );
		}
	}

	/**
	 * Register the exclude-from-llms meta for all post types.
	 *
	 * `show_in_rest` lets the Gutenberg panel (Phase E) read/write it via
	 * `useEntityProp` with zero custom endpoints. Auth follows the Phase 0.2
	 * capability decision: per-post `edit_post` (editors and shop managers
	 * included), not `manage_options`.
	 *
	 * @return void
	 */
	public function register_meta() {
		register_post_meta( '', self::EXCLUDE_META_KEY, [
			'type'              => 'boolean',
			'single'            => true,
			'default'           => false,
			'show_in_rest'      => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
			'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
				return current_user_can( 'edit_post', $post_id );
			},
		] );
	}

	/**
	 * Whether a post is excluded from the llms.txt exports.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_excluded( int $post_id ): bool {
		return (bool) get_post_meta( $post_id, self::EXCLUDE_META_KEY, true );
	}

	/**
	 * Meta query fragment that filters excluded posts out of export queries.
	 * Only an explicit '1' excludes; missing meta means included.
	 *
	 * @return array
	 */
	public static function exclude_meta_query(): array {
		return [
			'relation' => 'OR',
			[
				'key'     => self::EXCLUDE_META_KEY,
				'compare' => 'NOT EXISTS',
			],
			[
				'key'     => self::EXCLUDE_META_KEY,
				'value'   => '1',
				'compare' => '!=',
			],
		];
	}

	/**
	 * Post types that get the meta box / Quick Edit / bulk actions —
	 * the same set as the score surfaces.
	 *
	 * @return array
	 */
	private function get_surface_post_types(): array {
		$types = class_exists( __NAMESPACE__ . '\\Score_Store' )
			? Score_Store::get_post_types()
			: [ 'post', 'page' ];

		/**
		 * Filter the post types that get the classic meta box, Quick Edit
		 * field and llms.txt bulk actions.
		 *
		 * @param array $types Post type names.
		 */
		return (array) apply_filters( 'llmagnet_post_meta_surface_post_types', $types );
	}

	/**
	 * List-table column key the Quick Edit field rides on (D1's column).
	 *
	 * @return string
	 */
	private function get_column_key(): string {
		return class_exists( __NAMESPACE__ . '\\List_Table_Columns' )
			? List_Table_Columns::COLUMN_KEY
			: 'llmagnet_score';
	}

	/* ---------------------------------------------------------------------
	 * REST: drawer-toggle parity (adoption §2.1 note / §5.8, FB-5)
	 * ------------------------------------------------------------------- */

	/**
	 * Routes used by the Pages/Products drawer "Include in llms.txt"
	 * toggle. The Gutenberg panel reads/writes the meta via core REST
	 * (`show_in_rest`), but WooCommerce products are not exposed under
	 * wp/v2, so the drawers use this thin endpoint instead. Capability
	 * matches the meta's auth_callback: per-post `edit_post`.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		$args = [
			'post_id' => [
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
		];

		$permission = function ( $request ) {
			$post_id = absint( $request->get_param( 'post_id' ) );
			return $post_id && current_user_can( 'edit_post', $post_id );
		};

		register_rest_route( 'llm-analytics/v1', '/post-llms-inclusion', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_get_inclusion' ],
				'permission_callback' => $permission,
				'args'                => $args,
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_update_inclusion' ],
				'permission_callback' => $permission,
				'args'                => $args + [
					'excluded' => [
						'required'          => true,
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
				],
			],
		] );
	}

	/**
	 * GET /post-llms-inclusion?post_id=N → current toggle state.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_get_inclusion( $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => __( 'Post not found.', 'llmagnet-llm-txt-generator' ),
			], 404 );
		}

		return new \WP_REST_Response( [
			'success'  => true,
			'post_id'  => $post_id,
			'excluded' => self::is_excluded( $post_id ),
		], 200 );
	}

	/**
	 * POST /post-llms-inclusion {post_id, excluded} → update + re-sync the
	 * export files (same Generator path as the Gutenberg/REST save).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_update_inclusion( $request ) {
		$post_id  = absint( $request->get_param( 'post_id' ) );
		$excluded = rest_sanitize_boolean( $request->get_param( 'excluded' ) );
		$post     = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => __( 'Post not found.', 'llmagnet-llm-txt-generator' ),
			], 404 );
		}

		if ( $excluded ) {
			update_post_meta( $post_id, self::EXCLUDE_META_KEY, '1' );
		} else {
			// Included is the default — drop the meta row entirely.
			delete_post_meta( $post_id, self::EXCLUDE_META_KEY );
		}

		if ( $this->generator instanceof Generator ) {
			$this->generator->maybe_regenerate( $post_id, $post );
		}

		return new \WP_REST_Response( [
			'success'  => true,
			'post_id'  => $post_id,
			'excluded' => self::is_excluded( $post_id ),
		], 200 );
	}

	/* ---------------------------------------------------------------------
	 * Classic editor meta box (E1-5)
	 * ------------------------------------------------------------------- */

	/**
	 * Register the classic-editor meta box. `__back_compat_meta_box` hides
	 * it in the block editor, where the sidebar panel covers the same
	 * functionality.
	 *
	 * @param string        $post_type Post type.
	 * @param \WP_Post|null $post      Post being edited.
	 * @return void
	 */
	public function register_meta_box( $post_type, $post = null ) {
		if ( ! in_array( $post_type, $this->get_surface_post_types(), true ) ) {
			return;
		}

		add_meta_box(
			self::META_BOX_ID,
			__( 'LLMagnet — AI Visibility', 'llmagnet-llm-txt-generator' ),
			[ $this, 'render_meta_box' ],
			$post_type,
			'side',
			'default',
			[ '__back_compat_meta_box' => true ]
		);
	}

	/**
	 * Meta box body: server-rendered score badge (same markup as the D1
	 * list-table column), Fix deep-link, and the include checkbox saved via
	 * the classic save_post path.
	 *
	 * @param \WP_Post $post Post being edited.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$post_id = (int) $post->ID;

		// Score badge — persisted meta only, never computed in the render.
		$row        = class_exists( __NAMESPACE__ . '\\Score_Store' ) ? Score_Store::get( $post_id ) : null;
		$can_manage = current_user_can( 'manage_options' );

		echo '<p style="margin:4px 0 10px;">';
		echo '<strong>' . esc_html__( 'AI visibility score', 'llmagnet-llm-txt-generator' ) . '</strong><br />';
		if ( null !== $row && class_exists( __NAMESPACE__ . '\\List_Table_Columns' ) ) {
			$fix_url = ( $can_manage && class_exists( __NAMESPACE__ . '\\Admin_WP_Helper' ) )
				? Admin_WP_Helper::drawer_url( $post_id )
				: '';
			echo wp_kses_post( List_Table_Columns::badge_html( (int) $row['score'], $fix_url ) );
		} else {
			echo '<span aria-label="' . esc_attr__( 'AI visibility score not calculated yet', 'llmagnet-llm-txt-generator' ) . '">&mdash; ';
			echo esc_html__( 'Not calculated yet', 'llmagnet-llm-txt-generator' ) . '</span>';
		}
		echo '</p>';

		wp_nonce_field( self::NONCE_META_BOX, 'llmagnet_post_meta_nonce' );
		?>
		<label for="llmagnet_include_in_llms">
			<input type="checkbox" name="llmagnet_include_in_llms" id="llmagnet_include_in_llms" value="1" <?php checked( ! self::is_excluded( $post_id ) ); ?> />
			<?php esc_html_e( 'Include in llms.txt', 'llmagnet-llm-txt-generator' ); ?>
		</label>
		<p class="description" style="margin-top:4px;">
			<?php esc_html_e( 'When unchecked, this content is left out of llms.txt, llms-full.txt and the markdown docs.', 'llmagnet-llm-txt-generator' ); ?>
		</p>
		<?php
	}

	/**
	 * Classic save path for the meta box AND the Quick Edit field (both
	 * post through edit_post() → save_post with their own nonce). Gutenberg
	 * saves carry neither nonce and are handled by the REST meta field.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function handle_classic_save( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->get_surface_post_types(), true ) ) {
			return;
		}

		$nonce_ok = false;
		if ( isset( $_POST['llmagnet_post_meta_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['llmagnet_post_meta_nonce'] ) ), self::NONCE_META_BOX ) ) {
			$nonce_ok = true;
		} elseif ( isset( $_POST['llmagnet_quick_edit_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['llmagnet_quick_edit_nonce'] ) ), self::NONCE_QUICK_EDIT ) ) {
			$nonce_ok = true;
		}
		if ( ! $nonce_ok ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! empty( $_POST['llmagnet_include_in_llms'] ) ) {
			// Included is the default — drop the meta row entirely.
			delete_post_meta( $post_id, self::EXCLUDE_META_KEY );
		} else {
			update_post_meta( $post_id, self::EXCLUDE_META_KEY, '1' );
		}
	}

	/**
	 * Clear the per-post score transients on a real save so the editor
	 * panel's refetch-on-save (E1-2) returns a freshly computed score
	 * instead of the 1-hour cached one. Only the current plan's keys are
	 * cleared — those are the only ones the calculators read.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function clear_score_cache_on_save( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->get_surface_post_types(), true ) ) {
			return;
		}

		$plan   = get_option( 'llmagnet_plan', 'free' );
		$prefix = ( 'product' === $post->post_type ) ? 'llmagnet_product_score_' : 'llmagnet_page_score_';

		foreach ( [ 7, 30, 90 ] as $range ) {
			delete_transient( "{$prefix}{$post_id}_{$range}_{$plan}" );
		}
	}

	/**
	 * Re-sync export files after a Gutenberg (REST) save. Core's REST
	 * controller updates meta AFTER `save_post` fires, so the Generator's
	 * own save_post regeneration ran against the previous toggle value;
	 * this hook runs after meta is written and corrects the file state
	 * (the mtime skip makes the second pass cheap when nothing changed).
	 *
	 * @return void
	 */
	public function register_rest_sync_hooks() {
		if ( ! $this->generator instanceof Generator ) {
			return;
		}
		foreach ( $this->get_surface_post_types() as $post_type ) {
			add_action( "rest_after_insert_{$post_type}", [ $this, 'sync_exports_after_rest' ], 10, 1 );
		}
	}

	/**
	 * `rest_after_insert_{$post_type}` callback.
	 *
	 * @param \WP_Post $post Inserted/updated post.
	 * @return void
	 */
	public function sync_exports_after_rest( $post ) {
		if ( $post instanceof \WP_Post && $this->generator instanceof Generator ) {
			$this->generator->maybe_regenerate( $post->ID, $post );
		}
	}

	/* ---------------------------------------------------------------------
	 * Quick Edit + bulk actions (E1-7)
	 * ------------------------------------------------------------------- */

	/**
	 * Per-post-type list-table hooks (bulk actions + the hidden per-row
	 * state marker the Quick Edit JS reads). admin_init so WooCommerce
	 * detection inside the post-type set is safe.
	 *
	 * @return void
	 */
	public function register_list_table_hooks() {
		foreach ( $this->get_surface_post_types() as $post_type ) {
			add_filter( "bulk_actions-edit-{$post_type}", [ $this, 'register_bulk_actions' ] );
			add_filter( "handle_bulk_actions-edit-{$post_type}", [ $this, 'handle_bulk_actions' ], 10, 3 );
			// Priority 20 — appends after D1's badge render in the same cell.
			add_action( "manage_{$post_type}_posts_custom_column", [ $this, 'render_inline_state' ], 20, 2 );
		}
	}

	/**
	 * Hidden per-row include/exclude marker inside the LLM Score column
	 * cell. Hidden columns are still rendered (CSS-hidden), so the Quick
	 * Edit JS can always read it.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_inline_state( $column, $post_id ) {
		if ( $this->get_column_key() !== $column ) {
			return;
		}
		printf(
			'<span class="llmagnet-llms-include-state hidden" data-included="%s" aria-hidden="true"></span>',
			self::is_excluded( (int) $post_id ) ? '0' : '1'
		);
	}

	/**
	 * Quick Edit checkbox "Include in llms.txt" (rides on D1's column slot;
	 * `quick_edit_custom_box` fires once per custom column).
	 *
	 * @param string $column_name Column key.
	 * @param string $post_type   Post type of the list table.
	 * @return void
	 */
	public function render_quick_edit_field( $column_name, $post_type ) {
		if ( $this->get_column_key() !== $column_name ) {
			return;
		}
		if ( ! in_array( $post_type, $this->get_surface_post_types(), true ) ) {
			return;
		}

		wp_nonce_field( self::NONCE_QUICK_EDIT, 'llmagnet_quick_edit_nonce', false );
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label class="alignleft">
					<input type="checkbox" name="llmagnet_include_in_llms" value="1" checked="checked" />
					<span class="checkbox-title"><?php esc_html_e( 'Include in llms.txt', 'llmagnet-llm-txt-generator' ); ?></span>
				</label>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Quick Edit helper JS: populates the checkbox from the hidden row
	 * marker when a row enters inline-edit mode.
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public function enqueue_quick_edit_assets( $hook ) {
		if ( 'edit.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, $this->get_surface_post_types(), true ) ) {
			return;
		}

		wp_enqueue_script(
			self::QUICK_EDIT_HANDLE,
			LLMAGNET_AISEO_PLUGIN_URL . 'assets/quick-edit-llms.js',
			[ 'jquery', 'inline-edit-post' ],
			LLMAGNET_AISEO_VERSION,
			true
		);
	}

	/**
	 * Bulk actions "Include in llms.txt" / "Exclude from llms.txt".
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function register_bulk_actions( $actions ) {
		$actions[ self::BULK_INCLUDE ] = __( 'Include in llms.txt', 'llmagnet-llm-txt-generator' );
		$actions[ self::BULK_EXCLUDE ] = __( 'Exclude from llms.txt', 'llmagnet-llm-txt-generator' );
		return $actions;
	}

	/**
	 * Apply the bulk include/exclude action. Core has already verified the
	 * bulk-actions nonce before this filter runs.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Bulk action key.
	 * @param array  $post_ids    Selected post IDs.
	 * @return string
	 */
	public function handle_bulk_actions( $redirect_to, $action, $post_ids ) {
		if ( ! in_array( $action, [ self::BULK_INCLUDE, self::BULK_EXCLUDE ], true ) ) {
			return $redirect_to;
		}

		$changed = 0;
		foreach ( array_map( 'absint', (array) $post_ids ) as $post_id ) {
			if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			if ( self::BULK_EXCLUDE === $action ) {
				update_post_meta( $post_id, self::EXCLUDE_META_KEY, '1' );
			} else {
				delete_post_meta( $post_id, self::EXCLUDE_META_KEY );
			}
			$changed++;
		}

		if ( $changed > 0 ) {
			// Bulk meta changes don't fire save_post, so the exports won't
			// self-heal until the daily run — schedule a near-term one-off
			// of the existing batched generation event instead.
			wp_schedule_single_event( time() + 30, 'llmagnet_ai_seo_daily_event' );
		}

		$redirect_to = remove_query_arg( [ 'llmagnet_included', 'llmagnet_excluded' ], $redirect_to );

		return add_query_arg(
			self::BULK_EXCLUDE === $action ? 'llmagnet_excluded' : 'llmagnet_included',
			$changed,
			$redirect_to
		);
	}

	/**
	 * Success notice after a bulk include/exclude redirect.
	 *
	 * @return void
	 */
	public function render_bulk_action_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit' !== $screen->base ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notice from our own redirect args.
		if ( isset( $_GET['llmagnet_included'] ) ) {
			$count   = absint( $_GET['llmagnet_included'] );
			$message = sprintf(
				/* translators: %d: number of posts */
				_n( '%d item included in llms.txt.', '%d items included in llms.txt.', $count, 'llmagnet-llm-txt-generator' ),
				$count
			);
		} elseif ( isset( $_GET['llmagnet_excluded'] ) ) {
			$count   = absint( $_GET['llmagnet_excluded'] );
			$message = sprintf(
				/* translators: %d: number of posts */
				_n( '%d item excluded from llms.txt.', '%d items excluded from llms.txt.', $count, 'llmagnet-llm-txt-generator' ),
				$count
			);
		} else {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}
}
