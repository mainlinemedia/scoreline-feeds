<?php
/**
 * Skip log — the debugging surface for silent partner-side rejections (D7).
 * Entries carry a STABLE reason code (machine-matchable, drives dedupe and the
 * Fix 11 editor panel) plus a freetext detail string (dimensions, URLs,
 * counts). Rolling option, newest first, 200-cap, 7-day retention, never
 * autoloaded.
 *
 * Codes in use
 *   items:      title_empty, title_too_short, title_too_long_no_short_title,
 *               title_auto_shortened,
 *               description_below_word_floor, body_below_word_floor,
 *               body_empty, pubdate_future, pubdate_too_old, link_missing,
 *               guid_missing, embed_review, render_error
 *   lead image: no_featured_image, image_below_minimum, image_below_recommended,
 *               image_over_size_limit, image_dimensions_unknown,
 *               image_file_missing, image_format_unsupported,
 *               no_syndication_rights
 *   body:       inline_image_below_minimum, inline_image_unresolvable,
 *               inline_image_rewritten, inline_image_duplicate,
 *               affiliate_domain_matched, affiliate_path_matched
 *   slideshows: slide_missing_image, slide_missing_title, slide_image_unresolvable,
 *               slide_below_minimum, slide_duplicate_image,
 *               slideshow_too_few_slides, slideshow_layout_ambiguous,
 *               thumbnail_duplicates_slide
 *   videos:     video_missing, video_format_unsupported, video_not_self_hosted,
 *               video_file_missing, video_below_minimum,
 *               video_duration_unknown, video_dimensions_unknown
 *   settings:   filter_term_missing, exclusion_term_missing (post id 0)
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

/**
 * Plain-language meaning of every log code: [what happened, what to do].
 * The log is read by writers and editors, not by developers — a snake_case
 * code alone does not tell anyone what to fix. Every code the plugin can
 * emit MUST have an entry here (enforced by the test suite).
 */
function mmgrf_code_catalog() {
    return [
        // Items
        'title_empty'                   => [ 'Headline missing or too short', 'Give the article a headline of at least two words.' ],
        'title_too_short'               => [ 'Headline too short for MSN', 'MSN needs a headline longer than 20 characters.' ],
        'title_too_long_no_short_title' => [ 'Headline too long for MSN', 'Shorten the headline to 150 characters or fewer.' ],
        'title_auto_shortened'          => [ 'Long headline shortened for MSN', 'Nothing required. MSN was sent an automatically shortened card title; set your own under Syndication → Options if you prefer.' ],
        'description_below_word_floor'  => [ 'Summary too short', 'Add a short excerpt, or an opening paragraph, of at least a few words.' ],
        'body_below_word_floor'         => [ 'Article under 150 words', 'Yahoo only takes articles of 150 words or more. Nothing to fix if this is a short item by design.' ],
        'body_empty'                    => [ 'Article has no text', 'The article body was empty after cleaning. Add body text.' ],
        'pubdate_future'                => [ 'Publish date is in the future', 'It will be sent once the publish date has passed.' ],
        'pubdate_too_old'               => [ 'Older than MSN accepts', 'MSN only takes articles published in the last 365 days. Nothing to fix.' ],
        'link_missing'                  => [ 'Article has no link', 'Re-save the article so WordPress gives it a permalink.' ],
        'guid_missing'                  => [ 'Article has no link', 'Re-save the article so WordPress gives it a permalink.' ],
        'embed_review'                  => [ 'Contains embeds', 'Nothing required. Yahoo sometimes displays embedded posts and videos differently; worth a glance on Yahoo.' ],
        'render_error'                  => [ 'Article could not be processed', 'Something in this article (often a shortcode or block from another plugin) failed. The rest of the feed was sent. Re-save the article; if it persists, tell your site administrator.' ],
        // Lead image
        'no_featured_image'             => [ 'No usable featured image', 'Set a featured image. NewsBreak and MSN will not take an article without one.' ],
        'image_below_minimum'           => [ 'Featured image too small', 'Upload a larger image: at least 1280×720 for Yahoo, 640×360 for MSN.' ],
        'image_below_recommended'       => [ 'Featured image smaller than MSN recommends', 'Nothing required. MSN prefers 1280×720 or larger.' ],
        'image_over_size_limit'         => [ 'Featured image file too large', 'Re-export the image under 5 MB (2 MB for MSN).' ],
        'image_dimensions_unknown'      => [ 'Featured image size could not be read', 'Re-upload the image through the media library.' ],
        'image_file_missing'            => [ 'Featured image file is missing', 'The image was edited or replaced and its file is gone. Re-upload it and set it as the featured image again.' ],
        'image_format_unsupported'      => [ 'Image format not accepted', 'Use a JPEG, PNG or GIF image.' ],
        'no_syndication_rights'         => [ 'Image marked as not cleared for syndication', 'Use an image we hold syndication rights to, or clear the flag on the image if it was set by mistake.' ],
        // Body
        'inline_image_below_minimum'    => [ 'Small image removed from the Yahoo version', 'Nothing required. To keep it on Yahoo, upload it at 1280×720 or larger.' ],
        'inline_image_unresolvable'     => [ 'Image hosted on another site', 'Nothing required. For reliable display, upload the image to this site instead of linking to it.' ],
        'inline_image_rewritten'        => [ 'Image upgraded to a larger size', 'Nothing required.' ],
        'inline_image_duplicate'        => [ 'Repeated photo removed', 'Nothing required. The same photo appeared more than once; partners flag duplicates, so one copy was sent.' ],
        'affiliate_domain_matched'      => [ 'Links to a betting or affiliate site', 'Yahoo prohibits these links. Remove the link to send the article to Yahoo.' ],
        'affiliate_path_matched'        => [ 'Promotional link removed for Yahoo', 'Nothing required. The link was taken out of the Yahoo version.' ],
        // Slideshows
        'slide_missing_image'           => [ 'Slide has no photo', 'Add a photo directly under that heading.' ],
        'slide_missing_title'           => [ 'Slide heading has no text', 'Give the heading (H2) some text.' ],
        'slide_image_unresolvable'      => [ 'Slide photo is hosted on another site', 'Upload the photo to this site\'s media library.' ],
        'slide_below_minimum'           => [ 'Slide photo too small', 'Upload the photo at 1280×720 or larger.' ],
        'slide_duplicate_image'         => [ 'Same photo used on two slides', 'Use a different photo for each slide.' ],
        'slideshow_too_few_slides'      => [ 'Not enough slides', 'A slideshow needs at least 3 slides for Yahoo and 4 for MSN. A slide is a heading (H2) followed by a photo.' ],
        'slideshow_layout_ambiguous'    => [ 'Could not match photos to headings', 'Place each photo directly under its heading.' ],
        'thumbnail_duplicates_slide'    => [ 'Cover photo repeats a slide', 'Nothing required. For a cleaner result, set a featured image that is not one of the slides.' ],
        // Videos
        'video_missing'                 => [ 'Tagged as video but has no video', 'Add the uploaded video to the article, or remove the video tag.' ],
        'video_format_unsupported'      => [ 'Video format not accepted', 'Upload the video as an MP4 or MOV file. Audio-only files cannot be sent.' ],
        'video_not_self_hosted'         => [ 'Video is hosted on another site', 'Upload the video file to this site\'s media library.' ],
        'video_file_missing'            => [ 'Video file is missing', 'Re-upload the video.' ],
        'video_below_minimum'           => [ 'Video resolution too low', 'Yahoo needs at least 1280×720 (720×1280 vertical, 1080×1080 square).' ],
        'video_duration_unknown'        => [ 'Video length could not be read', 'Re-upload the video through the media library.' ],
        'video_dimensions_unknown'      => [ 'Video size could not be read', 'Nothing required. Re-uploading the video through the media library fixes it.' ],
        // Settings (administrators)
        'filter_term_missing'           => [ 'A feed filter points at a category or tag that no longer exists', 'Administrator: correct the category/tag in Scoreline Feeds settings. The feed is empty until then.' ],
        'exclusion_term_missing'        => [ 'An exclusion points at a category or tag that does not exist', 'Administrator: correct the category/tag in Scoreline Feeds settings. Nothing is being excluded for it.' ],
    ];
}

/** [label, fix] for one code; unknown codes fall back to the code itself. */
function mmgrf_code_info( $code ) {
    $catalog = mmgrf_code_catalog();
    return $catalog[ $code ] ?? [ ucfirst( str_replace( '_', ' ', (string) $code ) ), '' ];
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
