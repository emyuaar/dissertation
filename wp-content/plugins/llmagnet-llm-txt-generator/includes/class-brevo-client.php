<?php
/**
 * Brevo API Client
 *
 * Handles all communication with Brevo (formerly Sendinblue)
 * for transactional emails and contact management.
 *
 * @package LLMagnet
 * @since 1.0.0
 */

namespace LLMagnet\Lifecycle;

use LLMagnet_AI_SEO_Optimizer\Brevo_Key_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brevo_Client class
 */
class Brevo_Client {
	/**
	 * Brevo API base URL
	 *
	 * @var string
	 */
	const API_BASE_URL = 'https://api.brevo.com/v3';

	/**
	 * Request timeout in seconds
	 *
	 * @var int
	 */
	const REQUEST_TIMEOUT = 15;

	/**
	 * Get the Brevo API key (vendor transactional mail).
	 *
	 * Resolution order:
	 * 1. `llmagnet_brevo_api_key` filter — e.g. future remote fetch from your server.
	 * 2. `LLMAGNET_BREVO_API_KEY` constant (wp-config.php, main plugin file, or includes/brevo-vendor-key.php).
	 * 3. Encrypted `wp_options` value (CLI `brevo-set-key` / server provisioning).
	 *
	 * @return string API key or empty string
	 */
	private function get_api_key() {
		$filtered = apply_filters( 'llmagnet_brevo_api_key', '' );
		if ( is_string( $filtered ) ) {
			$filtered = trim( $filtered );
			if ( $filtered !== '' ) {
				return $filtered;
			}
		}
		if ( defined( 'LLMAGNET_BREVO_API_KEY' ) && LLMAGNET_BREVO_API_KEY !== '' ) {
			$from_constant = trim( (string) LLMAGNET_BREVO_API_KEY );
			if ( $from_constant !== '' ) {
				return $from_constant;
			}
		}
		return Brevo_Key_Store::get_decrypted_key();
	}

	/**
	 * Check if API key is configured
	 *
	 * @return bool
	 */
	public function is_configured() {
		return ! empty( $this->get_api_key() );
	}

	/**
	 * Send a transactional email using Brevo template
	 *
	 * @param string $to        Recipient email address
	 * @param int    $template_id Brevo template ID
	 * @param array  $params    Template parameters (key-value pairs)
	 * @param array  $tags      Optional tags for categorization
	 *
	 * @return array {
	 *     @type bool   $success              Whether send was successful
	 *     @type string $provider_message_id  Brevo message ID if successful
	 *     @type string $error_code           Error code if failed
	 *     @type string $error_message        Error message if failed
	 * }
	 */
	public function send_template( $to, $template_id, array $params = [], array $tags = [] ) {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return $this->error_response(
				'BREVO_NOT_CONFIGURED',
				'Brevo API key not configured'
			);
		}

		$to = sanitize_email( $to );
		if ( ! is_email( $to ) ) {
			return $this->error_response(
				'INVALID_RECIPIENT',
				'Invalid recipient email address'
			);
		}

		$template_id = intval( $template_id );
		if ( $template_id <= 0 ) {
			return $this->error_response(
				'INVALID_TEMPLATE_ID',
				'Invalid template ID'
			);
		}

		// Build request payload
		$body = [
			'to'         => [
				[
					'email' => $to,
				],
			],
			'templateId' => $template_id,
		];

		// Add parameters if provided
		if ( ! empty( $params ) ) {
			$body['params'] = $params;
		}

		// Add tags if provided
		if ( ! empty( $tags ) ) {
			$body['tags'] = (array) $tags;
		}

		return $this->make_request(
			'POST',
			'/smtp/email',
			$body,
			$api_key
		);
	}

	/**
	 * Create or update a contact in Brevo
	 *
	 * @param string $email        Contact email address
	 * @param array  $attributes   Contact attributes (name, company, etc.)
	 * @param array  $list_ids     List IDs to add contact to
	 *
	 * @return array {
	 *     @type bool   $success     Whether operation was successful
	 *     @type string $provider_id Brevo contact ID if successful
	 *     @type string $error_code  Error code if failed
	 *     @type string $error_message Error message if failed
	 * }
	 */
	public function upsert_contact( $email, array $attributes = [], array $list_ids = [] ) {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return $this->error_response(
				'BREVO_NOT_CONFIGURED',
				'Brevo API key not configured'
			);
		}

		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return $this->error_response(
				'INVALID_EMAIL',
				'Invalid email address'
			);
		}

		// Build request payload
		$body = [
			'email'         => $email,
			'updateEnabled' => true,
		];

		// Add attributes if provided
		if ( ! empty( $attributes ) ) {
			$body['attributes'] = $attributes;
		}

		// Add list IDs if provided
		if ( ! empty( $list_ids ) ) {
			$body['listIds'] = array_map( 'intval', (array) $list_ids );
		}

		return $this->make_request(
			'POST',
			'/contacts',
			$body,
			$api_key
		);
	}

	/**
	 * Get a contact by email address.
	 *
	 * @param string $email Contact email address.
	 *
	 * @return array
	 */
	public function get_contact_by_email( $email ) {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return $this->error_response(
				'BREVO_NOT_CONFIGURED',
				'Brevo API key not configured'
			);
		}

		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return $this->error_response(
				'INVALID_EMAIL',
				'Invalid email address'
			);
		}

		return $this->make_request(
			'GET',
			'/contacts/' . rawurlencode( $email ) . '?identifierType=email_id',
			[],
			$api_key
		);
	}

	/**
	 * Create a contact attribute if it does not exist yet.
	 *
	 * @param string $attribute_name Contact attribute internal name.
	 * @param string $type           Attribute type.
	 * @param string $category       Attribute category.
	 *
	 * @return array
	 */
	public function create_contact_attribute( $attribute_name, $type = 'text', $category = 'normal' ) {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return $this->error_response(
				'BREVO_NOT_CONFIGURED',
				'Brevo API key not configured'
			);
		}

		$attribute_name = sanitize_key( $attribute_name );
		if ( empty( $attribute_name ) ) {
			return $this->error_response(
				'INVALID_ATTRIBUTE_NAME',
				'Invalid contact attribute name'
			);
		}

		$allowed_categories = [ 'normal', 'transactional', 'category', 'calculated', 'global' ];
		if ( ! in_array( $category, $allowed_categories, true ) ) {
			return $this->error_response(
				'INVALID_ATTRIBUTE_CATEGORY',
				'Invalid contact attribute category'
			);
		}

		return $this->make_request(
			'POST',
			'/contacts/attributes/' . rawurlencode( $category ) . '/' . rawurlencode( strtoupper( $attribute_name ) ),
			[
				'type' => $type,
			],
			$api_key
		);
	}

	/**
	 * Get all company attributes.
	 *
	 * @return array
	 */
	public function get_company_attributes() {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return $this->error_response(
				'BREVO_NOT_CONFIGURED',
				'Brevo API key not configured'
			);
		}

		return $this->make_request(
			'GET',
			'/crm/attributes/companies',
			[],
			$api_key
		);
	}

	/**
	 * Create a company attribute.
	 *
	 * @param string $label Attribute label.
	 * @param string $type  Attribute type.
	 *
	 * @return array
	 */
	public function create_company_attribute( $label, $type = 'text' ) {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return $this->error_response(
				'BREVO_NOT_CONFIGURED',
				'Brevo API key not configured'
			);
		}

		$label = sanitize_text_field( $label );
		if ( '' === $label ) {
			return $this->error_response(
				'INVALID_COMPANY_ATTRIBUTE_LABEL',
				'Invalid company attribute label'
			);
		}

		return $this->make_request(
			'POST',
			'/crm/attributes',
			[
				'attributeType' => $type,
				'label'         => $label,
				'objectType'    => 'companies',
			],
			$api_key
		);
	}

	/**
	 * Get companies linked to a specific contact.
	 *
	 * @param int $contact_id Brevo contact ID.
	 *
	 * @return array
	 */
	public function get_companies_by_contact_id( $contact_id ) {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return $this->error_response(
				'BREVO_NOT_CONFIGURED',
				'Brevo API key not configured'
			);
		}

		$contact_id = intval( $contact_id );
		if ( $contact_id <= 0 ) {
			return $this->error_response(
				'INVALID_CONTACT_ID',
				'Invalid contact ID'
			);
		}

		$endpoint = add_query_arg(
			[
				'linkedContactsIds' => $contact_id,
				'limit'             => 50,
			],
			'/companies'
		);

		return $this->make_request(
			'GET',
			$endpoint,
			[],
			$api_key
		);
	}

	/**
	 * Create a company in Brevo.
	 *
	 * @param string $name               Company name.
	 * @param array  $attributes         Company attributes.
	 * @param array  $linked_contact_ids Contact IDs to link.
	 *
	 * @return array
	 */
	public function create_company( $name, array $attributes = [], array $linked_contact_ids = [] ) {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return $this->error_response(
				'BREVO_NOT_CONFIGURED',
				'Brevo API key not configured'
			);
		}

		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return $this->error_response(
				'INVALID_COMPANY_NAME',
				'Invalid company name'
			);
		}

		$body = [
			'name' => $name,
		];

		if ( ! empty( $attributes ) ) {
			$body['attributes'] = $attributes;
		}

		if ( ! empty( $linked_contact_ids ) ) {
			$body['linkedContactsIds'] = array_map( 'intval', $linked_contact_ids );
		}

		return $this->make_request(
			'POST',
			'/companies',
			$body,
			$api_key
		);
	}

	/**
	 * Update an existing company in Brevo.
	 *
	 * @param string $company_id Company ID.
	 * @param string $name       Company name.
	 * @param array  $attributes Company attributes.
	 *
	 * @return array
	 */
	public function update_company( $company_id, $name, array $attributes = [] ) {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return $this->error_response(
				'BREVO_NOT_CONFIGURED',
				'Brevo API key not configured'
			);
		}

		$company_id = sanitize_text_field( $company_id );
		$name = sanitize_text_field( $name );

		if ( '' === $company_id || '' === $name ) {
			return $this->error_response(
				'INVALID_COMPANY_PAYLOAD',
				'Invalid company payload'
			);
		}

		$body = [
			'name' => $name,
		];

		if ( ! empty( $attributes ) ) {
			$body['attributes'] = $attributes;
		}

		return $this->make_request(
			'PATCH',
			'/companies/' . rawurlencode( $company_id ),
			$body,
			$api_key
		);
	}

	/**
	 * Link an existing company to a contact.
	 *
	 * @param string $company_id Company ID.
	 * @param int    $contact_id Contact ID.
	 *
	 * @return array
	 */
	public function link_company_to_contact( $company_id, $contact_id ) {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return $this->error_response(
				'BREVO_NOT_CONFIGURED',
				'Brevo API key not configured'
			);
		}

		$company_id = sanitize_text_field( $company_id );
		$contact_id = intval( $contact_id );
		if ( '' === $company_id || $contact_id <= 0 ) {
			return $this->error_response(
				'INVALID_COMPANY_LINK',
				'Invalid company/contact link payload'
			);
		}

		return $this->make_request(
			'PATCH',
			'/companies/link-unlink/' . rawurlencode( $company_id ),
			[
				'linkContactIds' => [ $contact_id ],
			],
			$api_key
		);
	}

	/**
	 * Make HTTP request to Brevo API
	 *
	 * @param string $method   HTTP method (GET, POST, PUT, DELETE)
	 * @param string $endpoint API endpoint (e.g., '/smtp/email')
	 * @param array  $body     Request body for POST/PUT
	 * @param string $api_key  Brevo API key
	 *
	 * @return array Response array with success/error details
	 */
	private function make_request( $method, $endpoint, $body = [], $api_key = '' ) {
		$url = self::API_BASE_URL . $endpoint;

		$args = [
			'method'      => $method,
			'timeout'     => self::REQUEST_TIMEOUT,
			'redirection' => 5,
			'headers'     => [
				'api-key'       => $api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
		];

		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		// Make the request
		$response = wp_remote_request( $url, $args );

		// Check for network errors
		if ( is_wp_error( $response ) ) {
			$error_msg = $response->get_error_message();
			error_log( 'Brevo API Error: ' . $error_msg );
			return $this->error_response(
				'NETWORK_ERROR',
				'Failed to connect to Brevo: ' . $error_msg
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		// Parse response body
		$data = json_decode( $response_body, true );

		// Check for API errors
		if ( $status_code >= 400 ) {
			$error_msg = '';
			if ( is_array( $data ) && isset( $data['message'] ) ) {
				$error_msg = $data['message'];
			} else {
				$error_msg = 'HTTP ' . $status_code;
			}

			error_log(
				'Brevo API Error [' . $status_code . ']: ' . wp_json_encode(
					[
						'endpoint' => $endpoint,
						'error'    => $error_msg,
						'response' => $data,
					]
				)
			);

			return $this->error_response(
				'API_ERROR_' . $status_code,
				$error_msg
			);
		}

		// Success
		$provider_id = '';
		if ( is_array( $data ) && isset( $data['messageId'] ) ) {
			$provider_id = $data['messageId'];
		} elseif ( is_array( $data ) && isset( $data['id'] ) ) {
			$provider_id = strval( $data['id'] );
		}

		return [
			'success'              => true,
			'provider_message_id'  => $provider_id,
			'provider_id'          => $provider_id,
			'error_code'           => '',
			'error_message'        => '',
			'data'                 => is_array( $data ) ? $data : [],
		];
	}

	/**
	 * Create a standardized error response
	 *
	 * @param string $error_code    Error code
	 * @param string $error_message Error message
	 *
	 * @return array Error response array
	 */
	private function error_response( $error_code = '', $error_message = '' ) {
		return [
			'success'              => false,
			'provider_message_id'  => '',
			'provider_id'          => '',
			'error_code'           => $error_code,
			'error_message'        => $error_message,
			'data'                 => [],
		];
	}
}
