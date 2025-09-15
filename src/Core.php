<?php

namespace CityPaintsERP;

use CityPaintsERP\Admin\ProductSyncButton;
use CityPaintsERP\Admin\SettingsPage;
use CityPaintsERP\Api\ApiClient;
use CityPaintsERP\Includes\Enqueue;

class Core {
	private ?ApiClient $apiClient = null;

	public function init(): void {
		global $CLOGGER;

		if ( is_admin() ) {
			new SettingsPage();
			new ProductSyncButton();

//			$call = new ProductApi();
//			$call->listProducts();


		}

		new Enqueue();
	}

	public function getApiClient(): ?ApiClient {
		return $this->apiClient;
	}
}
