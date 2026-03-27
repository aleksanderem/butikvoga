<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OEI_EAN_Mover {

    /**
     * Move EAN from variations to parent product.
     * Parent gets _ean.
     * Variants get parent_id = parent WC product ID (via post_meta).
     * Variants _ean removed. SKU untouched.
     */
    public static function run( $dry_run = true ) {
        $log   = array();
        $stats = array( 'products_total' => 0, 'products_updated' => 0, 'products_skipped' => 0, 'variations_updated' => 0 );

        $products = wc_get_products( array( 'type' => 'variable', 'status' => 'publish', 'limit' => -1 ) );
        $stats['products_total'] = count( $products );

        foreach ( $products as $product ) {
            $children = $product->get_children();
            if ( empty( $children ) ) { $stats['products_skipped']++; continue; }

            $parent_id = $product->get_id();
            $found_ean = $product->get_meta( '_ean' );

            if ( empty( $found_ean ) ) {
                foreach ( $children as $vid ) {
                    $variation = wc_get_product( $vid );
                    if ( ! $variation ) continue;

                    $ean = $variation->get_meta( '_ean' );
                    $sku = $variation->get_sku();

                    if ( empty( $ean ) && ! empty( $sku ) && preg_match( '/^\d{8,13}$/', $sku ) ) {
                        $ean = $sku;
                    }

                    if ( ! empty( $ean ) ) {
                        $found_ean = $ean;
                        break;
                    }
                }
            }

            if ( empty( $found_ean ) ) { $stats['products_skipped']++; continue; }

            $parent_had_ean = $product->get_meta( '_ean' );

            $entry = array(
                'product_id'     => $parent_id,
                'product_name'   => $product->get_name(),
                'ean'            => $found_ean,
                'parent_had_ean' => ! empty( $parent_had_ean ) ? $parent_had_ean : '-',
                'variants_count' => count( $children ),
                'status'         => 'ok',
                'message'        => '',
                'details'        => array(),
            );

            if ( ! $dry_run ) {
                // Set EAN on parent
                $product->update_meta_data( '_ean', $found_ean );
                $product->save();

                // Set parent_id on ALL variants, remove _ean
                foreach ( $children as $vid ) {
                    // Use direct post_meta to avoid WC parent_id conflict
                    update_post_meta( $vid, 'parent_id', strval( $parent_id ) );
                    delete_post_meta( $vid, '_ean' );
                    wc_delete_product_transients( $vid );

                    $stats['variations_updated']++;
                    $entry['details'][] = '#' . $vid . ': parent_id=' . $parent_id . ', _ean usuniety';
                }
                $entry['message'] = 'EAN na parent #' . $parent_id . ', parent_id na ' . count( $children ) . ' wariantach';
            } else {
                foreach ( $children as $vid ) {
                    $stats['variations_updated']++;
                    $entry['details'][] = '#' . $vid . ': parent_id=' . $parent_id . ', _ean do usuniecia';
                }
                $entry['message'] = 'Do przeniesienia (' . count( $children ) . ' wariantow, parent_id=' . $parent_id . ')';
            }

            $stats['products_updated']++;
            $log[] = $entry;
        }

        return array( 'log' => $log, 'stats' => $stats );
    }
}
