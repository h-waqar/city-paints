<?php
//
//namespace CityPaintsERP\Woo;
//
//if ( ! defined( 'ABSPATH' ) ) {
//	exit;
//}
//
//class AdminOrderMeta {
//
//	public function __construct() {
//		// Hook into WooCommerce admin order details page
//		add_action( 'woocommerce_admin_order_data_after_order_details', [ $this, 'displayErpOrderMeta' ] );
//	}
//
//	/**
//	 * Display ERP order meta and DHL shipment details
//	 */
//	public function displayErpOrderMeta( $order ): void {
//		$order_id = $order->get_id();
//
//		// ERP order meta
//		$erp_payload  = get_post_meta( $order_id, '_erp_order_payload', true );
//		$erp_response = get_post_meta( $order_id, '_erp_order_response', true );
//		$erp_ref      = get_post_meta( $order_id, '_erp_order_ref', true );
//
//		// DHL shipment meta
//		$shipment_body            = get_post_meta( $order_id, 'dhl_shipment_body', true );
//		$shipment_response        = get_post_meta( $order_id, 'dhl_shipment_response_body', true );
//		$shipment_tracking_number = get_post_meta( $order_id, 'dhl_shipment_tracking_number', true );
//
//		echo '<div class="postbox">';
//		echo '<h3 class="hndle"><span>ERP & DHL Details</span></h3>';
//		echo '<div class="inside">';
//		echo '<table class="widefat striped" style="width: 100%; table-layout: fixed;"><tbody>';
//
//		// ERP Reference
//		echo '<tr>';
//		echo '<th style="padding: 10px; border: 1px solid #ccc;">ERP Order Reference</th>';
//		echo '<td style="padding: 10px; border: 1px solid #ccc;">' . esc_html( $erp_ref ) . '</td>';
//		echo '</tr>';
//
//		// ERP Payload
//		echo '<tr>';
//		echo '<th style="padding: 10px; border: 1px solid #ccc;">ERP Order Payload</th>';
//		echo '<td style="padding: 10px; border: 1px solid #ccc; word-wrap: break-word;"><pre>' . esc_html( maybe_serialize( $erp_payload ) ) . '</pre></td>';
//		echo '</tr>';
//
//		// ERP Response
//		echo '<tr>';
//		echo '<th style="padding: 10px; border: 1px solid #ccc;">ERP API Response</th>';
//		echo '<td style="padding: 10px; border: 1px solid #ccc; word-wrap: break-word;"><pre>' . esc_html( maybe_serialize( $erp_response ) ) . '</pre></td>';
//		echo '</tr>';
//
//		// DHL tracking
//		echo '<tr>';
//		echo '<th style="padding: 10px; border: 1px solid #ccc;">DHL Tracking Number</th>';
//		echo '<td style="padding: 10px; border: 1px solid #ccc;">' . esc_html( $shipment_tracking_number ) . '</td>';
//		echo '</tr>';
//
//		// DHL body
//		echo '<tr>';
//		echo '<th style="padding: 10px; border: 1px solid #ccc;">DHL Shipment Body</th>';
//		echo '<td style="padding: 10px; border: 1px solid #ccc; word-wrap: break-word;"><pre>' . esc_html( $shipment_body ) . '</pre></td>';
//		echo '</tr>';
//
//		// DHL response
//		echo '<tr>';
//		echo '<th style="padding: 10px; border: 1px solid #ccc;">DHL Shipment Response</th>';
//		echo '<td style="padding: 10px; border: 1px solid #ccc; word-wrap: break-word;"><pre>' . esc_html( $shipment_response ) . '</pre></td>';
//		echo '</tr>';
//
//		echo '</tbody></table></div></div>';
//	}
//}


namespace CityPaintsERP\Woo;

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
	public function displayErpOrderMeta( $order ): void {
		$order_id = $order->get_id();

		// Get serialized ERP meta and unserialize
		$erp_payload  = maybe_unserialize( get_post_meta( $order_id, '_erp_order_payload', true ) );
		$erp_response = maybe_unserialize( get_post_meta( $order_id, '_erp_order_response', true ) );
		$erp_ref      = get_post_meta( $order_id, '_erp_order_ref', true );

		$GLOBALS['CLOGGER']->log( 'Payload', $erp_payload );
		$GLOBALS['CLOGGER']->log( 'Response', $erp_response );
		$GLOBALS['CLOGGER']->log( 'Reference', $erp_ref );

		echo '<div class="postbox">';
		echo '<h3 class="hndle"><span>ERP Order Details</span></h3>';
		echo '<div class="inside">';
		echo '<table class="widefat striped" style="width: 100%; table-layout: fixed;"><tbody>';

		// ERP reference
		echo '<tr>';
		echo '<th style="padding: 10px; border: 1px solid #ccc; width: 200px;">ERP Order Reference</th>';
		echo '<td style="padding: 10px; border: 1px solid #ccc;">' . esc_html( $erp_ref ) . '</td>';
		echo '</tr>';

		// ERP payload
		echo '<tr>';
		echo '<th style="padding: 10px; border: 1px solid #ccc;">ERP Order Payload</th>';
		echo '<td style="padding: 10px; border: 1px solid #ccc; word-wrap: break-word;"><pre>' . esc_html( json_encode( $erp_payload, JSON_PRETTY_PRINT ) ) . '</pre></td>';
		echo '</tr>';

		// ERP response
		echo '<tr>';
		echo '<th style="padding: 10px; border: 1px solid #ccc;">ERP API Response</th>';
		echo '<td style="padding: 10px; border: 1px solid #ccc; word-wrap: break-word;"><pre>' . esc_html( json_encode( $erp_response, JSON_PRETTY_PRINT ) ) . '</pre></td>';
		echo '</tr>';

		echo '</tbody></table>';
		echo '</div>';
		echo '</div>';
	}
}
