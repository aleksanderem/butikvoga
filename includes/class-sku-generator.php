<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OEI_SKU_Generator {

    public static function generate( $ean, $kolor, $rozmiar ) {
        $ean = trim( $ean );
        if ( empty( $ean ) ) return '';

        $kolor   = self::slugify( trim( $kolor ) );
        $rozmiar = trim( $rozmiar );

        if ( empty( $rozmiar ) || mb_strtolower( $rozmiar ) === 'uni' ) {
            $rozmiar = 'UNI';
        } else {
            $rozmiar = self::slugify( $rozmiar );
        }

        $parts = array( $ean );
        if ( ! empty( $kolor ) ) $parts[] = $kolor;
        $parts[] = $rozmiar;

        return implode( '-', $parts );
    }

    public static function bulk_generate( $dry_run = false ) {
        $log   = array();
        $stats = array( 'total' => 0, 'generated' => 0, 'skipped' => 0 );

        $products = wc_get_products( array( 'type' => 'variable', 'status' => 'publish', 'limit' => -1 ) );

        foreach ( $products as $product ) {
            foreach ( $product->get_children() as $vid ) {
                $variation = wc_get_product( $vid );
                if ( ! $variation ) continue;

                $stats['total']++;
                $current_sku = $variation->get_sku();
                $ean         = $variation->get_meta( '_ean' );

                if ( empty( $ean ) && ! empty( $current_sku ) && preg_match( '/^\d{8,13}$/', $current_sku ) ) {
                    $ean = $current_sku;
                }

                if ( empty( $ean ) ) { $stats['skipped']++; continue; }

                if ( ! empty( $current_sku ) && strpos( $current_sku, '-' ) !== false ) {
                    $stats['skipped']++;
                    $log[] = array(
                        'product' => $product->get_name(), 'variation_id' => $vid, 'ean' => $ean,
                        'old_sku' => $current_sku, 'new_sku' => $current_sku, 'status' => 'skip', 'message' => 'Ma zlozony SKU',
                    );
                    continue;
                }

                $attrs = $variation->get_attributes();
                $kolor = ''; $rozmiar = '';
                foreach ( $attrs as $key => $val ) {
                    $k = mb_strtolower( $key );
                    if ( strpos( $k, 'kolor' ) !== false || strpos( $k, 'color' ) !== false || strpos( $k, 'colour' ) !== false ) {
                        $kolor = self::resolve_attr( $key, $val );
                    }
                    if ( strpos( $k, 'rozmiar' ) !== false || strpos( $k, 'size' ) !== false ) {
                        $rozmiar = self::resolve_attr( $key, $val );
                    }
                }

                $new_sku = self::generate( $ean, $kolor, $rozmiar );

                if ( ! $dry_run ) {
                    $variation->update_meta_data( '_ean', $ean );
                    $variation->set_sku( $new_sku );
                    $variation->save();
                }

                $stats['generated']++;
                $log[] = array(
                    'product' => $product->get_name(), 'variation_id' => $vid, 'ean' => $ean,
                    'old_sku' => $current_sku, 'new_sku' => $new_sku, 'status' => 'ok',
                    'message' => $dry_run ? 'Do wygenerowania' : 'Wygenerowano',
                );
            }
        }

        return array( 'log' => $log, 'stats' => $stats );
    }

    private static function slugify( $text ) {
        $text = mb_strtoupper( trim( $text ) );
        $text = preg_replace( '/[^A-Z0-9]+/u', '-', $text );
        return trim( $text, '-' );
    }

    private static function resolve_attr( $key, $value ) {
        if ( strpos( $key, 'pa_' ) === 0 ) {
            $term = get_term_by( 'slug', $value, $key );
            if ( $term && ! is_wp_error( $term ) ) return $term->name;
        }
        return $value;
    }
}
