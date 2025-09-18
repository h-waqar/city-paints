<?php

namespace CityPaintsERP\Sync;

use CityPaintsERP\Api\ProductApi;
use WC_Data_Exception;
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
			$raw        = $p['raw_data'];
			$normalized = $p['normalized'];

			$product_id = wc_get_product_id_by_sku( $p['SKU'] );

			if ( $product_id ) {
				$this->updateProduct( $product_id, $normalized, $raw );
			} else {
				$this->createProduct( $normalized, $raw );
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
	private function updateProduct( int $product_id, array $normalized, array $raw ): void {
		$product = new WC_Product_Variable( $product_id );
		$product->set_name( $normalized['Name'] );
		$product->set_description( $normalized['Description'] ?? '' );
		$product->save();

		// 🔹 Store raw ERP data in post_meta
		update_post_meta( $product_id, '_citypaints_raw_data', wp_json_encode( $raw ) );

		$this->setupAttributesAndVariations( $product, $normalized['Units'] );
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

		// ✅ Set default attribute (first unit)
		if ( ! empty( $unit_labels ) ) {
			$product->set_default_attributes( [
				$attribute_name => $unit_labels[0],
			] );
		}

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
	 * @throws WC_Data_Exception
	 */
	private function createOrUpdateVariation( WC_Product_Variable $product, array $unit, string $attribute_name ): void {
		// ✅ Use ERP SKU if provided, fallback to parentSKU-ID
		$variation_sku = ! empty( $unit['SKU'] )
			? $unit['SKU']
			: $product->get_sku() . '-' . $unit['Id'];

		$variation_id = wc_get_product_id_by_sku( $variation_sku );

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

		// ✅ Assign correct attribute (use slug, not label)
		$term = get_term_by( 'name', $unit['Short_Name'], $attribute_name );
		if ( $term ) {
			$current_attrs                    = $variation->get_attributes();
			$current_attrs[ $attribute_name ] = $term->slug;
			$variation->set_attributes( $current_attrs );
		}

		// ✅ Save ERP barcodes in variation meta (raw)
		if ( ! empty( $unit['Barcodes'] ) ) {
			update_post_meta( $variation->get_id(), '_erp_barcodes', $unit['Barcodes'] );
		}

		// Attach variation image if available
		if ( ! empty( $unit['Images'] ) && isset( $unit['Images'][0]['Path'] ) ) {
			$image_id = $this->attachImageFromUrl( $unit['Images'][0]['Path'] );
			if ( $image_id ) {
				$variation->set_image_id( $image_id );
			}
		}

		// ✅ Assign barcode into WooCommerce GTIN/UPC/EAN/ISBN field
		if ( ! empty( $unit['Product_BarCodes'] ) && is_array( $unit['Product_BarCodes'] ) ) {
			$chosen_barcode = null;

			foreach ( $unit['Product_BarCodes'] as $barcode ) {
				if ( (int) $barcode['Id'] !== (int) $unit['Id'] ) {
					continue; // skip if not for this unit
				}

				$code = trim( $barcode['BarCode'] ?? '' );
				if ( ! $code ) {
					continue;
				}

				// prefer long numeric codes (EAN/UPC/GTIN style)
				if ( preg_match( '/^\d{8,}$/', $code ) ) {
					$chosen_barcode = $code;
					break;
				}

				// fallback: short code if nothing else found
				if ( ! $chosen_barcode ) {
					$chosen_barcode = $code;
				}
			}

			if ( $chosen_barcode ) {
				update_post_meta( $variation->get_id(), '_global_unique_id', $chosen_barcode );
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
	private function createProduct( array $normalized, array $raw ): void {
		$product = new WC_Product_Variable();
		$product->set_name( $normalized['Name'] );
		$product->set_sku( $normalized['SKU'] );
		$product->set_description( $normalized['Description'] ?? '' );
		$product->save();

		// 🔹 Store raw ERP data in post_meta
		update_post_meta( $product->get_id(), '_citypaints_raw_data', wp_json_encode( $raw ) );

		$this->setupAttributesAndVariations( $product, $normalized['Units'] );
	}
}
