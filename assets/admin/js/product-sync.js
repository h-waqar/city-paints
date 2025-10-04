// assets/admin/js/product-sync.js
class CityPaintsProductSync {
    constructor(buttonSelector, options) {
        this.$button = jQuery(buttonSelector);
        this.ajaxUrl = options.ajaxUrl;
        this.nonce = options.nonce;

        if (this.$button.length) {
            this.bindEvents();
        }
    }

    bindEvents() {
        this.$button.on('click', () => this.handleClick());
    }

    handleClick() {
        this.setLoading(true);

        jQuery.post(this.ajaxUrl, {
            action: 'citypaints_sync_products',
            nonce: this.nonce
        })
            .done((response) => {
                if (response.success) {
                    this.notify(response.data.message || 'Sync completed');
                } else {
                    this.notify(
                        'Sync failed: ' + (response.data.message || 'Unknown error'),
                        true
                    );
                }
            })
            .fail((jqXHR, textStatus, errorThrown) => {
                this.notify('AJAX request failed.', true);
                console.error('AJAX Error:', textStatus, errorThrown, jqXHR.responseText);
            })
            .always(() => {
                this.setLoading(false);
            });
    }

    setLoading(isLoading) {
        if (isLoading) {
            this.$button.prop('disabled', true).text('Syncing...');
        } else {
            this.$button.prop('disabled', false).text('Sync Products from ERP');
        }
    }

    notify(message, isError = false) {
        if (isError) {
            alert('❌ ' + message);
        } else {
            alert('✅ ' + message);
        }
    }
}

// Init on DOM ready
jQuery(function () {
    new CityPaintsProductSync('#citypaints-sync-products', CityPaintsSync);
});
