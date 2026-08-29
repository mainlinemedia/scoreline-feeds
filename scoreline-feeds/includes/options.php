<?php
/**
 * Options. Back-compat: 'mmgrf_options' and 'mmgrf_tag_feeds' keys unchanged
 * from v2.1.0 — no migration. Network config is a new additive key.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const MMGRF_NETWORKS = [ 'newsbreak', 'msn', 'yahoo' ];

function mmgrf_get_options() {
    $defaults = [
        'feed_slug'             => 'raw-feed',
        'feed_title'            => get_bloginfo( 'name' ),
        'feed_description'      => get_bloginfo( 'description' ),
        'feed_link'             => home_url(),
        'utm_source'            => 'feed',
        'utm_medium'            => 'email',
        'post_count'            => 25, // D8: raised from 10
        'image_size'            => 'large',
        'categories'            => '',
        'exclude_cats'          => '',
        'tags'                  => '',
        'exclude_tags'          => '',
        // v3 additions (additive, safe defaults)
        'exclude_networks_cats' => '',
        'exclude_networks_tags' => '',
        'enhance_default_feeds' => 1, // preserves live NewsBreak-plugin behavior on /feed/
        // Fix 5 seeds. Domain list: operator-owned domains, unambiguous →
        // whole item skipped. Path list: href substrings, weaker signal →
        // link unwrapped. Bare "affiliate" and operator names deliberately
        // excluded from the path list (false-positives on editorial slugs
        // and "Caesars Superdome"-style prose).
        'affiliate_domains'       => 'draftkings.com, fanduel.com, bet365.com, bovada.lv, caesars.com, sportsbook.fanatics.com, betmgm.com, espnbet.com',
        'affiliate_paths'         => 'sportsbook, promo-code, promocode, /betting/, /aff/, ?tag=, utm_campaign=affiliate, /affiliate/, ?affiliate=',
        'affiliate_domain_action' => 'skip_item',
        'affiliate_path_action'   => 'unwrap',
    ];
    $saved = get_option( 'mmgrf_options', [] );
    return wp_parse_args( $saved, $defaults );
}

function mmgrf_get_tag_feeds() {
    return get_option( 'mmgrf_tag_feeds', [] );
}

/** Per-network stored config, merged with defaults. */
function mmgrf_get_network_config() {
    $saved  = get_option( 'mmgrf_networks', [] );
    $config = [];
    foreach ( MMGRF_NETWORKS as $key ) {
        $config[ $key ] = wp_parse_args( $saved[ $key ] ?? [], [
            'enabled'     => false,
            'slug'        => $key,
            'post_count'  => 0,   // 0 = profile default
            'image_size'  => '',  // '' = profile default
            'utm_enabled' => false,
            'nb_scripts'  => '',
        ] );
    }
    return $config;
}

/**
 * Fully-resolved options for one network profile (or 'aigeon' legacy), ready
 * for the pipeline: channel meta + per-network toggles + profile defaults.
 */
function mmgrf_network_options( $network ) {
    $base    = mmgrf_get_options();
    $profile = mmgrf_get_profile( $network );

    // Fix 7: RSS 2.0 requires a non-empty channel <description>. Configured
    // option → site tagline → site name; never empty.
    $description = trim( (string) $base['feed_description'] );
    if ( $description === '' ) {
        $description = trim( (string) get_bloginfo( 'description' ) );
    }
    if ( $description === '' ) {
        $description = (string) get_bloginfo( 'name' );
    }

    $opts = [
        'network'           => $network,
        'feed_title'        => $base['feed_title'],
        'feed_description'  => $description,
        'feed_link'         => $base['feed_link'],
        'utm_source'        => $base['utm_source'],
        'utm_medium'        => $base['utm_medium'],
        'post_count'        => (int) $base['post_count'] ?: $profile->get_default_post_count(),
        'image_size'        => $profile->get_default_image_size(),
        'utm_enabled'       => $profile->utm_default(),
        'nb_scripts'        => '',
        'affiliate_domains'       => array_values( array_filter( array_map( 'trim', explode( ',', (string) $base['affiliate_domains'] ) ) ) ),
        'affiliate_paths'         => array_values( array_filter( array_map( 'trim', explode( ',', (string) $base['affiliate_paths'] ) ) ) ),
        'affiliate_domain_action' => in_array( $base['affiliate_domain_action'], [ 'skip_item', 'unwrap', 'strip_paragraph' ], true ) ? $base['affiliate_domain_action'] : 'skip_item',
        'affiliate_path_action'   => in_array( $base['affiliate_path_action'], [ 'skip_item', 'unwrap', 'strip_paragraph' ], true ) ? $base['affiliate_path_action'] : 'unwrap',
        'exclude_networks_cats' => $base['exclude_networks_cats'],
        'exclude_networks_tags' => $base['exclude_networks_tags'],
    ];

    if ( $network === 'aigeon' ) {
        $opts['feed_slug']  = $base['feed_slug'];
        $opts['feed_url']   = mmgrf_feed_url( $base['feed_slug'] );
        $opts['image_size'] = $base['image_size']; // v2 parity: the saved setting, not the profile default
        return $opts;
    }

    $net = mmgrf_get_network_config()[ $network ];
    $opts['feed_slug']   = $net['slug'];
    $opts['feed_url']    = mmgrf_network_feed_url( $net['slug'] );
    $opts['enabled']     = (bool) $net['enabled'];
    $opts['utm_enabled'] = (bool) $net['utm_enabled'];
    $opts['nb_scripts']  = (string) $net['nb_scripts'];
    if ( (int) $net['post_count'] > 0 ) {
        $opts['post_count'] = min( (int) $net['post_count'], $profile->get_max_items() );
    } else {
        $opts['post_count'] = $profile->get_default_post_count();
    }
    if ( $net['image_size'] !== '' ) {
        $opts['image_size'] = $net['image_size'];
    }
    return $opts;
}

/** Legacy URL builder — unchanged from v2.1.0 (G5 back-compat). */
function mmgrf_feed_url( $slug ) {
    return home_url( '/?feed=' . rawurlencode( $slug ) . '-raw-feed' );
}

/**
 * Fix 8: network self-URL in the form the partner actually polls — pretty
 * permalink when rewrites are on, query-string otherwise. Used for both the
 * feed's atom:link and the admin display so the two always match.
 */
function mmgrf_network_feed_url( $slug ) {
    if ( get_option( 'permalink_structure' ) ) {
        return home_url( '/feed/' . rawurlencode( $slug ) . '/' );
    }
    return home_url( '/?feed=' . rawurlencode( $slug ) );
}

function mmgrf_get_profile( $network ) {
    static $profiles = [];
    if ( ! isset( $profiles[ $network ] ) ) {
        switch ( $network ) {
            case 'newsbreak': $profiles[ $network ] = new MMGRF_Profile_NewsBreak(); break;
            case 'msn':       $profiles[ $network ] = new MMGRF_Profile_MSN();       break;
            case 'yahoo':     $profiles[ $network ] = new MMGRF_Profile_Yahoo();     break;
            default:          $profiles[ $network ] = new MMGRF_Profile_Aigeon();    break;
        }
    }
    return $profiles[ $network ];
}
