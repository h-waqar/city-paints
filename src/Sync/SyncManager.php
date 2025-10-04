<?php

namespace CityPaintsERP\Sync;

use CityPaintsERP\Api\ProductApi;
use CityPaintsERP\Sync\ProductHandler\SimpleProductHandler;
use CityPaintsERP\Sync\ProductHandler\VariableProductHandler;
use Exception;
use Throwable;
use WP_Error;

class SyncManager
{
    /**
     * Entry point for AJAX or manual calls
     */
    public function syncProducts(): void
    {
        $this->log('info', 'Starting product sync');

        $megaProducts = $this->fetchMegaProducts();

        if (is_wp_error($megaProducts)) {
            $errors = $megaProducts->get_error_messages();
            $this->log('error', 'Failed to fetch products', ['errors' => $errors]);
            wp_send_json_error([
                'message' => 'Failed to fetch products.',
                'errors' => $errors,
            ]);
        }

        $this->log('info', 'Fetched products', ['count' => count($megaProducts)]);

        $errors = [];
        foreach ($megaProducts as $p) {
            try {
                $sku = $p['SKU'] ?? null;
                $normalized = $p['normalized'] ?? [];
                $unit_count = count($normalized['Units'] ?? []);
                $this->log('info', 'Processing product', [
                    'sku' => $sku ?? 'NO-SKU',
                    'units' => $unit_count,
                ]);

                $raw = $p['raw_data'] ?? [];

                // Decide create vs update by SKU (if SKU present) or by other logic
                $product_id = null;
                if (!empty($sku)) {
                    $product_id = wc_get_product_id_by_sku($sku);
                }

                // If SKU not present try to find by normalized Id (rare) - skip for now
                if ($product_id) {
                    // Update flow: determine product type and delegate
                    $this->log('info', 'Updating product', ['product_id' => $product_id, 'sku' => $sku]);
                    $this->updateProduct($product_id, $normalized, $raw);
                } else {
                    // Create flow: decide simple vs variable by units count
                    $this->log('info', 'Creating product', ['sku' => $sku, 'units' => $unit_count]);
                    $this->createProduct($normalized, $raw);
                }
            } catch (Throwable $e) {
                $sku = $p['SKU'] ?? 'NO-SKU';
                $this->log('error', 'Exception while syncing product', [
                    'sku' => $sku,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $errors[] = "Product SKU {$sku} failed: " . $e->getMessage();
                // continue to next product - do not fail the whole sync
                continue;
            }
        }

        if (!empty($errors)) {
            $this->log('warning', 'Some products failed', ['errors' => $errors]);
            wp_send_json_error([
                'message' => 'Some products failed to sync.',
                'errors' => $errors,
            ]);
        }

        $this->log('info', 'All products synced successfully');
        wp_send_json_success(['message' => 'Products synced successfully!']);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        global $CLOGGER;
        if (isset($CLOGGER)) {
            // Allow calls like $this->log('info', ...)
            if (method_exists($CLOGGER, $level)) {
                $CLOGGER->{$level}($message, $context);
            } else {
                $CLOGGER->info($message, $context);
            }
        }
    }

    /**
     * Fetch products from ERP
     */
    private function fetchMegaProducts(): array|WP_Error
    {
        $this->log('info', 'Fetching mega products from ERP');
        $api = new ProductApi();
        $fetch = new ProductMapper($api);
        $products = $fetch->fetchMegaProducts();
        $this->log('info', 'Fetched from ERP', [
            'count' => is_wp_error($products) ? 0 : count($products)
        ]);
        return $products;
    }

    /**
     * Update an existing product (delegates to handlers)
     */
    private function updateProduct(int $product_id, array $normalized, array $raw): void
    {
        $units = $normalized['Units'] ?? [];
        $unit_count = count($units);
        $this->log('info', 'UpdateProduct decision', ['product_id' => $product_id, 'unit_count' => $unit_count]);

        // Get current product object and type
        $current = wc_get_product($product_id);

        if ($unit_count === 1) {
            // Ensure conversion to simple if currently variable
            $handler = new SimpleProductHandler();
            $handler->update($product_id, $normalized, $raw, array_values($units)[0]);
            return;
        }

        // multi-unit -> variable
        $handler = new VariableProductHandler();
        $handler->update($product_id, $normalized, $raw, $units);
    }

    /**
     * Create a product (delegates to handlers)
     * @throws Exception
     */
    private function createProduct(array $normalized, array $raw): void
    {
        $units = $normalized['Units'] ?? [];
        $unit_count = count($units);

        if ($unit_count === 1) {
            $handler = new SimpleProductHandler();
            $handler->create($normalized, $raw, array_values($units)[0]);
        } else {
            $handler = new VariableProductHandler();
            $handler->create($normalized, $raw, $units);
        }
    }
}
