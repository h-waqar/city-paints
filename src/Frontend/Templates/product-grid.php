<?php
/** @var WC_Product $product */
$in_stock = $product->is_in_stock();
?>

<div class="col-md-4 mb-4">
    <div class="card citypaints-product-card h-100 shadow-sm position-relative">

        <!-- Stock badge -->
        <span class="badge position-absolute top-0 start-0 m-2 <?php echo $in_stock ? 'bg-success' : 'bg-danger'; ?>">
            <?= $in_stock ? 'In Stock' : 'Out of Stock'; ?>
        </span>

        <!-- Product link + image -->
        <a href="<?php echo get_permalink( $product->get_id() ); ?>" class="text-decoration-none text-dark">
            <?php if ( $product->get_image_id() ) : ?>
                <img src="<?php echo esc_url( wp_get_attachment_url( $product->get_image_id() ) ); ?>"
                     class="card-img-top"
                     alt="<?php echo esc_attr( $product->get_name() ); ?>">
            <?php else: ?>
                <img src="<?php echo esc_url( wc_placeholder_img_src() ); ?>"
                     class="card-img-top"
                     alt="<?php echo esc_attr( $product->get_name() ); ?>">
            <?php endif; ?>

            <div class="card-body text-center py-2">
                <h6 class="card-title mb-1"><?php echo esc_html( $product->get_name() ); ?></h6>
                <div class="card-text fw-bold text-primary mb-1">
                    <?php
                    // fix <br /> issue in price HTML
                    echo str_replace( '<br />', ' ', $product->get_price_html() );
                    ?>
                </div>
            </div>
        </a>

        <!-- Footer -->
        <div class="card-footer bg-white border-top-0 py-2">
            <?php if ( $product->is_type( 'variable' ) ): ?>
                <select class="form-select form-select-sm unit-variations mb-2"
                        id="unit-variations-<?php echo $product->get_id(); ?>"
                        data-product-id="<?php echo $product->get_id(); ?>">
                    <?php foreach ( $product->get_available_variations() as $variation ): ?>
                        <option value="<?php echo esc_attr( $variation['variation_id'] ); ?>">
                            <?php echo esc_html( $variation['attributes']['attribute_pa_unit_size'] ?? '' ); ?>
                            – <?php echo wc_price( $variation['display_price'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <a href="<?php echo esc_url( get_permalink( $product->get_id() ) ); ?>"
               class="btn btn-primary w-100 d-block text-center mt-2">View Product</a>


        </div>

    </div>
</div>
