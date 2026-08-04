<?php
/**
 * Lifecycle Email Helpers
 *
 * Centralized helpers for lifecycle email system.
 * Handles recipient resolution and plan/trial data access.
 *
 * @package LLMagnet
 * @since 1.0.0
 */

namespace LLMagnet\Lifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the transactional recipient email address
 *
 * Resolution order:
 * 1. llmagnet_report_email (if valid)
 * 2. admin_email (fallback)
 *
 * @return string Email address or empty string
 */
function resolve_transactional_recipient() {
	$recipient = get_option( 'llmagnet_report_email', '' );
	$resolved  = extract_first_valid_email( $recipient );
	if ( ! empty( $resolved ) ) {
		return $resolved;
	}

	$admin_email = get_option( 'admin_email', '' );
	return extract_first_valid_email( $admin_email );
}

/**
 * Extract the first valid email address from a string.
 *
 * Supports comma-separated values in `llmagnet_report_email`.
 *
 * @param string $raw_value Raw email string.
 *
 * @return string
 */
function extract_first_valid_email( $raw_value ) {
	if ( ! is_string( $raw_value ) || '' === trim( $raw_value ) ) {
		return '';
	}

	$candidates = array_map( 'trim', explode( ',', $raw_value ) );

	foreach ( $candidates as $candidate ) {
		$sanitized = sanitize_email( $candidate );
		if ( ! empty( $sanitized ) && is_email( $sanitized ) ) {
			return $sanitized;
		}
	}

	return '';
}

/**
 * Get current trial status
 *
 * @return array {
 *     @type bool   $is_trial             Whether currently in trial
 *     @type int    $trial_started_ts     Trial start timestamp
 *     @type int    $trial_ends_ts        Trial end timestamp
 *     @type int    $trial_days_remaining Days remaining in trial
 * }
 */
function get_trial_status() {
	$default = [
		'is_trial'             => false,
		'trial_started_ts'     => 0,
		'trial_ends_ts'        => 0,
		'trial_days_remaining' => 0,
	];
	
	if ( ! function_exists( 'lltg_fs' ) ) {
		return $default;
	}
	
	$fs = lltg_fs();
	
	if ( ! $fs->is_trial() ) {
		return $default;
	}
	
	// Get trial end timestamp
	$site = $fs->get_site();
	if ( ! $site || ! isset( $site->trial_ends ) ) {
		return $default;
	}
	
	$trial_ends_ts = strtotime( $site->trial_ends );
	if ( ! $trial_ends_ts ) {
		return $default;
	}
	
	// Get trial period in days
	$trial_plan = $fs->get_trial_plan();
	$trial_period = 0;
	if ( $trial_plan && isset( $trial_plan->trial_period ) ) {
		$trial_period = intval( $trial_plan->trial_period );
	}
	
	// If no trial period, estimate from trial_ends (7 days is default Freemius)
	if ( $trial_period === 0 ) {
		$trial_period = 7;
	}
	
	// Calculate trial start
	$trial_started_ts = $trial_ends_ts - ( $trial_period * DAY_IN_SECONDS );
	
	// Calculate days remaining
	$current_ts = current_time( 'timestamp' );
	$trial_days_remaining = max( 0, ceil( ( $trial_ends_ts - $current_ts ) / DAY_IN_SECONDS ) );
	
	return [
		'is_trial'             => true,
		'trial_started_ts'     => $trial_started_ts,
		'trial_ends_ts'        => $trial_ends_ts,
		'trial_days_remaining' => $trial_days_remaining,
	];
}

/**
 * Get plan information
 *
 * @return array {
 *     @type bool   $is_premium  Whether user has premium
 *     @type string $plan_name   Plan name (free, pro, plus, enterprise)
 *     @type string $plan_title  Human-readable plan title
 * }
 */
function get_plan_info() {
	$default = [
		'is_premium' => false,
		'plan_name'  => 'free',
		'plan_title' => 'Free Version',
	];
	
	if ( ! function_exists( 'lltg_fs' ) ) {
		return $default;
	}
	
	$fs = lltg_fs();
	
	$plan_name = 'free';
	$plan_title = 'Free Version';
	
	if ( $fs->is_plan( 'enterprise' ) ) {
		$plan_name = 'enterprise';
		$plan_title = 'Enterprise Version';
	} elseif ( $fs->is_plan( 'plus' ) ) {
		$plan_name = 'plus';
		$plan_title = 'Plus Version';
	} elseif ( $fs->is_plan( 'pro' ) ) {
		$plan_name = 'pro';
		$plan_title = 'Pro Version';
	}
	
	return [
		'is_premium' => $fs->is_premium(),
		'plan_name'  => $plan_name,
		'plan_title' => $plan_title,
	];
}

/**
 * Check if currently in trial period
 *
 * @return bool
 */
function is_in_trial() {
	$trial = get_trial_status();
	return $trial['is_trial'];
}

/**
 * Get days since trial started (0 if not in trial)
 *
 * @return int
 */
function get_trial_day() {
	$trial = get_trial_status();
	if ( ! $trial['is_trial'] ) {
		return 0;
	}
	
	$current_ts = current_time( 'timestamp' );
	$days_since_start = floor( ( $current_ts - $trial['trial_started_ts'] ) / DAY_IN_SECONDS );
	
	return max( 0, intval( $days_since_start ) );
}
