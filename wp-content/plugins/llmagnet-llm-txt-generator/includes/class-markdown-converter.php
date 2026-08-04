<?php
/**
 * Shared HTML → Markdown converter (agent-readiness-spec Feature 5)
 *
 * THE single converter for every surface that turns post HTML into
 * markdown (dependency D4 — "last Generator touch"):
 *
 * - Generator per-post exports in /llms-docs/ (class-generator.php).
 * - Markdown endpoints: `{permalink}.md` + `Accept: text/markdown`
 *   content negotiation (class-markdown-endpoints.php).
 * - MCP `get_content_markdown` / WebMCP `get_page_content` — these carry
 *   an interim private copy in class-mcp-tools.php that lane F-C replaces
 *   with this class (see docs/handoffs/fa-main-snippet.md §"MCP_Tools
 *   converter swap"). Do NOT edit class-mcp-tools.php from lane F-A.
 *
 * The conversion is the richer of the two pre-extraction implementations
 * (the MCP read-path one), extended with the Generator's shortcode
 * pre-processing so the Generator could delegate without behavior gaps:
 * headings, emphasis, links, images, code/pre, blockquotes, lists, hr/br,
 * paragraph separation, full entity decode, whitespace normalization.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Stateless HTML → Markdown conversion.
 */
class Markdown_Converter {

    /**
     * Convert an HTML fragment to markdown.
     *
     * @param string $html               HTML content.
     * @param bool   $process_shortcodes Run do_shortcode() first. Use true when
     *                                   passing raw post_content (Generator path);
     *                                   false when the input already went through
     *                                   `the_content` filters (MCP/WebMCP path).
     * @return string Markdown.
     */
    public static function convert( $html, bool $process_shortcodes = false ): string {
        $content = (string) $html;

        if ( $process_shortcodes && function_exists( 'do_shortcode' ) ) {
            $content = do_shortcode( $content );
        }

        // Drop non-content elements entirely.
        $content = preg_replace( '/<(script|style|noscript|iframe|form|svg)\b[^>]*>.*?<\/\1>/is', '', $content );
        $content = preg_replace( '/<!--.*?-->/s', '', $content );

        // Headings.
        for ( $i = 6; $i >= 1; $i-- ) {
            $content = preg_replace_callback(
                "/<h{$i}\b[^>]*>(.*?)<\/h{$i}>/is",
                static function ( $m ) use ( $i ) {
                    return "\n\n" . str_repeat( '#', $i ) . ' ' . trim( wp_strip_all_tags( $m[1] ) ) . "\n\n";
                },
                $content
            );
        }

        // Images (before links so linked images keep their alt text).
        $content = preg_replace_callback(
            '/<img\b[^>]*>/i',
            static function ( $m ) {
                $alt = preg_match( '/alt=["\']([^"\']*)["\']/i', $m[0], $a ) ? $a[1] : '';
                $src = preg_match( '/src=["\']([^"\']*)["\']/i', $m[0], $s ) ? $s[1] : '';
                return $src ? "![{$alt}]({$src})" : '';
            },
            $content
        );

        // Links.
        $content = preg_replace_callback(
            '/<a\b[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is',
            static function ( $m ) {
                $text = trim( wp_strip_all_tags( $m[2] ) );
                return '' !== $text ? "[{$text}]({$m[1]})" : '';
            },
            $content
        );

        // Emphasis.
        $content = preg_replace( '/<(strong|b)\b[^>]*>(.*?)<\/\1>/is', '**$2**', $content );
        $content = preg_replace( '/<(em|i)\b[^>]*>(.*?)<\/\1>/is', '*$2*', $content );

        // Code.
        $content = preg_replace_callback(
            '/<pre\b[^>]*>(.*?)<\/pre>/is',
            static function ( $m ) {
                return "\n\n```\n" . trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) . "\n```\n\n";
            },
            $content
        );
        $content = preg_replace( '/<code\b[^>]*>(.*?)<\/code>/is', '`$1`', $content );

        // Blockquotes.
        $content = preg_replace_callback(
            '/<blockquote\b[^>]*>(.*?)<\/blockquote>/is',
            static function ( $m ) {
                $inner = trim( wp_strip_all_tags( $m[1] ) );
                return "\n\n> " . str_replace( "\n", "\n> ", $inner ) . "\n\n";
            },
            $content
        );

        // List items.
        $content = preg_replace_callback(
            '/<li\b[^>]*>(.*?)<\/li>/is',
            static function ( $m ) {
                return "\n- " . trim( wp_strip_all_tags( $m[1] ) );
            },
            $content
        );

        // Block-level separators.
        $content = preg_replace( '/<\/(p|div|ul|ol|table|tr|section|article)>/i', "\n\n", $content );
        $content = preg_replace( '/<br\s*\/?>/i', "\n", $content );
        $content = preg_replace( '/<hr\s*\/?>/i', "\n\n---\n\n", $content );

        // Strip the rest, decode entities, normalize whitespace.
        $content = wp_strip_all_tags( $content );
        $content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $content = preg_replace( '/[ \t]+/', ' ', $content );
        $content = preg_replace( '/\n{3,}/', "\n\n", $content );

        return trim( $content );
    }
}
