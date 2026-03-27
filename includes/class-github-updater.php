<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Self-update from GitHub releases.
 * Checks github.com/aleksanderem/butikvoga for new releases.
 */
class OEI_GitHub_Updater {

    private $slug    = 'olavoga-ean-importer';
    private $repo    = 'aleksanderem/butikvoga';
    private $file;
    private $version;

    public function __construct( $plugin_file ) {
        $this->file    = plugin_basename( $plugin_file );
        $this->version = OEI_VERSION;

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
        add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );
    }

    private function get_release() {
        $transient_key = 'oei_github_release';
        $release = get_transient( $transient_key );

        if ( $release !== false ) {
            return $release;
        }

        $url = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
        $response = wp_remote_get( $url, array(
            'headers' => array( 'Accept' => 'application/vnd.github.v3+json' ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return false;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $release['tag_name'] ) ) {
            return false;
        }

        set_transient( $transient_key, $release, 6 * HOUR_IN_SECONDS );
        return $release;
    }

    private function get_version_from_release( $release ) {
        $tag = $release['tag_name'];
        return ltrim( $tag, 'vV' );
    }

    private function get_zip_url( $release ) {
        // Check for uploaded asset first
        if ( ! empty( $release['assets'] ) ) {
            foreach ( $release['assets'] as $asset ) {
                if ( strpos( $asset['name'], '.zip' ) !== false ) {
                    return $asset['browser_download_url'];
                }
            }
        }
        // Fallback to source zip
        return $release['zipball_url'];
    }

    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_release();
        if ( ! $release ) {
            return $transient;
        }

        $remote_version = $this->get_version_from_release( $release );

        if ( version_compare( $remote_version, $this->version, '>' ) ) {
            $transient->response[ $this->file ] = (object) array(
                'slug'        => $this->slug,
                'plugin'      => $this->file,
                'new_version' => $remote_version,
                'url'         => 'https://github.com/' . $this->repo,
                'package'     => $this->get_zip_url( $release ),
            );
        }

        return $transient;
    }

    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' || $args->slug !== $this->slug ) {
            return $result;
        }

        $release = $this->get_release();
        if ( ! $release ) {
            return $result;
        }

        return (object) array(
            'name'          => 'Olavoga EAN Importer',
            'slug'          => $this->slug,
            'version'       => $this->get_version_from_release( $release ),
            'author'        => 'Kolabo IT',
            'homepage'      => 'https://github.com/' . $this->repo,
            'download_link' => $this->get_zip_url( $release ),
            'sections'      => array(
                'description' => isset( $release['body'] ) ? $release['body'] : 'Import EAN + generate SKU for WooCommerce.',
                'changelog'   => isset( $release['body'] ) ? $release['body'] : '',
            ),
        );
    }

    /**
     * After install, rename folder from GitHub archive name to plugin slug.
     */
    public function after_install( $response, $hook_extra, $result ) {
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->file ) {
            return $result;
        }

        global $wp_filesystem;
        $install_dir = $result['destination'];
        $proper_dir  = WP_PLUGIN_DIR . '/' . $this->slug;

        $wp_filesystem->move( $install_dir, $proper_dir );
        $result['destination'] = $proper_dir;

        // Re-activate
        if ( is_plugin_active( $this->file ) ) {
            activate_plugin( $this->file );
        }

        return $result;
    }
}
