<?php

namespace CityPaintsERP\Sync;

use Throwable;

class SyncManager {
	public function syncProducts( array $products ): array {
		global $CLOGGER;
		$summary = [ 'created' => 0, 'updated' => 0, 'errors' => [] ];

		foreach ( $products as $p ) {
			try {
				$existing = wc_get_product_id_by_sku( $p['SKU'] );
				if ( $existing ) {
					$this->updateProduct( $existing, $p );
					$summary['updated'] ++;
				} else {
					$this->createProduct( $p );
					$summary['created'] ++;
				}
				$CLOGGER->info( "Synced product {$p['SKU']}" );
			} catch ( Throwable $e ) {
				$CLOGGER->error( "Error syncing {$p['SKU']}", $e->getMessage() );
				$summary['errors'][] = [ 'sku' => $p['SKU'], 'error' => $e->getMessage() ];
			}
		}

		update_option( 'citypaints_last_sync', current_time( 'mysql' ), false );

		return $summary;
	}


}