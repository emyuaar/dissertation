<?php
/**
 * Post-upgrade admin notice prompting a browser cache refresh.
 *
 * After a plugin update, stale lazy-loaded JS chunks (pages.js,
 * content-settings.js, …) can leave the admin shell running two React
 * copies. The build now appends ?ver= to those imports; this notice
 * nudges admins to hard-refresh once after upgrading.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect plugin upgrades and show a dismissible cache-refresh notice.
 */
class Upgrade_Notice {

	/**
	 * Option: last installed plugin version (autoload=no).
	 */
	const INSTALLED_VERSION_OPTION = 'llmagnet_aiseo_installed_version';

	/**
	 * Option: version string for which the cache notice should show.
	 */
	const NOTICE_VERSION_OPTION = 'llmagnet_aiseo_cache_refresh_notice';

	/**
	 * User meta: plugin version for which this user dismissed the notice.
	 */
	const DISMISSED_USER_META = 'llmagnet_dismissed_cache_notice_ver';

	/**
	 * Query arg used to dismiss the notice.
	 */
	const DISMISS_QUERY_ARG = 'llmagnet_dismiss_cache_notice';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'upgrader_process_complete', [ $this, 'on_upgrader_complete' ], 10, 2 );
		add_action( 'admin_init', [ $this, 'sync_installed_version' ], 5 );
		add_action( 'admin_init', [ $this, 'maybe_dismiss_notice' ] );
		if ( is_admin() ) {
			add_action( 'admin_notices', [ $this, 'maybe_render_notice' ] );
		}
	}

	/**
	 * Flag the notice when this plugin is updated via the WP upgrader.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance.
	 * @param array        $options  Upgrade context.
	 * @return void
	 */
	public function on_upgrader_complete( $upgrader, $options ) {
		unset( $upgrader );

		if ( ! is_array( $options ) ) {
			return;
		}
		if ( ( $options['action'] ?? '' ) !== 'update' || ( $options['type'] ?? '' ) !== 'plugin' ) {
			return;
		}
		if ( empty( $options['plugins'] ) || ! is_array( $options['plugins'] ) ) {
			return;
		}
		if ( ! in_array( LLMAGNET_AISEO_PLUGIN_BASENAME, $options['plugins'], true ) ) {
			return;
		}

		$this->flag_notice_for_current_version();
	}

	/**
	 * Detect manual/FTP upgrades by comparing stored vs current version.
	 *
	 * @return void
	 */
	public function sync_installed_version() {
		$stored = get_option( self::INSTALLED_VERSION_OPTION, '' );

		if ( is_string( $stored ) && $stored !== '' && version_compare( $stored, LLMAGNET_AISEO_VERSION, '<' ) ) {
			$this->flag_notice_for_current_version();
		}

		if ( $stored !== LLMAGNET_AISEO_VERSION ) {
			update_option( self::INSTALLED_VERSION_OPTION, LLMAGNET_AISEO_VERSION, false );
		}
	}

	/**
	 * Dismiss the notice for the current user + version.
	 *
	 * @return void
	 */
	public function maybe_dismiss_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		if ( ! isset( $_GET[ self::DISMISS_QUERY_ARG ] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'llmagnet_dismiss_cache_notice' ) ) {
			return;
		}

		update_user_meta( get_current_user_id(), self::DISMISSED_USER_META, LLMAGNET_AISEO_VERSION );

		wp_safe_redirect( remove_query_arg( [ self::DISMISS_QUERY_ARG, '_wpnonce' ] ) );
		exit;
	}

	/**
	 * Render the cache-refresh notice on LLMagnet admin screens.
	 *
	 * @return void
	 */
	public function maybe_render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice_version = get_option( self::NOTICE_VERSION_OPTION, '' );
		if ( $notice_version !== LLMAGNET_AISEO_VERSION ) {
			return;
		}

		$dismissed = get_user_meta( get_current_user_id(), self::DISMISSED_USER_META, true );
		if ( $dismissed === LLMAGNET_AISEO_VERSION ) {
			return;
		}

		if ( ! $this->is_llmagnet_admin_screen() ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( self::DISMISS_QUERY_ARG, '1' ),
			'llmagnet_dismiss_cache_notice'
		);
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<strong><?php esc_html_e( 'LLMagnet updated', 'llmagnet-llm-txt-generator' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: plugin version number */
						__(
							'Version %s is now active. If an admin page shows a blank screen or "useState" error, hard-refresh your browser (Ctrl+Shift+R or Cmd+Shift+R) or clear your site cache.',
							'llmagnet-llm-txt-generator'
						),
						LLMAGNET_AISEO_VERSION
					)
				);
				?>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left: 8px;">
					<?php esc_html_e( 'Dismiss', 'llmagnet-llm-txt-generator' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Store the flag that triggers the notice for the running version.
	 *
	 * @return void
	 */
	private function flag_notice_for_current_version() {
		update_option( self::NOTICE_VERSION_OPTION, LLMAGNET_AISEO_VERSION, false );
	}

	/**
	 * Whether the current admin screen belongs to this plugin.
	 *
	 * @return bool
	 */
	private function is_llmagnet_admin_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page && 0 === strpos( $page, 'llmagnet-ai-seo' ) ) {
			return true;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}

		return false !== strpos( $screen->id, 'llmagnet' );
	}
}
