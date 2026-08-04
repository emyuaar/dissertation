<?php
/**
 * Elementor page-settings integration for per-page JSON-LD schema.
 *
 * Per-page schema is merged into the single LLMagnet JSON-LD output (see
 * Schema_Jsonld::output_published_jsonld) — never printed as its own block.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor document settings: JSON-LD Schema field.
 */
class Elementor_Schema {

	/**
	 * Elementor document setting key (stored in post meta via Elementor).
	 */
	public const SETTING_KEY = 'llmagnet_page_json_ld';

	/**
	 * Register hooks when Elementor is available.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! Elementor_Integration::is_active() ) {
			return;
		}

		// Primary: works for pages (incl. static homepage), posts, and CPTs with elements.
		add_action( 'elementor/documents/register_controls', [ $this, 'register_schema_controls' ] );

		// Legacy stack-name hooks (Elementor version / install dependent).
		foreach ( [ 'wp-post', 'wp-page', 'post', 'page' ] as $stack ) {
			add_action(
				"elementor/element/{$stack}/document_settings/after_section_end",
				[ $this, 'add_schema_controls' ],
				10,
				2
			);
		}
	}

	/**
	 * @param \Elementor\Core\Base\Document $document Document.
	 * @return void
	 */
	public function register_schema_controls( $document ): void {
		if ( ! class_exists( '\Elementor\Core\DocumentTypes\PageBase' ) ) {
			return;
		}
		if ( ! $document instanceof \Elementor\Core\DocumentTypes\PageBase ) {
			return;
		}
		if ( ! $document::get_property( 'has_elements' ) ) {
			return;
		}

		static $registered = [];
		$doc_id = method_exists( $document, 'get_main_id' ) ? (int) $document->get_main_id() : 0;
		if ( $doc_id > 0 && isset( $registered[ $doc_id ] ) ) {
			return;
		}
		if ( $doc_id > 0 ) {
			$registered[ $doc_id ] = true;
		}

		$this->add_schema_controls( $document, '' );
	}

	/**
	 * Elementor editor URL for a post (opens the builder).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function editor_url( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return '';
		}
		return admin_url( 'post.php?post=' . $post_id . '&action=elementor' );
	}

	/**
	 * @param \Elementor\Core\Base\Document $document Document.
	 * @param string                        $section_id Section ID (unused).
	 * @return void
	 */
	public function add_schema_controls( $document, $section_id ): void {
		unset( $section_id );

		if ( ! $document instanceof \Elementor\Core\Base\Document ) {
			return;
		}

		// Avoid duplicate section when legacy hooks fire after register_controls.
		if ( method_exists( $document, 'get_controls' ) ) {
			$controls = $document->get_controls();
			if ( isset( $controls[ self::SETTING_KEY ] ) || isset( $controls['section_llmagnet_json_ld_schema' ] ) ) {
				return;
			}
		}

		$document->start_controls_section(
			'section_llmagnet_json_ld_schema',
			[
				'label' => esc_html__( 'JSON-LD Schema', 'llmagnet-llm-txt-generator' ),
				'tab'   => \Elementor\Controls_Manager::TAB_SETTINGS,
			]
		);

		$document->add_control(
			self::SETTING_KEY,
			[
				'label'       => esc_html__( 'Schema Markup', 'llmagnet-llm-txt-generator' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'rows'        => 10,
				'description' => esc_html__( 'Enter JSON-LD without <script> tags. Merged into the site LLMagnet schema block (not a separate script). Supports Elementor dynamic tags.', 'llmagnet-llm-txt-generator' ),
				'default'     => "{\n  \"@context\": \"https://schema.org\",\n  \"@type\": \"WebPage\",\n  \"name\": \"\"\n}",
				'dynamic'     => [
					'active' => true,
				],
			]
		);

		$document->end_controls_section();
	}

	/**
	 * Parsed schema.org entities for the current Elementor document.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_page_schema_entities( int $post_id ): array {
		if ( $post_id <= 0 || ! class_exists( '\Elementor\Plugin' ) ) {
			return [];
		}

		$document = \Elementor\Plugin::$instance->documents->get_doc_for_frontend( $post_id );
		if ( ! $document ) {
			return [];
		}

		$schema_raw = '';
		if ( method_exists( $document, 'get_settings_for_display' ) ) {
			$schema_raw = (string) $document->get_settings_for_display( self::SETTING_KEY );
		}
		if ( '' === trim( $schema_raw ) ) {
			$schema_raw = (string) $document->get_settings( self::SETTING_KEY );
		}
		if ( '' === trim( $schema_raw ) ) {
			return [];
		}

		$schema_clean = preg_replace( '/<\/?script[^>]*>/i', '', $schema_raw );
		$schema_clean = trim( (string) $schema_clean );
		if ( '' === $schema_clean ) {
			return [];
		}

		$decoded = json_decode( $schema_clean, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) || empty( $decoded ) ) {
			return [];
		}

		return Schema_Jsonld::data_to_entities( $decoded );
	}
}
