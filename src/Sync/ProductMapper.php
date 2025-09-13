<?php

namespace CityPaintsERP\Sync;

use CityPaintsERP\Api\ProductApi;
use WP_Error;

class ProductMapper {
	private ProductApi $productApi;

	public function __construct( ProductApi $api ) {
		$this->productApi = $api;
	}

	/**
	 * Return array of merged product arrays (mega objects).
	 * [
	 *   [
	 *     'Id'=>1,'SKU'=>'5190841','Name'=>'...','Units'=>[ ... ],
	 *     'Prices'=>[ ... ], 'Qtys'=>[ ... ], 'Images'=>[ ... ]
	 *   ],
	 *   ...
	 * ]
	 */
	public function fetchMegaProducts(): array|WP_Error {
		$products = $this->productApi->listProducts();
		$prices   = $this->productApi->listPrices();
		$qtys     = $this->productApi->listQuantities();
		$images   = $this->productApi->listImages();

		// handle WP_Error
		if ( is_wp_error( $products ) || is_wp_error( $prices ) || is_wp_error( $qtys ) || is_wp_error( $images ) ) {
			return is_wp_error( $products ) ? $products : new WP_Error( 'api_error', 'Failed to fetch some endpoints' );
		}

		// build lookup maps keyed by product Id
		$priceMap = [];
		foreach ( $prices as $p ) {
			$priceMap[ $p['Id'] ] = $p['Product_Prices'] ?? [];
		}

		$qtyMap = [];
		foreach ( $qtys as $q ) {
			$qtyMap[ $q['Id'] ] = $q['Product_Qtys'] ?? [];
		}

		$imgMap = [];
		foreach ( $images as $i ) {
			$imgMap[ $i['Id'] ] = $i['Product_Images'] ?? [];
		}

		// merge
		$out = [];
		foreach ( $products as $product ) {
			$id                        = $product['Id'];
			$product['Product_Prices'] = $priceMap[ $id ] ?? [];
			$product['Product_Qtys']   = $qtyMap[ $id ] ?? [];
			$product['Product_Images'] = $imgMap[ $id ] ?? [];
			$out[]                     = $this->normalize( $product );
		}

		return $out;
	}

	private function normalize( array $p ): array {
		// flatten into the shape SyncManager expects
		// map units into: [unit_id => ['id'=>..., 'short_name'=>..., 'price'=>..., 'stock'=>..., 'barcodes'=>[]]]
		$units = [];
		foreach ( $p['Product_Units'] ?? [] as $u ) {
			$uid           = $u['Id'];
			$units[ $uid ] = [
				'Id'          => $uid,
				'Short_Name'  => $u['Short_Name'] ?? '',
				'Description' => $u['Description'] ?? '',
				'Price'       => $this->findPriceForUnit( $p['Product_Prices'], $uid ),
				'Stock'       => $this->findQtyForUnit( $p['Product_Qtys'], $uid ),
				'BarCodes'    => $this->findBarcodesForUnit( $p['Product_BarCodes'], $uid ),
				'Images'      => $this->findImagesForUnit( $p['Product_Images'], $uid ),
			];
		}

		return [
			'Id'          => $p['Id'],
			'SKU'         => trim( $p['SKU'] ),
			'Name'        => $p['Name'],
			'Description' => $p['Full_Description'] ?? '',
			'Units'       => $units,
			'Raw'         => $p, // keep original for debugging
		];
	}

	private function findPriceForUnit( array $unitPrices, int $unitId ) {
		// unitPrices structure: [ ['Unit_Id'=>1,'Prices'=>[...]] , ...]
		foreach ( $unitPrices as $up ) {
			if ( (int) $up['Unit_Id'] === (int) $unitId ) {
				// find price where IsCustomerPrice true
				foreach ( $up['Prices'] as $price ) {
					if ( ! empty( $price['IsCustomerPrice'] ) ) {
						return $price;
					}
				}

				return $up['Prices'][0] ?? null;
			}
		}

		return null;
	}

	private function findQtyForUnit( array $unitQtys, int $unitId ) {
		foreach ( $unitQtys as $uq ) {
			if ( (int) $uq['Unit_Id'] === (int) $unitId ) {
				return $uq;
			}
		}

		return null;
	}

	private function findBarcodesForUnit( array $barcodes, int $unitId ) {
		$out = [];
		foreach ( $barcodes as $b ) {
			if ( (int) $b['Id'] === (int) $unitId ) {
				$out[] = trim( $b['BarCode'] );
			}
		}

		return $out;
	}

	private function findImagesForUnit( array $productImages, int $unitId ) {
		// productImages matches Product_Images structure (Unit_Id => Images)
		foreach ( $productImages as $pi ) {
			if ( (int) $pi['Unit_Id'] === (int) $unitId ) {
				return $pi['Images'] ?? [];
			}
		}

		return [];
	}
}
