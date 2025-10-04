<?php

namespace CityPaintsERP\Woo;

use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminOrderMeta {

	public function __construct() {
		add_action( 'woocommerce_admin_order_data_after_order_details', [ $this, 'displayErpOrderMeta' ] );
	}

	/**
	 * Display ERP order meta in WooCommerce admin
	 */
	public function displayErpOrderMeta( WC_Order $order ): void {
		// Fetch and unserialize meta safely
		$erp_payload  = maybe_unserialize( $order->get_meta( '_erp_order_payload' ) );
		$erp_response = maybe_unserialize( $order->get_meta( '_erp_order_response' ) );
		$erp_ref      = $order->get_meta( '_erp_order_ref' );

		// Decode if still JSON string
		if ( is_string( $erp_payload ) ) {
			$erp_payload = json_decode( $erp_payload, true );
		}
		if ( is_string( $erp_response ) ) {
			$erp_response = json_decode( $erp_response, true );
		}

		if ( ! $erp_payload ) {
			echo '<p><strong>No ERP data found for this order.</strong></p>';

			return;
		}

		// Styles
		echo <<<HTML
<style>
.erp-order-card { border:1px solid #ddd; border-radius:8px; background:#fff; padding:15px; margin-top:15px; }
.erp-order-card h3 { margin-bottom:10px; color:#0073aa; }
.erp-order-card h4 { margin:10px 0; font-size:14px; color:#555; }
.erp-order-card table { width:100%; border-collapse:collapse; margin-bottom:10px; }
.erp-order-card td, .erp-order-card th { padding:6px; vertical-align:top; border:1px solid #ccc; }
.erp-order-card td:first-child, .erp-order-card th:first-child { font-weight:600; color:#333; width:35%; }
</style>
HTML;

		echo '<div class="erp-order-card" style="padding-top: 10rem;">';
		echo '<h3>ERP Order Details</h3>';
		echo '<h4>Order Reference: ' . esc_html( $erp_ref ) . '</h4>';

		$order_data = $erp_payload['Order'][0] ?? null;
		if ( ! $order_data ) {
			echo '<p>No order data found in ERP payload.</p>';
			echo '</div>';

			return;
		}

		// Order Info
		echo '<h4>Order Info</h4><table><tbody>';
		$info_keys = [
			'Id'              => 'Order ID',
			'Order_Date'      => 'Order Date',
			'Order_Status'    => 'Status',
			'Payment_Status'  => 'Payment Status',
			'Shipping_Status' => 'Shipping Status',
			'Currency_Code'   => 'Currency'
		];
		foreach ( $info_keys as $key => $label ) {
			if ( isset( $order_data[ $key ] ) ) {
				echo '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( $order_data[ $key ] ) . '</td></tr>';
			}
		}
		echo '</tbody></table>';

		// Billing & Shipping
		foreach (
			[
				'Billing_Address'  => 'Billing Address',
				'Shipping_Address' => 'Shipping Address'
			] as $key => $label
		) {
			if ( ! empty( $order_data[ $key ] ) ) {
				echo '<h4>' . esc_html( $label ) . '</h4><table><tbody>';
				foreach ( $order_data[ $key ] as $field => $value ) {
					if ( $value !== '' && $value !== null ) {
						echo '<tr><td>' . esc_html( $field ) . '</td><td>' . esc_html( $value ) . '</td></tr>';
					}
				}
				echo '</tbody></table>';
			}
		}

		// Order Items
		if ( ! empty( $order_data['Order_Items'] ) ) {
			echo '<h4>Items</h4>';
			foreach ( $order_data['Order_Items'] as $item ) {
				echo '<table><tbody>';
				echo '<tr><td>Line Text</td><td>' . esc_html( $item['Line_Text'] ?? '' ) . '</td></tr>';
				echo '<tr><td>Quantity</td><td>' . esc_html( $item['Line_Quantity'] ?? '' ) . '</td></tr>';
				echo '<tr><td>Price</td><td>' . esc_html( $item['Line_Price'] ?? '' ) . '</td></tr>';

				// Product Attributes
				if ( ! empty( $item['Product_Attributes'] ) ) {
					foreach ( $item['Product_Attributes'] as $attr ) {
						echo '<tr><td>' . esc_html( $attr['Name'] ?? '' ) . '</td><td>' . esc_html( $attr['Value'] ?? '' ) . '</td></tr>';
					}
				}

				echo '</tbody></table>';
			}
		}

		// Totals
		if ( ! empty( $order_data['Order_Totals'] ) ) {
			echo '<h4>Totals</h4><table><tbody>';
			foreach ( $order_data['Order_Totals'] as $key => $value ) {
				echo '<tr><td>' . esc_html( $key ) . '</td><td>' . esc_html( $value ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '</div>'; // end card
	}
}
