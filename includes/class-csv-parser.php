<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OEI_CSV_Parser {

    public static function parse_upload() {
        if ( empty( $_FILES['csv_file']['tmp_name'] ) ) {
            wp_die( 'Nie wybrano pliku CSV.' );
        }

        $content = file_get_contents( $_FILES['csv_file']['tmp_name'] );
        if ( $content === false ) {
            wp_die( 'Nie udalo sie odczytac pliku CSV.' );
        }

        $content = preg_replace( '/^\xEF\xBB\xBF/', '', $content );
        $lines   = explode( "\n", str_replace( "\r\n", "\n", $content ) );
        $csv     = array();

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) continue;
            $csv[] = str_getcsv( $line, ',', '"' );
        }

        if ( empty( $csv ) ) {
            wp_die( 'Plik CSV jest pusty.' );
        }

        $header      = array_map( 'trim', array_map( 'mb_strtolower', $csv[0] ) );
        $col_nazwa   = array_search( 'nazwa', $header, true );
        $col_ean     = array_search( 'ean', $header, true );
        $col_kolor   = array_search( 'kolor', $header, true );
        $col_rozmiar = array_search( 'rozmiar', $header, true );

        if ( $col_nazwa === false || $col_ean === false || $col_kolor === false ) {
            wp_die( 'Brak wymaganych kolumn (Nazwa, EAN, Kolor). Znalezione: ' . implode( ', ', $header ) );
        }

        $rows = array();
        for ( $i = 1; $i < count( $csv ); $i++ ) {
            $r = $csv[ $i ];
            if ( empty( $r ) || count( $r ) < 3 ) continue;
            $rows[] = array(
                'nazwa'   => trim( isset( $r[ $col_nazwa ] ) ? $r[ $col_nazwa ] : '' ),
                'ean'     => trim( isset( $r[ $col_ean ] ) ? $r[ $col_ean ] : '' ),
                'kolor'   => trim( isset( $r[ $col_kolor ] ) ? $r[ $col_kolor ] : '' ),
                'rozmiar' => ( $col_rozmiar !== false && isset( $r[ $col_rozmiar ] ) ) ? trim( $r[ $col_rozmiar ] ) : '',
            );
        }

        return $rows;
    }
}
