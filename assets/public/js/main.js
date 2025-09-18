jQuery(document).on('click', '.add-to-cart-btn', function (e) {
    e.preventDefault();

    let button = jQuery(this);
    let productId = button.data('product-id');
    let variationId = button.closest('.card-footer').find('.unit-variations').val();

    jQuery.ajax({
        type: 'POST',
        url: wc_add_to_cart_params.ajax_url, // provided by Woo
        data: {
            action: 'woocommerce_ajax_add_to_cart',
            product_id: productId,
            variation_id: variationId,
            quantity: 1,
        },
        success: function (response) {
            if (response.error && response.product_url) {
                window.location = response.product_url;
            } else {
                jQuery(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, button]);
            }
        }
    });
});
