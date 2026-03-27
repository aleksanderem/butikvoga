<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OEI_EAN_Mover {

    /**
     * Move EAN from variations to parent product.
     * Parent gets _ean. Variations get parent_id = parent's EAN.
     * Variations keep their SKU, _ean is removed.
     */
    public static function run( $dry_run = true ) {
        $log   = array();
        $stats = array( 'products_total' => 0, 'products_updated' => 0, 'products_skipped' => 0, 'variations_updated' => 0 );

        $products = wc_get_products( array( 'type' => 'variable', 'status' => 'publish', 'limit' => -1 ) );
        $stats['products_total'] = count( $products );

        foreach ( $products as $product ) {
            $children = $product->get_children();
            if ( empty( $children ) ) { $stats['products_skipped']++; continue; }

            // Try to find EAN: first from parent, then from variations
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
                'product_id'     => $product->get_id(),
                'product_name'   => $product->get_name(),
                'ean'            => $found_ean,
                'parent_had_ean' => ! empty( $parent_had_ean ) ? $parent_had_ean : '-',
                'variants_count' => count( $children ),
                'status'         => 'ok',
                'message'        => '',
                'details'        => array(),
            );

            if ( ! $dry_run ) {
                // Set EAN on parent product
                $product->update_meta_data( '_ean', $found_ean );
                $product->save();

                // For ALL variants: set parent_id = EAN, remove _ean, keep SKU
                foreach ( $children as $vid ) {
                    $v = wc_get_product( $vid );
                    if ( ! $v ) continue;

                    $v->delete_meta_data( '_ean' );
                    $v->update_meta_data( 'parent_id', $found_ean );
                    $v->save();

                    $stats['variations_updated']++;
                    $entry['details'][] = '#' . $vid . ': parent_id=' . $found_ean . ', _ean usuniety';
                }
                $entry['message'] = 'EAN na parent, parent_id ustawione na ' . count( $children ) . ' wariantach';
            } else {
                foreach ( $children as $vid ) {
                    $stats['variations_updated']++;
                    $entry['details'][] = '#' . $vid . ': parent_id=' . $found_ean . ', _ean do usuniecia';
                }
                $entry['message'] = 'Do przeniesienia (' . count( $children ) . ' wariantow, parent_id = ' . $found_ean . ')';
            }

            $stats['products_updated']++;
            $log[] = $entry;
        }

        return array( 'log' => $log, 'stats' => $stats );
    }
}
