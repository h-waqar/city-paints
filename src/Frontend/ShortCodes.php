<?php

namespace CityPaintsERP\Frontend;

class ShortCodes {

	public function __construct() {
		add_shortcode( 'citypaints_products', [ $this, 'productsShortcode' ] );
	}

	/**
	 * Main products shortcode
	 */
	public function productsShortcode( $atts ): string {
		$atts = shortcode_atts( [
			'limit'  => 12,
			'layout' => 'grid', // future: 'list'
		], $atts, 'citypaints_products' );

		$display  = new ProductDisplay();
		$products = $display->getProducts( [ 'posts_per_page' => $atts['limit'] ] );

		return $display->renderGrid( $products );
	}
}
