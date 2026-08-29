<?php
/**
 * Fix 11 — publish-time syndication warnings.
 *
 * Non-blocking by design: warns, never prevents saving or publishing. Reuses
 * the EXACT functions the feed render uses (mmgrf_attachment_image_data,
 * mmgrf_pick_image_fit, mmgrf_gate_inline_images, mmgrf_guard_affiliate_links)
 * so a clean editor equals a clean feed. Does not write to the skip log —
 * the log records what happened at render time; these are predictions.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Networks with image-policy enforcement that are enabled on this site. */
function mmgrf_syndication_gating() {
    $networks = [];
    foreach ( mmgrf_get_network_config() as $key => $net ) {
        if ( ! empty( $net['enabled'] ) ) {
            $networks[] = $key; // yahoo/msn/newsbreak all gate images
        }
    }
    return [ 'enabled' => $networks !== [], 'networks' => $networks ];
}

function mmgrf_syndication_network_names( $networks ) {
    $names = [ 'yahoo' => 'Yahoo', 'msn' => 'MSN', 'newsbreak' => 'NewsBreak' ];
    return implode( ', ', array_map( fn( $n ) => $names[ $n ] ?? $n, $networks ) );
}

/** Post types the feed pipeline queries (profile config; currently posts). */
function mmgrf_syndication_post_types() {
    return [ 'post' ];
}

/**
 * Run all three checks against saved post state. Severity is 'warning' for
 * every check; 'error' is reserved and unused.
 */
function mmgrf_syndication_check( $post_id ) {
    $gating = mmgrf_syndication_gating();
    $result = [ 'enabled' => $gating['enabled'], 'networks' => $gating['networks'], 'issues' => [] ];
    if ( ! $gating['enabled'] ) {
        return $result;
    }
    $post = get_post( $post_id );
    if ( ! $post || ! in_array( $post->post_type, mmgrf_syndication_post_types(), true ) ) {
        return $result;
    }
    $nets = mmgrf_syndication_network_names( $gating['networks'] );
    $warn = function( $code, $message, $detail = [] ) use ( &$result ) {
        $result['issues'][] = [ 'code' => $code, 'severity' => 'warning', 'message' => $message, 'detail' => $detail ];
    };

    // ── Check A — featured image ─────────────────────────────────
    $thumb_id = get_post_thumbnail_id( $post_id );
    if ( ! $thumb_id ) {
        $warn( 'no_featured_image', "No featured image set. This article will syndicate to {$nets} without an image, which significantly reduces its distribution." );
    } else {
        $data = mmgrf_attachment_image_data( $thumb_id, 'full' );
        if ( $data && ! empty( $data['dims_unknown'] ) ) {
            $warn( 'image_dimensions_unknown', "Featured image size can't be verified. It may be dropped from the {$nets} feed.", [ 'attachment_id' => $thumb_id ] );
        } elseif ( ! $data || ! mmgrf_pick_image_fit( $data, MMGRF_Profile_Yahoo::IMG_MIN_W, MMGRF_Profile_Yahoo::IMG_MIN_H, MMGRF_Profile_Yahoo::IMG_MAX_BYTES ) ) {
            $w = $data ? (int) $data['width'] : 0;
            $h = $data ? (int) $data['height'] : 0;
            $warn( 'image_below_minimum', "Featured image is {$w}×{$h}. Yahoo requires at least 1280×720, so this article will syndicate without an image. Try re-uploading at a larger size.", [ 'width' => $w, 'height' => $h, 'attachment_id' => $thumb_id ] );
        }
    }

    // ── Check B — inline body images (what Fix 9 would strip) ────
    $gated = mmgrf_gate_inline_images( $post->post_content, MMGRF_Profile_Yahoo::IMG_MIN_W, MMGRF_Profile_Yahoo::IMG_MIN_H, MMGRF_Profile_Yahoo::IMG_MAX_BYTES );
    $counts = array_count_values( array_column( $gated['events'], 'code' ) );
    if ( ! empty( $counts['inline_image_below_minimum'] ) ) {
        $n = $counts['inline_image_below_minimum'];
        $warn( 'inline_image_below_minimum', "{$n} image(s) inside the article are below 1280×720 and will be removed from the {$nets} feed. They will still appear on the site.", [ 'count' => $n ] );
    }
    if ( ! empty( $counts['inline_image_unresolvable'] ) ) {
        $n = $counts['inline_image_unresolvable'];
        $warn( 'inline_image_unresolvable', "{$n} image(s) are hotlinked from another site and can't be size-checked. They may be dropped by {$nets}.", [ 'count' => $n ] );
    }

    // ── Check C — affiliate / commerce links ─────────────────────
    $opts  = mmgrf_network_options( 'yahoo' );
    // Dry run: 'unwrap' for both lists so every match is enumerated (skip_item
    // would return after the first); the rewritten HTML is discarded.
    $guard = mmgrf_guard_affiliate_links( $post->post_content, $opts['affiliate_domains'], $opts['affiliate_paths'], 'unwrap', 'unwrap' );
    foreach ( $guard['events'] as $ev ) {
        if ( $ev['code'] === 'affiliate_domain_matched' ) {
            preg_match( '/pattern "([^"]+)"/', $ev['detail'], $m );
            $warn( 'affiliate_domain_matched', 'This article links to ' . ( $m[1] ?? 'a betting operator' ) . ". The entire article will be excluded from the {$nets} feed.", [ 'pattern' => $m[1] ?? '' ] );
        } else {
            preg_match( '/in (\S+) —/', $ev['detail'], $m );
            $warn( 'affiliate_path_matched', 'This article contains a promotional link (' . ( $m[1] ?? '' ) . "). The link will be removed from the {$nets} feed; the article will still syndicate.", [ 'url' => $m[1] ?? '' ] );
        }
    }

    return $result;
}

// ── REST endpoint ────────────────────────────────────────────────

add_action( 'rest_api_init', function() {
    register_rest_route( 'mmgrf/v1', '/syndication-check', [
        'methods'             => 'GET',
        'callback'            => function( $request ) {
            return rest_ensure_response( mmgrf_syndication_check( (int) $request['post_id'] ) );
        },
        'args'                => [ 'post_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ] ],
        'permission_callback' => function( $request ) {
            return current_user_can( 'edit_post', (int) $request['post_id'] );
        },
    ] );
} );

// ── Meta box (server-side render works in both editors) ──────────

add_action( 'add_meta_boxes', function() {
    if ( ! mmgrf_syndication_gating()['enabled'] ) {
        return; // no relevant profile enabled → render nothing
    }
    foreach ( mmgrf_syndication_post_types() as $type ) {
        add_meta_box( 'mmgrf_syndication_readiness', 'Syndication readiness', 'mmgrf_syndication_meta_box_cb', $type, 'side', 'default' );
    }
} );

function mmgrf_syndication_meta_box_cb( $post ) {
    $status = get_post_status( $post );
    if ( $status === 'auto-draft' ) {
        echo '<p class="mmgrf-syn-muted" id="mmgrf-syn-panel" data-post="' . (int) $post->ID . '">Save the post to check syndication readiness.</p>';
        return;
    }
    $r = mmgrf_syndication_check( $post->ID );
    echo '<div id="mmgrf-syn-panel" data-post="' . (int) $post->ID . '">';
    echo mmgrf_syndication_panel_html( $r );
    echo '</div>';
}

/** Shared renderer — the JS swaps this same markup in on refresh. */
function mmgrf_syndication_panel_html( $result ) {
    if ( empty( $result['enabled'] ) ) {
        return '';
    }
    if ( empty( $result['issues'] ) ) {
        // Boring on purpose — the panel must be quiet when things are fine.
        return '<p class="mmgrf-syn-ok">✓ Ready for syndication</p>';
    }
    $out = '';
    foreach ( $result['issues'] as $issue ) {
        $out .= '<p class="mmgrf-syn-warn">' . esc_html( $issue['message'] ) . '</p>';
    }
    return $out;
}

// ── Editor assets (no build step: vanilla JS on wp globals) ──────

add_action( 'enqueue_block_editor_assets', function() {
    if ( ! mmgrf_syndication_gating()['enabled'] ) {
        return;
    }
    $base = plugin_dir_url( MMGRF_DIR . '/scoreline-feeds.php' ) . 'assets/';
    wp_enqueue_script( 'mmgrf-syndication-panel', $base . 'syndication-panel.js', [ 'wp-data', 'wp-api-fetch', 'wp-notices' ], MMGRF_VERSION, true );
    wp_enqueue_style( 'mmgrf-syndication-panel', $base . 'syndication-panel.css', [], MMGRF_VERSION );
} );
