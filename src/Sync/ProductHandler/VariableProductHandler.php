<?php

namespace CityPaintsERP\Sync\ProductHandler;

use Exception;
use WC_Data_Exception;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

class VariableProductHandler
{
    /**
     * Create a variable product with attributes & variations
     *
     * @throws Exception
     */
    public function create(array $normalized, array $raw, array $units): WC_Product_Variable
    {
        $this->log('info', 'VariableProductHandler::create', [
            'name' => $normalized['Name'] ?? null,
            'unit_count' => count($units),
        ]);

        $post_id = wp_insert_post([
            'post_title' => $normalized['Name'] ?? 'Untitled',
            'post_type' => 'product',
            'post_status' => 'publish',
        ]);

        if (is_wp_error($post_id)) {
            $this->log('error', 'wp_insert_post failed for variable product', ['error' => $post_id->get_error_message()]);
            throw new Exception('Failed to create variable product: ' . $post_id->get_error_message());
        }

        $product = new WC_Product_Variable($post_id);

        // Ensure SKU (fall back to generated)
        $sku = $normalized['SKU'] ?? null;
        if (empty($sku)) {
            $sku = 'erp-' . uniqid();
        }
        $existing = wc_get_product_id_by_sku($sku);
        if ($existing && (int)$existing !== (int)$post_id) {
            $sku = $sku . '-' . uniqid();
        }
        $product->set_sku($sku);

        $product->set_name($normalized['Name'] ?? '');
        $product->set_description($normalized['Description'] ?? '');
        $product->save();

        update_post_meta($post_id, '_citypaints_raw_data', wp_json_encode($raw));

        // Build attributes & variations
        $this->setupAttributesAndVariations($product, $units);

        $this->log('info', 'Variable product created', ['post_id' => $post_id, 'sku' => $sku]);

        return $product;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        global $CLOGGER;
        if (isset($CLOGGER)) {
            if (method_exists($CLOGGER, $level)) {
                $CLOGGER->{$level}($message, $context);
            } else {
                $CLOGGER->info($message, $context);
            }
        }
    }

    /**
     * Build attributes and create/update variations for the variable product
     */
    private function setupAttributesAndVariations(WC_Product_Variable $product, array $units): void
    {
        $attribute_name = 'pa_unit_size';

        $this->log('info', 'setupAttributesAndVariations', [
            'product_id' => $product->get_id(),
            'unit_count' => count($units)
        ]);

        // Ensure attribute exists in taxonomy and attribute table
        $this->ensureAttributeExists($attribute_name, 'Unit Size');

        // Map units to labels (e.g. "2.5L", "5L")
        $unit_labels = array_map(fn($u) => $u['Short_Name'], $units);

        // Register terms if missing
        foreach ($unit_labels as $label) {
            if (!term_exists($label, $attribute_name)) {
                wp_insert_term($label, $attribute_name);
            }
        }

        // Attach terms to product
        wp_set_object_terms($product->get_id(), $unit_labels, $attribute_name);

        // Build attribute object
        $attr_obj = new WC_Product_Attribute();
        $attr_obj->set_id(wc_attribute_taxonomy_id_by_name($attribute_name));
        $attr_obj->set_name($attribute_name);
        $attr_obj->set_options($unit_labels);
        $attr_obj->set_visible(true);
        $attr_obj->set_variation(true);

        $product->set_attributes([$attr_obj]);

        // Set first as default (optional)
        if (!empty($unit_labels)) {
            $product->set_default_attributes([$attribute_name => $unit_labels[0]]);
        }

        $product->save();

        // Create or update variations
        foreach ($units as $unit) {
            $this->createOrUpdateVariation($product, $unit, $attribute_name);
        }
    }

    /**
     * Ensure WooCommerce attribute exists
     */
    private function ensureAttributeExists(string $taxonomy, string $label): void
    {
        global $wpdb;
        $attr_name = wc_sanitize_taxonomy_name(str_replace('pa_', '', $taxonomy));

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s",
            $attr_name
        ));

        if (!$exists) {
            $wpdb->insert(
                "{$wpdb->prefix}woocommerce_attribute_taxonomies",
                [
                    'attribute_name' => $attr_name,
                    'attribute_label' => $label,
                    'attribute_type' => 'select',
                    'attribute_orderby' => 'menu_order',
                    'attribute_public' => 0,
                ]
            );
            delete_transient('wc_attribute_taxonomies');
        }
    }

    /**
     * Create or update a variation
     */
    private function createOrUpdateVariation(WC_Product_Variable $product, array $unit, string $attribute_name): void
    {
        $this->log('info', 'createOrUpdateVariation', [
            'parent' => $product->get_id(),
            'unit_id' => $unit['Id'] ?? null,
            'short_name' => $unit['Short_Name'] ?? null
        ]);

        $variation_sku = !empty($unit['SKU']) ? $unit['SKU'] : ($product->get_sku() . '-' . ($unit['Id'] ?? uniqid()));
        $variation_id = wc_get_product_id_by_sku($variation_sku);

        if ($variation_id) {
            $variation = new WC_Product_Variation($variation_id);
        } else {
            $variation_post_id = wp_insert_post([
                'post_title' => $product->get_name() . ' - ' . ($unit['Short_Name'] ?? 'variation'),
                'post_status' => 'publish',
                'post_parent' => $product->get_id(),
                'post_type' => 'product_variation',
            ]);

            if (is_wp_error($variation_post_id)) {
                $this->log('error', 'Failed to create variation post', ['error' => $variation_post_id->get_error_message()]);
                throw new Exception('Failed to create variation post: ' . $variation_post_id->get_error_message());
            }

            $variation = new WC_Product_Variation($variation_post_id);
            $variation->set_parent_id($product->get_id());
            $variation->set_sku($variation_sku);
        }

        // Pricing & stock
        $variation->set_regular_price($unit['Price']['Selling_Price'] ?? 0);
        $variation->set_manage_stock(true);
        $variation->set_stock_quantity($unit['Stock']['Quantity_On_Hand'] ?? 0);

        // Set variation attribute using term slug
        $term = get_term_by('name', $unit['Short_Name'], $attribute_name);
        if ($term) {
            $current_attrs = $variation->get_attributes();
            $current_attrs[$attribute_name] = $term->slug;
            $variation->set_attributes($current_attrs);
        }

        // Save barcodes if present
        if (!empty($unit['Barcodes'])) {
            update_post_meta($variation->get_id(), '_erp_barcodes', $unit['Barcodes']);
        }

        // Attach variation image if provided
        if (!empty($unit['Images']) && isset($unit['Images'][0]['Path'])) {
            $image_id = $this->attachImageFromUrl($unit['Images'][0]['Path']);
            $this->log('debug', 'Variation attach image', ['image_path' => $unit['Images'][0]['Path'], 'image_id' => $image_id]);
            if ($image_id) $variation->set_image_id($image_id);
        }

        // Choose a preferred barcode to store in _global_unique_id
        if (!empty($unit['Product_BarCodes']) && is_array($unit['Product_BarCodes'])) {
            $chosen_barcode = null;
            foreach ($unit['Product_BarCodes'] as $barcode) {
                if ((int)($barcode['Id'] ?? 0) !== (int)($unit['Id'] ?? 0)) continue;
                $code = trim($barcode['BarCode'] ?? '');
                if (!$code) continue;
                if (preg_match('/^\d{8,}$/', $code)) {
                    $chosen_barcode = $code;
                    break;
                }
                if (!$chosen_barcode) $chosen_barcode = $code;
            }
            if ($chosen_barcode) update_post_meta($variation->get_id(), '_global_unique_id', $chosen_barcode);
        }

        $variation->save();

        $this->log('info', 'Variation saved', ['variation_id' => $variation->get_id(), 'sku' => $variation_sku]);
    }

    /**
     * Attach image helper (shared)
     */
    private function attachImageFromUrl(string $url): ?int
    {
        if (empty($url)) {
            return null;
        }

        $image_id = attachment_url_to_postid($url);
        if ($image_id) {
            return $image_id;
        }

        $tmp = download_url($url);
        if (is_wp_error($tmp)) {
            $this->log('warning', 'download_url failed', ['url' => $url, 'error' => $tmp->get_error_message()]);
            return null;
        }

        $file_array = [
            'name' => basename($url),
            'tmp_name' => $tmp,
        ];

        $image_id = media_handle_sideload($file_array, 0);
        if (is_wp_error($image_id)) {
            @unlink($tmp);
            $this->log('warning', 'media_handle_sideload failed', ['error' => $image_id->get_error_message()]);
            return null;
        }

        return $image_id;
    }

    /**
     * Update an existing product to variable type (or update attributes/variations)
     *
     * @throws WC_Data_Exception
     */
    public function update(int $product_id, array $normalized, array $raw, array $units): WC_Product_Variable
    {
        $this->log('info', 'VariableProductHandler::update', ['product_id' => $product_id]);

        $existing = wc_get_product($product_id);

        if ($existing && $existing->is_type('simple')) {
            // Convert to variable: update product_type taxonomy to variable
            $this->log('info', 'Converting simple -> variable', ['product_id' => $product_id]);
            wp_set_object_terms($product_id, 'variable', 'product_type');
        }

        $product = new WC_Product_Variable($product_id);

        $product->set_name($normalized['Name'] ?? '');
        $product->set_description($normalized['Description'] ?? '');
        $product->save();

        update_post_meta($product_id, '_citypaints_raw_data', wp_json_encode($raw));

        $this->setupAttributesAndVariations($product, $units);

        $this->log('info', 'Variable product updated', ['product_id' => $product_id]);

        return $product;
    }
}
