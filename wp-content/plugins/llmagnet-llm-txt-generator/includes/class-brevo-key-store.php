<?php
/**
 * Encrypted storage for the Brevo API key in wp_options.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists the Brevo API key using AES-256-GCM (PHP 7.1+) with a key derived from WordPress salts.
 */
class Brevo_Key_Store {

	const OPTION_NAME = 'llmagnet_brevo_api_key_enc';

	/**
	 * Derive a 32-byte key from auth material (never store this in the database).
	 *
	 * @return string Binary key.
	 */
	public static function derive_key() {
		return hash_hmac( 'sha256', 'llmagnet_brevo_v1', wp_salt( 'auth' ), true );
	}

	/**
	 * Encrypt plaintext and return JSON string for wp_options.
	 *
	 * @param string $plaintext Raw API key.
	 * @return string|false JSON blob or false on failure.
	 */
	public static function encrypt_to_option_value( $plaintext ) {
		$plaintext = trim( (string) $plaintext );
		if ( '' === $plaintext ) {
			return false;
		}

		if ( function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			$key = self::derive_key();
			$iv  = function_exists( 'random_bytes' ) ? random_bytes( 12 ) : openssl_random_pseudo_bytes( 12 );
			$tag = '';
			$ciphertext = openssl_encrypt(
				$plaintext,
				'aes-256-gcm',
				$key,
				OPENSSL_RAW_DATA,
				$iv,
				$tag,
				'',
				16
			);
			if ( false === $ciphertext || '' === $tag ) {
				return false;
			}
			return wp_json_encode(
				[
					'v'   => 1,
					'alg' => 'aes-256-gcm',
					'iv'  => base64_encode( $iv ),
					'tag' => base64_encode( $tag ),
					'ct'  => base64_encode( $ciphertext ),
				]
			);
		}

		// Fallback: AES-256-CBC + HMAC (older OpenSSL builds).
		if ( ! function_exists( 'openssl_encrypt' ) || ! in_array( 'aes-256-cbc', openssl_get_cipher_methods(), true ) ) {
			return false;
		}
		$key     = self::derive_key();
		$enc_key = substr( $key, 0, 32 );
		$hmac_key = hash_hmac( 'sha256', 'llmagnet_brevo_hmac', wp_salt( 'secure_auth' ), true );
		$iv      = function_exists( 'random_bytes' ) ? random_bytes( 16 ) : openssl_random_pseudo_bytes( 16 );
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-cbc', $enc_key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			return false;
		}
		$mac = hash_hmac( 'sha256', $iv . $ciphertext, $hmac_key, true );
		return wp_json_encode(
			[
				'v'   => 1,
				'alg' => 'aes-256-cbc-hmac',
				'iv'  => base64_encode( $iv ),
				'mac' => base64_encode( $mac ),
				'ct'  => base64_encode( $ciphertext ),
			]
		);
	}

	/**
	 * Decrypt option JSON to plaintext API key.
	 *
	 * @param string $json Stored option value.
	 * @return string Empty string on failure or missing data.
	 */
	public static function decrypt_from_option_value( $json ) {
		$json = is_string( $json ) ? trim( $json ) : '';
		if ( '' === $json ) {
			return '';
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || empty( $data['v'] ) || empty( $data['ct'] ) || empty( $data['iv'] ) ) {
			return '';
		}

		$key = self::derive_key();
		$alg = isset( $data['alg'] ) ? $data['alg'] : 'aes-256-gcm';

		if ( 'aes-256-gcm' === $alg ) {
			if ( empty( $data['tag'] ) || ! function_exists( 'openssl_decrypt' ) ) {
				return '';
			}
			$iv  = base64_decode( $data['iv'], true );
			$tag = base64_decode( $data['tag'], true );
			$ct  = base64_decode( $data['ct'], true );
			if ( false === $iv || false === $tag || false === $ct ) {
				return '';
			}
			$plain = openssl_decrypt( $ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '' );
			return is_string( $plain ) ? $plain : '';
		}

		if ( 'aes-256-cbc-hmac' === $alg ) {
			if ( empty( $data['mac'] ) || ! function_exists( 'openssl_decrypt' ) ) {
				return '';
			}
			$enc_key   = substr( $key, 0, 32 );
			$hmac_key  = hash_hmac( 'sha256', 'llmagnet_brevo_hmac', wp_salt( 'secure_auth' ), true );
			$iv        = base64_decode( $data['iv'], true );
			$ct        = base64_decode( $data['ct'], true );
			$mac       = base64_decode( $data['mac'], true );
			if ( false === $iv || false === $ct || false === $mac ) {
				return '';
			}
			$expected = hash_hmac( 'sha256', $iv . $ct, $hmac_key, true );
			if ( ! hash_equals( $expected, $mac ) ) {
				return '';
			}
			$plain = openssl_decrypt( $ct, 'aes-256-cbc', $enc_key, OPENSSL_RAW_DATA, $iv );
			return is_string( $plain ) ? $plain : '';
		}

		return '';
	}

	/**
	 * Save plaintext key encrypted to wp_options.
	 *
	 * @param string $plaintext API key.
	 * @return bool Whether the option was updated.
	 */
	public static function save_plaintext_key( $plaintext ) {
		$blob = self::encrypt_to_option_value( $plaintext );
		if ( false === $blob ) {
			return false;
		}
		return update_option( self::OPTION_NAME, $blob, false );
	}

	/**
	 * Remove stored key.
	 *
	 * @return bool
	 */
	public static function clear_key() {
		return delete_option( self::OPTION_NAME );
	}

	/**
	 * Get decrypted API key from options only.
	 *
	 * @return string
	 */
	public static function get_decrypted_key() {
		$raw = get_option( self::OPTION_NAME, '' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}
		return self::decrypt_from_option_value( $raw );
	}

	/**
	 * If wp-config defines LLMAGNET_BREVO_API_KEY and nothing is stored yet, encrypt and persist once.
	 *
	 * @return void
	 */
	public static function maybe_migrate_from_constant() {
		if ( false !== get_option( self::OPTION_NAME, false ) ) {
			return;
		}
		if ( ! defined( 'LLMAGNET_BREVO_API_KEY' ) ) {
			return;
		}
		$plain = trim( (string) LLMAGNET_BREVO_API_KEY );
		if ( '' === $plain ) {
			return;
		}
		self::save_plaintext_key( $plain );
	}
}
