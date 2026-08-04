<?php
/**
 * IndexNow integration (agent-readiness-spec F8.2 — FA-8)
 *
 * Three pieces, all behind the `indexnow` feature toggle (default OFF):
 *
 * 1. Key file — `/{key}.txt` served at the site root through the Phase 0.1
 *    Well-Known router. The key comes from
 *    Agent_Readiness_Options::get_or_create_indexnow_key() (32 hex chars,
 *    generated on first use, option `llmagnet_indexnow_key`).
 * 2. Debounced ping — `transition_post_status` collects published/updated
 *    (and just-unpublished) URLs of llms-included post types into a queue
 *    option, then schedules ONE single-event cron 5 minutes out
 *    (`llmagnet_indexnow_ping`). Bulk edits collapse into one batched POST
 *    to https://api.indexnow.org/indexnow (urlList form). Queue capped at
 *    500 URLs; a small result log (last 20 pings) is kept for the Agent
 *    Ready page.
 * 3. Deference — when RankMath Instant Indexing / AIOSEO IndexNow / Yoast
 *    Premium already ping (Seo_Plugin_Detector::owns( 'indexnow' )),
 *    everything above no-ops and the audit reports handled_externally. No
 *    double pings.
 *
 * Self-scheduling pattern (class-cron.php is a hot file): the single event
 * is scheduled lazily from the enqueue path only — nothing recurring to
 * heal. Uninstall must clear `llmagnet_indexnow_ping` plus the queue/log
 * options (see docs/handoffs/fa-main-snippet.md).
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * F8.2 IndexNow key file + debounced pings.
 */
class IndexNow {

    /**
     * Single-event cron hook for the debounced batch ping.
     */
    const PING_EVENT = 'llmagnet_indexnow_ping';

    /**
     * Pending URL queue option (string[], autoload off).
     */
    const OPTION_QUEUE = 'llmagnet_indexnow_queue';

    /**
     * Ping result log option (last 20 entries, autoload off).
     */
    const OPTION_LOG = 'llmagnet_indexnow_log';

    /**
     * Debounce window in seconds.
     */
    const DEBOUNCE = 5 * MINUTE_IN_SECONDS;

    /**
     * Queue hard cap (IndexNow accepts up to 10k; stay far below).
     */
    const QUEUE_MAX = 500;

    /**
     * IndexNow API endpoint.
     */
    const API_URL = 'https://api.indexnow.org/indexnow';

    /**
     * Generator (post-type inclusion settings).
     *
     * @var Generator|null
     */
    private $generator;

    /**
     * @param Generator|null $generator Generator instance.
     */
    public function __construct( $generator = null ) {
        $this->generator = $generator instanceof Generator ? $generator : null;
    }

    /**
     * Wire hooks.
     *
     * The cron callback is registered unconditionally so a queued event
     * still runs (and drains) after the toggle is flipped off; everything
     * else self-gates per call.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'llmagnet_register_well_known_providers', [ $this, 'register_key_provider' ] );
        add_action( 'transition_post_status', [ $this, 'on_transition_post_status' ], 10, 3 );
        add_action( self::PING_EVENT, [ $this, 'process_queue' ] );
    }

    /**
     * Whether IndexNow is active: toggle ON and no other plugin owns it.
     *
     * @return bool
     */
    public static function is_active(): bool {
        if ( ! class_exists( __NAMESPACE__ . '\\Agent_Readiness_Options' )
            || ! Agent_Readiness_Options::is_feature_enabled( 'indexnow' ) ) {
            return false;
        }
        return ! self::handled_externally();
    }

    /**
     * Whether another SEO plugin already provides IndexNow.
     *
     * @return bool
     */
    public static function handled_externally(): bool {
        return class_exists( __NAMESPACE__ . '\\Seo_Plugin_Detector' )
            && Seo_Plugin_Detector::owns_indexnow();
    }

    /**
     * Register the root /{key}.txt provider with the Well-Known router.
     *
     * @return void
     */
    public function register_key_provider(): void {
        // Toggle/deference are re-checked in the callback at request time;
        // registration itself needs the key to exist only when enabled —
        // never generate one while the feature is dark.
        if ( ! class_exists( __NAMESPACE__ . '\\Agent_Readiness_Options' )
            || ! Agent_Readiness_Options::is_feature_enabled( 'indexnow' ) ) {
            return;
        }

        $key = Agent_Readiness_Options::get_or_create_indexnow_key();
        if ( '' === $key ) {
            return;
        }

        Well_Known::register(
            $key . '.txt',
            [ $this, 'render_key_file' ],
            'text/plain; charset=utf-8',
            [ 'noindex' => true ]
        );
    }

    /**
     * Provider callback: the bare key, or null to decline.
     *
     * Served even when another plugin owns the pinging — a spare key file
     * hurts nothing, but is only exposed while OUR toggle is on.
     *
     * @return string|null
     */
    public function render_key_file() {
        if ( ! class_exists( __NAMESPACE__ . '\\Agent_Readiness_Options' )
            || ! Agent_Readiness_Options::is_feature_enabled( 'indexnow' ) ) {
            return null;
        }

        $key = get_option( Agent_Readiness_Options::OPTION_INDEXNOW_KEY, '' );
        return is_string( $key ) && '' !== $key ? $key : null;
    }

    /**
     * transition_post_status: queue URLs for publish, update, and unpublish.
     *
     * @param string   $new_status New status.
     * @param string   $old_status Old status.
     * @param \WP_Post $post       Post.
     * @return void
     */
    public function on_transition_post_status( $new_status, $old_status, $post ): void {
        if ( ! $post instanceof \WP_Post || ! self::is_active() ) {
            return;
        }

        if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
            return;
        }

        $relevant =
            ( 'publish' === $new_status ) ||                                  // Publish or update-while-published.
            ( 'publish' === $old_status && 'publish' !== $new_status );       // Unpublish/trash → tell engines the URL changed.
        if ( ! $relevant ) {
            return;
        }

        if ( ! in_array( $post->post_type, $this->included_post_types(), true ) ) {
            return;
        }

        if ( '1' === get_post_meta( $post->ID, '_llmagnet_exclude_from_llms', true ) ) {
            return;
        }

        $permalink = get_permalink( $post );
        if ( ! is_string( $permalink ) || '' === $permalink ) {
            return;
        }

        $this->enqueue_url( $permalink );
    }

    /**
     * Add a URL to the queue and arm the debounced single event.
     *
     * @param string $url Absolute URL.
     * @return void
     */
    public function enqueue_url( string $url ): void {
        $queue = get_option( self::OPTION_QUEUE, [] );
        if ( ! is_array( $queue ) ) {
            $queue = [];
        }

        if ( ! in_array( $url, $queue, true ) ) {
            if ( count( $queue ) >= self::QUEUE_MAX ) {
                array_shift( $queue );
            }
            $queue[] = $url;
            update_option( self::OPTION_QUEUE, $queue, false );
        }

        if ( ! wp_next_scheduled( self::PING_EVENT ) ) {
            wp_schedule_single_event( time() + self::DEBOUNCE, self::PING_EVENT );
        }
    }

    /**
     * Cron callback: send one batched ping for everything queued.
     *
     * @return void
     */
    public function process_queue(): void {
        $queue = get_option( self::OPTION_QUEUE, [] );

        // Always drain the queue, even when the feature was just disabled —
        // stale URLs must not fire later if the toggle is re-enabled.
        delete_option( self::OPTION_QUEUE );

        if ( ! is_array( $queue ) || empty( $queue ) || ! self::is_active() ) {
            return;
        }

        $key = Agent_Readiness_Options::get_or_create_indexnow_key();
        if ( '' === $key ) {
            return;
        }

        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        $body = [
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => home_url( '/' . $key . '.txt' ),
            'urlList'     => array_values( $queue ),
        ];

        $response = wp_remote_post(
            self::API_URL,
            [
                'timeout' => 15,
                'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
                'body'    => wp_json_encode( $body ),
            ]
        );

        $this->log_ping(
            count( $queue ),
            is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response ),
            is_wp_error( $response ) ? $response->get_error_message() : ''
        );
    }

    /**
     * Last-20 ping log for the Agent Ready page.
     *
     * @return array<int, array{time: int, urls: int, status: int, error: string}>
     */
    public static function get_log(): array {
        $log = get_option( self::OPTION_LOG, [] );
        return is_array( $log ) ? $log : [];
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    /**
     * Post types included in llms.txt settings (the ping scope).
     *
     * @return string[]
     */
    private function included_post_types(): array {
        if ( ! $this->generator instanceof Generator ) {
            $this->generator = new Generator();
        }

        $settings = $this->generator->get_settings();
        $types    = isset( $settings['post_types'] ) && is_array( $settings['post_types'] )
            ? array_values( array_filter( $settings['post_types'], 'is_string' ) )
            : [];

        return ! empty( $types ) ? $types : [ 'post', 'page' ];
    }

    /**
     * Append a ping result to the rolling log.
     *
     * @param int    $url_count URLs in the batch.
     * @param int    $status    HTTP status (0 on transport error).
     * @param string $error     Error message when transport failed.
     * @return void
     */
    private function log_ping( int $url_count, int $status, string $error ): void {
        $log   = self::get_log();
        $log[] = [
            'time'   => time(),
            'urls'   => $url_count,
            'status' => $status,
            'error'  => $error,
        ];
        $log = array_slice( $log, -20 );
        update_option( self::OPTION_LOG, $log, false );
    }
}
