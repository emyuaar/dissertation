<?php
/**
 * WordPress filesystem API wrapper for plugin file writes.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses WP_Filesystem for writes when available.
 */
class Filesystem_Helper {

	/**
	 * Initialize global $wp_filesystem for direct method.
	 *
	 * @return bool
	 */
	public static function ensure_wp_filesystem() {
		global $wp_filesystem;
		if ( $wp_filesystem ) {
			return true;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			return false;
		}
		return WP_Filesystem();
	}

	/**
	 * Write file contents.
	 *
	 * @param string $path Absolute path.
	 * @param string $content File contents.
	 * @return bool
	 */
	public static function put_contents( $path, $content ) {
		if ( ! self::ensure_wp_filesystem() ) {
			return false;
		}
		global $wp_filesystem;
		$chmod = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
		return $wp_filesystem->put_contents( $path, $content, $chmod );
	}

	/**
	 * Read file contents.
	 *
	 * @param string $path Absolute path.
	 * @return string|false
	 */
	public static function get_contents( $path ) {
		if ( ! self::ensure_wp_filesystem() ) {
			return false;
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem->exists( $path ) ) {
			return false;
		}
		return $wp_filesystem->get_contents( $path );
	}
}
