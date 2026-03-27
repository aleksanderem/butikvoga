<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OEI_EAN_Mover {

    /**
     * Move EAN from variations to parent product, clear _ean from variations.
     * Variations keep their SKU intact.
     */
    public static function run( $dry_run = true, $set_parent_sku = false ) {
        $log   = array();
        $stats = array( 'products_total' => 0, 'products_updated' => 0, 'products_skipped' => 0, 'variations_cleaned' => 0 );

        $products = wc_get_products( array( 'type' => 'variable', 'status' => 'publish', 'limit' => -1 ) );
        $stats['products_total'] = count( $products );

        foreach ( $products as $product ) {
            $children = $product->get_children();
            if ( empty( $children ) ) { $stats['products_skipped']++; continue; }

            $found_ean = '';
            $vars_with_ean = array();

            foreach ( $children as $vid ) {
                $variation = wc_get_product( $vid );
                if ( ! $variation ) continue;

                $ean = $variation->get_meta( '_ean' );
                $sku = $variation->get_sku();

                if ( empty( $ean ) && ! empty( $sku ) && preg_match( '/^\d{8,13}$/', $sku ) ) {
                    $ean = $sku;
                }

                if ( ! empty( $ean ) ) {
                    if ( empty( $found_ean ) ) $found_ean = $ean;
                    $vars_with_ean[] = array( 'id' => $vid, 'ean' => $ean, 'sku' => $sku );
                }
            }

            if ( empty( $found_ean ) ) { $stats['products_skipped']++; continue; }

            $parent_ean = $product->get_meta( '_ean' );

            $entry = array(
                'product_id'     => $product->get_id(),
                'product_name'   => $product->get_name(),
                'ean'            => $found_ean,
                'parent_had_ean' => ! empty( $parent_ean ) ? $parent_ean : '-',
                'variants_count' => count( $vars_with_ean ),
                'status'         => 'ok',
                'message'        => '',
                'details'        => array(),
            );

            if ( ! $dry_run ) {
                // Set EAN on parent product
                $product->update_meta_data( '_ean', $found_ean );
                if ( $set_parent_sku && empty( $product->get_sku() ) ) {
                    $product->set_sku( $found_ean );
                }
                $product->save();

                // Remove _ean from variations, keep SKU
                foreach ( $vars_with_ean as $vdata ) {
                    $v = wc_get_product( $vdata['id'] );
                    if ( ! $v ) continue;
                    $v->delete_meta_data( '_ean' );
                    $v->save();
                    $stats['variations_cleaned']++;
                    $entry['details'][] = '#' . $vdata['id'] . ': _ean usuniety, SKU=' . $vdata['sku'];
                }
                $entry['message'] = 'EAN przeniesiony, wyczyszczono ' . count( $vars_with_ean ) . ' wariantow';
            } else {
                foreach ( $vars_with_ean as $vdata ) {
                    $stats['variations_cleaned']++;
                    $entry['details'][] = '#' . $vdata['id'] . ': _ean do usuniecia, SKU=' . $vdata['sku'];
                }
                $entry['message'] = 'Do przeniesienia (' . count( $vars_with_ean ) . ' wariantow)';
            }

            $stats['products_updated']++;
            $log[] = $entry;
        }

        return array( 'log' => $log, 'stats' => $stats );
    }
}
