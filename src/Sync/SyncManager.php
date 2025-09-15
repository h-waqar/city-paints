<?php
//
//namespace CityPaintsERP\Sync;
//
//use CityPaintsERP\Api\ProductApi;
//use WC_Product_Variable;
//use WC_Product_Variation;
//use WP_Error;
//
//class SyncManager {
//
//	/**
//	 * Entry point for AJAX or manual calls
//	 */
//	public function syncProducts(): void {
//		$megaProducts = $this->fetchMegaProducts();
//
//		if ( is_wp_error( $megaProducts ) ) {
//			wp_send_json_error( [
//				'message' => 'Failed to fetch products.',
//				'errors'  => $megaProducts->get_error_messages(),
//			] );
//		}
//
//		foreach ( $megaProducts as $p ) {
//			$product_id = wc_get_product_id_by_sku( $p['SKU'] );
//
//			if ( $product_id ) {
//				$this->updateProduct( $product_id, $p );
//			} else {
//				$this->createProduct( $p );
//			}
//		}
//
//		wp_send_json_success( [ 'message' => 'Products synced successfully!' ] );
//	}
//
//	/**
//	 * Fetch products from ERP via your wrapper
//	 * (this method already exists in your codebase)
//	 */
//	private function fetchMegaProducts(): array|WP_Error {
//		// 🔹 This should call your existing implementation
//		// Example: return $this->productApi->fetchMegaProducts();
//
//		$api   = new ProductApi();
//		$fetch = new ProductMapper( $api );
//
//		return $fetch->fetchMegaProducts();
//	}
//
//	/**
//	 * Update an existing variable product
//	 */
//	private function updateProduct( int $product_id, array $p ): void {
//		$product = new WC_Product_Variable( $product_id );
//		$product->set_name( $p['Name'] );
//		$product->set_description( $p['Description'] ?? '' );
//		$product->save();
//
//		$this->setupAttributesAndVariations( $product, $p['Units'] );
//	}
//
//	/**
//	 * Ensure attributes are set and create/update variations
//	 */
//	private function setupAttributesAndVariations( WC_Product_Variable $product, array $units ): void {
//		$attribute_name = 'pa_unit_size';
//
//		// Ensure attribute taxonomy exists
//		$this->ensureAttributeExists( $attribute_name, 'Unit Size' );
//
//		$unit_labels = array_map( fn( $u ) => $u['Short_Name'], $units );
//
//		wp_set_object_terms( $product->get_id(), $unit_labels, $attribute_name );
//
//		$attributes = [
//			$attribute_name => [
//				'name'         => $attribute_name,
//				'value'        => implode( ' | ', $unit_labels ),
//				'is_visible'   => 1,
//				'is_variation' => 1,
//				'is_taxonomy'  => 1,
//			],
//		];
//		$product->set_attributes( $attributes );
//		$product->save();
//
//		// Create/Update variations
//		foreach ( $units as $unit ) {
//			$this->createOrUpdateVariation( $product, $unit, $attribute_name );
//		}
//	}
//
//	/**
//	 * Ensure WooCommerce attribute exists
//	 */
//	private function ensureAttributeExists( string $taxonomy, string $label ): void {
//		global $wpdb;
//		$attr_name = wc_sanitize_taxonomy_name( str_replace( 'pa_', '', $taxonomy ) );
//
//		$exists = $wpdb->get_var( $wpdb->prepare(
//			"SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s",
//			$attr_name
//		) );
//
//		if ( ! $exists ) {
//			$wpdb->insert(
//				"{$wpdb->prefix}woocommerce_attribute_taxonomies",
//				[
//					'attribute_name'    => $attr_name,
//					'attribute_label'   => $label,
//					'attribute_type'    => 'select',
//					'attribute_orderby' => 'menu_order',
//					'attribute_public'  => 0,
//				]
//			);
//			delete_transient( 'wc_attribute_taxonomies' );
//		}
//	}
//
//	/**
//	 * Create or update a variation
//	 */
//	private function createOrUpdateVariation( WC_Product_Variable $product, array $unit, string $attribute_name ): void {
//		$variation_sku = $product->get_sku() . '-' . $unit['Id'];
//		$variation_id  = wc_get_product_id_by_sku( $variation_sku );
//
//		if ( $variation_id ) {
//			$variation = new WC_Product_Variation( $variation_id );
//		} else {
//			$variation = new WC_Product_Variation();
//			$variation->set_parent_id( $product->get_id() );
//			$variation->set_sku( $variation_sku );
//		}
//
//		// Pricing & stock
//		$variation->set_regular_price( $unit['Price']['Selling_Price'] ?? 0 );
//		$variation->set_stock_quantity( $unit['Stock']['Quantity_On_Hand'] ?? 0 );
//		$variation->set_manage_stock( true );
//
//		// ✅ FIXED: Variations require set_attributes() instead of set_attribute()
//		$current_attrs                    = $variation->get_attributes();
//		$current_attrs[ $attribute_name ] = $unit['Short_Name'];
//		$variation->set_attributes( $current_attrs );
//
//		// Optional: attach image if ERP provided one
//		if ( ! empty( $unit['Images'] ) && isset( $unit['Images'][0]['Path'] ) ) {
//			$image_id = $this->attachImageFromUrl( $unit['Images'][0]['Path'] );
//			if ( $image_id ) {
//				$variation->set_image_id( $image_id );
//			}
//		}
//
//		$variation->save();
//	}
//
//
//	/**
//	 * Download and attach image from URL
//	 */
//	private function attachImageFromUrl( string $url ): ?int {
//		$image_id = attachment_url_to_postid( $url );
//		if ( $image_id ) {
//			return $image_id;
//		}
//
//		$tmp = download_url( $url );
//		if ( is_wp_error( $tmp ) ) {
//			return null;
//		}
//
//		$file_array = [
//			'name'     => basename( $url ),
//			'tmp_name' => $tmp,
//		];
//
//		$image_id = media_handle_sideload( $file_array, 0 );
//		if ( is_wp_error( $image_id ) ) {
//			@unlink( $tmp );
//
//			return null;
//		}
//
//		return $image_id;
//	}
//
//	/**
//	 * Create a new variable product
//	 */
//	private function createProduct( array $p ): void {
//		$product = new WC_Product_Variable();
//		$product->set_name( $p['Name'] );
//		$product->set_sku( $p['SKU'] );
//		$product->set_description( $p['Description'] ?? '' );
//
//		// Save main product first
//		$product_id = $product->save();
//
//		// Setup attributes & variations
//		$this->setupAttributesAndVariations( $product, $p['Units'] );
//	}
//}


namespace CityPaintsERP\Sync;

use CityPaintsERP\Api\ProductApi;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Error;

class SyncManager {

	/**
	 * Entry point for AJAX or manual calls
	 */
	public function syncProducts(): void {
		$megaProducts = $this->fetchMegaProducts();

		if ( is_wp_error( $megaProducts ) ) {
			wp_send_json_error( [
				'message' => 'Failed to fetch products.',
				'errors'  => $megaProducts->get_error_messages(),
			] );
		}

		foreach ( $megaProducts as $p ) {
			$product_id = wc_get_product_id_by_sku( $p['SKU'] );

			if ( $product_id ) {
				$this->updateProduct( $product_id, $p );
			} else {
				$this->createProduct( $p );
			}
		}

		wp_send_json_success( [ 'message' => 'Products synced successfully!' ] );
	}

	/**
	 * Fetch products from ERP
	 */
	private function fetchMegaProducts(): array|WP_Error {
		$api   = new ProductApi();
		$fetch = new ProductMapper( $api );

		return $fetch->fetchMegaProducts();
	}

	/**
	 * Update an existing variable product
	 */
	private function updateProduct( int $product_id, array $p ): void {
		$product = new WC_Product_Variable( $product_id );
		$product->set_name( $p['Name'] );
		$product->set_description( $p['Description'] ?? '' );
		$product->save();

		$this->setupAttributesAndVariations( $product, $p['Units'] );
	}

	/**
	 * Ensure attributes are set and create/update variations
	 */
	private function setupAttributesAndVariations( WC_Product_Variable $product, array $units ): void {
		$attribute_name = 'pa_unit_size';

		// Ensure attribute taxonomy exists
		$this->ensureAttributeExists( $attribute_name, 'Unit Size' );

		// Collect unit labels (e.g. "1L", "5L", "10L")
		$unit_labels = array_map( fn( $u ) => $u['Short_Name'], $units );

		// Register terms in this attribute taxonomy
		foreach ( $unit_labels as $label ) {
			if ( ! term_exists( $label, $attribute_name ) ) {
				wp_insert_term( $label, $attribute_name );
			}
		}

		// Attach terms to product
		wp_set_object_terms( $product->get_id(), $unit_labels, $attribute_name );

		// Build WooCommerce attribute object
		$attr_obj = new WC_Product_Attribute();
		$attr_obj->set_id( wc_attribute_taxonomy_id_by_name( $attribute_name ) );
		$attr_obj->set_name( $attribute_name );
		$attr_obj->set_options( $unit_labels );
		$attr_obj->set_visible( true );
		$attr_obj->set_variation( true );

		// Assign attribute to product
		$product->set_attributes( [ $attr_obj ] );
		$product->save();

		// Create/Update variations
		foreach ( $units as $unit ) {
			$this->createOrUpdateVariation( $product, $unit, $attribute_name );
		}
	}

	/**
	 * Ensure WooCommerce attribute exists
	 */
	private function ensureAttributeExists( string $taxonomy, string $label ): void {
		global $wpdb;
		$attr_name = wc_sanitize_taxonomy_name( str_replace( 'pa_', '', $taxonomy ) );

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s",
			$attr_name
		) );

		if ( ! $exists ) {
			$wpdb->insert(
				"{$wpdb->prefix}woocommerce_attribute_taxonomies",
				[
					'attribute_name'    => $attr_name,
					'attribute_label'   => $label,
					'attribute_type'    => 'select',
					'attribute_orderby' => 'menu_order',
					'attribute_public'  => 0,
				]
			);
			delete_transient( 'wc_attribute_taxonomies' );
		}
	}

	/**
	 * Create or update a variation
	 */
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

		// Pricing & stock
		$variation->set_regular_price( $unit['Price']['Selling_Price'] ?? 0 );
		$variation->set_stock_quantity( $unit['Stock']['Quantity_On_Hand'] ?? 0 );
		$variation->set_manage_stock( true );

		// Assign attribute to variation
		$current_attrs                    = $variation->get_attributes();
		$current_attrs[ $attribute_name ] = $unit['Short_Name'];
		$variation->set_attributes( $current_attrs );

		// Attach variation image if available
		if ( ! empty( $unit['Images'] ) && isset( $unit['Images'][0]['Path'] ) ) {
			$image_id = $this->attachImageFromUrl( $unit['Images'][0]['Path'] );
			if ( $image_id ) {
				$variation->set_image_id( $image_id );
			}
		}

		$variation->save();
	}

	/**
	 * Download and attach image from URL
	 */
	private function attachImageFromUrl( string $url ): ?int {
		$image_id = attachment_url_to_postid( $url );
		if ( $image_id ) {
			return $image_id;
		}

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return null;
		}

		$file_array = [
			'name'     => basename( $url ),
			'tmp_name' => $tmp,
		];

		$image_id = media_handle_sideload( $file_array, 0 );
		if ( is_wp_error( $image_id ) ) {
			@unlink( $tmp );

			return null;
		}

		return $image_id;
	}

	/**
	 * Create a new variable product
	 */
	private function createProduct( array $p ): void {
		$product = new WC_Product_Variable();
		$product->set_name( $p['Name'] );
		$product->set_sku( $p['SKU'] );
		$product->set_description( $p['Description'] ?? '' );

		$product->save();

		$this->setupAttributesAndVariations( $product, $p['Units'] );
	}
}
