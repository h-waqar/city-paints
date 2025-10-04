<?php

namespace CityPaintsERP\Debug;

use WC_Product_Variable;
use WC_Product_Variation;

/**
 * DebugLogger
 *
 * Usage:
 * - &debug=post  → dumps product data
 * - &debug=order → dumps full order data including items & meta
 * - &debug=product → dumps product data (alternative to 'post')
 */
class DebugLogger {

	/**
	 * Optional direct ID (product or order)
	 */
	private ?int $direct_id = null;

	public function __construct( int $direct_id = null ) {
		$this->direct_id = $direct_id;

		// Run on frontend and admin
		add_action( 'template_redirect', [ $this, 'maybeDebug' ] );
		add_action( 'admin_init', [ $this, 'maybeDebug' ] );
	}

	public function maybeDebug(): void {
		// Direct call via constructor
		if ( isset( $this->direct_id ) && $this->direct_id > 0 ) {
			$order   = wc_get_order( $this->direct_id );
			$product = wc_get_product( $this->direct_id );

			if ( $order && is_a( $order, 'WC_Order' ) ) {
				$this->debugOrder( $this->direct_id );
			} elseif ( $product && is_a( $product, 'WC_Product' ) ) {
				$this->debugProduct( $this->direct_id );
			}
			exit;
		}

		// Debug via query param
		if ( ! isset( $_GET['debug'] ) ) {
			return;
		}

		$mode = sanitize_text_field( $_GET['debug'] );

		switch ( $mode ) {
			case 'post':
			case 'product':
				$product_id = $this->getProductId();
				$this->debugProduct( $product_id );
				break;

			case 'order':
				$order_id = $this->getOrderId();
				$this->debugOrder( $order_id );
				break;
		}
	}

	/**
	 * Debug order data - Enhanced to show ALL meta data clearly
	 */
	private function debugOrder( int $order_id ): void {
		if ( ! $order_id ) {
			wp_die( '<pre>[DebugLogger] No valid order ID found.</pre>' );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_die( '<pre>[DebugLogger] Order not found.</pre>' );
		}

		// Check if this is a valid order (works for both HPOS and legacy)
		if ( ! is_a( $order, 'WC_Order' ) ) {
			wp_die( '<pre>[DebugLogger] Invalid order object.</pre>' );
		}

		// Get ALL order meta (works for both HPOS and legacy)
		$order_meta = [];

		// Try to get meta from order object first (HPOS compatible)
		$meta_data = $order->get_meta_data();
		foreach ( $meta_data as $meta ) {
			$data  = $meta->get_data();
			$key   = $data['key'];
			$value = $data['value'];
			if ( isset( $order_meta[ $key ] ) ) {
				if ( ! is_array( $order_meta[ $key ] ) ) {
					$order_meta[ $key ] = [ $order_meta[ $key ] ];
				}
				$order_meta[ $key ][] = $value;
			} else {
				$order_meta[ $key ] = $value;
			}
		}

		// Also try legacy post meta (in case some meta is stored there)
		$post_meta = get_post_meta( $order->get_id() );
		foreach ( $post_meta as $key => $values ) {
			if ( ! isset( $order_meta[ $key ] ) ) {
				$order_meta[ $key ] = count( $values ) === 1 ? $values[0] : $values;
			}
		}

		$data = [
			'ORDER_INFO'  => [
				'ID'       => $order->get_id(),
				'Status'   => $order->get_status(),
				'Date'     => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
				'Total'    => $order->get_total(),
				'Currency' => $order->get_currency(),
				'Customer' => [
					'first_name' => $order->get_billing_first_name(),
					'last_name'  => $order->get_billing_last_name(),
					'email'      => $order->get_billing_email(),
					'phone'      => $order->get_billing_phone(),
					'address'    => [
						'street'   => $order->get_billing_address_1(),
						'city'     => $order->get_billing_city(),
						'state'    => $order->get_billing_state(),
						'postcode' => $order->get_billing_postcode(),
						'country'  => $order->get_billing_country(),
					],
				],
			],
			'ORDER_META'  => $order_meta,
			'ORDER_ITEMS' => [],
		];

		// Get order items with detailed meta
		foreach ( $order->get_items() as $item_id => $item ) {
			// Get all item meta
			$item_meta           = wc_get_order_item_meta( $item_id, '', false );
			$formatted_item_meta = [];
			foreach ( $item_meta as $key => $values ) {
				$formatted_item_meta[ $key ] = count( $values ) === 1 ? $values[0] : $values;
			}

			// Get product meta if product exists
			$product_meta = [];
			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();

			if ( $product_id ) {
				$product_meta_raw = get_post_meta( $product_id );
				foreach ( $product_meta_raw as $key => $values ) {
					$product_meta[ $key ] = count( $values ) === 1 ? $values[0] : $values;
				}
			}

			// Get variation meta if it's a variation
			$variation_meta = [];
			if ( $variation_id ) {
				$variation_meta_raw = get_post_meta( $variation_id );
				foreach ( $variation_meta_raw as $key => $values ) {
					$variation_meta[ $key ] = count( $values ) === 1 ? $values[0] : $values;
				}
			}

			$data['ORDER_ITEMS'][] = [
				'ITEM_INFO'      => [
					'item_id'      => $item_id,
					'name'         => $item->get_name(),
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'quantity'     => $item->get_quantity(),
					'subtotal'     => $item->get_subtotal(),
					'total'        => $item->get_total(),
				],
				'ITEM_META'      => $formatted_item_meta,
				'PRODUCT_META'   => $product_meta,
				'VARIATION_META' => $variation_meta,
			];
		}

		// Format output for better readability
		$output = "=== ORDER DEBUG DATA ===\n\n";
		$output .= print_r( $data, true );

		wp_die( '<pre>' . $output . '</pre>' );
	}

	/**
	 * Debug product data - Enhanced to show ALL meta data clearly
	 */
	private function debugProduct( int $product_id ): void {
		if ( ! $product_id ) {
			wp_die( '<pre>[DebugLogger] No product ID found.</pre>' );
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			wp_die( '<pre>[DebugLogger] Product not found.</pre>' );
		}

		// Get ALL product meta
		$product_meta           = get_post_meta( $product->get_id() );
		$formatted_product_meta = [];
		foreach ( $product_meta as $key => $values ) {
			$formatted_product_meta[ $key ] = count( $values ) === 1 ? $values[0] : $values;
		}

		$data = [
			'PRODUCT_INFO' => [
				'ID'            => $product->get_id(),
				'SKU'           => $product->get_sku(),
				'Name'          => $product->get_name(),
				'Type'          => $product->get_type(),
				'Price'         => $product->get_price(),
				'Regular Price' => $product->get_regular_price(),
				'Sale Price'    => $product->get_sale_price(),
				'Stock Status'  => $product->get_stock_status(),
				'Description'   => $product->get_description(),
				'Short Desc'    => $product->get_short_description(),
				'Categories'    => wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] ),
				'Tags'          => wp_get_post_terms( $product->get_id(), 'product_tag', [ 'fields' => 'names' ] ),
			],
			'PRODUCT_META' => $formatted_product_meta,
		];

		// Handle variable products
		if ( $product instanceof WC_Product_Variable ) {
			$variations = [];
			foreach ( $product->get_children() as $child_id ) {
				$variation = new WC_Product_Variation( $child_id );

				// Get variation meta
				$variation_meta           = get_post_meta( $variation->get_id() );
				$formatted_variation_meta = [];
				foreach ( $variation_meta as $key => $values ) {
					$formatted_variation_meta[ $key ] = count( $values ) === 1 ? $values[0] : $values;
				}

				$variations[] = [
					'VARIATION_INFO' => [
						'ID'    => $variation->get_id(),
						'SKU'   => $variation->get_sku(),
						'Price' => $variation->get_price(),
						'Stock' => $variation->get_stock_quantity(),
					],
					'VARIATION_META' => $formatted_variation_meta,
				];
			}
			$data['VARIATIONS'] = $variations;
		}

		// Format output for better readability
		$output = "=== PRODUCT DEBUG DATA ===\n\n";
		$output .= print_r( $data, true );

		wp_die( '<pre>' . $output . '</pre>' );
	}

	/**
	 * Get product ID from various sources
	 */
	private function getProductId(): int {
		// Try 'post' parameter first
		if ( isset( $_GET['post'] ) ) {
			return intval( $_GET['post'] );
		}

		// Try 'id' parameter
		if ( isset( $_GET['id'] ) ) {
			return intval( $_GET['id'] );
		}

		// Try to get from current admin page
		global $post;
		if ( $post && $post->post_type === 'product' ) {
			return $post->ID;
		}

		return 0;
	}

	/**
	 * Get order ID from various sources
	 */
	private function getOrderId(): int {
		// Try 'id' parameter first (for new order pages)
		if ( isset( $_GET['id'] ) ) {
			return intval( $_GET['id'] );
		}

		// Try 'post' parameter (for legacy order pages)
		if ( isset( $_GET['post'] ) ) {
			return intval( $_GET['post'] );
		}

		// Try to get from current admin page
		global $post;
		if ( $post && $post->post_type === 'shop_order' ) {
			return $post->ID;
		}

		return 0;
	}
}
