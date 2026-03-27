<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OEI_EAN_Mover {

    public static function run( $dry_run = true ) {
        $log   = array();
        $stats = array( 'products_total' => 0, 'products_updated' => 0, 'products_skipped' => 0, 'variations_updated' => 0 );

        $products = wc_get_products( array( 'type' => 'variable', 'status' => 'publish', 'limit' => -1 ) );
        $stats['products_total'] = count( $products );

        foreach ( $products as $product ) {
            $children = $product->get_children();
            if ( empty( $children ) ) { $stats['products_skipped']++; continue; }

            $parent_id = $product->get_id();

            // Find EAN: parent first, then variants
            $found_ean = $product->get_meta( '_ean' );
            if ( empty( $found_ean ) ) {
                foreach ( $children as $vid ) {
                    $v = wc_get_product( $vid );
                    if ( ! $v ) continue;
                    $ean = $v->get_meta( '_ean' );
                    $sku = $v->get_sku();
                    if ( empty( $ean ) && ! empty( $sku ) && preg_match( '/^\d{8,13}$/', $sku ) ) {
                        $ean = $sku;
                    }
                    if ( ! empty( $ean ) ) { $found_ean = $ean; break; }
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
                // EAN on parent
                $product->update_meta_data( '_ean', $found_ean );
                $product->save();

                // ALL variants: custom meta _parent_ean = EAN parenta, remove _ean
                foreach ( $children as $vid ) {
                    update_post_meta( $vid, '_parent_ean', $found_ean );
                    delete_post_meta( $vid, '_ean' );

                    $stats['variations_updated']++;
                    $entry['details'][] = '#' . $vid . ': _parent_ean=' . $found_ean;
                }
                $entry['message'] = 'OK: parent _ean=' . $found_ean . ', ' . count( $children ) . ' wariantow _parent_ean';
            } else {
                foreach ( $children as $vid ) {
                    $stats['variations_updated']++;
                    $entry['details'][] = '#' . $vid . ': _parent_ean=' . $found_ean;
                }
                $entry['message'] = count( $children ) . ' wariantow dostanie _parent_ean=' . $found_ean;
            }

            $stats['products_updated']++;
            $log[] = $entry;
        }

        return array( 'log' => $log, 'stats' => $stats );
    }
}
