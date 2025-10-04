<?php

namespace CityPaintsERP\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AddToCart {

	public function __construct() {
		// Add meta to cart item when product is added
		add_filter( 'woocommerce_add_cart_item_data', [ $this, 'addCartItemData' ], 10, 3 );

		// Show extra data in cart/checkout (optional, for debugging)
		add_filter( 'woocommerce_get_item_data', [ $this, 'displayCartItemData' ], 10, 2 );
	}

	/**
	 * Capture product + meta and store in cart item
	 */
	public function addCartItemData( $cart_item_data, $product_id, $variation_id ) {
		// Base data
		$data = [
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'quantity'     => isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 1,
		];

		// Hidden WooCommerce fields
		if ( isset( $_POST['add-to-cart'] ) ) {
			$data['add_to_cart'] = absint( $_POST['add-to-cart'] );
		}

		// CityPaints ERP raw meta
		$raw_data = get_post_meta( $variation_id ?: $product_id, '_citypaints_raw_data', true );
		if ( $raw_data ) {
			$data['_citypaints_raw_data'] = $raw_data;
		}

		// Merge into cart item
		$cart_item_data['citypaints_meta'] = $data;

		return $cart_item_data;
	}

	/**
	 * Display meta in cart/checkout (optional, mainly for debugging)
	 */
	public function displayCartItemData( $item_data, $cart_item ) {
		if ( isset( $cart_item['citypaints_meta'] ) ) {
			$item_data[] = [
				'key'   => __( 'CityPaints Data', 'citypaints' ),
				'value' => '<pre style="white-space: pre-wrap;">' . esc_html( json_encode( $cart_item['citypaints_meta'], JSON_PRETTY_PRINT ) ) . '</pre>',
			];
		}

		return $item_data;
	}
}
