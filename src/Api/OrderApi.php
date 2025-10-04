<?php

namespace CityPaintsERP\Api;

use Exception;
use WP_Error;

class OrderApi {
	private ApiClient $client;

	/**
	 * @throws Exception
	 */
	public function __construct() {
		try {
			$this->client = new ApiClient();
		} catch ( Exception $e ) {
			throw new Exception( 'Failed to initialize API client: ' . $e->getMessage() );
		}
	}

	/**
	 * Create a new order in ERP
	 *
	 * @param array $payload
	 *
	 * @return array|WP_Error
	 */
	public function createOrder( array $payload ): array|WP_Error {
		try {
			// Assuming the endpoint is 'orders'
			return $this->client->post( 'orders', $payload );
		} catch ( Exception $e ) {
			return new WP_Error( 'erp_api_error', $e->getMessage() );
		}
	}
}
