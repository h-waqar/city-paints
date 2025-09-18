<?php

namespace CityPaintsERP\Frontend;

use WC_Product_Variable;
use WP_Query;

class ProductDisplay {

	/**
	 * Fetch WooCommerce variable products
	 */
	public function getProducts( array $args = [] ): array {
		$defaults = [
			'post_type'      => 'product',
			'posts_per_page' => 12,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		$query = new WP_Query( wp_parse_args( $args, $defaults ) );

		return $query->posts ?? [];
	}

	/**
	 * Render a grid of products
	 */
	public function renderGrid( array $products ): string {
		ob_start();
		echo "<div class='row grid-item'>";
		foreach ( $products as $post ) {
			$product = wc_get_product( $post->ID );

			include __DIR__ . '/Templates/product-grid.php';
		}
		echo "</div>";

//		return ob_get_clean();
		return shortcode_unautop( ob_get_clean() );
	}

	/**
	 * Render a single product (for shortcode or block)
	 */
	public function renderSingle( WC_Product_Variable $product ): string {
		ob_start();
		include __DIR__ . '/Templates/product-single.php';

		return ob_get_clean();
	}

	/**
	 * Get variations for a variable product
	 */
	public function getVariations( WC_Product_Variable $product ): array {
		return $product->get_children()
			? array_map( fn( $id ) => wc_get_product( $id ), $product->get_children() )
			: [];
	}
}
