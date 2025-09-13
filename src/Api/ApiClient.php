<?php

namespace CityPaintsERP\Api;

use Exception;
use WP_Error;

class ApiClient {
	private string $baseUrl;
	private string $username;
	private string $password;
	private string $apiKey;
	private AuthManager $auth;

	/**
	 * @throws Exception
	 */
	public function __construct() {
		global $CLOGGER;

		$options = get_option( 'citypaints_erp_settings', [] );

		if ( empty( $options['username'] || $options['password'] || $options['base_url'] || $options['api_key'] ) ) {
			throw new Exception( 'API credentials not set in settings.' );
		}


		$this->baseUrl  = rtrim( $options['base_url'] ?? '', '/' ) . '/';
		$this->username = $options['username'] ?? '';
		$this->password = $options['password'] ?? '';
		$this->apiKey   = $options['api_key'] ?? '';
		$this->auth     = new AuthManager();

//		$CLOGGER->log( "Api Client started" );
	}

	public function get( string $endpoint, array $args = [] ): array|WP_Error {
		return $this->request( 'GET', $endpoint, $args );
	}

	private function request( string $method, string $endpoint, array $params = [], array $body = [] ): array|WP_Error {
		$token = $this->auth->getToken();

		if ( ! $token ) {
			$token = $this->refreshToken();
			if ( is_wp_error( $token ) ) {
				return $token;
			}
		}

		$url = $this->baseUrl . ltrim( $endpoint, '/' );
		if ( ! empty( $params ) ) {
			$url = add_query_arg( $params, $url );
		}

		$response = wp_remote_request( $url, [
			'method'    => $method,
			'headers'   => [
				'Authorization' => "Bearer $token",
				'X-API-Key'     => $this->apiKey,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
			'body'      => ! empty( $body ) ? wp_json_encode( $body ) : null,
			'timeout'   => 20,
			'sslverify' => false, // 🔥 dev only
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code === 401 ) {
			$this->auth->clearToken();

			return new WP_Error( 'unauthorized', 'Invalid or expired token' );
		}

		return is_array( $data ) ? $data : [];
	}

	private function refreshToken(): string|WP_Error {
//		$url = $this->baseUrl . 'auth/token'; // adjust if endpoint differs

		$url = $this->baseUrl . 'authentication/login'; // adjust if endpoint differs

		$response = wp_remote_post( $url, [
			'headers'   => [
				'x-api-key'    => $this->apiKey,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'body'      => wp_json_encode( [
				'UserName' => $this->username,
				'Password' => $this->password,
			] ),
			'timeout'   => 15,
			'sslverify' => false, // 🔥 dev only
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || empty( $body['AccessToken'] ) ) {
			return new WP_Error( 'auth_failed', 'Failed to get token', [ 'response' => $body ] );
		}

		$token     = $body['AccessToken'];
		$expiresIn = $body['ExpiresIn'] ?? 3600;

		$this->auth->saveToken( $token, (int) $expiresIn );

		return $token;
	}

	public function post( string $endpoint, array $body = [] ): array|WP_Error {
		return $this->request( 'POST', $endpoint, [], $body );
	}
}
