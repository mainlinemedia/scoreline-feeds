<?php
/**
 * Plugin Name: Scoreline Feeds
 * Plugin URI:  https://mainlinemediagroup.com
 * Description: Multi-network syndication feeds (Aigeon raw feed, NewsBreak, MSN, Yahoo) with per-network compliance profiles. Replaces both "MMG Raw Feed" v2.x and "NewsBreak RSS Feed" v1.x — deactivate those before activating this.
 * Version:     3.3.0
 * Author:      Mainline Media Group
 * License:     GPL2
 * Update URI:  https://github.com/mainlinemedia/scoreline-feeds
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MMGRF_VERSION', '3.3.0' );
define( 'MMGRF_DIR', __DIR__ );

require_once MMGRF_DIR . '/includes/emitter.php';
require_once MMGRF_DIR . '/includes/sanitizer.php';
require_once MMGRF_DIR . '/includes/validator.php';
require_once MMGRF_DIR . '/profiles/class-profile-base.php';
require_once MMGRF_DIR . '/profiles/class-profile-aigeon.php';
require_once MMGRF_DIR . '/profiles/class-profile-newsbreak.php';
require_once MMGRF_DIR . '/profiles/class-profile-msn.php';
require_once MMGRF_DIR . '/profiles/class-profile-yahoo.php';
require_once MMGRF_DIR . '/includes/options.php';
require_once MMGRF_DIR . '/includes/pipeline.php';
require_once MMGRF_DIR . '/includes/admin.php';
require_once MMGRF_DIR . '/includes/syndication-check.php';
require_once MMGRF_DIR . '/includes/updater.php';

// ─────────────────────────────────────────────────────────────────
// FEED REGISTRATION
// Legacy {slug}-raw-feed routes (aigeon profile) are unconditional —
// back-compat contract (§6). Network routes register only when enabled.
// ─────────────────────────────────────────────────────────────────

add_action( 'init', 'mmgrf_register_all_feeds' );
function mmgrf_register_all_feeds() {

    $opts = mmgrf_get_options();

    // Legacy main feed
    add_feed( $opts['feed_slug'] . '-raw-feed', function() use ( $opts ) {
        mmgrf_output_network_feed( 'aigeon', [
            'categories'   => $opts['categories'],
            'exclude_cats' => $opts['exclude_cats'],
            'tags'         => $opts['tags'],
            'exclude_tags' => $opts['exclude_tags'],
        ] );
    } );

    // Legacy tag feeds (aigeon-shaped, per v2 behavior)
    foreach ( mmgrf_get_tag_feeds() as $tf ) {
        if ( empty( $tf['slug'] ) ) {
            continue;
        }
        add_feed( $tf['slug'] . '-raw-feed', function() use ( $tf ) {
            $net_opts = mmgrf_network_options( 'aigeon' );
            $net_opts['feed_slug']  = $tf['slug'];
            $net_opts['feed_url']   = mmgrf_feed_url( $tf['slug'] );
            $net_opts['feed_title'] = ! empty( $tf['title'] ) ? $tf['title'] : $net_opts['feed_title'];
            foreach ( [ 'utm_source', 'utm_medium', 'post_count', 'image_size' ] as $k ) {
                if ( ! empty( $tf[ $k ] ) ) {
                    $net_opts[ $k ] = $tf[ $k ];
                }
            }
            mmgrf_output_network_feed_with_opts( 'aigeon', $net_opts, [ 'tags' => $tf['tags'] ?? '' ] );
        } );
    }

    // Network feeds
    foreach ( mmgrf_get_network_config() as $network => $net ) {
        if ( empty( $net['enabled'] ) ) {
            continue;
        }
        // Zero-downtime migration: while the standalone NewsBreak plugin still
        // owns ?feed=newsbreak, don't fight it for the route. Validate the v3
        // output on a parallel slug (e.g. newsbreak-v3), then retire the old
        // plugin and switch the slug back.
        if ( $net['slug'] === 'newsbreak' && class_exists( 'NewsBreak_RSS_Feed' ) ) {
            continue;
        }
        add_feed( $net['slug'], function() use ( $network ) {
            mmgrf_output_network_feed( $network );
        } );
    }

    if ( get_transient( 'mmgrf_flush_rewrites' ) ) {
        flush_rewrite_rules( false );
        delete_transient( 'mmgrf_flush_rewrites' );
    }
}

/** Variant used by tag feeds where options are pre-resolved. */
function mmgrf_output_network_feed_with_opts( $network, $opts, $filters = [] ) {
    if ( mmgrf_feed_headers_and_maybe_304() ) {
        return;
    }
    echo mmgrf_render_feed( mmgrf_get_profile( $network ), $opts, $filters );
}

// ─────────────────────────────────────────────────────────────────
// DEFAULT-FEED ENHANCEMENT (ported from NewsBreak RSS Feed v1.3.0)
// The standalone plugin silently enhanced /feed/ output; consumers may
// depend on it. Preserved behind enhance_default_feeds (default ON).
// ─────────────────────────────────────────────────────────────────

add_action( 'init', 'mmgrf_register_default_feed_enhancement', 2 );
function mmgrf_register_default_feed_enhancement() {
    if ( class_exists( 'NewsBreak_RSS_Feed' ) ) {
        return; // the standalone plugin already enhances /feed/ — never double up
    }
    $opts = mmgrf_get_options();
    if ( empty( $opts['enhance_default_feeds'] ) ) {
        return;
    }
    add_action( 'rss2_ns', 'mmgrf_default_feed_media_ns' );
    add_action( 'rss2_item', 'mmgrf_default_feed_media_tags' );
    add_filter( 'the_content_feed', 'mmgrf_default_feed_prepend_image', 1 );
    add_filter( 'the_content_feed', 'mmgrf_default_feed_clean_content', 999 );
}

/**
 * The rss2_ns / rss2_item / the_content_feed hooks fire inside ANY feed built
 * on the core rss2 template — including third-party custom feeds (a legacy
 * NewsBreak plugin on gamedayatlanta reuses it, and our unscoped callbacks
 * were injecting a duplicate lead figure and a jammed xmlns:media attribute
 * into its output). Enhance only WordPress's own core feeds.
 */
function mmgrf_is_core_feed() {
    if ( ! is_feed() ) {
        return false;
    }
    $feed = get_query_var( 'feed' );
    return in_array( $feed, [ 'feed', 'rss2', 'rss', 'atom', 'rdf', get_default_feed() ], true );
}

function mmgrf_default_feed_media_ns() {
    if ( ! mmgrf_is_core_feed() ) {
        return;
    }
    // Leading space: rss2_ns output from multiple plugins is concatenated
    // verbatim — without it, adjacent echoes jam into invalid XML.
    echo ' xmlns:media="http://search.yahoo.com/mrss/"' . "\n";
}

function mmgrf_default_feed_media_tags() {
    global $post;
    if ( ! mmgrf_is_core_feed() ) {
        return;
    }
    if ( ! $post || ! has_post_thumbnail( $post->ID ) ) {
        return;
    }
    $thumb_id = get_post_thumbnail_id( $post->ID );
    $image    = wp_get_attachment_image_src( $thumb_id, 'large' );
    if ( ! $image ) {
        return;
    }
    $mime = get_post_mime_type( $thumb_id ) ?: 'image/jpeg';
    $file = get_attached_file( $thumb_id );
    $size = ( $file && file_exists( $file ) ) ? filesize( $file ) : 100000;
    echo '<media:thumbnail url="' . mmgrf_xml( $image[0] ) . '" width="' . (int) $image[1] . '" height="' . (int) $image[2] . '" />' . "\n";
    echo '<media:content medium="image" url="' . mmgrf_xml( $image[0] ) . '" width="' . (int) $image[1] . '" height="' . (int) $image[2] . '" type="' . mmgrf_xml( $mime ) . '" />' . "\n";
    echo '<enclosure url="' . mmgrf_xml( $image[0] ) . '" length="' . (int) $size . '" type="' . mmgrf_xml( $mime ) . '" />' . "\n";
}

function mmgrf_default_feed_prepend_image( $content ) {
    global $post;
    if ( ! mmgrf_is_core_feed() ) {
        return $content;
    }
    if ( ! $post || ! has_post_thumbnail( $post->ID ) || preg_match( '/^\s*<figure/i', $content ) ) {
        return $content;
    }
    $thumb_id = get_post_thumbnail_id( $post->ID );
    $url      = wp_get_attachment_image_url( $thumb_id, 'large' );
    if ( ! $url ) {
        return $content;
    }
    $alt    = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) ?: get_the_title( $post->ID );
    $credit = mmgrf_resolve_credit( $post->ID, $thumb_id );
    $figure = '<figure><img src="' . mmgrf_xml( $url ) . '" alt="' . mmgrf_xml( $alt ) . '" />';
    if ( $credit !== '' ) {
        $figure .= '<figcaption>' . mmgrf_xml( $credit ) . '</figcaption>';
    }
    $figure .= '</figure>' . "\n";
    return $figure . $content;
}

function mmgrf_default_feed_clean_content( $content ) {
    if ( ! mmgrf_is_core_feed() ) {
        return $content;
    }
    foreach ( [ 'srcset', 'sizes', 'loading', 'decoding', 'fetchpriority' ] as $attr ) {
        $content = preg_replace( '/\s+' . $attr . '=["\'][^"\']*["\']/i', '', $content );
    }
    return preg_replace( '/\s+data-[a-z0-9_-]+=["\'][^"\']*["\']/i', '', $content );
}

// ─────────────────────────────────────────────────────────────────
// ACTIVATION
// ─────────────────────────────────────────────────────────────────

register_activation_hook( __FILE__, function() {
    mmgrf_register_all_feeds();
    flush_rewrite_rules( false );
} );

register_deactivation_hook( __FILE__, function() {
    flush_rewrite_rules( false );
} );
