<?php
/**
 * MCP token management.
 *
 * Managed bearer tokens for the LLMagnet MCP server (mcp-ai-spec Workstream B):
 * - Secrets hashed at rest (SHA-256), plaintext returned exactly once on create.
 * - Scopes: `read` (read-only tools) / `write` (all tools).
 * - Soft revocation, last-used tracking (throttled), max 20 tokens.
 * - Legacy-token bridge: the old plaintext `llmagnet_mcp_api_token` (WP-CLI)
 *   keeps working but is flagged `legacy` so the UI can nag to migrate.
 * - Brute-force protection: per-IP failure counter; callers should return
 *   HTTP 429 + Retry-After once an IP is blocked.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token CRUD, verification and abuse controls for the MCP server.
 */
class MCP_Tokens {

	/** Option storing the managed token records (autoload off). */
	const OPTION = 'llmagnet_mcp_tokens';

	/** Legacy single plaintext token set via WP-CLI. */
	const LEGACY_OPTION = 'llmagnet_mcp_api_token';

	/** Option storing OAuth-issued access/refresh grants (autoload off). */
	const OAUTH_GRANTS_OPTION = 'llmagnet_mcp_oauth_grants';

	/** Maximum number of managed tokens. */
	const MAX_TOKENS = 20;

	/** Maximum number of OAuth grants retained (older revoked/expired ones pruned). */
	const MAX_OAUTH_GRANTS = 100;

	/** OAuth access-token lifetime (seconds). */
	const OAUTH_ACCESS_TTL = 3600;

	/** OAuth refresh-token lifetime (seconds). */
	const OAUTH_REFRESH_TTL = 2592000;

	/** Secret prefix, also used for the display prefix. */
	const SECRET_PREFIX = 'llmt_';

	/** OAuth access-token prefix. */
	const OAUTH_ACCESS_PREFIX = 'llmo_';

	/** OAuth refresh-token prefix. */
	const OAUTH_REFRESH_PREFIX = 'llmr_';

	/** Failed-auth window (seconds). */
	const FAIL_WINDOW = 600;

	/** Failures within the window before an IP is blocked. */
	const FAIL_LIMIT = 10;

	/** Minimum seconds between last_used writes for one token (perf). */
	const LAST_USED_THROTTLE = 300;

	// ── CRUD ──────────────────────────────────────────────────────────────────

	/**
	 * Create a new token.
	 *
	 * The plaintext secret is returned exactly once and never stored.
	 *
	 * @param string $label   User-supplied label.
	 * @param string $scope   'read' or 'write'.
	 * @param int    $user_id Creating user ID (0 for CLI/system).
	 * @return array|\WP_Error { id, secret, prefix, label, scope, created_at } or error.
	 */
	public function create( $label, $scope = 'read', $user_id = 0 ) {
		$tokens = $this->get_records();

		$active = array_filter(
			$tokens,
			static function ( $t ) {
				return empty( $t['revoked'] );
			}
		);
		if ( count( $active ) >= self::MAX_TOKENS ) {
			return new \WP_Error(
				'llmagnet_mcp_token_limit',
				sprintf(
					/* translators: %d: maximum number of tokens. */
					__( 'Token limit reached (%d). Revoke an existing token first.', 'llmagnet-llm-txt-generator' ),
					self::MAX_TOKENS
				)
			);
		}

		$scope = ( 'write' === $scope ) ? 'write' : 'read';
		$label = sanitize_text_field( (string) $label );
		if ( '' === $label ) {
			$label = __( 'Unnamed token', 'llmagnet-llm-txt-generator' );
		}

		$secret = self::SECRET_PREFIX . bin2hex( random_bytes( 20 ) );

		$record = [
			'id'         => 'tk_' . bin2hex( random_bytes( 6 ) ),
			'label'      => $label,
			'hash'       => hash( 'sha256', $secret ),
			'prefix'     => substr( $secret, 0, 9 ),
			'scope'      => $scope,
			'created_at' => time(),
			'created_by' => (int) $user_id,
			'last_used'  => 0,
			'revoked'    => false,
		];

		$tokens[] = $record;
		update_option( self::OPTION, $tokens, false );

		return [
			'id'         => $record['id'],
			'secret'     => $secret,
			'prefix'     => $record['prefix'],
			'label'      => $record['label'],
			'scope'      => $record['scope'],
			'created_at' => $record['created_at'],
		];
	}

	/**
	 * List tokens for UI consumption — never includes hashes or secrets.
	 *
	 * @param bool $include_revoked Include revoked records.
	 * @return array[]
	 */
	public function list_tokens( $include_revoked = false ) {
		$out = [];
		foreach ( $this->get_records() as $t ) {
			if ( ! $include_revoked && ! empty( $t['revoked'] ) ) {
				continue;
			}
			$out[] = [
				'id'         => $t['id'],
				'label'      => $t['label'],
				'prefix'     => $t['prefix'],
				'scope'      => $t['scope'],
				'created_at' => $t['created_at'],
				'created_by' => $t['created_by'],
				'last_used'  => $t['last_used'],
				'revoked'    => ! empty( $t['revoked'] ),
			];
		}
		return $out;
	}

	/**
	 * Soft-revoke a token by id.
	 *
	 * @param string $id Token id.
	 * @return bool True if a token was revoked.
	 */
	public function revoke( $id ) {
		$tokens  = $this->get_records();
		$changed = false;
		foreach ( $tokens as &$t ) {
			if ( $t['id'] === $id && empty( $t['revoked'] ) ) {
				$t['revoked'] = true;
				$changed      = true;
			}
		}
		unset( $t );
		if ( $changed ) {
			update_option( self::OPTION, $tokens, false );
		}
		return $changed;
	}

	/**
	 * Whether the legacy WP-CLI plaintext token exists.
	 *
	 * @return bool
	 */
	public function has_legacy_token() {
		$legacy = get_option( self::LEGACY_OPTION, '' );
		return is_string( $legacy ) && '' !== $legacy;
	}

	/**
	 * Delete the legacy plaintext token.
	 *
	 * @return bool
	 */
	public function delete_legacy_token() {
		return delete_option( self::LEGACY_OPTION );
	}

	// ── Verification ──────────────────────────────────────────────────────────

	/**
	 * Verify a bearer candidate against the legacy token and managed tokens.
	 *
	 * @param string $candidate Raw bearer credential from the Authorization header.
	 * @return array|null Auth context on success:
	 *                    { type: 'token'|'legacy', id, label, scope, legacy: bool }
	 *                    or null on failure.
	 */
	public function verify( $candidate ) {
		if ( ! is_string( $candidate ) || '' === $candidate ) {
			return null;
		}

		// Legacy token bridge: plaintext compare, full (write) access, flagged legacy.
		$legacy = get_option( self::LEGACY_OPTION, '' );
		if ( is_string( $legacy ) && '' !== $legacy && hash_equals( $legacy, $candidate ) ) {
			return [
				'type'    => 'legacy',
				'id'      => 'legacy',
				'label'   => __( 'Legacy CLI token', 'llmagnet-llm-txt-generator' ),
				'scope'   => 'write',
				'user_id' => 0,
				'legacy'  => true,
			];
		}

		$candidate_hash = hash( 'sha256', $candidate );
		$tokens         = $this->get_records();

		foreach ( $tokens as $i => $t ) {
			if ( ! empty( $t['revoked'] ) || empty( $t['hash'] ) ) {
				continue;
			}
			if ( hash_equals( $t['hash'], $candidate_hash ) ) {
				$this->touch_last_used( $i, $tokens );
				return [
					'type'    => 'token',
					'id'      => $t['id'],
					'label'   => $t['label'],
					'scope'   => ( 'write' === $t['scope'] ) ? 'write' : 'read',
					'user_id' => 0,
					'legacy'  => false,
				];
			}
		}

		// OAuth access tokens (issued via the on-plugin authorization server).
		$oauth = $this->verify_oauth_access( $candidate, $candidate_hash );
		if ( null !== $oauth ) {
			return $oauth;
		}

		return null;
	}

	// ── OAuth grants (on-plugin authorization server) ──────────────────────────

	/**
	 * Issue an OAuth access + refresh token pair for an authorized connection.
	 *
	 * Secrets are returned once and stored only as SHA-256 hashes. The grant is
	 * recorded so it can be listed and revoked from the admin UI.
	 *
	 * @param string $client_id   Registered OAuth client id.
	 * @param string $client_name Human label (e.g. "ChatGPT").
	 * @param int    $user_id     Approving WordPress user id.
	 * @param string $scope       'read' or 'write'.
	 * @return array { access_token, refresh_token, expires_in, scope }
	 */
	public function issue_oauth_grant( $client_id, $client_name, $user_id, $scope ) {
		$scope   = ( 'write' === $scope ) ? 'write' : 'read';
		$now     = time();
		$access  = self::OAUTH_ACCESS_PREFIX . bin2hex( random_bytes( 24 ) );
		$refresh = self::OAUTH_REFRESH_PREFIX . bin2hex( random_bytes( 24 ) );

		$grants   = $this->get_oauth_grants();
		$grants[] = [
			'id'              => 'oa_' . bin2hex( random_bytes( 6 ) ),
			'client_id'       => (string) $client_id,
			'client_name'     => sanitize_text_field( (string) $client_name ),
			'user_id'         => (int) $user_id,
			'scope'           => $scope,
			'access_hash'     => hash( 'sha256', $access ),
			'access_expires'  => $now + self::OAUTH_ACCESS_TTL,
			'refresh_hash'    => hash( 'sha256', $refresh ),
			'refresh_expires' => $now + self::OAUTH_REFRESH_TTL,
			'created_at'      => $now,
			'last_used'       => 0,
			'revoked'         => false,
		];

		update_option( self::OAUTH_GRANTS_OPTION, $this->prune_oauth_grants( $grants ), false );

		return [
			'access_token'  => $access,
			'refresh_token' => $refresh,
			'expires_in'    => self::OAUTH_ACCESS_TTL,
			'scope'         => $scope,
		];
	}

	/**
	 * Rotate a refresh token: validate it, mint a fresh access + refresh pair,
	 * and invalidate the presented refresh token (rotation).
	 *
	 * @param string $refresh_candidate Raw refresh token from the token endpoint.
	 * @return array|null { access_token, refresh_token, expires_in, scope } or null on failure.
	 */
	public function refresh_oauth_grant( $refresh_candidate ) {
		if ( ! is_string( $refresh_candidate ) || '' === $refresh_candidate ) {
			return null;
		}

		$now    = time();
		$hash   = hash( 'sha256', $refresh_candidate );
		$grants = $this->get_oauth_grants();

		foreach ( $grants as $i => $g ) {
			if ( ! empty( $g['revoked'] ) || empty( $g['refresh_hash'] ) ) {
				continue;
			}
			if ( ! empty( $g['refresh_expires'] ) && (int) $g['refresh_expires'] < $now ) {
				continue;
			}
			if ( hash_equals( $g['refresh_hash'], $hash ) ) {
				$scope   = ( 'write' === $g['scope'] ) ? 'write' : 'read';
				$access  = self::OAUTH_ACCESS_PREFIX . bin2hex( random_bytes( 24 ) );
				$refresh = self::OAUTH_REFRESH_PREFIX . bin2hex( random_bytes( 24 ) );

				$grants[ $i ]['access_hash']     = hash( 'sha256', $access );
				$grants[ $i ]['access_expires']  = $now + self::OAUTH_ACCESS_TTL;
				$grants[ $i ]['refresh_hash']    = hash( 'sha256', $refresh );
				$grants[ $i ]['refresh_expires'] = $now + self::OAUTH_REFRESH_TTL;
				$grants[ $i ]['last_used']       = $now;

				update_option( self::OAUTH_GRANTS_OPTION, $grants, false );

				return [
					'access_token'  => $access,
					'refresh_token' => $refresh,
					'expires_in'    => self::OAUTH_ACCESS_TTL,
					'scope'         => $scope,
				];
			}
		}

		return null;
	}

	/**
	 * List active OAuth connections for the admin UI (no secrets/hashes).
	 *
	 * @return array[]
	 */
	public function list_oauth_connections() {
		$now = time();
		$out = [];
		foreach ( $this->get_oauth_grants() as $g ) {
			if ( ! empty( $g['revoked'] ) ) {
				continue;
			}
			$out[] = [
				'id'          => isset( $g['id'] ) ? $g['id'] : '',
				'client_name' => isset( $g['client_name'] ) ? $g['client_name'] : '',
				'client_id'   => isset( $g['client_id'] ) ? $g['client_id'] : '',
				'scope'       => isset( $g['scope'] ) ? $g['scope'] : 'read',
				'created_at'  => isset( $g['created_at'] ) ? (int) $g['created_at'] : 0,
				'last_used'   => isset( $g['last_used'] ) ? (int) $g['last_used'] : 0,
				'expired'     => isset( $g['refresh_expires'] ) && (int) $g['refresh_expires'] < $now,
			];
		}
		return $out;
	}

	/**
	 * Revoke an OAuth connection (access + refresh) by grant id.
	 *
	 * @param string $id Grant id.
	 * @return bool True when a connection was revoked.
	 */
	public function revoke_oauth_connection( $id ) {
		$grants  = $this->get_oauth_grants();
		$changed = false;
		foreach ( $grants as &$g ) {
			if ( isset( $g['id'] ) && $g['id'] === $id && empty( $g['revoked'] ) ) {
				$g['revoked'] = true;
				$changed      = true;
			}
		}
		unset( $g );
		if ( $changed ) {
			update_option( self::OAUTH_GRANTS_OPTION, $grants, false );
		}
		return $changed;
	}

	/**
	 * Match a candidate bearer against active, unexpired OAuth access tokens.
	 *
	 * @param string $candidate      Raw bearer credential.
	 * @param string $candidate_hash Pre-computed SHA-256 of the candidate.
	 * @return array|null Auth context on success, null otherwise.
	 */
	private function verify_oauth_access( $candidate, $candidate_hash ) {
		// Fast-path: only OAuth-issued tokens carry this prefix.
		if ( 0 !== strncmp( $candidate, self::OAUTH_ACCESS_PREFIX, strlen( self::OAUTH_ACCESS_PREFIX ) ) ) {
			return null;
		}

		$now    = time();
		$grants = $this->get_oauth_grants();

		foreach ( $grants as $i => $g ) {
			if ( ! empty( $g['revoked'] ) || empty( $g['access_hash'] ) ) {
				continue;
			}
			if ( ! empty( $g['access_expires'] ) && (int) $g['access_expires'] < $now ) {
				continue;
			}
			if ( hash_equals( $g['access_hash'], $candidate_hash ) ) {
				$this->touch_oauth_last_used( $i, $grants );
				return [
					'type'    => 'oauth',
					'id'      => isset( $g['id'] ) ? $g['id'] : '',
					'label'   => ( isset( $g['client_name'] ) && '' !== $g['client_name'] ) ? $g['client_name'] : 'OAuth client',
					'scope'   => ( 'write' === $g['scope'] ) ? 'write' : 'read',
					'user_id' => isset( $g['user_id'] ) ? (int) $g['user_id'] : 0,
					'legacy'  => false,
				];
			}
		}

		return null;
	}

	/**
	 * Raw OAuth grant records.
	 *
	 * @return array[]
	 */
	private function get_oauth_grants() {
		$grants = get_option( self::OAUTH_GRANTS_OPTION, [] );
		return is_array( $grants ) ? array_values( $grants ) : [];
	}

	/**
	 * Drop fully-dead grants (revoked or with an expired refresh token) and cap
	 * the retained count, keeping the most recent.
	 *
	 * @param array[] $grants Grant records.
	 * @return array[]
	 */
	private function prune_oauth_grants( $grants ) {
		$now  = time();
		$live = array_filter(
			$grants,
			static function ( $g ) use ( $now ) {
				if ( ! empty( $g['revoked'] ) ) {
					return false;
				}
				return ! ( isset( $g['refresh_expires'] ) && (int) $g['refresh_expires'] < $now );
			}
		);
		$live = array_values( $live );

		if ( count( $live ) > self::MAX_OAUTH_GRANTS ) {
			$live = array_slice( $live, -self::MAX_OAUTH_GRANTS );
		}
		return $live;
	}

	/**
	 * Update last_used on a matched grant, throttled like managed tokens.
	 *
	 * @param int     $index  Index in the grants array.
	 * @param array[] $grants Full grants array (already loaded).
	 * @return void
	 */
	private function touch_oauth_last_used( $index, $grants ) {
		$now  = time();
		$last = isset( $grants[ $index ]['last_used'] ) ? (int) $grants[ $index ]['last_used'] : 0;
		if ( ( $now - $last ) < self::LAST_USED_THROTTLE ) {
			return;
		}
		$grants[ $index ]['last_used'] = $now;
		update_option( self::OAUTH_GRANTS_OPTION, $grants, false );
	}

	// ── Brute-force protection ────────────────────────────────────────────────

	/**
	 * Record a failed bearer attempt for an IP.
	 *
	 * @param string $ip Client IP.
	 * @return void
	 */
	public function record_failure( $ip ) {
		$key   = $this->fail_key( $ip );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, self::FAIL_WINDOW );
	}

	/**
	 * Whether an IP is currently blocked for repeated auth failures.
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	public function is_blocked( $ip ) {
		return (int) get_transient( $this->fail_key( $ip ) ) >= self::FAIL_LIMIT;
	}

	/**
	 * Retry-After value (seconds) for a blocked IP.
	 *
	 * @return int
	 */
	public function get_retry_after() {
		return self::FAIL_WINDOW;
	}

	// ── Internals ─────────────────────────────────────────────────────────────

	/**
	 * Raw token records.
	 *
	 * @return array[]
	 */
	private function get_records() {
		$tokens = get_option( self::OPTION, [] );
		return is_array( $tokens ) ? array_values( $tokens ) : [];
	}

	/**
	 * Update last_used on a matched token, at most once per throttle window.
	 *
	 * @param int     $index  Index in the records array.
	 * @param array[] $tokens Full records array (already loaded).
	 * @return void
	 */
	private function touch_last_used( $index, $tokens ) {
		$now  = time();
		$last = isset( $tokens[ $index ]['last_used'] ) ? (int) $tokens[ $index ]['last_used'] : 0;
		if ( ( $now - $last ) < self::LAST_USED_THROTTLE ) {
			return;
		}
		$tokens[ $index ]['last_used'] = $now;
		update_option( self::OPTION, $tokens, false );
	}

	/**
	 * Transient key for an IP's failure counter.
	 *
	 * @param string $ip Client IP.
	 * @return string
	 */
	private function fail_key( $ip ) {
		return 'llmagnet_mcp_fail_' . md5( (string) $ip );
	}
}
