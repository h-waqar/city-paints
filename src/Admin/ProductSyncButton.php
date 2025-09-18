<?php

namespace CityPaintsERP\Admin;


use CityPaintsERP\Sync\SyncManager;
use Throwable;

class ProductSyncButton {

//	private $fetch;

	public function __construct() {
		// Add button in Products list page toolbar
		add_action( 'manage_posts_extra_tablenav', [ $this, 'renderButton' ], 20, 1 );

		// Register AJAX action
		add_action( 'wp_ajax_citypaints_sync_products', [ $this, 'handleSync' ] );

//		$this->fetch = new ProductApi();
//		$api         = new ProductApi();
//		$this->fetch = new ProductMapper( $api );
	}

	public function renderButton( string $which ): void {
		$screen = get_current_screen();

		// Only on Products list table, and only on the top toolbar row
		if ( $screen && $screen->id === 'edit-product' && $which === 'top' ) {
			echo '<div class="alignleft actions">';
			echo '<button id="citypaints-sync-products" class="button button-primary">';
			echo esc_html__( 'Sync Products from ERP', 'citypaints-erp-sync' );
			echo '</button>';
			echo '</div>';
		}
	}

	public function handleSync(): void {
		try {
			check_ajax_referer( 'citypaints_sync', 'nonce' );

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( [ 'message' => 'Permission denied' ], 403 );
			}

			// Just call your sync function
			$this->syncProducts();

		} catch ( Throwable $e ) {
			wp_send_json_error( [
				'message' => $e->getMessage(),
				'trace'   => $e->getTraceAsString(),
			], 500 );
		}
	}

	private function syncProducts(): void {
		$syncManager = new SyncManager();

		$syncManager->syncProducts();
	}

}
