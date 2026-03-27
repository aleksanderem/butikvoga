<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OEI_Product_Matcher {

    public static function find( $row, $skip_existing = false ) {
        $nazwa   = trim( isset( $row['nazwa'] ) ? $row['nazwa'] : '' );
        $kolor   = trim( isset( $row['kolor'] ) ? $row['kolor'] : '' );
        $rozmiar = trim( isset( $row['rozmiar'] ) ? $row['rozmiar'] : '' );
        $ean     = trim( isset( $row['ean'] ) ? $row['ean'] : '' );

        $entry = array(
            'nazwa' => $nazwa, 'kolor' => $kolor, 'rozmiar' => $rozmiar,
            'ean' => $ean, 'status' => 'error', 'message' => '', 'variation_id' => null,
        );

        if ( empty( $ean ) ) { $entry['message'] = 'Brak EAN'; return $entry; }
        if ( empty( $nazwa ) ) { $entry['message'] = 'Brak nazwy'; return $entry; }

        $product = self::find_product( $nazwa );
        if ( ! $product ) { $entry['message'] = 'Nie znaleziono produktu: ' . $nazwa; return $entry; }

        $vid = self::match_variation( $product, $kolor, $rozmiar );
        if ( ! $vid ) {
            $entry['message'] = '"' . $product->get_name() . '" - brak wariantu: ' . $kolor . ' / ' . $rozmiar;
            return $entry;
        }

        $variation = wc_get_product( $vid );
        if ( $skip_existing && $variation && ! empty( $variation->get_sku() ) ) {
            $entry['status'] = 'skip';
            $entry['message'] = '#' . $vid . ' ma SKU: ' . $variation->get_sku();
            $entry['variation_id'] = $vid;
            return $entry;
        }

        $entry['status'] = 'ok';
        $entry['variation_id'] = $vid;
        $entry['message'] = 'Wariant #' . $vid . ' (' . $product->get_name() . ')';
        return $entry;
    }

    private static function find_product( $nazwa ) {
        $query = new WP_Query( array(
            'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => -1,
            's' => $nazwa,
            'tax_query' => array( array( 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => 'variable' ) ),
        ) );

        foreach ( $query->posts as $post ) {
            if ( mb_strtolower( trim( $post->post_title ) ) === mb_strtolower( $nazwa ) ) {
                return wc_get_product( $post->ID );
            }
        }
        foreach ( $query->posts as $post ) {
            if ( mb_stripos( $post->post_title, $nazwa ) !== false ) {
                return wc_get_product( $post->ID );
            }
        }
        return null;
    }

    private static function match_variation( $product, $kolor, $rozmiar ) {
        foreach ( $product->get_children() as $vid ) {
            $variation = wc_get_product( $vid );
            if ( ! $variation ) continue;

            $attrs = $variation->get_attributes();
            $var_kolor = '';
            $var_rozmiar = '';

            foreach ( $attrs as $key => $val ) {
                $k = mb_strtolower( $key );
                if ( strpos( $k, 'kolor' ) !== false || strpos( $k, 'color' ) !== false || strpos( $k, 'colour' ) !== false ) {
                    $var_kolor = self::resolve_attr( $key, $val );
                }
                if ( strpos( $k, 'rozmiar' ) !== false || strpos( $k, 'size' ) !== false ) {
                    $var_rozmiar = self::resolve_attr( $key, $val );
                }
            }

            $color_ok = mb_strtolower( trim( $var_kolor ) ) === mb_strtolower( $kolor );
            $size_ok  = mb_strtolower( trim( $var_rozmiar ) ) === mb_strtolower( $rozmiar );
            $is_uni   = ( mb_strtolower( $rozmiar ) === 'uni' || empty( $rozmiar ) );

            if ( $color_ok && ( $size_ok || ( $is_uni && empty( $var_rozmiar ) ) ) ) {
                return $vid;
            }
        }
        return null;
    }

    private static function resolve_attr( $key, $value ) {
        if ( strpos( $key, 'pa_' ) === 0 ) {
            $term = get_term_by( 'slug', $value, $key );
            if ( $term && ! is_wp_error( $term ) ) return $term->name;
        }
        return $value;
    }
}
