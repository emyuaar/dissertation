<?php
/**
 * Product news feed (JSON) — fetched server-side so private URLs/tokens stay off the browser.
 *
 * Notifications may include `conditions`: outer array is OR (any group matches); each inner
 * array is AND (all conditions in the group). Types: `plugin` (active / not), `plan` (current plan slug).
 *
 * Default feed: llmagnet-wp-news (Vercel). Override via wp-config.php:
 *
 *   define( 'LLMAGNET_NEWS_JSON_URL', 'https://example.com/news.json' );
 *   define( 'LLMAGNET_NEWS_JSON_AUTH_HEADER', 'Bearer …' ); // optional
 *
 * Filters: `llmagnet_news_json_url`, `llmagnet_news_remote_request_args`, `llmagnet_news_condition_matches`.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

/**
 * News feed REST handler
 */
class News_Feed {

    /** Raw remote payload (items unfiltered); filter runs on each request so plan/plugin changes apply without waiting on cache. */
    const CACHE_KEY = 'llmagnet_news_feed_cache_v3';
    const CACHE_TTL = 1800; // 30 minutes

    /** @var string Default notifications JSON (Elementor-style: notifications + lastUpdated). */
    const DEFAULT_FEED_URL = 'https://llmagnet-wp-news.vercel.app/data/notifications.full-sample.json';

    /**
     * Register REST routes
     *
     * @return void
     */
    public function register_rest_routes() {
        \register_rest_route(
            'llm-analytics/v1',
            '/news',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'rest_get_news' ],
                'permission_callback' => function () {
                    return \current_user_can( 'manage_options' );
                },
            ]
        );
    }

    /**
     * GET /news — returns JSON from remote feed or cache, filtered by conditions for this site.
     *
     * @return \WP_REST_Response
     */
    public function rest_get_news() {
        $url = $this->get_feed_url();

        $cached = \get_transient( self::CACHE_KEY );
        if ( false !== $cached && is_array( $cached ) && isset( $cached['items'] ) && is_array( $cached['items'] ) ) {
            return $this->build_response( $cached, true );
        }

        $args = [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ];

        if ( \defined( 'LLMAGNET_NEWS_JSON_AUTH_HEADER' ) && \LLMAGNET_NEWS_JSON_AUTH_HEADER ) {
            $args['headers']['Authorization'] = \LLMAGNET_NEWS_JSON_AUTH_HEADER;
        }

        /**
         * Filter remote request args for the news JSON URL (add headers, user-agent, etc.).
         *
         * @param array  $args Request args for wp_remote_get.
         * @param string $url  Resolved feed URL.
         */
        $args = \apply_filters( 'llmagnet_news_remote_request_args', $args, $url );

        $response = \wp_remote_get( \esc_url_raw( $url ), $args );

        if ( \is_wp_error( $response ) ) {
            return new \WP_REST_Response(
                [
                    'success' => false,
                    'error'   => $response->get_error_message(),
                ],
                200
            );
        }

        $code = \wp_remote_retrieve_response_code( $response );
        $body = \wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            return new \WP_REST_Response(
                [
                    'success' => false,
                    'error'   => sprintf(
                        /* translators: %d HTTP status code */
                        __( 'Remote server returned HTTP %d', 'llmagnet-llm-txt-generator' ),
                        (int) $code
                    ),
                ],
                200
            );
        }

        $decoded = \json_decode( $body, true );
        if ( null === $decoded || JSON_ERROR_NONE !== \json_last_error() ) {
            return new \WP_REST_Response(
                [
                    'success' => false,
                    'error'   => __( 'Invalid JSON in news feed.', 'llmagnet-llm-txt-generator' ),
                ],
                200
            );
        }

        $items = [];
        if ( isset( $decoded['notifications'] ) && is_array( $decoded['notifications'] ) ) {
            $items = $decoded['notifications'];
        } elseif ( isset( $decoded['items'] ) && is_array( $decoded['items'] ) ) {
            $items = $decoded['items'];
        } else {
            return new \WP_REST_Response(
                [
                    'success' => false,
                    'error'   => __( 'News feed must include a "notifications" or "items" array.', 'llmagnet-llm-txt-generator' ),
                ],
                200
            );
        }

        if ( isset( $decoded['lastUpdated'] ) && is_string( $decoded['lastUpdated'] ) && $decoded['lastUpdated'] !== '' ) {
            $feed_version = $decoded['lastUpdated'];
        } elseif ( isset( $decoded['feedVersion'] ) && is_string( $decoded['feedVersion'] ) && $decoded['feedVersion'] !== '' ) {
            $feed_version = $decoded['feedVersion'];
        } else {
            $feed_version = md5( \wp_json_encode( $items ) );
        }

        $last_updated = isset( $decoded['lastUpdated'] ) && is_string( $decoded['lastUpdated'] ) ? $decoded['lastUpdated'] : '';

        $stored = [
            'success'     => true,
            'configured'  => true,
            'feedVersion' => $feed_version,
            'lastUpdated' => $last_updated,
            'items'       => $items,
        ];

        \set_transient( self::CACHE_KEY, $stored, self::CACHE_TTL );

        return $this->build_response( $stored, false );
    }

    /**
     * Apply filters, strip conditions from payload, return REST response.
     *
     * @param array $stored Raw cached or fresh structure.
     * @param bool  $from_cache Whether this came from transient.
     * @return \WP_REST_Response
     */
    private function build_response( array $stored, $from_cache ) {
        $raw_items = isset( $stored['items'] ) && is_array( $stored['items'] ) ? $stored['items'] : [];
        $filtered    = $this->filter_notifications_by_conditions( $raw_items );
        $stripped    = $this->strip_conditions_from_notifications( $filtered );
        $for_client  = $this->resolve_template_vars_in_items( $stripped );

        $payload = [
            'success'       => true,
            'configured'    => ! empty( $stored['configured'] ),
            'cached'        => $from_cache,
            'feedVersion'   => isset( $stored['feedVersion'] ) ? (string) $stored['feedVersion'] : '',
            'lastUpdated'   => isset( $stored['lastUpdated'] ) ? (string) $stored['lastUpdated'] : '',
            'items'         => $for_client,
            'planContext'   => $this->get_plan_context_for_client(),
        ];

        return new \WP_REST_Response( $payload, 200 );
    }

    /**
     * Current plan slug for JSON `plan` conditions: trial | free | pro | plus | enterprise.
     *
     * @return string
     */
    private function get_current_plan_slug() {
        $data = $this->get_freemius_plan_snapshot();
        if ( ! empty( $data['is_trial'] ) ) {
            return 'trial';
        }
        $name = isset( $data['plan_name'] ) ? (string) $data['plan_name'] : 'free';
        return in_array( $name, [ 'free', 'pro', 'plus', 'enterprise' ], true ) ? $name : 'free';
    }

    /**
     * Minimal plan snapshot (aligned with Admin::get_plan_data).
     *
     * @return array{plan_name: string, is_trial: bool}
     */
    private function get_freemius_plan_snapshot() {
        $out = [
            'plan_name' => 'free',
            'is_trial'  => false,
        ];
        if ( function_exists( 'lltg_fs' ) ) {
            $fs = \lltg_fs();
            if ( $fs->is_plan( 'enterprise' ) ) {
                $out['plan_name'] = 'enterprise';
            } elseif ( $fs->is_plan( 'plus' ) ) {
                $out['plan_name'] = 'plus';
            } elseif ( $fs->is_plan( 'pro' ) ) {
                $out['plan_name'] = 'pro';
            } else {
                $out['plan_name'] = 'free';
            }
            $out['is_trial'] = $fs->is_trial();
        }
        return $out;
    }

    /**
     * Optional debug for UI (same values used for condition evaluation).
     *
     * @return array{plan: string, is_trial: bool}
     */
    private function get_plan_context_for_client() {
        $snap = $this->get_freemius_plan_snapshot();
        return [
            'plan'     => $this->get_current_plan_slug(),
            'is_trial' => ! empty( $snap['is_trial'] ),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items Raw notifications.
     * @return array<int, array<string, mixed>>
     */
    private function filter_notifications_by_conditions( array $items ) {
        $out = [];
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            if ( $this->notification_matches_conditions( $item ) ) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $notification Single notification.
     * @return bool
     */
    private function notification_matches_conditions( array $notification ) {
        if ( ! isset( $notification['conditions'] ) || ! is_array( $notification['conditions'] ) ) {
            return true;
        }
        $groups = $notification['conditions'];
        if ( count( $groups ) === 0 ) {
            return true;
        }
        // OR across groups; each group is AND of its conditions.
        foreach ( $groups as $group ) {
            if ( ! is_array( $group ) ) {
                continue;
            }
            if ( $this->condition_group_matches( $group ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * AND within one group.
     *
     * @param array<int, array<string, mixed>> $group List of conditions.
     * @return bool
     */
    private function condition_group_matches( array $group ) {
        if ( count( $group ) === 0 ) {
            return false;
        }
        foreach ( $group as $cond ) {
            if ( ! is_array( $cond ) ) {
                return false;
            }
            if ( ! $this->single_condition_matches( $cond ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $cond Condition object.
     * @return bool
     */
    private function single_condition_matches( array $cond ) {
        $type = isset( $cond['type'] ) ? (string) $cond['type'] : '';

        if ( 'plugin' === $type ) {
            $plugin   = isset( $cond['plugin'] ) ? (string) $cond['plugin'] : '';
            $operator = isset( $cond['operator'] ) ? (string) $cond['operator'] : '==';
            $active   = $this->is_plugin_active_path( $plugin );
            if ( '!=' === $operator ) {
                return ! $active;
            }
            return $active;
        }

        if ( 'plan' === $type ) {
            $expected = isset( $cond['plan'] ) ? strtolower( (string) $cond['plan'] ) : '';
            $operator = isset( $cond['operator'] ) ? (string) $cond['operator'] : '==';
            $current  = strtolower( $this->get_current_plan_slug() );
            if ( '!=' === $operator ) {
                return $current !== $expected;
            }
            return $current === $expected;
        }

        /**
         * Fires for unknown condition types; default false until extended.
         *
         * @param bool  $matches Default match result.
         * @param array $cond    Condition.
         * @param self  $feed    This instance.
         */
        return (bool) \apply_filters( 'llmagnet_news_condition_matches', false, $cond, $this );
    }

    /**
     * Returns true if the given plugin file is active (site-level or network-level).
     *
     * `is_plugin_active()` only covers site-level activation; on Multisite installations
     * (or any setup using network-wide activation) we must also consult
     * `is_plugin_active_for_network()`, otherwise network-activated plugins like
     * WooCommerce will appear inactive even when they are running.
     *
     * @param string $plugin Relative path e.g. "woocommerce/woocommerce.php"
     * @return bool
     */
    private function is_plugin_active_path( $plugin ) {
        if ( ! is_string( $plugin ) || $plugin === '' ) {
            return false;
        }
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( \is_plugin_active( $plugin ) ) {
            return true;
        }
        if ( \is_multisite() && function_exists( 'is_plugin_active_for_network' ) && \is_plugin_active_for_network( $plugin ) ) {
            return true;
        }
        return false;
    }

    /**
     * Resolve `{{VARIABLE}}` placeholders in `ctaLink` and `link` fields.
     *
     * Supported variables:
     *   {{URL}} — site base URL (no trailing slash), from get_site_url().
     *
     * Links that contained at least one placeholder are considered internal and
     * get an extra boolean field (`ctaLinkInternal` / `linkInternal` = true) so
     * the React layer can open them in the same tab instead of a new window.
     *
     * @param array<int, array<string, mixed>> $items Notifications (conditions already stripped).
     * @return array<int, array<string, mixed>>
     */
    private function resolve_template_vars_in_items( array $items ) {
        $vars = [
            'URL' => untrailingslashit( \get_site_url() ),
        ];

        $pattern = '/\{\{(\w+)\}\}/';

        $out = [];
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            foreach ( [ 'ctaLink' => 'ctaLinkInternal', 'link' => 'linkInternal' ] as $field => $flag ) {
                if ( ! isset( $item[ $field ] ) || ! is_string( $item[ $field ] ) ) {
                    continue;
                }
                $original = $item[ $field ];
                if ( ! preg_match( $pattern, $original ) ) {
                    continue;
                }
                $resolved = preg_replace_callback(
                    $pattern,
                    function ( $m ) use ( $vars ) {
                        return isset( $vars[ $m[1] ] ) ? $vars[ $m[1] ] : '';
                    },
                    $original
                );
                $item[ $field ] = is_string( $resolved ) ? $resolved : $original;
                $item[ $flag ]  = true;
            }

            $out[] = $item;
        }
        return $out;
    }

    /**
     * Remove `conditions` from each item before sending to the browser.
     *
     * @param array<int, array<string, mixed>> $items Notifications.
     * @return array<int, array<string, mixed>>
     */
    private function strip_conditions_from_notifications( array $items ) {
        $out = [];
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            unset( $item['conditions'] );
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Resolve JSON URL from constant or filter.
     *
     * @return string
     */
    private function get_feed_url() {
        if ( \defined( 'LLMAGNET_NEWS_JSON_URL' ) && \LLMAGNET_NEWS_JSON_URL ) {
            $url = (string) \LLMAGNET_NEWS_JSON_URL;
        } else {
            $url = self::DEFAULT_FEED_URL;
        }
        /**
         * Filter the news JSON URL (e.g. read from a custom option).
         *
         * @param string $url Default URL.
         */
        $url = \apply_filters( 'llmagnet_news_json_url', $url );
        return is_string( $url ) ? trim( $url ) : '';
    }
}
