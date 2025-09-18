<?php

namespace CityPaintsERP\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Checkout {

	public function __construct() {
		// Save cart meta into order line items
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'saveOrderLineItemMeta' ], 10, 4 );

		// Add a sync flag on the order itself
		add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'addOrderSyncFlag' ], 10, 2 );
	}

	/**
	 * Save cart item data into order line item meta
	 */
	public function saveOrderLineItemMeta( $item, $cart_item_key, $values, $order ): void {
		if ( isset( $values['citypaints_meta'] ) ) {
			$item->add_meta_data( '_citypaints_meta', $values['citypaints_meta'], true );
		}
	}

	/**
	 * Add sync flag in order meta to avoid double-processing
	 */
	public function addOrderSyncFlag( $order_id, $data ): void {
		if ( ! get_post_meta( $order_id, '_citypaint_order_sync', true ) ) {
			update_post_meta( $order_id, '_citypaint_order_sync', 'pending' ); // will update to 'done' after API call
		}
	}
}
