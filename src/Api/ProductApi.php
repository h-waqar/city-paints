<?php

namespace CityPaintsERP\Api;

use WP_Error;

class ProductApi {


	private ApiClient $api;

	public function __construct() {
		global $CLOGGER;

		$this->api = new ApiClient();

		echo "<pre>";

//		$listProducts   = $this->listProducts();
//		$listQuantities = $this->listQuantities();
//		$listPrices     = $this->listPrices();
//		$listImages     = $this->listImages();
//		$getBySku       = $this->getBySku( 5190841 );
//
//		$CLOGGER->log( "Product listProducts", $listProducts );
//		$CLOGGER->log( "Product listQuantities", $listQuantities );
//		$CLOGGER->log( "Product listPrices", $listPrices );
//		$CLOGGER->log( "Product listImages", $listImages );
//		$CLOGGER->log( "Product getBySku", $getBySku );


	}

	public function listProducts(): array|WP_Error {
		// returns array of products (as in your sample)
		return $this->api->get( 'products' );
	}

	public function listQuantities(): array|WP_Error {
		return $this->api->get( 'products/quantities' );
	}

	public function listPrices(): array|WP_Error {
		return $this->api->get( 'products/prices' );
	}

	public function listImages(): array|WP_Error {
		return $this->api->get( 'products/images' );
	}

	// Optional: fetch by SKU or id if needed
	public function getBySku( string $sku ): array|WP_Error {
		return $this->api->get( "products/sku/{$sku}" );
	}
}
