<?php
/**
 * Self-updates from GitHub via WordPress's native Update URI mechanism
 * (WP 5.8+). The plugin header declares `Update URI: https://github.com/…`;
 * WordPress then consults the update_plugins_github.com filter during its
 * normal update checks, and our answer comes from a version manifest in the
 * repo. Updates surface in wp-admin → Plugins like any other plugin (and
 * auto-update if enabled per site). No third-party updater library.
 *
 * Trust boundary: whoever can push to the GitHub repo controls the code every
 * site will install. The package URL is additionally pinned to this repo's
 * releases path — a tampered manifest cannot point sites at a foreign host.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const MMGRF_UPDATE_REPO     = 'https://github.com/mainlinemedia/scoreline-feeds';
const MMGRF_UPDATE_MANIFEST = 'https://raw.githubusercontent.com/mainlinemedia/scoreline-feeds/main/manifest.json';
const MMGRF_UPDATE_PKG_PREFIX = 'https://github.com/mainlinemedia/scoreline-feeds/releases/';

add_filter( 'update_plugins_github.com', 'mmgrf_github_update_check', 10, 3 );

function mmgrf_github_update_check( $update, $plugin_data, $plugin_file ) {
    if ( $plugin_file !== 'scoreline-feeds/scoreline-feeds.php' ) {
        return $update; // another plugin also updating from github.com
    }
    $info = mmgrf_fetch_update_manifest();
    if ( ! $info || ! version_compare( $info['version'], MMGRF_VERSION, '>' ) ) {
        return $update;
    }
    return [
        'id'      => 'github.com/mainlinemedia/scoreline-feeds',
        'slug'    => 'scoreline-feeds',
        'plugin'  => $plugin_file,
        'version' => $info['version'],
        'url'     => MMGRF_UPDATE_REPO,
        'package' => $info['package'],
    ];
}

/** Fetch + validate manifest.json, cached 6h. Returns ['version','package'] or null. */
function mmgrf_fetch_update_manifest() {
    $cached = get_transient( 'mmgrf_update_manifest' );
    if ( is_array( $cached ) ) {
        return $cached ?: null;
    }
    $resp = wp_remote_get( MMGRF_UPDATE_MANIFEST, [ 'timeout' => 10 ] );
    $info = null;
    if ( ! is_wp_error( $resp ) ) {
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( is_array( $data )
            && ! empty( $data['version'] ) && is_string( $data['version'] )
            && preg_match( '/^\d+(\.\d+)*$/', $data['version'] )
            && ! empty( $data['package'] ) && is_string( $data['package'] )
            && strpos( $data['package'], MMGRF_UPDATE_PKG_PREFIX ) === 0 ) {
            $info = [ 'version' => $data['version'], 'package' => $data['package'] ];
        }
    }
    // Cache failures as [] too — never hammer GitHub from a broken state.
    set_transient( 'mmgrf_update_manifest', $info ?: [], 6 * 3600 );
    return $info;
}
