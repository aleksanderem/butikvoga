<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OEI_Admin_Page {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_pages' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'styles' ) );

        // Import EAN
        add_action( 'admin_post_oei_preview', array( $this, 'handle_preview' ) );
        add_action( 'admin_post_oei_import', array( $this, 'handle_import' ) );
        // Bulk SKU
        add_action( 'admin_post_oei_bulk_sku_preview', array( $this, 'handle_bulk_sku_preview' ) );
        add_action( 'admin_post_oei_bulk_sku_run', array( $this, 'handle_bulk_sku_run' ) );
        // Move EAN
        add_action( 'admin_post_oei_move_preview', array( $this, 'handle_move_preview' ) );
        add_action( 'admin_post_oei_move_run', array( $this, 'handle_move_run' ) );
    }

    public function register_pages() {
        add_menu_page( 'EAN Importer', 'EAN Importer', 'manage_woocommerce', 'oei-import', array( $this, 'page_import' ), 'dashicons-barcode', 56 );
        add_submenu_page( 'oei-import', 'Import EAN', 'Import EAN', 'manage_woocommerce', 'oei-import', array( $this, 'page_import' ) );
        add_submenu_page( 'oei-import', 'Generuj SKU', 'Generuj SKU', 'manage_woocommerce', 'oei-bulk-sku', array( $this, 'page_bulk_sku' ) );
        add_submenu_page( 'oei-import', 'Przenies EAN', 'Przenies EAN', 'manage_woocommerce', 'oei-move-ean', array( $this, 'page_move_ean' ) );
    }

    public function styles( $hook ) {
        if ( strpos( $hook, 'oei' ) === false && strpos( $hook, 'ean-importer' ) === false ) return;
        wp_add_inline_style( 'wp-admin', '
            .oei-wrap{max-width:1000px;margin:20px auto}
            .oei-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:24px;margin-bottom:20px}
            .oei-card h2{margin-top:0}
            .oei-table{width:100%;border-collapse:collapse;margin-top:12px;font-size:13px}
            .oei-table th,.oei-table td{padding:8px 10px;border:1px solid #ddd;text-align:left}
            .oei-table th{background:#f0f0f1}
            .oei-ok{color:#00a32a}.oei-err{color:#d63638}.oei-skip{color:#996800}
            .oei-stats{display:flex;gap:12px;margin:16px 0;flex-wrap:wrap}
            .oei-stat{padding:10px 18px;border-radius:4px;font-size:14px;font-weight:600}
            .oei-s-total{background:#f0f0f1;color:#50575e}
            .oei-s-ok{background:#edfaef;color:#00a32a}
            .oei-s-skip{background:#fef8ee;color:#996800}
            .oei-s-err{background:#fcf0f1;color:#d63638}
        ' );
    }

    // ═══════════════════════════════════════════════
    // TAB 1: Import EAN from CSV
    // ═══════════════════════════════════════════════

    public function page_import() {
        $preview = $this->pop_transient( 'oei_preview' );
        $results = $this->pop_transient( 'oei_results' );
        ?>
        <div class="wrap oei-wrap">
            <h1>Import EAN z CSV</h1>
            <div class="oei-card">
                <h2>Wgraj plik CSV</h2>
                <p>Kolumny: <code>Nazwa</code>, <code>EAN</code>, <code>Kolor</code>, <code>Rozmiar</code></p>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'oei_action', 'oei_nonce' ); ?>
                    <input type="hidden" name="action" value="oei_preview">
                    <p><input type="file" name="csv_file" accept=".csv" required></p>
                    <p><label><input type="checkbox" name="skip_existing" value="1" checked> Pomin warianty z istniejacym SKU</label></p>
                    <p><label><input type="checkbox" name="generate_sku" value="1" checked> <strong>Generuj SKU</strong> (EAN-KOLOR-ROZMIAR)</label></p>
                    <p><button type="submit" class="button button-secondary">Podglad (dry run)</button></p>
                </form>
            </div>
            <?php if ( $preview ) : ?>
                <?php $this->import_results_card( $preview, true ); ?>
            <?php endif; ?>
            <?php if ( $results ) : ?>
                <?php $this->import_results_card( $results, false ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function import_results_card( $data, $is_preview ) {
        $gen = ! empty( $data['generate_sku'] );
        ?>
        <div class="oei-card">
            <h2><?php echo $is_preview ? 'Podglad importu (dry run)' : 'Wynik importu'; ?></h2>
            <?php $this->render_stats( $data['stats'], array( 'total', 'matched', 'skipped', 'not_found' ) ); ?>
            <?php $this->import_table( $data['log'], $gen ); ?>
            <?php if ( $is_preview && $data['stats']['matched'] > 0 ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'oei_action', 'oei_nonce' ); ?>
                <input type="hidden" name="action" value="oei_import">
                <input type="hidden" name="csv_data" value="<?php echo esc_attr( base64_encode( wp_json_encode( $data['rows'] ) ) ); ?>">
                <input type="hidden" name="skip_existing" value="<?php echo intval( $data['skip_existing'] ); ?>">
                <input type="hidden" name="generate_sku" value="<?php echo intval( $data['generate_sku'] ); ?>">
                <p style="margin-top:16px">
                    <button type="submit" class="button button-primary button-hero"
                        onclick="return confirm('Importowac <?php echo intval( $data['stats']['matched'] ); ?> wpisow?');">
                        Importuj <?php echo intval( $data['stats']['matched'] ); ?> wpisow
                    </button>
                </p>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private function import_table( $log, $show_sku ) {
        if ( empty( $log ) ) return;
        ?>
        <table class="oei-table"><thead><tr>
            <th>#</th><th>Nazwa</th><th>Kolor</th><th>Rozmiar</th><th>EAN</th>
            <?php if ( $show_sku ) : ?><th>SKU</th><?php endif; ?>
            <th>Status</th><th>Info</th>
        </tr></thead><tbody>
        <?php $i = 1; foreach ( $log as $e ) : ?>
        <tr>
            <td><?php echo $i++; ?></td>
            <td><?php echo esc_html( $e['nazwa'] ); ?></td>
            <td><?php echo esc_html( $e['kolor'] ); ?></td>
            <td><?php echo esc_html( $e['rozmiar'] ); ?></td>
            <td><code><?php echo esc_html( $e['ean'] ); ?></code></td>
            <?php if ( $show_sku ) : ?><td><code><?php echo esc_html( isset( $e['new_sku'] ) ? $e['new_sku'] : '-' ); ?></code></td><?php endif; ?>
            <td><?php echo $this->status_badge( $e['status'] ); ?></td>
            <td><?php echo esc_html( $e['message'] ); ?></td>
        </tr>
        <?php endforeach; ?></tbody></table>
        <?php
    }

    public function handle_preview() {
        $this->verify();
        $rows = OEI_CSV_Parser::parse_upload();
        $skip = ! empty( $_POST['skip_existing'] );
        $gen  = ! empty( $_POST['generate_sku'] );

        $log = array(); $stats = array( 'total' => count( $rows ), 'matched' => 0, 'skipped' => 0, 'not_found' => 0 );
        foreach ( $rows as $row ) {
            $r = OEI_Product_Matcher::find( $row, $skip );
            if ( $gen && $r['status'] === 'ok' ) $r['new_sku'] = OEI_SKU_Generator::generate( $row['ean'], $row['kolor'], $row['rozmiar'] );
            $log[] = $r;
            if ( $r['status'] === 'ok' ) $stats['matched']++;
            elseif ( $r['status'] === 'skip' ) $stats['skipped']++;
            else $stats['not_found']++;
        }

        set_transient( 'oei_preview', array( 'log' => $log, 'stats' => $stats, 'rows' => $rows, 'skip_existing' => $skip ? 1 : 0, 'generate_sku' => $gen ? 1 : 0 ), 300 );
        wp_safe_redirect( admin_url( 'admin.php?page=oei-import' ) ); exit;
    }

    public function handle_import() {
        $this->verify();
        $raw  = base64_decode( sanitize_text_field( wp_unslash( isset( $_POST['csv_data'] ) ? $_POST['csv_data'] : '' ) ) );
        $rows = json_decode( $raw, true );
        if ( ! $rows ) wp_die( 'Brak danych.' );

        $skip = ! empty( $_POST['skip_existing'] );
        $gen  = ! empty( $_POST['generate_sku'] );

        $log = array(); $stats = array( 'total' => count( $rows ), 'matched' => 0, 'skipped' => 0, 'not_found' => 0 );
        foreach ( $rows as $row ) {
            $r = OEI_Product_Matcher::find( $row, $skip );
            if ( $r['status'] === 'ok' && ! empty( $r['variation_id'] ) ) {
                $ean = sanitize_text_field( $row['ean'] );
                $v   = wc_get_product( $r['variation_id'] );
                if ( $v ) {
                    if ( $gen ) {
                        $sku = OEI_SKU_Generator::generate( $ean, $row['kolor'], $row['rozmiar'] );
                        $v->set_sku( $sku );
                        $r['new_sku'] = $sku;
                    } else {
                        $v->set_sku( $ean );
                    }
                    $v->update_meta_data( '_ean', $ean );
                    $v->save();
                }
                $r['message'] = 'Zapisano #' . $r['variation_id'];
                $stats['matched']++;
            } elseif ( $r['status'] === 'skip' ) { $stats['skipped']++;
            } else { $stats['not_found']++; }
            $log[] = $r;
        }

        set_transient( 'oei_results', array( 'log' => $log, 'stats' => $stats, 'generate_sku' => $gen ? 1 : 0 ), 300 );
        wp_safe_redirect( admin_url( 'admin.php?page=oei-import' ) ); exit;
    }

    // ═══════════════════════════════════════════════
    // TAB 2: Bulk generate SKU
    // ═══════════════════════════════════════════════

    public function page_bulk_sku() {
        $preview = $this->pop_transient( 'oei_bulk_preview' );
        $results = $this->pop_transient( 'oei_bulk_results' );
        ?>
        <div class="wrap oei-wrap">
            <h1>Generuj SKU dla istniejacych EAN</h1>
            <div class="oei-card">
                <h2>Jak to dziala</h2>
                <p>Szuka wariantow z EAN (w <code>_ean</code> lub czysty numer jako SKU) i generuje <strong>EAN-KOLOR-ROZMIAR</strong>. Pomija warianty z juz zlozonym SKU.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'oei_action', 'oei_nonce' ); ?>
                    <input type="hidden" name="action" value="oei_bulk_sku_preview">
                    <p><button type="submit" class="button button-secondary">Podglad (dry run)</button></p>
                </form>
            </div>
            <?php if ( $preview ) : ?>
                <?php $this->bulk_sku_card( $preview, true ); ?>
            <?php endif; ?>
            <?php if ( $results ) : ?>
                <?php $this->bulk_sku_card( $results, false ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function bulk_sku_card( $data, $is_preview ) {
        ?>
        <div class="oei-card">
            <h2><?php echo $is_preview ? 'Podglad (dry run)' : 'Wynik'; ?></h2>
            <?php $this->render_stats( $data['stats'], array( 'total', 'generated', 'skipped' ) ); ?>
            <?php $this->bulk_sku_table( $data['log'] ); ?>
            <?php if ( $is_preview && $data['stats']['generated'] > 0 ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'oei_action', 'oei_nonce' ); ?>
                <input type="hidden" name="action" value="oei_bulk_sku_run">
                <p style="margin-top:16px">
                    <button type="submit" class="button button-primary button-hero"
                        onclick="return confirm('Wygenerowac <?php echo intval( $data['stats']['generated'] ); ?> SKU?');">
                        Generuj <?php echo intval( $data['stats']['generated'] ); ?> SKU
                    </button>
                </p>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private function bulk_sku_table( $log ) {
        if ( empty( $log ) ) { echo '<p>Brak wariantow z EAN.</p>'; return; }
        ?>
        <table class="oei-table"><thead><tr>
            <th>#</th><th>Produkt</th><th>Wariant</th><th>EAN</th><th>Stary SKU</th><th>Nowy SKU</th><th>Status</th>
        </tr></thead><tbody>
        <?php $i = 1; foreach ( $log as $e ) : ?>
        <tr>
            <td><?php echo $i++; ?></td>
            <td><?php echo esc_html( $e['product'] ); ?></td>
            <td>#<?php echo intval( $e['variation_id'] ); ?></td>
            <td><code><?php echo esc_html( $e['ean'] ); ?></code></td>
            <td><code><?php echo esc_html( $e['old_sku'] ); ?></code></td>
            <td><code><?php echo esc_html( $e['new_sku'] ); ?></code></td>
            <td><?php echo $this->status_badge( $e['status'] ); ?></td>
        </tr>
        <?php endforeach; ?></tbody></table>
        <?php
    }

    public function handle_bulk_sku_preview() {
        $this->verify();
        set_transient( 'oei_bulk_preview', OEI_SKU_Generator::bulk_generate( true ), 300 );
        wp_safe_redirect( admin_url( 'admin.php?page=oei-bulk-sku' ) ); exit;
    }
    public function handle_bulk_sku_run() {
        $this->verify();
        set_transient( 'oei_bulk_results', OEI_SKU_Generator::bulk_generate( false ), 300 );
        wp_safe_redirect( admin_url( 'admin.php?page=oei-bulk-sku' ) ); exit;
    }

    // ═══════════════════════════════════════════════
    // TAB 3: Move EAN to parent product
    // ═══════════════════════════════════════════════

    public function page_move_ean() {
        $preview = $this->pop_transient( 'oei_move_preview' );
        $results = $this->pop_transient( 'oei_move_results' );
        ?>
        <div class="wrap oei-wrap">
            <h1>Przenies EAN na produkt glowny</h1>
            <div class="oei-card">
                <h2>Jak to dziala</h2>
                <p>Dla kazdego produktu zmiennego: pobiera EAN z wariantow, ustawia go na produkcie glownym (meta <code>_ean</code>),
                   a z wariantow usuwa <code>_ean</code>. <strong>SKU wariantow pozostaje bez zmian.</strong></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'oei_action', 'oei_nonce' ); ?>
                    <input type="hidden" name="action" value="oei_move_preview">
                    <p><button type="submit" class="button button-secondary">Podglad (dry run)</button></p>
                </form>
            </div>
            <?php if ( $preview ) : ?>
                <?php $this->move_card( $preview, true ); ?>
            <?php endif; ?>
            <?php if ( $results ) : ?>
                <?php $this->move_card( $results, false ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function move_card( $data, $is_preview ) {
        $s = $data['stats'];
        ?>
        <div class="oei-card">
            <h2><?php echo $is_preview ? 'Podglad (dry run)' : 'Wynik'; ?></h2>
            <div class="oei-stats">
                <div class="oei-stat oei-s-total">Produktow: <?php echo intval( $s['products_total'] ); ?></div>
                <div class="oei-stat oei-s-ok">Do przeniesienia: <?php echo intval( $s['products_updated'] ); ?></div>
                <div class="oei-stat oei-s-skip">Pominiete: <?php echo intval( $s['products_skipped'] ); ?></div>
                <div class="oei-stat oei-s-total">Wariantow do aktualizacji: <?php echo intval( $s['variations_updated'] ); ?></div>
            </div>
            <?php $this->move_table( $data['log'] ); ?>
            <?php if ( $is_preview && $s['products_updated'] > 0 ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'oei_action', 'oei_nonce' ); ?>
                <input type="hidden" name="action" value="oei_move_run">
                <p style="margin-top:16px">
                    <button type="submit" class="button button-primary button-hero"
                        onclick="return confirm('Przeniesc EAN dla <?php echo intval( $s['products_updated'] ); ?> produktow?');">
                        Przenies EAN (<?php echo intval( $s['products_updated'] ); ?> produktow)
                    </button>
                </p>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private function move_table( $log ) {
        if ( empty( $log ) ) { echo '<p>Brak produktow z EAN w wariantach.</p>'; return; }
        ?>
        <table class="oei-table"><thead><tr>
            <th>#</th><th>Produkt</th><th>ID</th><th>EAN</th><th>Mial EAN?</th><th>Wariantow</th><th>Status</th><th>Info</th>
        </tr></thead><tbody>
        <?php $i = 1; foreach ( $log as $e ) : ?>
        <tr>
            <td><?php echo $i++; ?></td>
            <td><?php echo esc_html( $e['product_name'] ); ?></td>
            <td>#<?php echo intval( $e['product_id'] ); ?></td>
            <td><code><?php echo esc_html( $e['ean'] ); ?></code></td>
            <td><?php echo esc_html( $e['parent_had_ean'] ); ?></td>
            <td><?php echo intval( $e['variants_count'] ); ?></td>
            <td><?php echo $this->status_badge( $e['status'] ); ?></td>
            <td><?php echo esc_html( $e['message'] ); ?></td>
        </tr>
        <?php endforeach; ?></tbody></table>
        <?php
    }

    public function handle_move_preview() {
        $this->verify();
        $data = OEI_EAN_Mover::run( true );
        set_transient( 'oei_move_preview', $data, 300 );
        wp_safe_redirect( admin_url( 'admin.php?page=oei-move-ean' ) ); exit;
    }

    public function handle_move_run() {
        $this->verify();
        $data = OEI_EAN_Mover::run( false );
        set_transient( 'oei_move_results', $data, 300 );
        wp_safe_redirect( admin_url( 'admin.php?page=oei-move-ean' ) ); exit;
    }

    // ═══════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════

    private function render_stats( $s, $keys ) {
        $labels = array(
            'total' => 'Razem', 'matched' => 'OK', 'skipped' => 'Pominiete',
            'not_found' => 'Bledy', 'generated' => 'Do generowania',
        );
        $classes = array(
            'total' => 'oei-s-total', 'matched' => 'oei-s-ok', 'skipped' => 'oei-s-skip',
            'not_found' => 'oei-s-err', 'generated' => 'oei-s-ok',
        );
        echo '<div class="oei-stats">';
        foreach ( $keys as $k ) {
            $cls = isset( $classes[ $k ] ) ? $classes[ $k ] : 'oei-s-total';
            $lbl = isset( $labels[ $k ] ) ? $labels[ $k ] : $k;
            echo '<div class="oei-stat ' . esc_attr( $cls ) . '">' . esc_html( $lbl ) . ': ' . intval( $s[ $k ] ) . '</div>';
        }
        echo '</div>';
    }

    private function status_badge( $status ) {
        if ( $status === 'ok' ) return '<span class="oei-ok">OK</span>';
        if ( $status === 'skip' ) return '<span class="oei-skip">Pominiety</span>';
        return '<span class="oei-err">Blad</span>';
    }

    private function pop_transient( $key ) {
        $val = get_transient( $key );
        if ( $val ) delete_transient( $key );
        return $val;
    }

    private function verify() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Brak uprawnien.' );
        $nonce = isset( $_POST['oei_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['oei_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'oei_action' ) ) wp_die( 'Blad nonce.' );
    }
}
