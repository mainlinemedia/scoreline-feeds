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
 * site will install. Within that boundary the manifest is held to one exact
 * shape: the package must be THIS repo's release asset for THAT version —
 * no other host, no other repository (no "../" walk out of the releases
 * path), and no stale package riding under a bumped version number.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const MMGRF_UPDATE_REPO     = 'https://github.com/mainlinemedia/scoreline-feeds';
const MMGRF_UPDATE_MANIFEST = 'https://raw.githubusercontent.com/mainlinemedia/scoreline-feeds/main/manifest.json';
const MMGRF_UPDATE_PKG_PREFIX = 'https://github.com/mainlinemedia/scoreline-feeds/releases/';

add_filter( 'update_plugins_github.com', 'mmgrf_github_update_check', 10, 3 );

function mmgrf_github_update_check( $update, $plugin_data, $plugin_file ) {
    if ( $plugin_file !== MMGRF_PLUGIN_FILE ) {
        return $update; // another plugin also updating from github.com
    }
    $info = mmgrf_fetch_update_manifest();
    if ( ! $info ) {
        return $update;
    }
    // Answer whenever the manifest is valid — even when it is not newer.
    // WordPress compares the versions itself (an equal or older one can
    // never be installed) and only offers the per-site "Enable auto-updates"
    // toggle for plugins it has an answer for. Returning nothing when up to
    // date hid that toggle except while an update happened to be pending.
    return [
        'id'           => 'github.com/mainlinemedia/scoreline-feeds',
        'slug'         => 'scoreline-feeds',
        'plugin'       => $plugin_file,
        'version'      => $info['version'],
        'new_version'  => $info['version'],
        'url'          => MMGRF_UPDATE_REPO,
        'package'      => $info['package'],
        'requires'     => '5.8',
        'requires_php' => '7.4',
    ];
}

/**
 * Validate a decoded manifest. Returns ['version','package'] or null.
 * The package URL must be exactly
 *   {repo}/releases/download/v{version}/scoreline-feeds-{version}.zip
 */
function mmgrf_validate_update_manifest( $data ) {
    if ( ! is_array( $data ) || ! isset( $data['version'], $data['package'] )
        || ! is_string( $data['version'] ) || ! is_string( $data['package'] ) ) {
        return null;
    }
    if ( ! preg_match( '/\A\d+(?:\.\d+){1,3}\z/', $data['version'] ) ) {
        return null;
    }
    $expected = MMGRF_UPDATE_PKG_PREFIX . 'download/v' . $data['version'] . '/scoreline-feeds-' . $data['version'] . '.zip';
    if ( $data['package'] !== $expected ) {
        return null;
    }
    return [ 'version' => $data['version'], 'package' => $data['package'] ];
}

/**
 * Fetch + validate manifest.json. A good answer is cached 6 hours; a failure
 * only 15 minutes (one GitHub blip must not hide a release for a quarter of
 * a day). "Check again" on Dashboard → Updates bypasses the cache.
 */
function mmgrf_fetch_update_manifest() {
    $forced = ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' );
    $cached = $forced ? false : get_transient( 'mmgrf_update_manifest' );
    if ( is_array( $cached ) ) {
        return $cached ?: null;
    }
    $resp = wp_remote_get( MMGRF_UPDATE_MANIFEST, [ 'timeout' => 10 ] );
    $info = is_wp_error( $resp ) ? null : mmgrf_validate_update_manifest( json_decode( (string) wp_remote_retrieve_body( $resp ), true ) );
    set_transient( 'mmgrf_update_manifest', $info ?: [], $info ? 6 * 3600 : 900 );
    return $info;
}

/**
 * "View details" in the Plugins list asks wordpress.org for the slug by
 * default — which answers "Plugin not found" (or, one day, with a stranger's
 * plugin of the same name). Answer it ourselves.
 */
add_filter( 'plugins_api', 'mmgrf_plugins_api', 10, 3 );
function mmgrf_plugins_api( $result, $action, $args ) {
    if ( $action !== 'plugin_information' || ! is_object( $args ) || ( $args->slug ?? '' ) !== 'scoreline-feeds' ) {
        return $result;
    }
    $info = mmgrf_fetch_update_manifest();
    return (object) [
        'name'          => 'Scoreline Feeds',
        'slug'          => 'scoreline-feeds',
        'version'       => $info['version'] ?? MMGRF_VERSION,
        'author'        => 'Mainline Media Group',
        'homepage'      => MMGRF_UPDATE_REPO,
        'requires'      => '5.8',
        'requires_php'  => '7.4',
        'download_link' => $info['package'] ?? '',
        'sections'      => [
            'description' => '<p>Multi-network syndication feeds (Aigeon raw feed, NewsBreak, MSN, Yahoo) with per-network compliance profiles, plus slideshow and video feeds for Yahoo and MSN.</p>',
            'changelog'   => '<p>Release notes for every version: <a href="' . esc_url( MMGRF_UPDATE_REPO . '/releases' ) . '" target="_blank" rel="noopener">' . esc_html( MMGRF_UPDATE_REPO . '/releases' ) . '</a></p>',
        ],
    ];
}
