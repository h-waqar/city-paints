<?php

namespace CityPaintsERP\Sync;

use CityPaintsERP\Api\ProductApi;
use WP_Error;

class ProductMapper
{
    private ProductApi $productApi;

    public function __construct(ProductApi $api)
    {
        $this->productApi = $api;
    }

    /**
     * Fetch products and fetch prices, quantities, images for each individually
     */
    public function fetchMegaProducts(): array|WP_Error
    {
        global $CLOGGER;


        $products = $this->productApi->listProducts();
        if (is_wp_error($products)) {
            if (isset($CLOGGER)) {
                $CLOGGER->error('Failed to fetch products', ['error' => $products->get_error_messages()]);
            }

            return $products;
        }

        if (isset($CLOGGER)) {
            $CLOGGER->info('Fetched products count', ['count' => count($products)]);
        }

        $megaProducts = [];

        foreach ($products as $product) {
            $id = $product['Id'] ?? null;
            if (!$id) {
                if (isset($CLOGGER)) {
                    $CLOGGER->warning('Product missing Id, skipping', ['product' => $product]);
                }
                continue;
            }

            if (isset($CLOGGER)) {
                $CLOGGER->info("Processing product {$id}", ['SKU' => $product['SKU'] ?? null]);
            }

            $errors = [];

            // per-product calls
            $pricesResp = $this->productApi->listPrices((int)$id);
            if (is_wp_error($pricesResp)) {
                $errors[] = [
                    'prices' => $pricesResp->get_error_messages(),
                    'code' => $pricesResp->get_error_code()
                ];
                $productPrices = [];
                if (isset($CLOGGER)) {
                    $CLOGGER->error("Prices fetch failed for {$id}", $pricesResp->get_error_messages());
                }
            } else {
                // unwrap structure: may be wrapper { Id, SKU, Product_Prices }
                $productPrices = $pricesResp['Product_Prices'] ?? (is_array($pricesResp) ? $pricesResp : []);
            }

            $qtysResp = $this->productApi->listQuantities((int)$id);
            if (is_wp_error($qtysResp)) {
                $errors[] = ['qtys' => $qtysResp->get_error_messages(), 'code' => $qtysResp->get_error_code()];
                $productQtys = [];
                if (isset($CLOGGER)) {
                    $CLOGGER->error("Qtys fetch failed for {$id}", $qtysResp->get_error_messages());
                }
            } else {
                $productQtys = $qtysResp['Product_Qtys'] ?? (is_array($qtysResp) ? $qtysResp : []);
            }

            $imagesResp = $this->productApi->listImages((int)$id);
            if (is_wp_error($imagesResp)) {
                $errors[] = [
                    'images' => $imagesResp->get_error_messages(),
                    'code' => $imagesResp->get_error_code()
                ];
                $productImages = [];
                if (isset($CLOGGER)) {
                    $CLOGGER->error("Images fetch failed for {$id}", $imagesResp->get_error_messages());
                }
            } else {
                $productImages = $imagesResp['Product_Images'] ?? (is_array($imagesResp) ? $imagesResp : []);
            }

            // attach the unwrapped arrays into product so normalize() can use them
            $product['Product_Prices'] = $productPrices;
            $product['Product_Qtys'] = $productQtys;
            $product['Product_Images'] = $productImages;

            // keep errors on the product (or just log — depending on what you prefer)
            if (!empty($errors)) {
                $product['_sync_errors'] = $errors;
            }

//			$megaProducts[] = $this->normalize( $product );

            $normalized = $this->normalize($product);
            $megaProducts[] = [
                'raw_data' => $product,
                'normalized' => $normalized,
            ];
        }

        $CLOGGER->log('Products data with RAW', $megaProducts);

        return $megaProducts;
    }


    private function normalize(array $p): array
    {
        $units = [];
        foreach ($p['Product_Units'] ?? [] as $u) {
            $uid = $u['Id'];
            $units[$uid] = [
                'Id' => $uid,
                'Short_Name' => $u['Short_Name'] ?? '',
                'Description' => $u['Description'] ?? '',
                'Price' => $this->findPriceForUnit($p['Product_Prices'], $uid),
                'Stock' => $this->findQtyForUnit($p['Product_Qtys'], $uid),
                'BarCodes' => $this->findBarcodesForUnit($p['Product_BarCodes'], $uid),
                'Images' => $this->findImagesForUnit($p['Product_Images'], $uid),
            ];
        }

        return [
            'Id' => $p['Id'],
            'SKU' => trim($p['SKU']),
            'Name' => $p['Name'],
            'Description' => $p['Full_Description'] ?? '',
            'Units' => $units,
        ];
    }


    private function findPriceForUnit(array $unitPrices, int $unitId)
    {
        // unitPrices structure: [ ['Unit_Id'=>1,'Prices'=>[...]] , ...]

        foreach ($unitPrices as $up) {
            if ((int)$up['Unit_Id'] === (int)$unitId) {
                // find price where IsCustomerPrice true
                foreach ($up['Prices'] as $price) {
                    if (!empty($price['IsCustomerPrice'])) {
                        return $price;
                    }
                }

                return $up['Prices'][0] ?? null;
            }
        }

        return null;
    }

    private function findQtyForUnit(array $unitQtys, int $unitId)
    {
        foreach ($unitQtys as $uq) {
            if ((int)$uq['Unit_Id'] === (int)$unitId) {
                return $uq;
            }
        }

        return null;
    }

    private function findBarcodesForUnit(array $barcodes, int $unitId): array
    {
        $out = [];
        foreach ($barcodes as $b) {
            if ((int)$b['Id'] === (int)$unitId) {
                $out[] = trim($b['BarCode']);
            }
        }

        return $out;
    }

    private function findImagesForUnit(array $productImages, int $unitId)
    {
        // productImages matches Product_Images structure (Unit_Id => Images)
        foreach ($productImages as $pi) {
            if ((int)$pi['Unit_Id'] === (int)$unitId) {
                return $pi['Images'] ?? [];
            }
        }

        return [];
    }
}
