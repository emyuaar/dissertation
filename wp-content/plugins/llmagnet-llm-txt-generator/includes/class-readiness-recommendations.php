<?php
/**
 * Structured AI-readiness recommendations for page/product drawers (P3-1).
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds actionable recommendation rows with optional admin deep-links.
 */
class Readiness_Recommendations {

	/**
	 * Build one recommendation item.
	 *
	 * @param string      $id           Stable identifier.
	 * @param string      $message      Human-readable recommendation.
	 * @param string|null $action_label Link/button label.
	 * @param string|null $action_url   Admin URL to fix the issue.
	 * @return array{id: string, message: string, action_label?: string, action_url?: string}
	 */
	public static function item( string $id, string $message, ?string $action_label = null, ?string $action_url = null ): array {
		$row = [
			'id'      => $id,
			'message' => $message,
		];

		if ( $action_label && $action_url ) {
			$row['action_label'] = $action_label;
			$row['action_url']   = $action_url;
		}

		return $row;
	}

	/**
	 * Admin URL for a plugin React page slug.
	 *
	 * @param string $page_slug `?page=` value.
	 * @return string
	 */
	public static function admin_page_url( string $page_slug ): string {
		return admin_url( 'admin.php?page=' . $page_slug );
	}

	/**
	 * Schema types detected for a URL in the last LLMagnet scan.
	 *
	 * @param string $url Permalink or path-normalized URL.
	 * @return string[]
	 */
	public static function schema_types_for_url( string $url ): array {
		$scan = get_option( Schema_Jsonld::OPTION_LAST_SCAN, [] );
		if ( ! is_array( $scan ) || empty( $scan['pages'] ) ) {
			return [];
		}

		$needle = untrailingslashit( $url );

		foreach ( $scan['pages'] as $page ) {
			if ( ! is_array( $page ) || empty( $page['url'] ) ) {
				continue;
			}
			if ( untrailingslashit( (string) $page['url'] ) === $needle ) {
				$types = isset( $page['types_found'] ) && is_array( $page['types_found'] ) ? $page['types_found'] : [];
				return array_values( array_filter( array_map( 'strval', $types ) ) );
			}
		}

		return [];
	}

	/**
	 * Whether a post is included in llms.txt exports.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_in_llms_txt( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		if ( get_post_meta( $post_id, '_llmagnet_exclude_from_llms', true ) ) {
			return false;
		}

		$settings    = get_option( Generator::OPTION_NAME, [] );
		$post_types  = isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ? $settings['post_types'] : [ 'post', 'page' ];

		return in_array( $post->post_type, $post_types, true );
	}
}
