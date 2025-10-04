<?php

namespace CityPaintsERP\Sync\ProductHandler;

use Exception;
use WC_Product_Simple;

class SimpleProductHandler
{
    /**
     * Create a simple product
     *
     * @throws Exception
     */
    public function create(array $normalized, array $raw, array $unit): WC_Product_Simple
    {
        $this->log('info', 'SimpleProductHandler::create', [
            'name' => $normalized['Name'] ?? null,
            'unit' => $unit['Short_Name'] ?? null,
        ]);

        $post_id = wp_insert_post([
            'post_title' => $normalized['Name'] ?? 'Untitled',
            'post_type' => 'product',
            'post_status' => 'publish',
        ]);

        if (is_wp_error($post_id)) {
            $this->log('error', 'wp_insert_post failed for simple product', ['error' => $post_id->get_error_message()]);
            throw new Exception('Failed to create simple product: ' . $post_id->get_error_message());
        }

        $product = new WC_Product_Simple($post_id);

        // Ensure SKU: normalized SKU -> unit SKU -> generated fallback
        $sku = $normalized['SKU'] ?? ($unit['SKU'] ?? null);
        if (empty($sku)) {
            $sku = 'erp-' . $normalized['Id'] ?? uniqid('erp-');
        }
        // If SKU collides with another product, make a unique fallback
        $existing = wc_get_product_id_by_sku($sku);
        if ($existing && (int)$existing !== (int)$post_id) {
            $sku = $sku . '-' . uniqid();
        }
        $product->set_sku($sku);

        // Name & description
        $product->set_name($normalized['Name'] ?? '');
        $product->set_description($normalized['Description'] ?? '');

        // Price and stock (defensive read with 0 fallback)
        $price = $unit['Price']['Selling_Price'] ?? ($unit['Price'] ?? 0);
        $stock = $unit['Stock']['Quantity_On_Hand'] ?? ($unit['Stock'] ?? 0);

        $product->set_regular_price($price);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($stock);

        // Save ERP raw
        update_post_meta($post_id, '_citypaints_raw_data', wp_json_encode($raw));

        // Barcodes - raw
        if (!empty($unit['Barcodes'])) {
            update_post_meta($post_id, '_erp_barcodes', $unit['Barcodes']);
        }

        // Global unique id from Product_BarCodes if available
        if (!empty($unit['Product_BarCodes']) && is_array($unit['Product_BarCodes'])) {
            foreach ($unit['Product_BarCodes'] as $barcode) {
                if ((int)($barcode['Id'] ?? 0) === (int)($unit['Id'] ?? 0) && !empty($barcode['BarCode'])) {
                    update_post_meta($post_id, '_global_unique_id', trim($barcode['BarCode']));
                    break;
                }
            }
        }

        // Attach image if available
        if (!empty($unit['Images']) && isset($unit['Images'][0]['Path'])) {
            $path = $unit['Images'][0]['Path'];
            $image_id = $this->attachImageFromUrl($path);
            $this->log('debug', 'Simple create: attach image', ['path' => $path, 'image_id' => $image_id]);
            if ($image_id) {
                $product->set_image_id($image_id);
            }
        }

        $product->save();

        $this->log('info', 'Simple product created', ['post_id' => $post_id, 'sku' => $sku]);

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
     * Attach image helper (duplicated here to keep handler self-contained)
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
            $this->log('warning', 'download_url returned WP_Error', ['url' => $url, 'error' => $tmp->get_error_message()]);
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
     * Update an existing simple product (or convert variable->simple first)
     *
     * @throws Exception
     */
    public function update(int $product_id, array $normalized, array $raw, array $unit): WC_Product_Simple
    {
        $this->log('info', 'SimpleProductHandler::update', ['product_id' => $product_id]);

        $existing = wc_get_product($product_id);
        if ($existing && $existing->is_type('variable')) {
            // convert variable -> simple: delete variations and set product_type term
            $this->log('info', 'Converting variable -> simple', ['product_id' => $product_id]);
            foreach ($existing->get_children() as $child_id) {
                wp_delete_post($child_id, true);
            }
            wp_set_object_terms($product_id, 'simple', 'product_type');
        }

        $product = new WC_Product_Simple($product_id);

        // Ensure SKU exists or set fallback
        $sku = $normalized['SKU'] ?? ($unit['SKU'] ?? null);
        if (empty($sku)) {
            $sku = 'erp-' . ($normalized['Id'] ?? uniqid());
        }
        // avoid collisions
        $other = wc_get_product_id_by_sku($sku);
        if ($other && ((int)$other !== (int)$product_id)) {
            $sku = $sku . '-' . uniqid();
        }
        $product->set_sku($sku);

        $product->set_name($normalized['Name'] ?? '');
        $product->set_description($normalized['Description'] ?? '');

        $price = $unit['Price']['Selling_Price'] ?? ($unit['Price'] ?? 0);
        $stock = $unit['Stock']['Quantity_On_Hand'] ?? ($unit['Stock'] ?? 0);

        $product->set_regular_price($price);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($stock);

        // Barcodes
        if (!empty($unit['Barcodes'])) {
            update_post_meta($product->get_id(), '_erp_barcodes', $unit['Barcodes']);
        }

        // Global unique id
        if (!empty($unit['Product_BarCodes']) && is_array($unit['Product_BarCodes'])) {
            foreach ($unit['Product_BarCodes'] as $barcode) {
                if ((int)($barcode['Id'] ?? 0) === (int)($unit['Id'] ?? 0) && !empty($barcode['BarCode'])) {
                    update_post_meta($product->get_id(), '_global_unique_id', trim($barcode['BarCode']));
                    break;
                }
            }
        }

        // Images
        if (!empty($unit['Images']) && isset($unit['Images'][0]['Path'])) {
            $path = $unit['Images'][0]['Path'];
            $image_id = $this->attachImageFromUrl($path);
            $this->log('debug', 'Simple update: attach image', ['path' => $path, 'image_id' => $image_id]);
            if ($image_id) {
                $product->set_image_id($image_id);
            }
        }

        $product->save();
        update_post_meta($product->get_id(), '_citypaints_raw_data', wp_json_encode($raw));

        $this->log('info', 'Simple product updated', ['product_id' => $product->get_id(), 'sku' => $sku]);

        return $product;
    }
}
