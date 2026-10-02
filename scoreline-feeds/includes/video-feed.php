<?php
/**
 * Video feeds for Yahoo and MSN.
 *
 * Content model (Austin, 2026-10-02): first-party video only — client
 * podcasts (video recordings) and original celebrity/athlete content, hosted
 * in the WordPress media library. A video item is a NORMAL post tagged with
 * the video tag whose body carries the uploaded file (WP video block). Both
 * networks require self-hosted file URLs; YouTube is not syndicatable.
 *
 * Yahoo shape per publishers.yahooinc.com/docs/video-ingestion (note: that
 * doc declares the httpS mrss namespace, unlike the slideshow doc's http —
 * each feed matches its own spec exactly). MSN shape is PROVISIONAL against
 * the public metadata docs pending the gated Content Specification page.
 * Audio-only files (mp3) are rejected with video_format_unsupported — the
 * networks ingest video, not audio podcasts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Accepted containers per Yahoo's published format list. */
const MMGRF_VIDEO_MIMES = [ 'video/mp4', 'video/quicktime', 'video/x-m4v' ];

function mmgrf_video_marker_ids() {
    return mmgrf_tag_slugs_to_ids( mmgrf_get_options()['video_tag'] ?? 'video' );
}

/**
 * Find the post's video: first <video src> / <source src> in the rendered
 * body, resolved to its attachment for duration/dimensions/mime metadata.
 * Returns null, or ['url','mime','duration','width','height','filesize','attachment_id'].
 */
function mmgrf_resolve_post_video( $post_id, $content ) {
    if ( ! preg_match( '/<(?:video|source)[^>]+src=["\']([^"\']+)["\']/i', (string) $content, $m ) ) {
        return null;
    }
    $url    = $m[1];
    $att_id = mmgrf_url_to_attachment( $url );
    $mime   = $att_id ? ( get_post_mime_type( $att_id ) ?: '' ) : '';
    if ( $mime === '' ) {
        // Unattached file: infer container from the extension.
        $ext  = strtolower( pathinfo( (string) parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
        $mime = [ 'mp4' => 'video/mp4', 'm4v' => 'video/x-m4v', 'mov' => 'video/quicktime' ][ $ext ] ?? '';
    }
    $meta = $att_id ? wp_get_attachment_metadata( $att_id ) : [];
    $meta = is_array( $meta ) ? $meta : [];
    return [
        'url'           => $url,
        'mime'          => $mime,
        'duration'      => (int) ( $meta['length'] ?? 0 ),
        'width'         => (int) ( $meta['width'] ?? 0 ),
        'height'        => (int) ( $meta['height'] ?? 0 ),
        'filesize'      => (int) ( $meta['filesize'] ?? 0 ),
        'attachment_id' => $att_id,
    ];
}

/** Resolved options for a network's video feed. */
function mmgrf_video_options( $network ) {
    $opts = mmgrf_network_options( $network );
    $opts['feed_slug']  = $opts['feed_slug'] . '-videos';
    $opts['feed_url']   = mmgrf_network_feed_url( $opts['feed_slug'] );
    $opts['feed_title'] = $opts['feed_title'] . ' - Videos';
    $opts['post_count'] = 20;
    return $opts;
}

function mmgrf_render_video_feed( $network, $opts ) {
    $marker_ids = mmgrf_video_marker_ids();
    $query      = new WP_Query( [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => max( 1, (int) $opts['post_count'] ),
        'orderby'        => 'date',
        'order'          => 'DESC',
        'tag__in'        => $marker_ids ?: [ -1 ],
    ] );

    // Yahoo's video doc declares the https mrss namespace (its slideshow and
    // article docs use other variants — match each spec exactly).
    $ns = [
        'media'   => 'https://search.yahoo.com/mrss/',
        'dc'      => 'http://purl.org/dc/elements/1.1/',
        'dcterms' => 'http://purl.org/dc/terms/',
        'atom'    => 'http://www.w3.org/2005/Atom',
    ];
    if ( $network === 'msn' ) {
        $ns['mi'] = 'http://schemas.ingestion.microsoft.com/common/';
    }
    $ns_block = '';
    foreach ( $ns as $prefix => $uri ) {
        $ns_block .= "\n     xmlns:{$prefix}=\"{$uri}\"";
    }

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<rss version="2.0"' . $ns_block . ">\n";
    $xml .= "  <channel>\n";
    $xml .= '    ' . mmgrf_el( 'title', $opts['feed_title'] ) . "\n";
    $xml .= '    <atom:link href="' . mmgrf_xml( $opts['feed_url'] ) . '" rel="self" type="application/rss+xml"/>' . "\n";
    $xml .= '    ' . mmgrf_el( 'link', $opts['feed_link'] ) . "\n";
    $xml .= '    ' . mmgrf_el( 'description', $opts['feed_description'] ) . "\n";
    $xml .= '    ' . mmgrf_el( 'lastBuildDate', mmgrf_rfc822( time() ) ) . "\n";
    $xml .= '    ' . mmgrf_el( 'language', get_bloginfo( 'language' ) ) . "\n";
    $xml .= '    <generator>Scoreline Feeds ' . mmgrf_xml( MMGRF_VERSION ) . "</generator>\n";

    $log = $network . '-videos';
    foreach ( $query->posts as $post ) {
        $post_id = $post->ID;
        $GLOBALS['post'] = $post;
        setup_postdata( $post );

        $title = mmgrf_plain_text( get_the_title( $post_id ) );
        if ( count( preg_split( '/\s+/u', trim( $title ), -1, PREG_SPLIT_NO_EMPTY ) ) < 2 ) {
            mmgrf_skip_log_add( $log, $post_id, $title !== '' ? $title : '(untitled)', 'title_empty', 'Yahoo video titles need at least 2 words' );
            continue;
        }
        $content = apply_filters( 'the_content', get_the_content( null, false, $post_id ) );
        $video   = mmgrf_resolve_post_video( $post_id, $content );
        if ( ! $video ) {
            mmgrf_skip_log_add( $log, $post_id, $title, 'video_missing', 'post is tagged video but carries no uploaded video file' );
            continue;
        }
        if ( ! in_array( $video['mime'], MMGRF_VIDEO_MIMES, true ) ) {
            mmgrf_skip_log_add( $log, $post_id, $title, 'video_format_unsupported', $video['mime'] . ' — networks accept MP4/H.264 or MOV video; audio-only podcasts cannot syndicate here' );
            continue;
        }

        $pub_ts = (int) get_post_time( 'U', true, $post_id );
        $mod_ts = (int) get_post_modified_time( 'U', true, $post_id );
        $now    = time();
        if ( $pub_ts > $now ) {
            mmgrf_skip_log_add( $log, $post_id, $title, 'pubdate_future', gmdate( 'c', $pub_ts ) );
            continue;
        }
        if ( $network === 'msn' && $pub_ts < $now - 365 * 86400 ) {
            mmgrf_skip_log_add( $log, $post_id, $title, 'pubdate_too_old', gmdate( 'c', $pub_ts ) . ' (MSN limit: 365 days)' );
            continue;
        }

        // Thumbnail is REQUIRED for video on both networks.
        $thumb = mmgrf_resolve_image( $post_id, 'full', '' );
        $thumb = $thumb ? mmgrf_pick_image_fit( $thumb, 1280, 720, 5242880 ) : null;
        if ( ! $thumb ) {
            mmgrf_skip_log_add( $log, $post_id, $title, 'no_featured_image', 'video items require a thumbnail >= 1280x720' );
            continue;
        }

        // Description: intro text before the video, else excerpt. Plain text.
        $desc = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( str_replace( '<', ' <', preg_replace( '/<figure class="wp-block-video".*?<\/figure>|<video.*?<\/video>/is', ' ', $content ) ) ) ) );
        if ( $desc === '' ) {
            $desc = mmgrf_plain_text( has_excerpt( $post_id ) ? get_the_excerpt( $post_id ) : $title );
        }
        $desc = wp_trim_words( $desc, 55, '…' );

        $categories = get_the_category( $post_id );
        $category   = ! empty( $categories ) ? $categories[0]->name : 'General';
        $permalink  = get_permalink( $post_id );
        $post_tags  = get_the_tags( $post_id );
        $keywords   = [];
        if ( ! empty( $post_tags ) && is_array( $post_tags ) ) {
            foreach ( $post_tags as $tag ) {
                $keywords[] = $tag->name;
            }
        }
        $keywords = array_slice( $keywords, 0, 10 ); // Yahoo: max 10 terms

        $xml .= "    <item>\n";
        $xml .= '      ' . mmgrf_el( 'title', $title, [], true ) . "\n";
        $xml .= '      ' . mmgrf_el( 'link', $permalink ) . "\n";
        $xml .= '      ' . mmgrf_el( 'pubDate', mmgrf_rfc822( $pub_ts ) ) . "\n";
        if ( $mod_ts > $pub_ts + MMGRF_MODIFIED_JITTER ) {
            $xml .= $network === 'msn'
                ? '      ' . mmgrf_el( 'dcterms:modified', gmdate( 'Y-m-d\TH:i:s\Z', $mod_ts ) ) . "\n"
                : '      ' . mmgrf_el( 'updated', mmgrf_rfc822( $mod_ts ) ) . "\n";
        }
        $xml .= '      <guid isPermaLink="true">' . mmgrf_xml( $permalink ) . "</guid>\n";
        $xml .= '      ' . mmgrf_el( 'dc:creator', mmgrf_plain_text( get_the_author_meta( 'display_name', $post->post_author ) ), [], true ) . "\n";
        $xml .= '      ' . mmgrf_el( 'description', mmgrf_plain_text( $desc ), [], true ) . "\n";
        $xml .= '      ' . mmgrf_el( 'category', mmgrf_plain_text( $category ) ) . "\n";
        if ( $keywords ) {
            $xml .= '      ' . mmgrf_el( 'media:keywords', implode( ', ', $keywords ) ) . "\n";
        }

        $vattrs = ' url="' . mmgrf_xml( $video['url'] ) . '" type="' . mmgrf_xml( $video['mime'] ) . '" medium="video"';
        if ( $video['duration'] > 0 ) {
            $vattrs .= ' duration="' . (int) $video['duration'] . '"';
        }
        if ( $video['width'] > 0 && $video['height'] > 0 ) {
            $vattrs .= ' width="' . (int) $video['width'] . '" height="' . (int) $video['height'] . '"';
        }
        if ( $video['filesize'] > 0 ) {
            $vattrs .= ' fileSize="' . (int) $video['filesize'] . '"';
        }
        $xml .= '      <media:content' . $vattrs . "/>\n";
        $xml .= '      <media:thumbnail url="' . mmgrf_xml( $thumb['url'] ) . '" width="' . (int) $thumb['width'] . '" height="' . (int) $thumb['height'] . '"/>' . "\n";
        $xml .= "    </item>\n";
    }
    wp_reset_postdata();

    $xml .= "  </channel>\n</rss>\n";
    return $xml;
}

/** WP feed callback: headers, conditional GET, 60s cache. */
function mmgrf_output_video_feed( $network ) {
    if ( mmgrf_feed_headers_and_maybe_304() ) {
        return;
    }
    $key    = 'mmgrf_feed_' . md5( 'video|' . $network . '|' . mmgrf_feed_lastmod() . '|' . MMGRF_VERSION );
    $cached = get_transient( $key );
    if ( $cached === false ) {
        $cached = mmgrf_render_video_feed( $network, mmgrf_video_options( $network ) );
        set_transient( $key, $cached, 60 );
    }
    echo $cached;
}
