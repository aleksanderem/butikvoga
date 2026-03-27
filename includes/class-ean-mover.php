<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OEI_EAN_Mover {

    /**
     * Move EAN from variations to parent product.
     * Set SKU of ALL variants to the parent product's ID.
     * Clear _ean from variations.
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
            $new_sku = strval( $parent_id );

            $entry = array(
                'product_id'     => $parent_id,
                'product_name'   => $product->get_name(),
                'ean'            => $found_ean,
                'parent_had_ean' => ! empty( $parent_had_ean ) ? $parent_had_ean : '-',
                'variants_count' => count( $children ),
                'new_sku'        => $new_sku,
                'status'         => 'ok',
                'message'        => '',
                'details'        => array(),
            );

            if ( ! $dry_run ) {
                // Set EAN on parent product
                $product->update_meta_data( '_ean', $found_ean );
                $product->save();

                // Set SKU = parent ID on ALL variants, remove _ean meta
                foreach ( $children as $vid ) {
                    $v = wc_get_product( $vid );
                    if ( ! $v ) continue;

                    $old_sku = $v->get_sku();

                    // Delete _ean meta
                    delete_post_meta( $vid, '_ean' );

                    // Set SKU = parent product ID (bypass WC unique check)
                    update_post_meta( $vid, '_sku', $new_sku );

                    // Clear WC product cache
                    wc_delete_product_transients( $vid );

                    $stats['variations_updated']++;
                    $entry['details'][] = '#' . $vid . ': SKU ' . ( ! empty( $old_sku ) ? $old_sku : '(pusty)' ) . ' -> ' . $new_sku;
                }
                $entry['message'] = 'EAN na parent, SKU wariantow = ' . $new_sku . ' (' . count( $children ) . ' wariantow)';
            } else {
                foreach ( $children as $vid ) {
                    $v = wc_get_product( $vid );
                    if ( ! $v ) continue;
                    $old_sku = $v->get_sku();
                    $stats['variations_updated']++;
                    $entry['details'][] = '#' . $vid . ': SKU ' . ( ! empty( $old_sku ) ? $old_sku : '(pusty)' ) . ' -> ' . $new_sku;
                }
                $entry['message'] = 'Do przeniesienia (' . count( $children ) . ' wariantow, SKU = ' . $new_sku . ')';
            }

            $stats['products_updated']++;
            $log[] = $entry;
        }

        return array( 'log' => $log, 'stats' => $stats );
    }
}
