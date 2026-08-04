<?php
/**
 * Public agent-skills registry (agent-readiness-spec §4.2)
 *
 * Single source of truth for the site's PUBLIC, no-auth agent skills,
 * consumed by three surfaces so they can never disagree:
 *
 * 1. WebMCP tools (`navigator.modelContext`, Feature 4 — Phase E)
 * 2. `/.well-known/agent-card.json` skills (Feature 3.1)
 * 3. `/.well-known/agent-skills` index (Feature 3.2)
 *
 * This registry is intentionally SEPARATE from MCP_Tools (dependency D7):
 * MCP_Tools is the authed JSON-RPC server surface; this registry describes
 * the public, anonymous front-end surface. The content-exposure semantics
 * MUST match MCP_Tools' content tools (`get_content_markdown` /
 * `search_content`): only published, public-post-type, llms.txt-included,
 * non-password-protected content is ever served, and the
 * `_llmagnet_exclude_from_llms` per-post exclusion is respected. Skill
 * descriptions below restate those rules so agents reading the published
 * indices get the same contract the executors enforce.
 *
 * ## Skill entry shape
 *
 * - `id`           (string) snake_case skill id (doubles as the WebMCP tool name).
 * - `title`        (string) Human-readable title.
 * - `description`  (string) Agent-facing description (imperative, parameter docs).
 * - `input_schema` (array)  JSON Schema for arguments.
 * - `surfaces`     (array)  [ 'webmcp' => bool, 'card' => bool ].
 * - `endpoint`     (array)  [ 'type' => 'rest', 'method' => 'GET'|'POST', 'route' => string, 'url' => string ].
 * - `auth`         (string) 'none' — all public skills are anonymous.
 *
 * NOTE: the backing public REST routes (`llm-analytics/v1/public/*`) are
 * implemented in Phase E (Lane E3, task E3-5). Until they land, this
 * registry describes the contract; the well-known indices published from it
 * are accurate as to names/semantics and the endpoints go live at E3.
 *
 * Extend via the `llmagnet_agent_skills` filter.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registry of public agent skills (WebMCP / agent-card / agent-skills).
 */
class Agent_Skills_Registry {

    /**
     * REST namespace + prefix for the public, no-auth routes (Phase E3-5).
     */
    const PUBLIC_REST_BASE = 'llm-analytics/v1/public';

    /**
     * Cached, filtered skills.
     *
     * @var array<string, array>|null
     */
    private $skills;

    /**
     * All skill definitions keyed by skill id, after the `llmagnet_agent_skills` filter.
     *
     * @return array<string, array>
     */
    public function get_skills(): array {
        if ( null !== $this->skills ) {
            return $this->skills;
        }

        $skills = $this->build_skills();

        /**
         * Filter the public agent-skills registry.
         *
         * @param array                 $skills   Skill definitions keyed by skill id.
         * @param Agent_Skills_Registry $registry Registry instance.
         */
        $skills = apply_filters( 'llmagnet_agent_skills', $skills, $this );

        $this->skills = is_array( $skills ) ? $skills : [];
        return $this->skills;
    }

    /**
     * Skills exposed on a given surface
     *
     * @param string $surface 'webmcp' | 'card'.
     * @return array<string, array>
     */
    public function get_skills_for_surface( string $surface ): array {
        $skills = [];
        foreach ( $this->get_skills() as $id => $skill ) {
            if ( ! empty( $skill['surfaces'][ $surface ] ) ) {
                $skills[ $id ] = $skill;
            }
        }
        return $skills;
    }

    /**
     * Default auto-derived skill set (spec §4.3)
     *
     * @return array<string, array>
     */
    private function build_skills(): array {
        $skills = [];

        $skills['search_site'] = [
            'id'           => 'search_site',
            'title'        => __( 'Search Site Content', 'llmagnet-llm-txt-generator' ),
            'description'  => 'Search this site\'s published content by keyword. Pass the search terms in "q". Returns matching posts/pages with title, URL, excerpt, and content type. Only published, public content included in the site\'s llms.txt settings is searched. Use it to find relevant pages before fetching them with get_page_content.',
            'input_schema' => [
                'type'                 => 'object',
                'properties'           => [
                    'q' => [
                        'type'        => 'string',
                        'description' => 'Search keywords.',
                    ],
                ],
                'required'             => [ 'q' ],
                'additionalProperties' => false,
            ],
            'surfaces'     => [ 'webmcp' => true, 'card' => true ],
            'endpoint'     => $this->endpoint( 'GET', 'search' ),
            'auth'         => 'none',
        ];

        $skills['get_page_content'] = [
            'id'           => 'get_page_content',
            'title'        => __( 'Get Page Content as Markdown', 'llmagnet-llm-txt-generator' ),
            'description'  => 'Fetch a page of this site as clean markdown — title, metadata, and body with HTML removed. Pass the page URL in "url". Only published, public, non-password-protected content is served; pages excluded from llms.txt are not available. Use it to actually read the site\'s content.',
            'input_schema' => [
                'type'                 => 'object',
                'properties'           => [
                    'url' => [
                        'type'        => 'string',
                        'description' => 'Full URL of the page on this site.',
                    ],
                ],
                'required'             => [ 'url' ],
                'additionalProperties' => false,
            ],
            'surfaces'     => [ 'webmcp' => true, 'card' => true ],
            'endpoint'     => $this->endpoint( 'GET', 'content' ),
            'auth'         => 'none',
        ];

        $skills['list_recent_content'] = [
            'id'           => 'list_recent_content',
            'title'        => __( 'List Recent Content', 'llmagnet-llm-txt-generator' ),
            'description'  => 'List this site\'s most recently published content. Optionally filter by post type with "type" and cap the result count with "limit" (max 20). Returns title, URL, date, and type for each item. Only published, public content is listed.',
            'input_schema' => [
                'type'                 => 'object',
                'properties'           => [
                    'type'  => [
                        'type'        => 'string',
                        'description' => 'Post type to filter by (e.g. "post", "page", "product"). Omit for all types.',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Maximum number of items to return (1–20).',
                        'minimum'     => 1,
                        'maximum'     => 20,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'surfaces'     => [ 'webmcp' => true, 'card' => true ],
            'endpoint'     => $this->endpoint( 'GET', 'recent' ),
            'auth'         => 'none',
        ];

        $skills['get_site_info'] = [
            'id'           => 'get_site_info',
            'title'        => __( 'Get Site Information', 'llmagnet-llm-txt-generator' ),
            'description'  => 'Get basic machine-readable facts about this site: name, tagline, language, llms.txt URL, and feed URLs. Takes no arguments. Call it first to orient yourself before using the content skills.',
            'input_schema' => [
                'type'                 => 'object',
                'properties'           => new \stdClass(),
                'additionalProperties' => false,
            ],
            'surfaces'     => [ 'webmcp' => true, 'card' => true ],
            'endpoint'     => $this->endpoint( 'GET', 'site-info' ),
            'auth'         => 'none',
        ];

        // Commerce skill — WooCommerce active + Plus/Enterprise plan only
        // (spec §4.3 / Freemius gating table).
        if ( self::is_woo_active() && self::has_commerce_plan() ) {
            $skills['search_products'] = [
                'id'           => 'search_products',
                'title'        => __( 'Search Products', 'llmagnet-llm-txt-generator' ),
                'description'  => 'Search this store\'s published products by keyword. Pass the search terms in "q". Returns title, price, stock status, and URL for each match. Only published, publicly visible products are searched.',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'q' => [
                            'type'        => 'string',
                            'description' => 'Product search keywords.',
                        ],
                    ],
                    'required'             => [ 'q' ],
                    'additionalProperties' => false,
                ],
                'surfaces'     => [ 'webmcp' => true, 'card' => true ],
                'endpoint'     => $this->endpoint( 'GET', 'products' ),
                'auth'         => 'none',
            ];
        }

        // NOTE: `add_to_cart` (spec §4.3, write action, default OFF) is a
        // client-side WebMCP tool calling Woo's own Store API — it ships with
        // the WebMCP loader in Phase E (E3-7), not from this registry's
        // public-read defaults.

        return $skills;
    }

    /**
     * Endpoint descriptor for a public REST route
     *
     * @param string $method HTTP method.
     * @param string $slug   Route slug under PUBLIC_REST_BASE.
     * @return array{type: string, method: string, route: string, url: string}
     */
    private function endpoint( string $method, string $slug ): array {
        $route = self::PUBLIC_REST_BASE . '/' . $slug;
        return [
            'type'   => 'rest',
            'method' => $method,
            'route'  => $route,
            'url'    => rest_url( $route ),
        ];
    }

    /**
     * Whether WooCommerce is active
     *
     * @return bool
     */
    private static function is_woo_active(): bool {
        return class_exists( 'WooCommerce', false ) || function_exists( 'wc_get_product' );
    }

    /**
     * Whether the install is on a commerce-capable plan (Plus / Enterprise)
     *
     * Server-side gate, mirroring the pattern used by Schema_Jsonld.
     *
     * @return bool
     */
    private static function has_commerce_plan(): bool {
        if ( ! function_exists( 'lltg_fs' ) ) {
            return false;
        }

        $fs = lltg_fs();

        return $fs->is_plan( 'plus' ) || $fs->is_plan( 'enterprise' );
    }
}
