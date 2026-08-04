<?php
/**
 * HTML foundations & Open Graph verify-or-fill (agent-readiness-spec F7 — FA-6)
 *
 * Principle: NEVER double-emit. Every emitter asks Seo_Plugin_Detector
 * first; when another plugin owns the capability we do nothing and the
 * audit reports `handled_externally` ("handled by Yoast SEO"). Same
 * complement-not-compete stance as Schema_Jsonld.
 *
 * Fills, each individually gated and only when nothing owns them:
 *
 * - Open Graph (`og_fill` feature toggle): og:title, og:description
 *   (excerpt → trimmed content), og:image (featured → first content image
 *   → site icon), og:url, og:type (article/website/product), og:site_name,
 *   plus twitter:card. Per-post overrides come later via the planned
 *   Gutenberg panel — no meta box here.
 * - Canonical (`llmagnet_canonical_fill` option): rel=canonical = permalink
 *   on singular, paged-aware archive links elsewhere. Safety net for
 *   no-SEO-plugin sites only.
 * - html lang (with `og_fill`): adds the get_locale()-derived BCP 47 tag
 *   via the `language_attributes` filter ONLY when the theme omitted it.
 * - Meta description (`llmagnet_meta_description_fill` option, default OFF
 *   — the audit copy encourages a real SEO plugin instead): from excerpt
 *   on singular views.
 *
 * Heading hierarchy / viewport / doctype / charset / favicon stay
 * audit-only (theme scope).
 *
 * All emission happens in wp_head at priority 4 (before most theme output,
 * after charset/viewport at 0–2). Everything default OFF.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * F7 verify-or-fill emitters.
 */
class Open_Graph {

    /**
     * Canonical fill opt-in (bool option, default false, autoload off).
     */
    const OPTION_CANONICAL_FILL = 'llmagnet_canonical_fill';

    /**
     * Meta-description fill opt-in (bool option, default false, autoload off).
     */
    const OPTION_METADESC_FILL = 'llmagnet_meta_description_fill';

    /**
     * Wire hooks.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'wp_head', [ $this, 'output_tags' ], 4 );
        add_filter( 'language_attributes', [ $this, 'ensure_lang_attribute' ], 20 );
    }

    /**
     * Whether the og_fill feature toggle is on (default OFF).
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return class_exists( __NAMESPACE__ . '\\Agent_Readiness_Options' )
            && Agent_Readiness_Options::is_feature_enabled( 'og_fill' );
    }

    /**
     * wp_head: emit OG/twitter, canonical, meta description per gating rules.
     *
     * @return void
     */
    public function output_tags(): void {
        if ( is_admin() || is_feed() || is_404() ) {
            return;
        }

        $out = '';

        if ( self::is_enabled() && ! Seo_Plugin_Detector::owns_og() ) {
            $out .= $this->build_og_tags();
        }

        if ( get_option( self::OPTION_CANONICAL_FILL, false ) && ! Seo_Plugin_Detector::owns_canonical() ) {
            $out .= $this->build_canonical_tag();
        }

        if (
            is_singular()
            && get_option( self::OPTION_METADESC_FILL, false )
            && ! Seo_Plugin_Detector::owns_meta_description()
        ) {
            $description = $this->get_description();
            if ( '' !== $description ) {
                $out .= '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
            }
        }

        if ( '' !== $out ) {
            echo "<!-- LLMagnet agent-readiness tags -->\n" . $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each tag is escaped at build time.
        }
    }

    /**
     * language_attributes filter: add lang only when the theme omitted it.
     *
     * @param string $output Attribute string from the theme/core.
     * @return string
     */
    public function ensure_lang_attribute( $output ) {
        if ( ! self::is_enabled() || ! is_string( $output ) ) {
            return $output;
        }

        if ( false !== stripos( $output, 'lang=' ) ) {
            return $output;
        }

        // get_locale() gives e.g. en_US → BCP 47 en-US.
        $lang = str_replace( '_', '-', get_locale() );
        if ( '' === $lang ) {
            return $output;
        }

        return trim( $output . ' lang="' . esc_attr( $lang ) . '"' );
    }

    // ── Builders ───────────────────────────────────────────────────────────────

    /**
     * Open Graph + twitter:card tag block for the current view.
     *
     * @return string Escaped HTML.
     */
    private function build_og_tags(): string {
        $title = is_singular()
            ? html_entity_decode( get_the_title(), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
            : wp_get_document_title();

        $url = is_singular() ? get_permalink() : home_url( add_query_arg( [] ) );
        if ( ! is_string( $url ) || '' === $url ) {
            $url = home_url( '/' );
        }

        $description = $this->get_description();
        $image       = $this->get_image();

        $type = 'website';
        if ( is_singular( 'product' ) ) {
            $type = 'product';
        } elseif ( is_singular() && ! is_page() && ! is_front_page() ) {
            $type = 'article';
        }

        $tags = [
            [ 'property', 'og:title', $title ],
            [ 'property', 'og:type', $type ],
            [ 'property', 'og:url', $url ],
            [ 'property', 'og:site_name', get_bloginfo( 'name' ) ],
        ];
        if ( '' !== $description ) {
            $tags[] = [ 'property', 'og:description', $description ];
        }
        if ( '' !== $image ) {
            $tags[] = [ 'property', 'og:image', $image ];
        }
        if ( is_singular() && 'article' === $type ) {
            $tags[] = [ 'property', 'article:published_time', get_the_date( 'c' ) ];
            $tags[] = [ 'property', 'article:modified_time', get_the_modified_date( 'c' ) ];
        }

        $tags[] = [ 'name', 'twitter:card', '' !== $image ? 'summary_large_image' : 'summary' ];

        $html = '';
        foreach ( $tags as list( $attr, $key, $value ) ) {
            if ( ! is_string( $value ) || '' === $value ) {
                continue;
            }
            $html .= sprintf(
                '<meta %s="%s" content="%s">' . "\n",
                $attr, // 'property' or 'name' — literals above.
                esc_attr( $key ),
                'og:url' === $key || 'og:image' === $key ? esc_url( $value ) : esc_attr( $value )
            );
        }

        return $html;
    }

    /**
     * rel=canonical for the current view (singular + paged-aware archives).
     *
     * @return string Escaped HTML ('' when not determinable).
     */
    private function build_canonical_tag(): string {
        $canonical = '';

        if ( is_singular() ) {
            // wp_get_canonical_url() is page/paged aware for singular.
            $canonical = wp_get_canonical_url();
        } elseif ( is_front_page() || is_home() ) {
            $paged     = max( 1, (int) get_query_var( 'paged' ) );
            $canonical = $paged > 1 ? get_pagenum_link( $paged ) : home_url( '/' );
        } elseif ( is_category() || is_tag() || is_tax() ) {
            $term_link = get_term_link( get_queried_object() );
            if ( ! is_wp_error( $term_link ) ) {
                $paged     = max( 1, (int) get_query_var( 'paged' ) );
                $canonical = $paged > 1 ? get_pagenum_link( $paged ) : $term_link;
            }
        } elseif ( is_post_type_archive() ) {
            $archive = get_post_type_archive_link( get_query_var( 'post_type' ) );
            if ( is_string( $archive ) ) {
                $paged     = max( 1, (int) get_query_var( 'paged' ) );
                $canonical = $paged > 1 ? get_pagenum_link( $paged ) : $archive;
            }
        }

        if ( ! is_string( $canonical ) || '' === $canonical ) {
            return '';
        }

        return '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
    }

    /**
     * Description: excerpt → trimmed content → site tagline.
     *
     * @return string Plain text.
     */
    private function get_description(): string {
        if ( is_singular() ) {
            $post = get_post();
            if ( $post instanceof \WP_Post ) {
                $raw = '' !== $post->post_excerpt
                    ? $post->post_excerpt
                    : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 30, '…' );
                return trim( wp_strip_all_tags( (string) $raw ) );
            }
        }

        return trim( (string) get_bloginfo( 'description' ) );
    }

    /**
     * og:image: featured image → first content <img> → site icon.
     *
     * @return string URL or ''.
     */
    private function get_image(): string {
        if ( is_singular() ) {
            $post = get_post();
            if ( $post instanceof \WP_Post ) {
                if ( has_post_thumbnail( $post ) ) {
                    $thumb = wp_get_attachment_image_url( get_post_thumbnail_id( $post ), 'large' );
                    if ( is_string( $thumb ) && '' !== $thumb ) {
                        return $thumb;
                    }
                }

                if ( preg_match( '/<img\b[^>]*src=["\']([^"\']+)["\']/i', (string) $post->post_content, $m ) ) {
                    $src = $m[1];
                    if ( 0 === strpos( $src, '//' ) ) {
                        $src = ( is_ssl() ? 'https:' : 'http:' ) . $src;
                    } elseif ( 0 === strpos( $src, '/' ) ) {
                        $src = home_url( $src );
                    }
                    if ( false !== filter_var( $src, FILTER_VALIDATE_URL ) ) {
                        return $src;
                    }
                }
            }
        }

        $icon = get_site_icon_url( 512 );
        return is_string( $icon ) ? $icon : '';
    }
}
