<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OEI_EAN_Mover {

    /**
     * Move EAN from variations to parent product.
     * Set SKU of ALL variants to the parent's EAN value.
     * Clear _ean from variations.
     *
     * Uses update_post_meta for SKU to bypass WooCommerce unique SKU validation
     * (all variants of one product get the same SKU = EAN).
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

                // Set SKU = EAN on ALL variants, remove _ean meta
                // Use update_post_meta directly to bypass WooCommerce unique SKU check
                foreach ( $children as $vid ) {
                    $v = wc_get_product( $vid );
                    if ( ! $v ) continue;

                    $old_sku = $v->get_sku();

                    // Delete _ean meta
                    delete_post_meta( $vid, '_ean' );

                    // Set SKU directly in DB - bypasses WC unique SKU validation
                    update_post_meta( $vid, '_sku', $found_ean );

                    // Clear WC product cache for this variation
                    wc_delete_product_transients( $vid );

                    $stats['variations_updated']++;
                    $entry['details'][] = '#' . $vid . ': SKU ' . ( ! empty( $old_sku ) ? $old_sku : '(pusty)' ) . ' -> ' . $found_ean;
                }
                $entry['message'] = 'EAN na parent, SKU wariantow = ' . $found_ean . ' (' . count( $children ) . ' wariantow)';
            } else {
                foreach ( $children as $vid ) {
                    $v = wc_get_product( $vid );
                    if ( ! $v ) continue;
                    $old_sku = $v->get_sku();
                    $stats['variations_updated']++;
                    $entry['details'][] = '#' . $vid . ': SKU ' . ( ! empty( $old_sku ) ? $old_sku : '(pusty)' ) . ' -> ' . $found_ean;
                }
                $entry['message'] = 'Do przeniesienia (' . count( $children ) . ' wariantow, SKU = ' . $found_ean . ')';
            }

            $stats['products_updated']++;
            $log[] = $entry;
        }

        return array( 'log' => $log, 'stats' => $stats );
    }
}
