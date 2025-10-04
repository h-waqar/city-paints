<?php

namespace CityPaintsERP\Woo;

use CityPaintsERP\Api\OrderApi;
use Exception;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PlaceOrder {

	public function __construct() {
		// Trigger after order is created and customer hits thankyou page
		add_action( 'woocommerce_thankyou', [ $this, 'maybeSyncOrder' ] );
		add_action( 'woocommerce_payment_complete', [ $this, 'maybeSyncOrder' ] );
		add_action( 'woocommerce_order_status_completed', [ $this, 'maybeSyncOrder' ] );
		add_action( 'woocommerce_order_status_processing', [ $this, 'maybeSyncOrder' ] );
	}

	/**
	 * Attempt to sync order with ERP
	 */
	public function maybeSyncOrder( $order_id = 0 ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Skip if already synced (allow 'pending' to continue)
		$sync_status = $order->get_meta( '_citypaint_order_sync' );
		if ( $sync_status && $sync_status !== 'pending' ) {
			return;
		}


		// mark pending so subsequent triggers don't double-send while processing
		$order->update_meta_data( '_citypaint_order_sync', 'pending' );
		$order->save();

		$payload  = $this->buildPayload( $order );
		$response = $this->sendToApi( $payload );

		// Evaluate response
		if ( is_wp_error( $response ) ) {
			$order->update_meta_data( '_citypaint_order_sync', 'failed' );
			$GLOBALS['CLOGGER']->log( 'ERP Order Sync Error (WP_Error)', $response->get_error_message() );
		} elseif ( is_array( $response ) && isset( $response['Order'][0] ) && empty( $response['Order'][0]['ErrorMsg'] ) ) {
			$order_ref = $response['Order'][0]['ProfileDocumentReference'] ?? '';
			$order->update_meta_data( '_citypaint_order_reference', $order_ref );
			$order->update_meta_data( '_citypaint_order_sync', 'synced' );
			$GLOBALS['CLOGGER']->log( 'ERP Order Sync Success', [
				'order_id' => $order->get_id(),
				'erp_ref'  => $order_ref
			] );
		} else {
			// response but with error
			$error_msg = is_array( $response ) && isset( $response['Order'][0]['ErrorMsg'] ) ? $response['Order'][0]['ErrorMsg'] : 'Unknown ERP response';
			$order->update_meta_data( '_citypaint_order_sync', 'failed' );
			$GLOBALS['CLOGGER']->log( 'ERP Order Sync Failed (API)', $error_msg );
		}

		$order->save();
	}

	/**
	 * Build ERP API payload from Woo order
	 */
	private function buildPayload( WC_Order $order ): array {
		$items_and_totals = $this->mapItemsAndCalculateTotals( $order );

		return [
			'Order' => [
				[
					// You requested Order.Id to be the Woo order id:
					'Id'                   => (int) $order->get_id(),
					'Profile_Account_Code' => '', // map if you have ERP profile ref
					'Billing_Address'      => $this->mapAddress( $order, 'billing' ),
					'Shipping_Address'     => $this->mapAddress( $order, 'shipping' ),
					'Order_Date'           => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '',
					'Order_Status'         => 10,
					'Payment_Status'       => 10,
					'Shipping_Status'      => 10,
					'Currency_Code'        => 'EU', // per your API requirement
					'Currency_Rate'        => 1,
					'Vat_Number'           => $order->get_meta( '_billing_vat_number' ) ?: '',
					'Account_Reference'    => '',
					'Order_Payments'       => $this->mapPayments( $order ),
					'Order_Items'          => $items_and_totals['items'],
					'Order_Totals'         => $items_and_totals['totals'],
				]
			]
		];
	}

	/**
	 * Produce items array AND calculate totals in a single pass to ensure internal consistency.
	 * Returns ['items' => [...], 'totals' => [...]]
	 *
	 * Uses integer cents to avoid floating rounding drift and makes a deterministic
	 * single-line adjustment so ERP totals always match Woo's order total.
	 */
	private function mapItemsAndCalculateTotals( WC_Order $order ): array {
		$entries = []; // temp storage per line (cents)
		$line    = 1;

		// collect per-line entries (cents)
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			/** @var WC_Product $product */
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			// raw ERP product data (use parent if child/variation)
			$raw_data_json = $product->get_meta( '_citypaints_raw_data' );
			if ( empty( $raw_data_json ) && method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
				$parent = wc_get_product( $product->get_parent_id() );
				if ( $parent ) {
					$raw_data_json = $parent->get_meta( '_citypaints_raw_data' );
				}
			}
			$raw_data = $raw_data_json ? json_decode( $raw_data_json, true ) : [];

			// ERP product id
			$erp_product_id = isset( $raw_data['Id'] ) && (int) $raw_data['Id'] > 0 ? (int) $raw_data['Id'] : (int) $product->get_id();

			// Unit id
			$unit_id = $this->findUnitIdForItem( $item, $product, $raw_data );

			// Woo values (floats)
			$line_total_excl_f = (float) $item->get_total();      // excl tax (float)
			$line_tax_f        = (float) $item->get_total_tax();  // tax amount (float)
			$line_total_incl_f = $line_total_excl_f + $line_tax_f; // inclusive (float)
			$qty               = max( 1, (int) $item->get_quantity() );

			// VAT info
			$vat_info   = $this->extractVatInfo( $raw_data, $unit_id );
			$vat_rate   = (float) ( $vat_info['VatRate'] ?? 0 );
			$vat_switch = strtoupper( substr( ( $vat_info['VAT_Switch'] ?? '' ), 0, 1 ) );
			if ( ! in_array( $vat_switch, [ 'I', 'E' ], true ) ) {
				$prices_include_tax = 'yes' === get_option( 'woocommerce_prices_include_tax' );
				$vat_switch         = $prices_include_tax ? 'I' : 'E';
			}

			// Discounts (float -> cents)
			$discount_amount_f = (float) $item->get_subtotal() - $line_total_excl_f;
			$discount_cents    = (int) round( $discount_amount_f * 100 );

			// Use cents everywhere
			$line_total_excl_cents = (int) round( $line_total_excl_f * 100 );
			$line_total_incl_cents = (int) round( $line_total_incl_f * 100 );

			// Compute excl/vat in cents consistently:
			if ( $vat_switch === 'I' ) {
				// inclusive price given -> derive excl by dividing
				$line_excl_f     = $line_total_incl_f / ( 1 + ( $vat_rate / 100 ) );
				$line_excl_cents = (int) round( $line_excl_f * 100 );
				$vat_cents       = $line_total_incl_cents - $line_excl_cents;
				$line_incl_cents = $line_total_incl_cents; // trust Woo's inclusive cents as base
			} else {
				// exclusive price given -> compute VAT and inclusive
				$line_excl_cents = (int) round( $line_total_excl_f * 100 );
				$vat_cents       = (int) round( ( $line_excl_cents * $vat_rate ) / 100 );
				$line_incl_cents = $line_excl_cents + $vat_cents;
			}

			// store entry
			$entries[] = [
				'line'            => $line,
				'product_id'      => $erp_product_id,
				'unit_id'         => (int) $unit_id,
				'qty'             => $qty,
				'line_incl_cents' => $line_incl_cents,
				'line_excl_cents' => $line_excl_cents,
				'vat_cents'       => $vat_cents,
				'vat_rate'        => $vat_rate,
				'vat_switch'      => $vat_switch,
				'discount_cents'  => $discount_cents,
				'line_text'       => $item->get_name(),
				'attributes'      => $this->collectAttributesFromItem( $item ),
			];

			$line ++;
		}

		// Shipping in cents
		$shipping_excl_cents = (int) round( (float) $order->get_shipping_total() * 100 );
		$shipping_tax_cents  = (int) round( (float) $order->get_shipping_tax() * 100 );
		$shipping_incl_cents = $shipping_excl_cents + $shipping_tax_cents;

		// Totals from line entries
		$sum_incl_cents = 0;
		foreach ( $entries as $e ) {
			$sum_incl_cents += $e['line_incl_cents'];
		}
		$sum_incl_cents += $shipping_incl_cents;

		// Woo authoritative total
		$order_total_cents = (int) round( (float) $order->get_total() * 100 );

		// If there's a discrepancy, adjust the first item (deterministic)
		$diff = $order_total_cents - $sum_incl_cents;
		if ( $diff !== 0 && count( $entries ) > 0 ) {
			// adjust entry 0's inclusive cents by diff, then recompute its excl & vat
			$entries[0]['line_incl_cents'] += $diff;

			// recompute based on VAT switch/rate
			$li_cents   = $entries[0]['line_incl_cents'];
			$vat_rate   = (float) $entries[0]['vat_rate'];
			$vat_switch = $entries[0]['vat_switch'];

			// recompute using float division for accuracy, then convert to cents
			$li_float        = $li_cents / 100.0;
			$line_excl_float = $li_float / ( 1 + ( $vat_rate / 100 ) );
			$line_excl_cents = (int) round( $line_excl_float * 100 );
			$vat_cents       = $li_cents - $line_excl_cents;

			// save back
			$entries[0]['line_excl_cents'] = $line_excl_cents;
			$entries[0]['vat_cents']       = $vat_cents;

			// recompute sum_incl_cents
			$sum_incl_cents = 0;
			foreach ( $entries as $e ) {
				$sum_incl_cents += $e['line_incl_cents'];
			}
			$sum_incl_cents += $shipping_incl_cents;
		}

		// Now build final items array and totals (decimals)
		$items                = [];
		$total_vat_excl_cents = 0;
		$total_vat_cents      = 0;
		$total_discount_cents = 0;

		foreach ( $entries as $e ) {
			$line_price_unit = round( ( $e['line_incl_cents'] / $e['qty'] ) / 100, 2 ); // unit price incl VAT, 2 decimals

			$items[] = [
				'Line_Id'            => $e['line'],
				'Stock_Module'       => 'ERP',
				'Product_Id'         => $e['product_id'],
				'Unit_Id'            => $e['unit_id'],
				'Line_Quantity'      => $e['qty'],
				'Line_Price'         => $line_price_unit,
				'Discount_Rate'      => $e['discount_cents'] ? round( ( $e['discount_cents'] / max( 1, $e['line_excl_cents'] ) ) * 100, 2 ) : 0,
				'Discount_Amount'    => round( $e['discount_cents'] / 100, 2 ),
				'Line_Total_Excl'    => round( $e['line_excl_cents'] / 100, 2 ),
				'Vat_Code'           => $vat_info['VATCode'] ?? '01',
				// best-effort - keep from last extracted (not ideal but matches earlier behavior)
				'Vat_Switch'         => $e['vat_switch'],
				'Vat_Rate'           => $e['vat_rate'],
				'Vat_Amount'         => round( $e['vat_cents'] / 100, 2 ),
				'Line_Text'          => $e['line_text'],
				'Product_Attributes' => $e['attributes'],
			];

			$total_vat_excl_cents += $e['line_excl_cents'];
			$total_vat_cents      += $e['vat_cents'];
			$total_discount_cents += $e['discount_cents'];
		}

		// add shipping to totals
		$total_vat_excl_cents += $shipping_excl_cents;
		$total_vat_cents      += $shipping_tax_cents;

		// compute totals (decimals)
		$total_vat_excl = round( $total_vat_excl_cents / 100, 2 );
		$total_vat      = round( $total_vat_cents / 100, 2 );
		$total_vat_incl = round( ( $total_vat_excl_cents + $total_vat_cents ) / 100, 2 );

		// final authoritative payment amount = Woo order total
		$order_total = round( (float) $order->get_total(), 2 );

		// Safety: if tiny residual remains, sync totals to order_total
		if ( $total_vat_incl !== $order_total ) {
			// adjust total_vat to match order_total while keeping total_vat_excl
			$total_vat_incl = $order_total;
			$total_vat      = round( $total_vat_incl - $total_vat_excl, 2 );
		}

		$totals = [
			'Total_Vat_Excl' => $total_vat_excl,
			'Total_Discount' => round( $total_discount_cents / 100, 2 ),
			'Total_Vat'      => $total_vat,
			'Total_Vat_Incl' => $total_vat_incl,
			'Total_Payment'  => $order_total,
		];

		return [ 'items' => $items, 'totals' => $totals ];
	}

	/**
	 * Find Unit_Id for a given item/product using raw ERP data and variation attributes.
	 * Strategy:
	 * 1) Try match pa_unit_size attribute to Product_Units.Short_Name
	 * 2) If not found and item is a variation, map by variation index among parent children -> Product_Units[index]
	 * 3) Fallback to _citypaints_unit_id product meta, then first Product_Units Id, then 1
	 */
	private function findUnitIdForItem( WC_Order_Item_Product $item, $product, array $raw_data ): int {
		// Normalize available product_units
		$product_units = isset( $raw_data['Product_Units'] ) && is_array( $raw_data['Product_Units'] ) ? $raw_data['Product_Units'] : [];

		// 1) Try attribute match (variation attribute)
		$unit_size_value = '';
		$variation_id    = $item->get_variation_id();
		if ( $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation ) {
				$unit_size_value = $variation->get_attribute( 'pa_unit_size' );
			}
		}

		// fallback: product attribute (if not variation)
		if ( empty( $unit_size_value ) && method_exists( $product, 'get_attribute' ) ) {
			$unit_size_value = $product->get_attribute( 'pa_unit_size' );
		}

		$unit_size_value = strtoupper( trim( (string) $unit_size_value ) );

		if ( $unit_size_value !== '' && count( $product_units ) > 0 ) {
			foreach ( $product_units as $unit ) {
				$short = strtoupper( trim( (string) ( $unit['Short_Name'] ?? '' ) ) );
				if ( $short !== '' && $unit_size_value !== '' && $short === $unit_size_value ) {
					return (int) $unit['Id'];
				}
			}
		}

		// 2) If variation exists, map by variation index among parent's children -> Product_Units[index]
		if ( $variation_id && count( $product_units ) > 0 ) {
			// Determine parent product
			$parent_id = 0;
			if ( method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
				$parent_id = (int) $product->get_parent_id();
			} elseif ( $product->is_type( 'variation' ) && method_exists( $product, 'get_parent_id' ) ) {
				$parent_id = (int) $product->get_parent_id();
			}

			if ( $parent_id ) {
				$parent = wc_get_product( $parent_id );
				if ( $parent ) {
					$children = (array) $parent->get_children(); // ordered array
					$index    = array_search( $variation_id, $children, true );
					if ( $index !== false && isset( $product_units[ $index ] ) && isset( $product_units[ $index ]['Id'] ) ) {
						return (int) $product_units[ $index ]['Id'];
					}
				}
			}
		}

		// 3) fallback to explicit stored unit id on product meta
		$stored_unit = (int) $product->get_meta( '_citypaints_unit_id' );
		if ( $stored_unit > 0 ) {
			return $stored_unit;
		}

		// 4) fallback to first Product_Units Id
		if ( isset( $product_units[0]['Id'] ) ) {
			return (int) $product_units[0]['Id'];
		}

		// 5) safe default
		return 1;
	}

	/**
	 * Extract VAT info from raw_data (if present)
	 */
	private function extractVatInfo( array $raw_data, int $unit_id ): array {
		// Product_Prices structure may contain Unit_Id -> Vat_Info
		if ( isset( $raw_data['Product_Prices'] ) && is_array( $raw_data['Product_Prices'] ) ) {
			foreach ( $raw_data['Product_Prices'] as $price_group ) {
				if ( isset( $price_group['Unit_Id'] ) && (int) $price_group['Unit_Id'] === (int) $unit_id ) {
					$prices = $price_group['Prices'] ?? [];
					$first  = isset( $prices[0] ) ? $prices[0] : [];
					$vi     = $first['Vat_Info'] ?? ( $price_group['Vat_Info'] ?? [] );

					return [
						'VATCode'    => $vi['VATCode'] ?? ( $first['VatCode'] ?? '01' ),
						'VatRate'    => isset( $vi['VatRate'] ) ? (float) $vi['VatRate'] : ( isset( $price_group['VatRate'] ) ? (float) $price_group['VatRate'] : 0 ),
						'VAT_Switch' => isset( $vi['VAT_Switch'] ) ? strtoupper( substr( $vi['VAT_Switch'], 0, 1 ) ) : ( isset( $price_group['VAT_Switch'] ) ? strtoupper( substr( $price_group['VAT_Switch'], 0, 1 ) ) : '' ),
					];
				}
			}
		}

		// fallback: if there's a global Vat_Info on raw_data
		if ( isset( $raw_data['Vat_Info'] ) && is_array( $raw_data['Vat_Info'] ) ) {
			$vi = $raw_data['Vat_Info'];

			return [
				'VATCode'    => $vi['VATCode'] ?? '01',
				'VatRate'    => isset( $vi['VatRate'] ) ? (float) $vi['VatRate'] : 0,
				'VAT_Switch' => isset( $vi['VAT_Switch'] ) ? strtoupper( substr( $vi['VAT_Switch'], 0, 1 ) ) : '',
			];
		}

		return [ 'VATCode' => '01', 'VatRate' => 0, 'VAT_Switch' => '' ];
	}

	/**
	 * Helper to extract attributes for an order item (kept separate for clarity)
	 */
	private function collectAttributesFromItem( $item ): array {
		$attributes   = [];
		$variation_id = $item->get_variation_id();
		if ( $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation ) {
				foreach ( $variation->get_attributes() as $name => $value ) {
					$attributes[] = [
						'Id'    => 0,
						'Name'  => $name,
						'Value' => $value,
					];
				}
			}
		}

		return $attributes;
	}

	/**
	 * Map billing/shipping address
	 */
	private function mapAddress( WC_Order $order, string $type ): array {
		$address = $order->get_address( $type );

		return [
			'First_Name'        => $address['first_name'] ?? '',
			'Last_Name'         => $address['last_name'] ?? '',
			'Company_Name'      => $address['company'] ?? '',
			'Phone_Number'      => $address['phone'] ?? '',
			'Email'             => $address['email'] ?? $order->get_billing_email(),
			'Country_Code'      => $this->mapCountryCode( $address['country'] ?? '' ),
			'Address1'          => $address['address_1'] ?? '',
			'Address2'          => $address['address_2'] ?? '',
			'Address3'          => '',
			'Address4'          => '',
			'Address5'          => '',
			'Zip_Post_Eir_Code' => $address['postcode'] ?? '',
		];
	}

	/**
	 * Convert ISO code → API expected country code
	 * example: DE -> GER (your API expects GER not DE)
	 */
	private function mapCountryCode( string $iso ): string {
		$iso = strtoupper( trim( $iso ) );
		if ( $iso === 'DE' || $iso === 'DEU' ) {
			return 'GER';
		}

		return $iso;
	}

	/**
	 * Map Woo payments → ERP payments
	 */
	private function mapPayments( WC_Order $order ): array {
		$method = $order->get_payment_method() ?: '';
		$code   = $this->mapPaymentCode( $method );

		$paid_date = $order->get_date_paid() ? $order->get_date_paid()->date( 'Y-m-d' ) : ( $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '' );

		return [
			[
				'Line_Id'               => 1,
				'Payment_Code'          => $code,
				'Payment_Sub_Code'      => '',
				'Payment_Currency_Code' => 'EU',
				'Currency_Rate'         => 1,
				'Paid_Date'             => $paid_date,
				'Payment_Amount'        => round( (float) $order->get_total(), 2 ),
				'Payment_Order_Amount'  => round( (float) $order->get_total(), 2 ),
			]
		];
	}

	/**
	 * Map common Woo method -> ERP code
	 */
	private function mapPaymentCode( string $method ): string {
		$method = strtolower( $method );
		if ( in_array( $method, [ 'cod', 'cash_on_delivery', 'cash' ], true ) ) {
			return 'CASH';
		}
		if ( strpos( $method, 'stripe' ) !== false || strpos( $method, 'card' ) !== false || strpos( $method, 'cc' ) !== false ) {
			return 'CARD';
		}

		// default to CASH if unknown (or extend mapping)
		return 'CASH';
	}

	private function sendToApi( array $payload ): WP_Error|array {
		// Log the payload for debugging
		$GLOBALS['CLOGGER']->log( 'ERP Order Payload', $payload );

		try {
			// Initialize your OrderApi
			$api = new OrderApi();

			// Send the payload to ERP
			$response = $api->createOrder( $payload );

			// Get the WooCommerce order ID from payload
			$order_id = $payload['Order'][0]['Id'] ?? 0;

			// Save to WooCommerce order meta
			if ( $order_id ) {
				$this->saveOrderResponseToMeta( $order_id, $payload, $response );
			}

			// Check for errors from API
			if ( is_wp_error( $response ) ) {
				$GLOBALS['CLOGGER']->error( 'ERP API returned an error', [
					'error_code' => $response->get_error_code(),
					'error_msg'  => $response->get_error_message(),
					'payload'    => $payload,
				] );

				// Return a structured error response like your fake one
				return [
					'Order' => [
						[
							'Id'                       => 0,
							'ErrorMsg'                 => $response->get_error_message(),
							'ProfileDocumentReference' => '',
						]
					]
				];
			}

			// Success — log and return the API response
			$GLOBALS['CLOGGER']->log( 'ERP API Response', $response );

			return $response;

		} catch ( Exception $e ) {
			// Catch any unexpected exceptions
			$GLOBALS['CLOGGER']->error( 'ERP API exception', [
				'exception_msg' => $e->getMessage(),
				'payload'       => $payload,
			] );

			return [
				'Order' => [
					[
						'Id'                       => 0,
						'ErrorMsg'                 => $e->getMessage(),
						'ProfileDocumentReference' => '',
					]
				]
			];
		}
	}


	/**
	 * Send response to Order Meta
	 */
	private function saveOrderResponseToMeta( int $order_id, array $payload, array $response ): void {
		if ( ! $order_id ) {
			$GLOBALS['CLOGGER']->log( 'saveOrderResponseToMeta skipped: invalid order ID', $order_id );

			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			$GLOBALS['CLOGGER']->log( 'saveOrderResponseToMeta skipped: invalid WC_Order', $order_id );

			return;
		}

		if ( empty( $payload ) && empty( $response ) ) {
			$GLOBALS['CLOGGER']->log( 'saveOrderResponseToMeta skipped: payload & response empty', $order_id );

			return;
		}

		$order->update_meta_data( '_erp_order_payload', $payload );
		$order->update_meta_data( '_erp_order_response', $response );

		$erp_ref = $response['Order'][0]['ProfileDocumentReference'] ?? '';
		if ( $erp_ref ) {
			$order->update_meta_data( '_erp_order_ref', $erp_ref );
		}

		$order->save();

		// Log after save
		$GLOBALS['CLOGGER']->log( "ERP order meta saved for order #$order_id", [
			'erp_ref'  => $erp_ref,
			'payload'  => $payload,
			'response' => $response,
		] );
	}
}