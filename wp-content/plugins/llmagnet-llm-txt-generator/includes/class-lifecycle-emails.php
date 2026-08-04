<?php
/**
 * Lifecycle Emails Manager
 *
 * Orchestrates triggered lifecycle emails including:
 * - Event-based emails (E-02 on first bot visit)
 * - Time-based emails (E-01, E-05 via cron)
 * - Wrapper for all email sends
 * - Deduplication and state management
 *
 * @package LLMagnet
 * @since 1.0.0
 */

namespace LLMagnet\Lifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lifecycle_Emails class
 */
class Lifecycle_Emails {
	/**
	 * Brevo list ID for install-time contact sync.
	 *
	 * @var int
	 */
	const INSTALL_LIST_ID = 10;

	/**
	 * Single event hook used to sync site identity to Brevo.
	 *
	 * @var string
	 */
	const SITE_IDENTITY_SYNC_HOOK = 'llmagnet_brevo_site_identity_sync';

	/**
	 * Contact attribute used for the site URL in Brevo.
	 *
	 * @var string
	 */
	const CONTACT_WEBSITE_ATTRIBUTE = 'WEBSITE';

	/**
	 * Company attribute label for storing the site URL.
	 *
	 * @var string
	 */
	const COMPANY_DOMAIN_ATTRIBUTE_LABEL = 'domain';

	/**
	 * Company attribute label for storing the platform/industry.
	 *
	 * @var string
	 */
	const COMPANY_INDUSTRY_ATTRIBUTE_LABEL = 'industry';

	/**
	 * Company attribute label for onboarding completion flag.
	 *
	 * @var string
	 */
	const COMPANY_ONBOARDING_COMPLETED_ATTRIBUTE_LABEL = 'onboarding_completed';

	/**
	 * Company attribute label for the visibility score.
	 *
	 * @var string
	 */
	const COMPANY_VISIBILITY_SCORE_ATTRIBUTE_LABEL = 'visibility_score';

	/**
	 * Company attribute label for the free trial status.
	 *
	 * @var string
	 */
	const COMPANY_FREE_TRIAL_ATTRIBUTE_LABEL = 'free_trial';

	/**
	 * Brevo client instance
	 *
	 * @var Brevo_Client
	 */
	private $brevo_client;

	/**
	 * Email state manager
	 *
	 * @var Email_State
	 */
	private $email_state;

	/**
	 * Email log manager
	 *
	 * @var Email_Log
	 */
	private $email_log;

	/**
	 * Analytics instance (if available)
	 *
	 * @var mixed
	 */
	private $analytics;

	/**
	 * Visibility score instance (if available)
	 *
	 * @var mixed
	 */
	private $visibility_score;

	/**
	 * Email definitions
	 *
	 * @var array
	 */
	private $email_definitions = [];

	/**
	 * Constructor
	 *
	 * @param Brevo_Client $brevo_client Brevo client instance
	 * @param Email_State  $email_state Email state manager
	 * @param Email_Log    $email_log Email log manager
	 * @param mixed        $analytics Optional analytics instance
	 * @param mixed        $visibility_score Optional visibility score instance
	 */
	public function __construct(
		Brevo_Client $brevo_client,
		Email_State $email_state,
		Email_Log $email_log,
		$analytics = null,
		$visibility_score = null
	) {
		$this->brevo_client = $brevo_client;
		$this->email_state = $email_state;
		$this->email_log = $email_log;
		$this->analytics = $analytics;
		$this->visibility_score = $visibility_score;

		$this->setup_email_definitions();
	}

	/**
	 * Initialize hooks for lifecycle emails
	 */
	public function init() {
		// Event-based: First bot visit
		add_action( 'llmagnet_first_bot_visit_detected', [ $this, 'handle_first_bot_visit' ] );

		// Time-based: Cron scan for scheduled emails
		add_action( 'llmagnet_lifecycle_email_scan', [ $this, 'scan_scheduled_emails' ] );

		// Single-event sync for site identity in Brevo.
		add_action( self::SITE_IDENTITY_SYNC_HOOK, [ $this, 'run_site_identity_sync' ] );

		// Company metadata updates.
		add_action( 'llmagnet_onboarding_completed', [ $this, 'handle_onboarding_completed' ] );
		add_action( 'llmagnet_visibility_score_updated', [ $this, 'handle_visibility_score_updated' ] );
		add_action( 'fs_after_license_change_llmagnet-llm-txt-generator', [ $this, 'handle_license_change' ], 10, 2 );

		$this->maybe_schedule_site_identity_sync();
	}

	/**
	 * Setup email definitions
	 *
	 * Centralizes template IDs and configuration for all lifecycle emails
	 */
	private function setup_email_definitions() {
		$this->email_definitions = [
			'e01_onboarding_incomplete' => [
				'name'                  => 'E-01: Onboarding Incomplete',
				'template_id_option'    => 'llmagnet_brevo_template_e01',
				'send_once'             => true,
				'type'                  => 'time-based',
				'default_template_id'   => 6, // Will be set from option or fallback
			],
			'e02_first_bot_visit' => [
				'name'                  => 'E-02: First Bot Visit',
				'template_id_option'    => 'llmagnet_brevo_template_e02',
				'send_once'             => true,
				'type'                  => 'event-based',
				'default_template_id'   => 8,
			],
			'e05_trial_day_5' => [
				'name'                  => 'E-05: Trial Day 5',
				'template_id_option'    => 'llmagnet_brevo_template_e05',
				'send_once'             => true,
				'type'                  => 'time-based',
				'default_template_id'   => 7,
			],
		];
	}

	/**
	 * Get template ID for an email key
	 *
	 * @param string $email_key Email key
	 *
	 * @return int Template ID or 0 if not configured
	 */
	private function get_template_id( $email_key ) {
		if ( ! isset( $this->email_definitions[ $email_key ] ) ) {
			return 0;
		}

		$def = $this->email_definitions[ $email_key ];
		$option_name = isset( $def['template_id_option'] ) ? $def['template_id_option'] : '';

		if ( ! empty( $option_name ) ) {
			$template_id = intval( get_option( $option_name, 0 ) );
			if ( $template_id > 0 ) {
				return $template_id;
			}
		}

		return isset( $def['default_template_id'] ) ? $def['default_template_id'] : 0;
	}

	/**
	 * Public site hostname (no scheme) for lifecycle templates, e.g. linking PAGE_TITLE to PAGE_PATH.
	 *
	 * @return string Host like "example.com", or empty if not parseable.
	 */
	private function get_site_domain_for_lifecycle_email() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}
		return strtolower( $host );
	}

	/**
	 * Add DOMAIN to template params (sent on every lifecycle transactional email).
	 *
	 * @param array $payload Existing template parameters.
	 *
	 * @return array
	 */
	private function with_domain_in_lifecycle_payload( array $payload ) {
		$payload['DOMAIN'] = $this->get_site_domain_for_lifecycle_email();
		return $payload;
	}

	/**
	 * Handle first bot visit event
	 *
	 * Called via hook when a bot visit is first detected and logged.
	 *
	 * @param array $visit_data {
	 *     @type string $bot_name Bot name
	 *     @type string $page_path Page path visited
	 *     @type string $page_title Page title
	 *     @type int    $visit_time Timestamp of visit
	 *     @type string $user_agent User agent string
	 * }
	 */
	public function handle_first_bot_visit( array $visit_data ) {
		$this->maybe_send_e02_first_bot_visit( $visit_data );
	}

	/**
	 * Scan for time-based emails that should be sent
	 *
	 * Called via cron hook. Evaluates trigger conditions and sends eligible emails.
	 */
	public function scan_scheduled_emails() {
		$this->maybe_send_e01_onboarding_incomplete();
		$this->maybe_send_e05_trial_day_5();
	}

	/**
	 * Sync the lifecycle recipient to Brevo once after install.
	 *
	 * @return void
	 */
	public function maybe_sync_install_contact( $force = false ) {
		return $this->sync_site_identity( $force, 'activation' );
	}

	/**
	 * Run a scheduled Brevo site identity sync.
	 *
	 * @return void
	 */
	public function run_site_identity_sync() {
		$this->sync_site_identity( false, 'scheduled' );
	}

	/**
	 * Mark the synced Brevo company as onboarding-complete.
	 *
	 * @return void
	 */
	public function handle_onboarding_completed() {
		$this->update_company_metadata(
			[
				self::COMPANY_ONBOARDING_COMPLETED_ATTRIBUTE_LABEL => true,
			],
			[
				self::COMPANY_ONBOARDING_COMPLETED_ATTRIBUTE_LABEL => 'boolean',
			],
			'onboarding_completed'
		);
	}

	/**
	 * Push the current visibility score into the synced Brevo company.
	 *
	 * @param int $score Visibility score.
	 *
	 * @return void
	 */
	public function handle_visibility_score_updated( $score ) {
		$this->update_company_metadata(
			[
				self::COMPANY_VISIBILITY_SCORE_ATTRIBUTE_LABEL => intval( round( $score ) ),
			],
			[
				self::COMPANY_VISIBILITY_SCORE_ATTRIBUTE_LABEL => 'number',
			],
			'visibility_score_update'
		);
	}

	/**
	 * React to a Freemius license/plan change and sync the free_trial
	 * company attribute to Brevo when a trial starts or ends.
	 *
	 * @param string $plan_change Plan change identifier (e.g. 'trial_started', 'trial_expired').
	 * @param mixed  $plan        Freemius plan object.
	 *
	 * @return void
	 */
	public function handle_license_change( $plan_change, $plan ) {
		if ( 'trial_started' === $plan_change ) {
			$this->update_company_metadata(
				[
					self::COMPANY_FREE_TRIAL_ATTRIBUTE_LABEL => 'started',
				],
				[
					self::COMPANY_FREE_TRIAL_ATTRIBUTE_LABEL => 'text',
				],
				'free_trial_started'
			);
		} elseif ( in_array( $plan_change, [ 'trial_expired', 'trial_cancelled' ], true ) ) {
			$this->update_company_metadata(
				[
					self::COMPANY_FREE_TRIAL_ATTRIBUTE_LABEL => 'ended',
				],
				[
					self::COMPANY_FREE_TRIAL_ATTRIBUTE_LABEL => 'text',
				],
				'free_trial_ended'
			);
		}
	}

	/**
	 * Schedule a one-off site identity sync when the plugin version
	 * or site identity data changed.
	 *
	 * @return void
	 */
	public function maybe_schedule_site_identity_sync() {
		// Brevo contact sync + lifecycle (product/activity) emails are operational,
		// not marketing, so they are NOT gated by the telemetry opt-in. The
		// llmagnet_telemetry_consent option only controls Mixpanel usage analytics.
		if ( ! $this->should_sync_site_identity() ) {
			return;
		}

		if ( wp_next_scheduled( self::SITE_IDENTITY_SYNC_HOOK ) ) {
			return;
		}

		wp_schedule_single_event(
			time() + MINUTE_IN_SECONDS,
			self::SITE_IDENTITY_SYNC_HOOK
		);
	}

	/**
	 * Sync the site owner contact and company details to Brevo.
	 *
	 * @param bool   $force   Whether to bypass sync guards.
	 * @param string $trigger Trigger source for logging.
	 *
	 * @return array
	 */
	private function sync_site_identity( $force = false, $trigger = 'automatic' ) {
		if ( ! $this->should_sync_site_identity( $force ) ) {
			return [
				'success' => true,
				'skipped' => true,
			];
		}

		$site_identity = $this->get_site_identity_data();
		$recipient = $site_identity['recipient'];
		if ( empty( $recipient ) ) {
			$this->email_log->log_attempt(
				'install_contact_sync',
				'',
				0,
				Email_Log::STATUS_SKIPPED,
				'No valid site owner email for Brevo sync',
				'',
				[
					'trigger' => $trigger,
				]
			);
			return [
				'success'       => false,
				'error_message' => 'No valid site owner email for Brevo sync',
			];
		}

		if ( ! $this->brevo_client->is_configured() ) {
			$this->email_log->log_attempt(
				'install_contact_sync',
				$recipient,
				0,
				Email_Log::STATUS_SKIPPED,
				'Brevo API not configured',
				'',
				[
					'trigger' => $trigger,
				]
			);
			return [
				'success'       => false,
				'error_message' => 'Brevo API key not configured',
			];
		}

		$contact_result = $this->upsert_site_owner_contact( $site_identity );
		if ( ! $contact_result['success'] ) {
			$this->email_log->log_attempt(
				'install_contact_sync',
				$recipient,
				0,
				Email_Log::STATUS_FAILED,
				'Contact sync failed: ' . $contact_result['error_message'],
				'',
				[
					'trigger' => $trigger,
					'list_id' => (string) self::INSTALL_LIST_ID,
				]
			);
			return $contact_result;
		}

		$contact_id = $this->get_contact_id_by_email( $recipient );
		if ( $contact_id <= 0 ) {
			$this->email_log->log_attempt(
				'install_contact_sync',
				$recipient,
				0,
				Email_Log::STATUS_FAILED,
				'Contact sync succeeded but contact ID could not be resolved',
				'',
				[
					'trigger' => $trigger,
					'list_id' => (string) self::INSTALL_LIST_ID,
				]
			);
			return [
				'success'       => false,
				'error_message' => 'Contact sync succeeded but contact ID could not be resolved',
			];
		}

		$company_result = $this->sync_company_identity( $contact_id, $site_identity );
		if ( ! $company_result['success'] ) {
			$this->email_log->log_attempt(
				'install_contact_sync',
				$recipient,
				0,
				Email_Log::STATUS_FAILED,
				'Company sync failed: ' . $company_result['error_message'],
				'',
				[
					'trigger'    => $trigger,
					'list_id'    => (string) self::INSTALL_LIST_ID,
					'contact_id' => (string) $contact_id,
				]
			);
			return $company_result;
		}

		$this->email_log->log_attempt(
			'install_contact_sync',
			$recipient,
			0,
			Email_Log::STATUS_SUCCESS,
			'',
			'',
			[
				'trigger'    => $trigger,
				'list_id'    => (string) self::INSTALL_LIST_ID,
				'contact_id' => (string) $contact_id,
				'company_id' => $company_result['company_id'],
			]
		);

		$this->email_state->set( 'brevo_contact_synced', true );
		$this->email_state->set( 'brevo_contact_synced_at', current_time( 'timestamp' ) );
		$this->email_state->set( 'brevo_contact_email', $recipient );
		$this->email_state->set( 'brevo_contact_id', $contact_id );
		$this->email_state->set( 'brevo_company_id', $company_result['company_id'] );
		$this->email_state->set( 'brevo_site_identity_signature', $this->build_site_identity_signature( $site_identity ) );
		$this->email_state->set( 'brevo_site_identity_synced_version', LLMAGNET_AISEO_VERSION );

		return [
			'success'    => true,
			'contact_id' => $contact_id,
			'company_id' => $company_result['company_id'],
		];
	}

	/**
	 * Determine if the current site identity still needs a Brevo sync.
	 *
	 * @param bool $force Whether to bypass sync guards.
	 *
	 * @return bool
	 */
	private function should_sync_site_identity( $force = false ) {
		if ( $force ) {
			return true;
		}

		$signature = $this->build_site_identity_signature( $this->get_site_identity_data() );
		$stored_signature = (string) $this->email_state->get( 'brevo_site_identity_signature', '' );
		$stored_version = (string) $this->email_state->get( 'brevo_site_identity_synced_version', '' );
		$is_synced = (bool) $this->email_state->get( 'brevo_contact_synced', false );

		return ! (
			$is_synced &&
			$stored_signature === $signature &&
			$stored_version === LLMAGNET_AISEO_VERSION
		);
	}

	/**
	 * Resolve the current site identity payload used for Brevo sync.
	 *
	 * @return array
	 */
	private function get_site_identity_data() {
		return [
			'recipient'  => $this->resolve_site_owner_email(),
			'site_name'  => wp_specialchars_decode( get_bloginfo( 'name' ) ),
			'site_url'   => home_url( '/' ),
			'admin_email'=> sanitize_email( get_option( 'admin_email', '' ) ),
		];
	}

	/**
	 * Resolve the email address of the site owner.
	 *
	 * @return string
	 */
	private function resolve_site_owner_email() {
		$admin_email = sanitize_email( get_option( 'admin_email', '' ) );
		if ( ! empty( $admin_email ) && is_email( $admin_email ) ) {
			return $admin_email;
		}

		return resolve_transactional_recipient();
	}

	/**
	 * Build a stable signature for the current site identity state.
	 *
	 * @param array $site_identity Site identity payload.
	 *
	 * @return string
	 */
	private function build_site_identity_signature( array $site_identity ) {
		return md5( wp_json_encode( $site_identity ) );
	}

	/**
	 * Create or update the Brevo contact for the site owner.
	 *
	 * @param array $site_identity Site identity payload.
	 *
	 * @return array
	 */
	private function upsert_site_owner_contact( array $site_identity ) {
		$result = $this->brevo_client->upsert_contact(
			$site_identity['recipient'],
			[
				self::CONTACT_WEBSITE_ATTRIBUTE => $site_identity['site_url'],
			],
			[ self::INSTALL_LIST_ID ]
		);

		if (
			! $result['success'] &&
			$this->is_missing_attribute_error( $result['error_message'] )
		) {
			$this->brevo_client->create_contact_attribute( self::CONTACT_WEBSITE_ATTRIBUTE, 'text' );

			$result = $this->brevo_client->upsert_contact(
				$site_identity['recipient'],
				[
					self::CONTACT_WEBSITE_ATTRIBUTE => $site_identity['site_url'],
				],
				[ self::INSTALL_LIST_ID ]
			);
		}

		return $result;
	}

	/**
	 * Get the Brevo contact ID for a given email address.
	 *
	 * @param string $email Contact email.
	 *
	 * @return int
	 */
	private function get_contact_id_by_email( $email ) {
		$result = $this->brevo_client->get_contact_by_email( $email );
		if ( ! $result['success'] ) {
			return 0;
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : [];
		return isset( $data['id'] ) ? intval( $data['id'] ) : 0;
	}

	/**
	 * Create or update the Brevo company linked to the contact.
	 *
	 * @param int   $contact_id     Brevo contact ID.
	 * @param array $site_identity  Site identity payload.
	 *
	 * @return array
	 */
	private function sync_company_identity( $contact_id, array $site_identity ) {
		$company_attributes = $this->build_company_attributes( $site_identity['site_url'] );
		if ( empty( $company_attributes ) ) {
			return [
				'success'       => false,
				'error_message' => 'Company attributes could not be resolved in Brevo',
			];
		}

		$company_id = $this->find_company_id_for_site(
			$contact_id,
			$site_identity['site_url'],
			(string) $this->email_state->get( 'brevo_company_id', '' )
		);

		if ( '' !== $company_id ) {
			$result = $this->brevo_client->update_company(
				$company_id,
				$site_identity['site_name'],
				$company_attributes
			);
			if ( ! $result['success'] ) {
				return $result;
			}

			$link_result = $this->brevo_client->link_company_to_contact( $company_id, $contact_id );
			if ( ! $link_result['success'] ) {
				return $link_result;
			}

			return [
				'success'    => true,
				'company_id' => $company_id,
			];
		}

		$result = $this->brevo_client->create_company(
			$site_identity['site_name'],
			$company_attributes,
			[ $contact_id ]
		);
		if ( ! $result['success'] ) {
			return $result;
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : [];

		return [
			'success'    => true,
			'company_id' => isset( $data['id'] ) ? sanitize_text_field( (string) $data['id'] ) : '',
		];
	}

	/**
	 * Build the company attribute payload using Brevo internal names.
	 *
	 * @param string $site_url Site URL.
	 *
	 * @return array
	 */
	private function build_company_attributes( $site_url ) {
		$domain_attr = $this->ensure_company_attribute_internal_name( self::COMPANY_DOMAIN_ATTRIBUTE_LABEL, 'text' );
		$industry_attr = $this->ensure_company_attribute_internal_name( self::COMPANY_INDUSTRY_ATTRIBUTE_LABEL, 'text' );

		if ( '' === $domain_attr || '' === $industry_attr ) {
			return [];
		}

		return [
			$domain_attr   => $site_url,
			$industry_attr => 'wordpress',
		];
	}

	/**
	 * Ensure a Brevo company attribute exists and return its internal name.
	 *
	 * @param string $label Desired attribute label.
	 *
	 * @return string
	 */
	private function ensure_company_attribute_internal_name( $label, $type = 'text' ) {
		$internal_name = $this->find_company_attribute_internal_name( $label );
		if ( '' !== $internal_name ) {
			return $internal_name;
		}

		$this->brevo_client->create_company_attribute( $label, $type );

		return $this->find_company_attribute_internal_name( $label );
	}

	/**
	 * Update Brevo company metadata fields for the current site.
	 *
	 * @param array  $fields        Label => value map.
	 * @param array  $attribute_map Label => type map.
	 * @param string $trigger       Trigger label for logs.
	 *
	 * @return array
	 */
	private function update_company_metadata( array $fields, array $attribute_map, $trigger ) {
		if ( empty( $fields ) ) {
			return [
				'success' => true,
			];
		}

		$sync_result = $this->sync_site_identity( false, $trigger );
		if ( empty( $sync_result['success'] ) ) {
			return $sync_result;
		}

		$company_id = sanitize_text_field( (string) $this->email_state->get( 'brevo_company_id', '' ) );
		if ( '' === $company_id ) {
			return [
				'success'       => false,
				'error_message' => 'Brevo company ID not available',
			];
		}

		$company_attributes = [];
		foreach ( $fields as $label => $value ) {
			$type = isset( $attribute_map[ $label ] ) ? $attribute_map[ $label ] : 'text';
			$internal_name = $this->ensure_company_attribute_internal_name( $label, $type );
			if ( '' === $internal_name ) {
				return [
					'success'       => false,
					'error_message' => sprintf( 'Brevo company attribute "%s" could not be resolved', $label ),
				];
			}

			$company_attributes[ $internal_name ] = $value;
		}

		$result = $this->brevo_client->update_company(
			$company_id,
			wp_specialchars_decode( get_bloginfo( 'name' ) ),
			$company_attributes
		);

		if ( ! $result['success'] ) {
			$this->email_log->log_attempt(
				'company_metadata_sync',
				$this->resolve_site_owner_email(),
				0,
				Email_Log::STATUS_FAILED,
				'Company metadata sync failed: ' . $result['error_message'],
				'',
				[
					'trigger'    => $trigger,
					'company_id' => $company_id,
				]
			);

			return $result;
		}

		$this->email_log->log_attempt(
			'company_metadata_sync',
			$this->resolve_site_owner_email(),
			0,
			Email_Log::STATUS_SUCCESS,
			'',
			'',
			[
				'trigger'    => $trigger,
				'company_id' => $company_id,
			]
		);

		return [
			'success'    => true,
			'company_id' => $company_id,
		];
	}

	/**
	 * Find a Brevo company attribute internal name by label or internal name.
	 *
	 * @param string $label Attribute label.
	 *
	 * @return string
	 */
	private function find_company_attribute_internal_name( $label ) {
		$result = $this->brevo_client->get_company_attributes();
		if ( ! $result['success'] ) {
			return '';
		}

		$needle = $this->normalize_company_attribute_key( $label );
		$attributes = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : [];

		foreach ( $attributes as $attribute ) {
			$current_label = isset( $attribute['label'] ) ? $this->normalize_company_attribute_key( $attribute['label'] ) : '';
			$current_internal_name = isset( $attribute['internalName'] ) ? $this->normalize_company_attribute_key( $attribute['internalName'] ) : '';

			if ( $current_label === $needle || $current_internal_name === $needle ) {
				return isset( $attribute['internalName'] ) ? sanitize_text_field( $attribute['internalName'] ) : '';
			}
		}

		return '';
	}

	/**
	 * Find the Brevo company ID that matches the current site domain.
	 *
	 * @param int    $contact_id         Brevo contact ID.
	 * @param string $site_url           Current site URL.
	 * @param string $preferred_company_id Previously stored company ID.
	 *
	 * @return string
	 */
	private function find_company_id_for_site( $contact_id, $site_url, $preferred_company_id = '' ) {
		$companies_result = $this->brevo_client->get_companies_by_contact_id( $contact_id );
		if ( ! $companies_result['success'] ) {
			return sanitize_text_field( $preferred_company_id );
		}

		$items = isset( $companies_result['data']['items'] ) && is_array( $companies_result['data']['items'] )
			? $companies_result['data']['items']
			: [];

		$normalized_site_url = untrailingslashit( strtolower( $site_url ) );
		$preferred_company_id = sanitize_text_field( $preferred_company_id );

		foreach ( $items as $company ) {
			$company_id = isset( $company['id'] ) ? sanitize_text_field( (string) $company['id'] ) : '';
			$company_domain = $this->get_company_domain_value( $company );
			if ( '' !== $company_domain && untrailingslashit( strtolower( $company_domain ) ) === $normalized_site_url ) {
				return $company_id;
			}
		}

		if ( '' !== $preferred_company_id ) {
			foreach ( $items as $company ) {
				$company_id = isset( $company['id'] ) ? sanitize_text_field( (string) $company['id'] ) : '';
				if ( $company_id === $preferred_company_id ) {
					return $company_id;
				}
			}
		}

		return '';
	}

	/**
	 * Extract the logical domain value from a Brevo company payload.
	 *
	 * @param array $company Brevo company payload.
	 *
	 * @return string
	 */
	private function get_company_domain_value( array $company ) {
		$attributes = isset( $company['attributes'] ) && is_array( $company['attributes'] ) ? $company['attributes'] : [];
		$target_key = $this->normalize_company_attribute_key( self::COMPANY_DOMAIN_ATTRIBUTE_LABEL );

		foreach ( $attributes as $attribute_key => $attribute_value ) {
			if ( $this->normalize_company_attribute_key( $attribute_key ) === $target_key ) {
				return is_scalar( $attribute_value ) ? sanitize_text_field( (string) $attribute_value ) : '';
			}
		}

		return '';
	}

	/**
	 * Normalize company attribute labels/internal names for comparisons.
	 *
	 * @param string $value Raw attribute label or internal name.
	 *
	 * @return string
	 */
	private function normalize_company_attribute_key( $value ) {
		$value = strtolower( sanitize_text_field( (string) $value ) );
		return preg_replace( '/[^a-z0-9]+/', '', $value );
	}

	/**
	 * Determine if Brevo returned a missing-attribute style error.
	 *
	 * @param string $error_message Brevo error message.
	 *
	 * @return bool
	 */
	private function is_missing_attribute_error( $error_message ) {
		$error_message = strtolower( (string) $error_message );

		return false !== strpos( $error_message, 'attribute' ) && false !== strpos( $error_message, 'not found' );
	}

	/**
	 * E-01: Send if onboarding not completed yet
	 *
	 * Trigger: Install timestamp exists AND onboarding not completed
	 * Timing: Can be sent immediately or on a schedule
	 * Send once: Yes
	 */
	private function maybe_send_e01_onboarding_incomplete() {
		$email_key = 'e01_onboarding_incomplete';

		// Check if already sent
		if ( $this->email_state->was_sent( $email_key ) ) {
			return;
		}

		// Get install timestamp
		$install_ts = get_option( 'llmagnet_install_timestamp', 0 );
		if ( ! $install_ts ) {
			// Not yet installed or no timestamp
			return;
		}

		// Check if onboarding is completed
		$onboarding_state = get_option( 'llmagnet_onboarding_state', [] );
		if ( ! empty( $onboarding_state['completed'] ) ) {
			// Onboarding already completed, no need to send
			return;
		}

		// Get recipient
		$recipient = resolve_transactional_recipient();
		if ( empty( $recipient ) ) {
			$this->email_log->log_attempt(
				$email_key,
				'',
				$this->get_template_id( $email_key ),
				Email_Log::STATUS_SKIPPED,
				'No valid recipient'
			);
			return;
		}

		// Prepare payload
		$payload = [
			'SITE_NAME' => wp_specialchars_decode( get_bloginfo( 'name' ) ),
			'ADMIN_URL' => admin_url(),
		];

		// Send email
		$this->send_lifecycle_email( $email_key, $recipient, $payload );
	}

	/**
	 * E-02: Send on first bot visit
	 *
	 * Trigger: Bot visit is detected and logged
	 * Timing: Immediately on event
	 * Send once: Yes
	 *
	 * @param array $visit_data Bot visit data
	 */
	private function maybe_send_e02_first_bot_visit( array $visit_data ) {
		$email_key = 'e02_first_bot_visit';

		// Check if already sent
		if ( $this->email_state->was_sent( $email_key ) ) {
			return;
		}

		// Get recipient
		$recipient = resolve_transactional_recipient();
		if ( empty( $recipient ) ) {
			$this->email_log->log_attempt(
				$email_key,
				'',
				$this->get_template_id( $email_key ),
				Email_Log::STATUS_SKIPPED,
				'No valid recipient',
				'',
				[]
			);
			return;
		}

		// Prepare payload with visit data (DOMAIN added in send_lifecycle_email for all emails).
		$payload = [
			'SITE_NAME'  => wp_specialchars_decode( get_bloginfo( 'name' ) ),
			'BOT_NAME'   => isset( $visit_data['bot_name'] ) ? $visit_data['bot_name'] : 'AI Bot',
			'PAGE_PATH'  => isset( $visit_data['page_path'] ) ? $visit_data['page_path'] : '/',
			'PAGE_TITLE' => isset( $visit_data['page_title'] ) ? $visit_data['page_title'] : 'Page',
			'ADMIN_URL'  => admin_url(),
		];

		$context = [
			'bot_name' => isset( $visit_data['bot_name'] ) ? $visit_data['bot_name'] : 'unknown',
		];

		// Send email
		$this->send_lifecycle_email( $email_key, $recipient, $payload, [], $context );
	}

	/**
	 * E-05: Send on trial day 5
	 *
	 * Trigger: Trial is active AND 5+ days have passed since trial start
	 * Timing: Via cron scan
	 * Send once: Yes
	 */
	private function maybe_send_e05_trial_day_5() {
		$email_key = 'e05_trial_day_5';

		// Check if already sent
		if ( $this->email_state->was_sent( $email_key ) ) {
			return;
		}

		// Check if in trial
		if ( ! is_in_trial() ) {
			return;
		}

		// Check trial day
		$trial_day = get_trial_day();
		if ( $trial_day < 5 ) {
			// Not yet day 5
			return;
		}

		// Get recipient
		$recipient = resolve_transactional_recipient();
		if ( empty( $recipient ) ) {
			$this->email_log->log_attempt(
				$email_key,
				'',
				$this->get_template_id( $email_key ),
				Email_Log::STATUS_SKIPPED,
				'No valid recipient'
			);
			return;
		}

		// Prepare payload
		$trial_status = get_trial_status();

		$bot_names_list = '';
		if ( $this->analytics ) {
			$visits_by_bot  = $this->analytics->get_total_bot_visits();
			$bot_names_list = implode(
				', ',
				array_column( $visits_by_bot, 'bot_name' )
			);
		}

		$payload = [
			'SITE_NAME'            => wp_specialchars_decode( get_bloginfo( 'name' ) ),
			'TRIAL_DAYS_REMAINING' => $trial_status['trial_days_remaining'],
			'ADMIN_URL'            => admin_url(),
			'TRIAL_BOT_VISITS'     => $bot_names_list,
		];

		// Send email
		$this->send_lifecycle_email( $email_key, $recipient, $payload );
	}

	/**
	 * Unified wrapper for sending lifecycle emails
	 *
	 * Handles:
	 * - Recipient validation
	 * - Template ID resolution
	 * - Deduplication
	 * - API call to Brevo
	 * - Logging
	 * - State marking
	 *
	 * @param string $email_key Email identifier (e.g., 'e01_onboarding_incomplete')
	 * @param string $recipient Recipient email address
	 * @param array  $payload Template parameters ({@see with_domain_in_lifecycle_payload()} always adds DOMAIN)
	 * @param array  $tags Optional tags for email categorization
	 * @param array  $context Optional context for logging
	 *
	 * @return array {
	 *     @type bool $success Whether send was successful
	 *     @type string $provider_msg_id Brevo message ID if successful
	 *     @type string $error_code Error code if failed
	 *     @type string $error_message Error message if failed
	 * }
	 */
	public function send_lifecycle_email(
		$email_key,
		$recipient,
		array $payload = [],
		array $tags = [],
		array $context = []
	) {
		$email_key = sanitize_key( $email_key );
		$recipient = sanitize_email( $recipient );

		// Validate recipient
		if ( ! is_email( $recipient ) ) {
			$this->email_log->log_attempt(
				$email_key,
				$recipient,
				0,
				Email_Log::STATUS_SKIPPED,
				'Invalid recipient email'
			);
			return [
				'success'              => false,
				'provider_message_id'  => '',
				'error_code'           => 'INVALID_RECIPIENT',
				'error_message'        => 'Invalid recipient email address',
			];
		}

		// Check for deduplication
		if ( $this->email_state->was_sent( $email_key ) ) {
			$this->email_log->log_attempt(
				$email_key,
				$recipient,
				$this->get_template_id( $email_key ),
				Email_Log::STATUS_SKIPPED,
				'Already sent (dedupe)'
			);
			return [
				'success'              => false,
				'provider_message_id'  => '',
				'error_code'           => 'DEDUPE_SKIP',
				'error_message'        => 'Email already sent',
			];
		}

		// Get template ID
		$template_id = $this->get_template_id( $email_key );
		if ( ! $template_id ) {
			$this->email_log->log_attempt(
				$email_key,
				$recipient,
				0,
				Email_Log::STATUS_SKIPPED,
				'Template ID not configured'
			);
			return [
				'success'              => false,
				'provider_message_id'  => '',
				'error_code'           => 'NO_TEMPLATE_ID',
				'error_message'        => 'Template ID not configured for this email',
			];
		}

		// Check if Brevo is configured
		if ( ! $this->brevo_client->is_configured() ) {
			$this->email_log->log_attempt(
				$email_key,
				$recipient,
				$template_id,
				Email_Log::STATUS_SKIPPED,
				'Brevo API not configured'
			);
			return [
				'success'              => false,
				'provider_message_id'  => '',
				'error_code'           => 'BREVO_NOT_CONFIGURED',
				'error_message'        => 'Brevo API key not configured',
			];
		}

		$payload = $this->with_domain_in_lifecycle_payload( $payload );

		// Send via Brevo
		$result = $this->brevo_client->send_template(
			$recipient,
			$template_id,
			$payload,
			$tags
		);

		// Log the attempt
		$status = $result['success'] ? Email_Log::STATUS_SUCCESS : Email_Log::STATUS_FAILED;
		$reason = $result['success'] ? '' : $result['error_message'];

		$this->email_log->log_attempt(
			$email_key,
			$recipient,
			$template_id,
			$status,
			$reason,
			isset( $result['provider_message_id'] ) ? $result['provider_message_id'] : '',
			$context
		);

		// Mark as sent only if successful
		if ( $result['success'] ) {
			$this->email_state->mark_sent(
				$email_key,
				'default',
				[
					'recipient' => $recipient,
					'template_id' => $template_id,
				]
			);
		}

		return $result;
	}

	/**
	 * Force-send a lifecycle email for testing purposes
	 *
	 * Bypasses deduplication, was_sent checks, and trigger conditions.
	 * Does NOT mark the email as sent, so it can be triggered again.
	 *
	 * @param string $email_key Email key (e.g. 'e01_onboarding_incomplete')
	 * @param string $recipient Optional override recipient email
	 *
	 * @return array {
	 *     @type bool   $success       Whether send was successful
	 *     @type string $error_code    Error code if failed
	 *     @type string $error_message Error message if failed
	 *     @type string $recipient     Resolved recipient used
	 *     @type array  $payload       Payload sent (always includes DOMAIN)
	 * }
	 */
	public function force_send_test( $email_key, $recipient = '' ) {
		$email_key = sanitize_key( $email_key );

		if ( ! isset( $this->email_definitions[ $email_key ] ) ) {
			return [
				'success'       => false,
				'error_code'    => 'UNKNOWN_EMAIL_KEY',
				'error_message' => 'Unknown email key: ' . $email_key,
				'recipient'     => '',
				'payload'       => [],
			];
		}

		// Resolve recipient
		if ( empty( $recipient ) ) {
			$recipient = resolve_transactional_recipient();
		}
		$recipient = sanitize_email( $recipient );

		if ( ! is_email( $recipient ) ) {
			return [
				'success'       => false,
				'error_code'    => 'NO_RECIPIENT',
				'error_message' => 'No valid recipient configured',
				'recipient'     => '',
				'payload'       => [],
			];
		}

		// Build payload per email type (DOMAIN merged for all transactional templates).
		$payload = $this->with_domain_in_lifecycle_payload(
			$this->build_test_payload( $email_key )
		);

		// Validate template
		$template_id = $this->get_template_id( $email_key );
		if ( ! $template_id ) {
			return [
				'success'       => false,
				'error_code'    => 'NO_TEMPLATE_ID',
				'error_message' => 'Template ID not configured for: ' . $email_key,
				'recipient'     => $recipient,
				'payload'       => $payload,
			];
		}

		// Validate Brevo
		if ( ! $this->brevo_client->is_configured() ) {
			return [
				'success'       => false,
				'error_code'    => 'BREVO_NOT_CONFIGURED',
				'error_message' => 'Brevo API key not configured',
				'recipient'     => $recipient,
				'payload'       => $payload,
			];
		}

		// Send — no dedup, no state marking
		$result = $this->brevo_client->send_template(
			$recipient,
			$template_id,
			$payload,
			[ 'test' ]
		);

		$this->email_log->log_attempt(
			$email_key,
			$recipient,
			$template_id,
			$result['success'] ? Email_Log::STATUS_SUCCESS : Email_Log::STATUS_FAILED,
			$result['success'] ? 'test-send' : $result['error_message'],
			isset( $result['provider_message_id'] ) ? $result['provider_message_id'] : '',
			[ 'test' => true ]
		);

		return array_merge( $result, [
			'recipient' => $recipient,
			'payload'   => $payload,
		] );
	}

	/**
	 * Build test payload for a given email key
	 *
	 * @param string $email_key Email key
	 *
	 * @return array
	 */
	private function build_test_payload( $email_key ) {
		$base = [
			'SITE_NAME' => wp_specialchars_decode( get_bloginfo( 'name' ) ),
			'ADMIN_URL' => admin_url(),
		];

		switch ( $email_key ) {
			case 'e02_first_bot_visit':
				return array_merge( $base, [
					'BOT_NAME'   => 'GPTBot',
					'PAGE_PATH'  => '/',
					'PAGE_TITLE' => get_bloginfo( 'name' ),
				] );

			case 'e05_trial_day_5':
				$trial_status   = get_trial_status();
				$bot_names_list = '';
				if ( $this->analytics ) {
					$visits_by_bot  = $this->analytics->get_total_bot_visits();
					$bot_names_list = implode(
						', ',
						array_column( $visits_by_bot, 'bot_name' )
					);
				}
				return array_merge( $base, [
					'TRIAL_DAYS_REMAINING' => $trial_status['trial_days_remaining'],
					'TRIAL_BOT_VISITS'     => $bot_names_list,
				] );

			case 'e01_onboarding_incomplete':
			default:
				return $base;
		}
	}
}
