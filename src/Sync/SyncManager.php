<?php

namespace CityPaintsERP\Sync;

use WC_Product_Variable;
use WC_Product_Variation;

class SyncManager {
	private function createProduct( array $p ): void {
		$product = new WC_Product_Variable();
		$product->set_name( $p['Name'] );
		$product->set_sku( $p['SKU'] );
		$product->set_description( $p['Description'] ?? '' );

		// Save main product first
		$product_id = $product->save();

		// Setup attributes & variations
		$this->setupAttributesAndVariations( $product, $p['Units'] );
	}

	private function setupAttributesAndVariations( WC_Product_Variable $product, array $units ): void {
		$attribute_name = 'pa_unit_size';
		$unit_labels    = array_map( fn( $u ) => $u['Short_Name'], $units );

		wp_set_object_terms( $product->get_id(), $unit_labels, $attribute_name );

		$attributes = [
			$attribute_name => [
				'name'         => $attribute_name,
				'value'        => implode( ' | ', $unit_labels ),
				'is_visible'   => 1,
				'is_variation' => 1,
				'is_taxonomy'  => 1,
			],
		];
		$product->set_attributes( $attributes );
		$product->save();

		// Create/Update variations
		foreach ( $units as $unit ) {
			$this->createOrUpdateVariation( $product, $unit, $attribute_name );
		}
	}

	private function createOrUpdateVariation( WC_Product_Variable $product, array $unit, string $attribute_name ): void {
		$variation_sku = $product->get_sku() . '-' . $unit['Id'];
		$variation_id  = wc_get_product_id_by_sku( $variation_sku );

		if ( $variation_id ) {
			$variation = new WC_Product_Variation( $variation_id );
		} else {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_sku( $variation_sku );
		}

		$variation->set_regular_price( $unit['Price']['Selling_Price'] ?? 0 );
		$variation->set_stock_quantity( $unit['Stock']['Quantity_On_Hand'] ?? 0 );
		$variation->set_manage_stock( true );
		$variation->set_attribute( $attribute_name, $unit['Short_Name'] );

		$variation->save();
	}

	private function updateProduct( int $product_id, array $p ): void {
		$product = new WC_Product_Variable( $product_id );
		$product->set_name( $p['Name'] );
		$product->set_description( $p['Description'] ?? '' );
		$product->save();

		$this->setupAttributesAndVariations( $product, $p['Units'] );
	}


}
