<?php

namespace CityPaintsERP;

use CityPaintsERP\Admin\ProductSyncButton;
use CityPaintsERP\Admin\SettingsPage;
use CityPaintsERP\Api\ApiClient;
use CityPaintsERP\Api\ProductApi;
use CityPaintsERP\Includes\Enqueue;

class Core {
	private ?ApiClient $apiClient = null;

	public function init(): void {
		global $CLOGGER;

//		$this->initApiClient();

//		new ApiClient();

		if ( is_admin() ) {
			new SettingsPage();
			new ProductSyncButton();

//			$call = new ProductApi();
			new ProductApi();
		}
		
		new Enqueue(); // handles both admin & frontend scripts


//		$CLOGGER->log( 'Core initialized' );
	}

	public function getApiClient(): ?ApiClient {
		return $this->apiClient;
	}
}
