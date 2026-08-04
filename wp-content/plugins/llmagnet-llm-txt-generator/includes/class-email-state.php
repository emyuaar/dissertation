<?php
/**
 * Email State Manager
 *
 * Manages state tracking for lifecycle emails, including:
 * - Deduplication tracking
 * - Cooldown checks
 * - Milestone tracking
 *
 * Storage: Single WordPress option 'llmagnet_lifecycle_email_state'
 *
 * @package LLMagnet
 * @since 1.0.0
 */

namespace LLMagnet\Lifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email_State class
 */
class Email_State {
	/**
	 * Option name for storing all email state
	 *
	 * @var string
	 */
	const OPTION_NAME = 'llmagnet_lifecycle_email_state';

	/**
	 * Default state structure
	 *
	 * @var array
	 */
	private $default_state = [
		'default' => [],
	];

	/**
	 * In-memory cache of state
	 *
	 * @var array|null
	 */
	private $state_cache = null;

	/**
	 * Whether state was modified and needs saving
	 *
	 * @var bool
	 */
	private $state_dirty = false;

	/**
	 * Load state from database
	 *
	 * @return array Current state
	 */
	private function load_state() {
		if ( null !== $this->state_cache ) {
			return $this->state_cache;
		}

		$state = get_option( self::OPTION_NAME, $this->default_state );
		if ( ! is_array( $state ) ) {
			$state = $this->default_state;
		}

		$this->state_cache = $state;
		return $state;
	}

	/**
	 * Save state to database
	 */
	private function save_state() {
		if ( ! $this->state_dirty ) {
			return;
		}

		if ( null === $this->state_cache ) {
			return;
		}

		update_option( self::OPTION_NAME, $this->state_cache );
		$this->state_dirty = false;
	}

	/**
	 * Get entire state for a site
	 *
	 * @param string $site_key Site key (default: 'default')
	 *
	 * @return array Site state
	 */
	public function get_site_state( $site_key = 'default' ) {
		$state = $this->load_state();
		return isset( $state[ $site_key ] ) ? $state[ $site_key ] : [];
	}

	/**
	 * Check if an email was already sent
	 *
	 * @param string $email_key Email key (e.g., 'e01_onboarding_incomplete')
	 * @param string $site_key  Site key (default: 'default')
	 *
	 * @return bool
	 */
	public function was_sent( $email_key, $site_key = 'default' ) {
		$state = $this->load_state();
		if ( ! isset( $state[ $site_key ][ $email_key ] ) ) {
			return false;
		}

		$email_state = $state[ $site_key ][ $email_key ];
		return isset( $email_state['sent'] ) && $email_state['sent'] === true;
	}

	/**
	 * Get full state for an email
	 *
	 * @param string $email_key Email key
	 * @param string $site_key  Site key (default: 'default')
	 *
	 * @return array Email state including sent time, recipient, meta, etc.
	 */
	public function get_email_state( $email_key, $site_key = 'default' ) {
		$state = $this->load_state();
		return isset( $state[ $site_key ][ $email_key ] ) ? $state[ $site_key ][ $email_key ] : [];
	}

	/**
	 * Mark an email as sent
	 *
	 * @param string $email_key Email key
	 * @param string $site_key  Site key (default: 'default')
	 * @param array  $meta      Optional metadata to store with the email
	 */
	public function mark_sent( $email_key, $site_key = 'default', array $meta = [] ) {
		$state = $this->load_state();

		// Ensure site key exists
		if ( ! isset( $state[ $site_key ] ) ) {
			$state[ $site_key ] = [];
		}

		// Mark as sent
		$state[ $site_key ][ $email_key ] = [
			'sent'    => true,
			'sent_at' => current_time( 'timestamp' ),
			'meta'    => $meta,
		];

		$this->state_cache = $state;
		$this->state_dirty = true;
		$this->save_state();
	}

	/**
	 * Mark an email as skipped (for logging purposes)
	 *
	 * @param string $email_key Email key
	 * @param string $reason    Reason for skipping (dedupe, missing_config, etc.)
	 * @param string $site_key  Site key (default: 'default')
	 * @param array  $meta      Optional metadata
	 */
	public function mark_skipped( $email_key, $reason = '', $site_key = 'default', array $meta = [] ) {
		$state = $this->load_state();

		// Ensure site key exists
		if ( ! isset( $state[ $site_key ] ) ) {
			$state[ $site_key ] = [];
		}

		// Store skip info
		$state[ $site_key ][ $email_key ] = [
			'sent'      => false,
			'skipped'   => true,
			'reason'    => $reason,
			'skipped_at' => current_time( 'timestamp' ),
			'meta'      => $meta,
		];

		$this->state_cache = $state;
		$this->state_dirty = true;
		$this->save_state();
	}

	/**
	 * Get a generic key-value pair from state
	 *
	 * @param string $key     Option key
	 * @param mixed  $default Default value if key doesn't exist
	 * @param string $site_key Site key (default: 'default')
	 *
	 * @return mixed
	 */
	public function get( $key, $default = null, $site_key = 'default' ) {
		$state = $this->load_state();
		if ( ! isset( $state[ $site_key ][ $key ] ) ) {
			return $default;
		}

		return $state[ $site_key ][ $key ];
	}

	/**
	 * Set a generic key-value pair in state
	 *
	 * @param string $key    Option key
	 * @param mixed  $value  Option value
	 * @param string $site_key Site key (default: 'default')
	 */
	public function set( $key, $value, $site_key = 'default' ) {
		$state = $this->load_state();

		// Ensure site key exists
		if ( ! isset( $state[ $site_key ] ) ) {
			$state[ $site_key ] = [];
		}

		$state[ $site_key ][ $key ] = $value;

		$this->state_cache = $state;
		$this->state_dirty = true;
		$this->save_state();
	}

	/**
	 * Destructor: Ensure state is saved on shutdown
	 */
	public function __destruct() {
		$this->save_state();
	}
}
