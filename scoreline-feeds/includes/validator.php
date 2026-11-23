<?php
/**
 * Skip log — the debugging surface for silent partner-side rejections (D7).
 * Entries carry a STABLE reason code (machine-matchable, drives dedupe and the
 * Fix 11 editor panel) plus a freetext detail string (dimensions, URLs,
 * counts). Rolling option, newest first, 200-cap, 7-day retention, never
 * autoloaded.
 *
 * Codes in use: no_featured_image, image_below_minimum, image_over_size_limit,
 * image_dimensions_unknown, image_format_unsupported, no_syndication_rights,
 * inline_image_below_minimum, inline_image_unresolvable,
 * affiliate_domain_matched, affiliate_path_matched, body_below_word_floor,
 * body_empty, title_empty, title_too_short, title_too_long_no_short_title,
 * description_below_word_floor, pubdate_future, pubdate_too_old, link_missing,
 * guid_missing, embed_review, render_error, filter_term_missing,
 * exclusion_term_missing.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const MMGRF_SKIP_LOG_OPTION = 'mmgrf_skip_log';
const MMGRF_SKIP_LOG_CAP    = 200;
const MMGRF_SKIP_LOG_TTL    = 604800; // 7 days

/**
 * Queue one log entry. Inside a render (mmgrf_skip_log_begin/_end) entries
 * are collected and written in ONE option update at the end; outside one
 * they are written immediately.
 *
 * @param string $code   Stable reason code (snake_case, from the list above).
 * @param string $detail Human detail: dimensions, matched pattern, word count.
 * @param string $level  'skip' (item withheld) or 'warn' (shipped, needs review)
 */
function mmgrf_skip_log_add( $network, $post_id, $post_title, $code, $detail = '', $level = 'skip' ) {
    $GLOBALS['mmgrf_skip_log_pending'][] = [
        'ts'         => time(),
        'network'    => (string) $network,
        'post_id'    => (int) $post_id,
        'post_title' => (string) $post_title,
        'code'       => (string) $code,
        'detail'     => (string) $detail,
        'level'      => (string) $level,
    ];
    if ( empty( $GLOBALS['mmgrf_skip_log_depth'] ) ) {
        mmgrf_skip_log_flush();
    }
}

function mmgrf_skip_log_begin() {
    $GLOBALS['mmgrf_skip_log_depth'] = (int) ( $GLOBALS['mmgrf_skip_log_depth'] ?? 0 ) + 1;
}

function mmgrf_skip_log_end() {
    $GLOBALS['mmgrf_skip_log_depth'] = max( 0, (int) ( $GLOBALS['mmgrf_skip_log_depth'] ?? 0 ) - 1 );
    if ( ! $GLOBALS['mmgrf_skip_log_depth'] ) {
        mmgrf_skip_log_flush();
    }
}

/**
 * Merge queued entries into the stored log with at most one write.
 *
 * Entries are grouped by (post, network, code). A group reported in this
 * batch REPLACES the stored group, so a condition whose detail changed
 * (word count, dimensions) shows the current value — while several notes of
 * the same code for one post (three undersized inline images) coexist
 * instead of evicting each other on every poll. A stored entry identical to
 * a queued one and under an hour old is kept untouched, and when nothing
 * differs there is no database write at all: steady-state warnings must not
 * rewrite the option on every partner poll.
 */
function mmgrf_skip_log_flush() {
    $pending = $GLOBALS['mmgrf_skip_log_pending'] ?? [];
    $GLOBALS['mmgrf_skip_log_pending'] = [];
    if ( ! $pending ) {
        return;
    }
    $log = get_option( MMGRF_SKIP_LOG_OPTION, [] );
    if ( ! is_array( $log ) ) {
        $log = [];
    }
    $group_of = function( $e ) {
        return (int) ( $e['post_id'] ?? 0 ) . '|' . ( $e['network'] ?? '' ) . '|' . ( $e['code'] ?? '' );
    };
    $variant_of = function( $e ) {
        return md5( ( $e['detail'] ?? '' ) . '|' . ( $e['level'] ?? 'skip' ) );
    };

    $incoming = [];
    foreach ( $pending as $e ) {
        $incoming[ $group_of( $e ) ][ $variant_of( $e ) ] = $e;
    }
    $stored = [];
    $rest   = [];
    foreach ( $log as $e ) {
        $g = $group_of( $e );
        if ( isset( $incoming[ $g ] ) ) {
            $stored[ $g ][ $variant_of( $e ) ] = $e;
        } else {
            $rest[] = $e;
        }
    }

    $changed = false;
    $fresh   = time() - 3600;
    $merged  = [];
    foreach ( $incoming as $g => $variants ) {
        foreach ( $variants as $v => $e ) {
            $old = $stored[ $g ][ $v ] ?? null;
            if ( $old && ( $old['ts'] ?? 0 ) >= $fresh ) {
                $merged[] = $old;
            } else {
                $merged[] = $e;
                $changed  = true;
            }
        }
        if ( count( $stored[ $g ] ?? [] ) !== count( $variants ) ) {
            $changed = true; // a previously logged variant no longer applies
        }
    }
    if ( ! $changed ) {
        return;
    }
    $log = array_merge( $merged, $rest );
    usort( $log, function( $a, $b ) {
        return ( $b['ts'] ?? 0 ) <=> ( $a['ts'] ?? 0 );
    } );
    update_option( MMGRF_SKIP_LOG_OPTION, array_slice( $log, 0, MMGRF_SKIP_LOG_CAP ), 'no' ); // never autoload 200 entries on every page view
}

function mmgrf_skip_log_get() {
    $log = get_option( MMGRF_SKIP_LOG_OPTION, [] );
    if ( ! is_array( $log ) ) {
        return [];
    }
    $cutoff = time() - MMGRF_SKIP_LOG_TTL;
    return array_values( array_filter( $log, fn( $e ) => ( $e['ts'] ?? 0 ) >= $cutoff ) );
}

/**
 * Entries visible to a user: admins see everything; everyone else sees only
 * entries for posts they authored — the person who needs to see a dropped
 * image is the writer, not the operator.
 */
function mmgrf_skip_log_visible_entries( $user_id, $can_manage_options ) {
    $log = mmgrf_skip_log_get();
    if ( $can_manage_options ) {
        return $log;
    }
    return array_values( array_filter( $log, function( $e ) use ( $user_id ) {
        $post = get_post( $e['post_id'] ?? 0 );
        return $post && (int) $post->post_author === (int) $user_id;
    } ) );
}

function mmgrf_skip_log_clear() {
    delete_option( MMGRF_SKIP_LOG_OPTION );
}
