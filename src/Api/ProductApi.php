<?php

namespace CityPaintsERP\Api;

use WP_Error;

class ProductApi extends ApiClient {

	public function listProducts(): array|WP_Error {
		return $this->get( 'products' );
	}

	public function listPrices( int $id ): array|WP_Error {
		return $this->get( "products/prices/$id" );
	}

	public function listQuantities( int $id ): array|WP_Error {
		return $this->get( "products/quantities/$id" );
	}

	public function listImages( int $id ): array|WP_Error {
		return $this->get( "products/images/$id" );
	}

	// Optional: fetch by SKU or id if needed
	public function getBySku( string $sku ): array|WP_Error {
		return $this->get( "products/sku/$sku" );
	}
}
