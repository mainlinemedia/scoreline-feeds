<?php
/**
 * The Syndication box in the post editor.
 *
 * Governing rule (Austin, 2026-11-24): a writer never has to do anything for
 * an article to be syndicated. Publishing IS the action; nothing in this box
 * is a step. It only reports — per network — what will happen, and why, so
 * that an article which will not be taken by a network is seen at the desk
 * instead of discovered later. Opting an article OUT is the only control,
 * and it sits behind a closed "Options" disclosure so it cannot be ticked by
 * accident.
 *
 * The verdicts reuse the EXACT functions the feed render uses, on the same
 * input the feed sees (the rendered body, through the network's own
 * sanitizer rules), so a clean box equals a clean feed. Nothing here writes
 * to the skip log — the log records what happened at render time; these are
 * predictions.
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

function mmgrf_network_label( $network ) {
    $names = [ 'yahoo' => 'Yahoo', 'msn' => 'MSN', 'newsbreak' => 'NewsBreak', 'aigeon' => 'Email feed' ];
    $base  = (string) $network;
    $kind  = '';
    foreach ( [ '-slideshows' => ' slideshows', '-videos' => ' videos' ] as $suffix => $text ) {
        if ( substr( $base, -strlen( $suffix ) ) === $suffix ) {
            $base = substr( $base, 0, -strlen( $suffix ) );
            $kind = $text;
        }
    }
    if ( strpos( $base, 'aigeon:' ) === 0 ) {
        return 'Email feed (' . substr( $base, 7 ) . ')';
    }
    return ( $names[ $base ] ?? ucfirst( $base ) ) . $kind;
}

function mmgrf_syndication_network_names( $networks ) {
    return implode( ', ', array_map( 'mmgrf_network_label', $networks ) );
}

/** Post types the feed pipeline queries (profile config; currently posts). */
function mmgrf_syndication_post_types() {
    return [ 'post' ];
}

/**
 * The body as the feed will see it: the_content output, every page of a
 * paginated post. Runs inside the edit screen, so a filter that throws or
 * echoes there must not break the editor — fall back to the raw content.
 */
function mmgrf_syndication_preview_content( $post ) {
    $raw = str_replace( '<!--nextpage-->', "\n\n", (string) $post->post_content );
    try {
        return mmgrf_quiet( function() use ( $post, $raw ) {
            $before          = $GLOBALS['post'] ?? null;
            $GLOBALS['post'] = $post;
            setup_postdata( $post );
            try {
                return mmgrf_utf8( (string) apply_filters( 'the_content', $raw ) );
            } finally {
                $GLOBALS['post'] = $before;
                if ( $before ) {
                    setup_postdata( $before );
                }
            }
        } );
    } catch ( \Throwable $e ) {
        return mmgrf_utf8( $raw );
    }
}

/**
 * Predict what each enabled network will do with a post.
 *
 * Returns:
 *   enabled   bool
 *   networks  [key,…] enabled on this site
 *   issues    [ [code, severity, message, detail, networks, effect], … ]
 *             effect 'blocks'  — that network will not take the article
 *                    'note'    — it will, with something changed or removed
 *   verdicts  [ network => [state, reason] ], state one of
 *             'ok' | 'note' | 'blocked' | 'excluded'
 */
function mmgrf_syndication_check( $post_id ) {
    $gating = mmgrf_syndication_gating();
    $result = [ 'enabled' => $gating['enabled'], 'networks' => $gating['networks'], 'issues' => [], 'verdicts' => [] ];
    if ( ! $gating['enabled'] ) {
        return $result;
    }
    $post = get_post( $post_id );
    if ( ! $post || ! in_array( $post->post_type, mmgrf_syndication_post_types(), true ) ) {
        return $result;
    }
    $on    = array_fill_keys( $gating['networks'], true );
    $nets  = mmgrf_syndication_network_names( $gating['networks'] );
    $typed = array_values( array_intersect( MMGRF_TYPED_NETWORKS, $gating['networks'] ) );
    $warn  = function( $code, $message, $detail = [], $networks = [], $effect = 'note' ) use ( &$result, $on ) {
        $result['issues'][] = [
            'code'     => $code,
            'severity' => 'warning',
            'message'  => $message,
            'detail'   => $detail,
            'networks' => array_values( array_filter( $networks, fn( $n ) => isset( $on[ $n ] ) ) ),
            'effect'   => $effect,
        ];
    };

    $tag_ids      = array_map( fn( $t ) => (int) $t->term_id, get_the_tags( $post_id ) ?: [] );
    $is_slideshow = (bool) array_intersect( $tag_ids, mmgrf_slideshow_marker_ids() );
    $is_video     = ! $is_slideshow && (bool) array_intersect( $tag_ids, mmgrf_video_marker_ids() );
    $content      = mmgrf_syndication_preview_content( $post );

    // ── Check A — featured image ─────────────────────────────────
    // Yahoo ships without an image (reduced reach); NewsBreak needs any
    // image; MSN needs one that clears its own floor.
    $thumb_id = get_post_thumbnail_id( $post_id );
    if ( ! $thumb_id ) {
        // NewsBreak falls back to the first image in the body; MSN cannot.
        $has_inline = stripos( $content, '<img' ) !== false;
        $blocked    = array_values( array_intersect( array_merge( [ 'msn' ], $has_inline ? [] : [ 'newsbreak' ] ), $gating['networks'] ) );
        $message    = $blocked
            ? "No featured image set. NewsBreak and MSN will not take an article without one, and Yahoo distributes it far less ({$nets})."
            : 'No featured image set. This article will syndicate to Yahoo without an image, which significantly reduces its distribution.';
        if ( $blocked ) {
            $warn( 'no_featured_image', $message, [], $blocked, 'blocks' );
            $warn( 'no_featured_image', '', [], [ 'yahoo' ], 'note' ); // verdict carrier
        } else {
            $warn( 'no_featured_image', $message, [], [ 'yahoo' ], 'note' );
        }
    } else {
        $data = mmgrf_attachment_image_data( $thumb_id, 'full' );
        if ( isset( $on['yahoo'] ) ) {
            [ $floor_net, $min_w, $min_h, $max_b ] = [ 'Yahoo', MMGRF_Profile_Yahoo::IMG_MIN_W, MMGRF_Profile_Yahoo::IMG_MIN_H, MMGRF_Profile_Yahoo::IMG_MAX_BYTES ];
        } elseif ( isset( $on['msn'] ) ) {
            [ $floor_net, $min_w, $min_h, $max_b ] = [ 'MSN', MMGRF_Profile_MSN::IMG_MIN_W, MMGRF_Profile_MSN::IMG_MIN_H, MMGRF_Profile_MSN::IMG_MAX_BYTES ];
        } else {
            [ $floor_net, $min_w, $min_h, $max_b ] = [ '', 0, 0, 0 ];
        }
        $msn_fits = $data && mmgrf_pick_image_fit( $data, MMGRF_Profile_MSN::IMG_MIN_W, MMGRF_Profile_MSN::IMG_MIN_H, MMGRF_Profile_MSN::IMG_MAX_BYTES );
        if ( $data && ! empty( $data['file_missing'] ) ) {
            $warn( 'image_file_missing', "The featured image's file is missing from the server (it may have been edited or replaced in the media library). Re-upload it — partners cannot download it.", [ 'attachment_id' => $thumb_id ], [ 'msn', 'newsbreak' ], 'blocks' );
            $warn( 'image_file_missing', '', [], [ 'yahoo' ], 'note' );
        } elseif ( $floor_net !== '' && $data && ! empty( $data['dims_unknown'] ) ) {
            $warn( 'image_dimensions_unknown', "Featured image size can't be verified, so {$floor_net} will not receive it. Re-upload the image through the media library.", [ 'attachment_id' => $thumb_id ], [ 'msn' ], 'blocks' );
            $warn( 'image_dimensions_unknown', '', [], [ 'yahoo' ], 'note' );
        } elseif ( $floor_net !== '' && ( ! $data || ! mmgrf_pick_image_fit( $data, $min_w, $min_h, $max_b ) ) ) {
            $w = $data ? (int) $data['width'] : 0;
            $h = $data ? (int) $data['height'] : 0;
            $consequence = $floor_net === 'MSN' ? 'so MSN will not take this article' : 'so this article will syndicate to Yahoo without an image';
            $warn( 'image_below_minimum', "Featured image is {$w}×{$h}. {$floor_net} requires at least {$min_w}×{$min_h}, {$consequence}. Try re-uploading at a larger size.", [ 'width' => $w, 'height' => $h, 'attachment_id' => $thumb_id ], $floor_net === 'MSN' ? [ 'msn' ] : [ 'yahoo' ], $floor_net === 'MSN' ? 'blocks' : 'note' );
            if ( $floor_net === 'Yahoo' && ! $msn_fits ) {
                $warn( 'image_below_minimum', '', [], [ 'msn' ], 'blocks' );
            }
        }
        if ( isset( $on['msn'] ) && get_post_meta( $thumb_id, '_mmgrf_no_syndication_rights', true ) ) {
            $warn( 'no_syndication_rights', 'The featured image is marked as not cleared for syndication, so MSN will not take this article.', [ 'attachment_id' => $thumb_id ], [ 'msn' ], 'blocks' );
        }
    }

    // ── Slideshow / video posts: on Yahoo and MSN they ship ONLY through
    //    their typed feed (NewsBreak takes them as ordinary articles) ──────
    $typed_post = false;
    if ( $typed && $is_slideshow ) {
        $typed_post  = true;
        $typed_names = mmgrf_syndication_network_names( $typed );
        $parsed      = mmgrf_parse_slideshow( $content );
        if ( $parsed['layout'] === 'ambiguous' ) {
            $warn( 'slideshow_layout_ambiguous', "This post is tagged as a slideshow, but it is not clear which photo belongs to which heading, so it will not syndicate to {$typed_names}. Put each photo directly under its heading.", [], $typed, 'blocks' );
        } else {
            $n = count( $parsed['slides'] );
            foreach ( $typed as $net ) {
                $need = MMGRF_SLIDESHOW_MIN_SLIDES[ $net ] ?? 3;
                if ( $n < $need ) {
                    $need_all = max( array_map( fn( $t ) => MMGRF_SLIDESHOW_MIN_SLIDES[ $t ] ?? 3, $typed ) );
                    $first    = ! array_filter( $result['issues'], fn( $i ) => $i['code'] === 'slideshow_too_few_slides' );
                    $warn( 'slideshow_too_few_slides', $first ? "This post is tagged as a slideshow but has {$n} slide(s) — a slide is a heading (H2) followed by a photo. It needs at least {$need_all}; until then it will not syndicate to {$typed_names} at all." : '', [ 'slides' => $n ], [ $net ], 'blocks' );
                }
            }
        }
    } elseif ( $typed && $is_video ) {
        $typed_post  = true;
        $typed_names = mmgrf_syndication_network_names( $typed );
        $video       = mmgrf_resolve_post_video( $post_id, $content );
        if ( ! $video ) {
            $warn( 'video_missing', "This post is tagged as a video but has no uploaded video file, so it will not syndicate to {$typed_names} at all. Add the video, or remove the tag to send it as a normal article.", [], $typed, 'blocks' );
        } elseif ( $video['url'] === '' ) {
            $warn( 'video_format_unsupported', ! empty( $video['foreign'] )
                ? "The video is hosted on another site. Only files uploaded to this site's media library can be syndicated to {$typed_names}."
                : "The video is not an MP4 or MOV file, so it cannot be syndicated to {$typed_names}.", [], $typed, 'blocks' );
        }
    }

    // ── Yahoo: body as its profile will ship it ──────────────────
    if ( isset( $on['yahoo'] ) && ! $typed_post ) {
        $opts  = mmgrf_network_options( 'yahoo' );
        $clean = mmgrf_sanitize( $content, mmgrf_get_profile( 'yahoo' )->get_sanitizer_rules() );

        // Check C — affiliate / commerce links. Dry run with 'unwrap' so
        // every match is enumerated (skip_item returns after the first); the
        // message states the action actually configured.
        $guard = mmgrf_guard_affiliate_links( $clean['html'], $opts['affiliate_domains'], $opts['affiliate_paths'], 'unwrap', 'unwrap' );
        $says  = [
            'skip_item'       => 'The entire article will be excluded from the Yahoo feed.',
            'unwrap'          => 'The link will be removed from the Yahoo feed; the article will still syndicate.',
            'strip_paragraph' => 'The paragraph containing it will be removed from the Yahoo feed; the article will still syndicate.',
        ];
        foreach ( $guard['events'] as $ev ) {
            if ( $ev['code'] === 'affiliate_domain_matched' ) {
                preg_match( '/pattern "([^"]+)"/', $ev['detail'], $m );
                $warn( 'affiliate_domain_matched', 'This article links to ' . ( $m[1] ?? 'a betting operator' ) . '. ' . $says[ $opts['affiliate_domain_action'] ], [ 'pattern' => $m[1] ?? '' ], [ 'yahoo' ], $opts['affiliate_domain_action'] === 'skip_item' ? 'blocks' : 'note' );
            } else {
                preg_match( '/in (\S+) —/', $ev['detail'], $m );
                $warn( 'affiliate_path_matched', 'This article contains a promotional link (' . ( $m[1] ?? '' ) . '). ' . $says[ $opts['affiliate_path_action'] ], [ 'url' => $m[1] ?? '' ], [ 'yahoo' ], $opts['affiliate_path_action'] === 'skip_item' ? 'blocks' : 'note' );
            }
        }

        // Check B — inline body images (what the Fix 9 gate would strip).
        $gated  = mmgrf_process_body_images( $clean['html'], [
            'min_w'     => MMGRF_Profile_Yahoo::IMG_MIN_W,
            'min_h'     => MMGRF_Profile_Yahoo::IMG_MIN_H,
            'max_bytes' => MMGRF_Profile_Yahoo::IMG_MAX_BYTES,
        ] );
        $counts = array_count_values( array_column( $gated['events'], 'code' ) );
        if ( ! empty( $counts['inline_image_below_minimum'] ) ) {
            $n = $counts['inline_image_below_minimum'];
            $warn( 'inline_image_below_minimum', "{$n} image(s) inside the article are below 1280×720 and will be removed from the Yahoo feed. They will still appear on the site.", [ 'count' => $n ], [ 'yahoo' ], 'note' );
        }
        if ( ! empty( $counts['inline_image_unresolvable'] ) ) {
            $n = $counts['inline_image_unresolvable'];
            $warn( 'inline_image_unresolvable', "{$n} image(s) are hotlinked from another site and can't be size-checked. Yahoo may drop them.", [ 'count' => $n ], [ 'yahoo' ], 'note' );
        }

        // Check D — Yahoo's body floor, on what would actually ship.
        $words = mmgrf_count_words( mmgrf_prune_empty_nodes( $gated['html'] ) );
        if ( $words < MMGRF_Profile_Yahoo::BODY_MIN_WORDS ) {
            $warn( 'body_below_word_floor', "The article body is {$words} words. Yahoo requires at least " . MMGRF_Profile_Yahoo::BODY_MIN_WORDS . ', so it will not syndicate to Yahoo.', [ 'words' => $words ], [ 'yahoo' ], 'blocks' );
        }
    }

    // ── MSN: headline length. (Over 150 needs nothing from the writer: a
    //    card title is generated automatically.) ───────────────────────────
    if ( isset( $on['msn'] ) && ! $typed_post ) {
        $len = mb_strlen( mmgrf_text_field( get_the_title( $post_id ) ) );
        if ( $len < MMGRF_Profile_MSN::TITLE_MIN ) {
            $warn( 'title_too_short', "The headline is {$len} characters. MSN requires more than 20, so it will not syndicate to MSN.", [ 'length' => $len ], [ 'msn' ], 'blocks' );
        }
    }

    // ── Per-network verdicts ─────────────────────────────────────
    foreach ( $gating['networks'] as $net ) {
        if ( get_post_meta( $post_id, '_mmgrf_exclude_' . $net, true ) === '1' ) {
            $result['verdicts'][ $net ] = [ 'state' => 'excluded', 'reason' => 'kept out (see Options)' ];
            continue;
        }
        $state  = 'ok';
        $reason = '';
        foreach ( $result['issues'] as $issue ) {
            if ( ! in_array( $net, $issue['networks'], true ) ) {
                continue;
            }
            if ( $issue['effect'] === 'blocks' ) {
                $state  = 'blocked';
                $reason = mmgrf_code_info( $issue['code'] )[0];
                break;
            }
            if ( $state === 'ok' ) {
                $state  = 'note';
                $reason = mmgrf_code_info( $issue['code'] )[0];
            }
        }
        if ( $state === 'ok' && $typed_post && in_array( $net, MMGRF_TYPED_NETWORKS, true ) ) {
            $reason = $is_slideshow ? 'as a slideshow' : 'as a video';
        }
        $result['verdicts'][ $net ] = [ 'state' => $state, 'reason' => $reason ];
    }
    // Carrier entries (empty message) only exist to feed the verdicts.
    $result['issues'] = array_values( array_filter( $result['issues'], fn( $i ) => $i['message'] !== '' ) );

    return $result;
}

// ── REST endpoint ────────────────────────────────────────────────

add_action( 'rest_api_init', function() {
    register_rest_route( 'mmgrf/v1', '/syndication-check', [
        'methods'             => 'GET',
        'callback'            => function( $request ) {
            $result         = mmgrf_syndication_check( (int) $request['post_id'] );
            $post           = get_post( (int) $request['post_id'] );
            $result['html'] = mmgrf_syndication_panel_html( $result, $post ? $post->post_status : '' );
            return rest_ensure_response( $result );
        },
        'args'                => [ 'post_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ] ],
        'permission_callback' => 'mmgrf_syndication_check_permission',
    ] );
} );

function mmgrf_syndication_check_permission( $request ) {
    return current_user_can( 'edit_post', (int) $request['post_id'] );
}

// ── The Syndication box (server-side render works in both editors) ──

add_action( 'add_meta_boxes', function() {
    foreach ( mmgrf_syndication_post_types() as $type ) {
        add_meta_box( 'mmgrf_syndication', 'Syndication', 'mmgrf_syndication_meta_box', $type, 'side', 'default' );
    }
} );

function mmgrf_syndication_meta_box( $post ) {
    wp_nonce_field( 'mmgrf_save_syndication', 'mmgrf_syndication_nonce' );
    $gating = mmgrf_syndication_gating();
    $status = get_post_status( $post );

    echo '<div id="mmgrf-syn-panel" data-post="' . (int) $post->ID . '">';
    if ( ! $gating['enabled'] ) {
        echo '<p class="mmgrf-syn-muted">No syndication networks are switched on for this site.</p>';
    } elseif ( $status === 'auto-draft' ) {
        echo '<p class="mmgrf-syn-lead">Sent automatically to ' . esc_html( mmgrf_syndication_network_names( $gating['networks'] ) ) . ' when you publish. Nothing to do here.</p>';
    } else {
        echo mmgrf_syndication_panel_html( mmgrf_syndication_check( $post->ID ), $status );
    }
    echo '</div>';

    // ── Options: the only controls, closed unless one is in use ──
    $score       = get_post_meta( $post->ID, '_mmgrf_score', true );
    $short_title = (string) get_post_meta( $post->ID, '_mmgrf_short_title', true );
    $excluded    = [];
    foreach ( MMGRF_NETWORKS as $network ) {
        $excluded[ $network ] = get_post_meta( $post->ID, '_mmgrf_exclude_' . $network, true ) === '1';
    }
    $sponsored = ! in_array( false, $excluded, true );
    $in_use    = in_array( true, $excluded, true );

    echo '<details class="mmgrf-syn-options"' . ( $in_use ? ' open' : '' ) . '>';
    echo '<summary>Options</summary>';
    echo '<p class="mmgrf-syn-muted">Everything here is optional. Leave it alone and the article is syndicated as normal.</p>';

    echo '<fieldset><legend>Keep this article out of</legend>';
    foreach ( MMGRF_NETWORKS as $network ) {
        echo '<label><input type="checkbox" name="mmgrf_exclude_' . esc_attr( $network ) . '" value="1"' . ( $excluded[ $network ] ? ' checked' : '' ) . '> ' . esc_html( mmgrf_network_label( $network ) ) . '</label>';
    }
    echo '<label class="mmgrf-syn-sponsored"><input type="checkbox" name="mmgrf_sponsored" value="1"' . ( $sponsored ? ' checked' : '' ) . '> <strong>Sponsored content</strong> — keep out of every network</label>';
    echo '</fieldset>';

    echo '<p><label for="mmgrf_short_title">MSN card title</label>';
    echo '<input type="text" id="mmgrf_short_title" name="mmgrf_short_title" value="' . esc_attr( $short_title ) . '" maxlength="150">';
    echo '<span class="mmgrf-syn-muted">Only used when the headline is over 150 characters. Left blank, one is created automatically.</span></p>';

    echo '<p><label for="mmgrf_score">Email feed score</label>';
    echo '<input type="number" step="0.01" id="mmgrf_score" name="mmgrf_score" value="' . esc_attr( $score !== '' ? $score : '0' ) . '"></p>';
    echo '</details>';
}

/**
 * The verdict panel. Shared by the server render and the REST refresh (the
 * editor script swaps this same markup in after each save).
 */
function mmgrf_syndication_panel_html( $result, $post_status = '' ) {
    if ( empty( $result['enabled'] ) ) {
        return '';
    }
    $icons = [ 'ok' => 'yes-alt', 'note' => 'yes-alt', 'blocked' => 'dismiss', 'excluded' => 'hidden' ];
    $states = array_column( $result['verdicts'] ?? [], 'state' );
    $all_ok = $states && ! array_diff( $states, [ 'ok', 'note' ] );

    $all_out = $states && ! array_diff( $states, [ 'excluded' ] );
    if ( $all_out ) {
        $lead = 'This article is being kept out of syndication (see Options).';
    } elseif ( $post_status === 'publish' ) {
        $lead = $all_ok ? 'Sent automatically. Nothing to do here.' : 'Sent automatically where it qualifies. Nothing here is required.';
    } else {
        $lead = $all_ok ? 'Will be sent automatically when you publish. Nothing to do here.' : 'Will be sent automatically where it qualifies. Nothing here is required.';
    }
    $out = '<p class="mmgrf-syn-lead">' . esc_html( $lead ) . '</p>';

    $out .= '<ul class="mmgrf-syn-nets">';
    foreach ( $result['verdicts'] ?? [] as $net => $verdict ) {
        $state = $verdict['state'];
        $text  = [ 'ok' => '', 'note' => '', 'blocked' => 'will not be sent', 'excluded' => 'kept out' ][ $state ] ?? '';
        $why   = $state === 'excluded' ? '' : (string) $verdict['reason'];
        $out  .= '<li class="is-' . esc_attr( $state ) . '"><span class="dashicons dashicons-' . esc_attr( $icons[ $state ] ?? 'yes-alt' ) . '" aria-hidden="true"></span>'
            . '<strong>' . esc_html( mmgrf_network_label( $net ) ) . '</strong>'
            . ( $text !== '' ? ' <span class="mmgrf-syn-state">' . esc_html( $text ) . '</span>' : '' )
            . ( $why !== '' ? ' <span class="mmgrf-syn-why">' . esc_html( ( $text !== '' ? '— ' : '' ) . lcfirst( $why ) ) . '</span>' : '' )
            . '</li>';
    }
    $out .= '</ul>';

    foreach ( $result['issues'] ?? [] as $issue ) {
        $out .= '<p class="mmgrf-syn-warn">' . esc_html( $issue['message'] ) . '</p>';
    }
    return $out;
}

// ── Editor assets (no build step: vanilla JS on wp globals) ──────

add_action( 'enqueue_block_editor_assets', function() {
    $base = plugin_dir_url( MMGRF_DIR . '/scoreline-feeds.php' ) . 'assets/';
    wp_enqueue_style( 'mmgrf-syndication-panel', $base . 'syndication-panel.css', [ 'dashicons' ], MMGRF_VERSION );
    if ( mmgrf_syndication_gating()['enabled'] ) {
        wp_enqueue_script( 'mmgrf-syndication-panel', $base . 'syndication-panel.js', [ 'wp-data', 'wp-api-fetch', 'wp-notices' ], MMGRF_VERSION, true );
    }
} );

// The classic editor renders the same box server-side; give it the same styles.
add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( $hook === 'post.php' || $hook === 'post-new.php' ) {
        wp_enqueue_style( 'mmgrf-syndication-panel', plugin_dir_url( MMGRF_DIR . '/scoreline-feeds.php' ) . 'assets/syndication-panel.css', [ 'dashicons' ], MMGRF_VERSION );
    }
} );
