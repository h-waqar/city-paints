<?php

namespace CityPaintsERP;

use CityPaintsERP\Admin\ProductSyncButton;
use CityPaintsERP\Admin\SettingsPage;
use CityPaintsERP\Api\ApiClient;
use CityPaintsERP\Debug\DebugLogger;
use CityPaintsERP\Includes\Enqueue;
use CityPaintsERP\Woo\AddToCart;
use CityPaintsERP\Woo\AdminOrderMeta;
use CityPaintsERP\Woo\Checkout;
use CityPaintsERP\Woo\PlaceOrder;

class Core {
	private ?ApiClient $apiClient = null;

	public function init(): void {
		global $CLOGGER;

		if ( is_admin() ) {
			new SettingsPage();
			new ProductSyncButton();

//			$api  = new ProductApi();
//			$call = new ProductMapper( $api );
//			$call->fetchMegaProducts();
//			$call->listProducts();

		}
		new DebugLogger();

//		new ShortCodes();

		new AddToCart();
		new Checkout();
		new PlaceOrder();
		new AdminOrderMeta();

		new Enqueue();
	}

	public function getApiClient(): ?ApiClient {
		return $this->apiClient;
	}
}
