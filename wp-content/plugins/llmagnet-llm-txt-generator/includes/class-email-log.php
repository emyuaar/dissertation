<?php
/**
 * Email Log Manager
 *
 * Maintains an operational log of email sending attempts.
 * Stores a limited history (ring buffer) for debugging and audit.
 *
 * Storage: Single WordPress option 'llmagnet_lifecycle_email_log'
 *
 * @package LLMagnet
 * @since 1.0.0
 */

namespace LLMagnet\Lifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email_Log class
 */
class Email_Log {
	/**
	 * Option name for storing email log
	 *
	 * @var string
	 */
	const OPTION_NAME = 'llmagnet_lifecycle_email_log';

	/**
	 * Maximum number of log entries to keep
	 *
	 * @var int
	 */
	const MAX_LOG_ENTRIES = 200;

	/**
	 * Status: Email sent successfully
	 *
	 * @var string
	 */
	const STATUS_SUCCESS = 'success';

	/**
	 * Status: Email send failed
	 *
	 * @var string
	 */
	const STATUS_FAILED = 'failed';

	/**
	 * Status: Email skipped
	 *
	 * @var string
	 */
	const STATUS_SKIPPED = 'skipped';

	/**
	 * Log an email attempt
	 *
	 * @param string $email_key       Email key (e.g., 'e01_onboarding_incomplete')
	 * @param string $recipient       Recipient email address
	 * @param int    $template_id     Brevo template ID
	 * @param string $status          Status (success, failed, skipped)
	 * @param string $reason          Optional reason for status
	 * @param string $provider_msg_id Optional Brevo message ID on success
	 * @param array  $context         Optional context data (bot_name, etc.)
	 */
	public function log_attempt(
		$email_key,
		$recipient,
		$template_id,
		$status = self::STATUS_SUCCESS,
		$reason = '',
		$provider_msg_id = '',
		array $context = []
	) {
		$log_entry = [
			'time'              => current_time( 'timestamp' ),
			'email_key'         => sanitize_key( $email_key ),
			'recipient'         => sanitize_email( $recipient ),
			'template_id'       => intval( $template_id ),
			'status'            => sanitize_key( $status ),
			'reason'            => sanitize_text_field( $reason ),
			'provider_msg_id'   => sanitize_text_field( $provider_msg_id ),
			'context'           => array_map( 'sanitize_text_field', $context ),
		];

		$log = $this->get_log();
		$log[] = $log_entry;

		// Trim log to max entries (keep newest)
		if ( count( $log ) > self::MAX_LOG_ENTRIES ) {
			$log = array_slice( $log, -self::MAX_LOG_ENTRIES );
		}

		update_option( self::OPTION_NAME, $log );
	}

	/**
	 * Get full email log
	 *
	 * @return array Array of log entries
	 */
	public function get_log() {
		$log = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $log ) ) {
			return [];
		}
		return $log;
	}

	/**
	 * Get recent log entries for a specific email key
	 *
	 * @param string $email_key Email key
	 * @param int    $limit     Max number of entries to return
	 *
	 * @return array Matching log entries
	 */
	public function get_email_log( $email_key, $limit = 10 ) {
		$log = $this->get_log();
		$email_log = [];

		foreach ( array_reverse( $log ) as $entry ) {
			if ( isset( $entry['email_key'] ) && $entry['email_key'] === $email_key ) {
				$email_log[] = $entry;
				if ( count( $email_log ) >= $limit ) {
					break;
				}
			}
		}

		return $email_log;
	}

	/**
	 * Get log entries for a specific date range
	 *
	 * @param int $start_timestamp Start of date range
	 * @param int $end_timestamp   End of date range
	 *
	 * @return array Matching log entries
	 */
	public function get_log_by_date_range( $start_timestamp, $end_timestamp ) {
		$log = $this->get_log();
		$filtered = [];

		foreach ( $log as $entry ) {
			if ( isset( $entry['time'] ) ) {
				$time = intval( $entry['time'] );
				if ( $time >= $start_timestamp && $time <= $end_timestamp ) {
					$filtered[] = $entry;
				}
			}
		}

		return $filtered;
	}

	/**
	 * Get summary statistics for a specific email key
	 *
	 * @param string $email_key Email key
	 *
	 * @return array {
	 *     @type int $total_attempts  Total send attempts
	 *     @type int $successful      Successful sends
	 *     @type int $failed          Failed sends
	 *     @type int $skipped         Skipped sends
	 *     @type int $last_attempt_ts Timestamp of last attempt
	 * }
	 */
	public function get_email_stats( $email_key ) {
		$log = $this->get_log();
		$stats = [
			'total_attempts'  => 0,
			'successful'      => 0,
			'failed'          => 0,
			'skipped'         => 0,
			'last_attempt_ts' => 0,
		];

		foreach ( $log as $entry ) {
			if ( isset( $entry['email_key'] ) && $entry['email_key'] === $email_key ) {
				$stats['total_attempts']++;

				if ( isset( $entry['status'] ) ) {
					if ( $entry['status'] === self::STATUS_SUCCESS ) {
						$stats['successful']++;
					} elseif ( $entry['status'] === self::STATUS_FAILED ) {
						$stats['failed']++;
					} elseif ( $entry['status'] === self::STATUS_SKIPPED ) {
						$stats['skipped']++;
					}
				}

				if ( isset( $entry['time'] ) ) {
					$stats['last_attempt_ts'] = max( $stats['last_attempt_ts'], intval( $entry['time'] ) );
				}
			}
		}

		return $stats;
	}

	/**
	 * Clear all log entries (mostly for testing/reset)
	 */
	public function clear_log() {
		delete_option( self::OPTION_NAME );
	}
}
