<?php
/**
 * Operational health: the misconfigurations that have actually taken feeds
 * down on this fleet, surfaced where an operator will see them — the
 * settings page, a site-wide notice for the fatal ones, and Tools → Site
 * Health — instead of being discovered when a partner reports an empty feed.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Folder/file WordPress must know this plugin as for updates to reach it. */
const MMGRF_PLUGIN_FILE = 'scoreline-feeds/scoreline-feeds.php';

/** PHP extensions the feed renderer cannot work without. */
function mmgrf_missing_requirements() {
    $missing = [];
    if ( ! class_exists( 'DOMDocument' ) ) {
        $missing[] = 'the PHP "dom" extension';
    }
    if ( ! function_exists( 'mb_substr' ) || ! function_exists( 'mb_strrpos' ) ) {
        $missing[] = 'the PHP "mbstring" extension';
    }
    return (array) apply_filters( 'mmgrf_missing_requirements', $missing );
}

/**
 * Current problems, most serious first.
 * Each: ['level' => 'error'|'warning', 'code' => string, 'message' => string].
 */
function mmgrf_health_problems() {
    $problems = [];

    foreach ( mmgrf_missing_requirements() as $what ) {
        $problems[] = [
            'level'   => 'error',
            'code'    => 'missing_extension',
            'message' => 'This server is missing ' . $what . '. Syndication feeds cannot be generated until the host enables it.',
        ];
    }

    $networks = mmgrf_get_network_config();
    $enabled  = array_filter( $networks, fn( $net ) => ! empty( $net['enabled'] ) );

    if ( $enabled && ! get_option( 'permalink_structure' ) ) {
        $problems[] = [
            'level'   => 'warning',
            'code'    => 'plain_permalinks',
            'message' => 'Permalinks are set to "Plain", so feeds are only served at the ?feed= URLs shown below. A partner given a /feed/…/ URL will get the home page instead. Choose any other structure under Settings → Permalinks.',
        ];
    }

    if ( ! empty( $networks['newsbreak']['enabled'] ) && $networks['newsbreak']['slug'] === 'newsbreak' && mmgrf_legacy_newsbreak_active() ) {
        $problems[] = [
            'level'   => 'warning',
            'code'    => 'legacy_newsbreak_active',
            'message' => 'The old "NewsBreak RSS Feed" plugin is still active and owns the newsbreak feed URL, so the Scoreline NewsBreak feed is NOT being served. Deactivate the old plugin — or give this feed a different slug to compare the two side by side first.',
        ];
    }

    $installed_as = plugin_basename( MMGRF_DIR . '/scoreline-feeds.php' );
    if ( $installed_as !== MMGRF_PLUGIN_FILE ) {
        $problems[] = [
            'level'   => 'warning',
            'code'    => 'wrong_folder',
            'message' => 'This plugin is installed in the folder "' . dirname( $installed_as ) . '" instead of "scoreline-feeds", so automatic updates cannot reach it. Delete it and install the official release zip (settings are kept).',
        ];
    }

    return $problems;
}

// Fatal problems get a notice on every admin screen, for administrators.
add_action( 'admin_notices', 'mmgrf_admin_health_notice' );
function mmgrf_admin_health_notice() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    foreach ( mmgrf_health_problems() as $problem ) {
        if ( $problem['level'] === 'error' ) {
            echo '<div class="notice notice-error"><p><strong>Scoreline Feeds:</strong> ' . esc_html( $problem['message'] ) . '</p></div>';
        }
    }
}

add_filter( 'site_status_tests', 'mmgrf_site_status_tests' );
function mmgrf_site_status_tests( $tests ) {
    $tests['direct']['mmgrf_feeds'] = [
        'label' => 'Scoreline syndication feeds',
        'test'  => 'mmgrf_site_health_test',
    ];
    return $tests;
}

function mmgrf_site_health_test() {
    $problems = mmgrf_health_problems();
    $slugs    = mmgrf_registered_feed_slugs();
    $result   = [
        'label'       => 'Scoreline syndication feeds are configured correctly',
        'status'      => 'good',
        'badge'       => [ 'label' => 'Syndication', 'color' => 'blue' ],
        'description' => '<p>Scoreline Feeds ' . esc_html( MMGRF_VERSION ) . ' is serving ' . count( $slugs ) . ' feed route(s).</p>',
        'actions'     => '<p><a href="' . esc_url( admin_url( 'options-general.php?page=scoreline-feeds' ) ) . '">Scoreline Feeds settings</a></p>',
        'test'        => 'mmgrf_feeds',
    ];
    if ( ! $problems ) {
        return $result;
    }
    $has_error             = (bool) array_filter( $problems, fn( $p ) => $p['level'] === 'error' );
    $result['status']      = $has_error ? 'critical' : 'recommended';
    $result['label']       = $has_error ? 'Scoreline syndication feeds cannot be generated' : 'Scoreline syndication feeds need attention';
    $result['description'] = '<ul><li>' . implode( '</li><li>', array_map( fn( $p ) => esc_html( $p['message'] ), $problems ) ) . '</li></ul>';
    return $result;
}
