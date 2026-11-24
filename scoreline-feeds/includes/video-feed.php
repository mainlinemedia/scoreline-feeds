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
 * The same withholding rules as the article feeds apply here: per-post
 * "Exclude from networks" / "Sponsored content", the policy categories and
 * tags, password protection, and the settle window. A post carrying BOTH
 * marker tags is a slideshow (one guid never ships as two content types).
 *
 * Yahoo shape per publishers.yahooinc.com/docs/video-ingestion (note: that
 * doc declares the httpS mrss namespace, unlike the slideshow doc's http —
 * each feed matches its own spec exactly). MSN shape is PROVISIONAL against
 * the public metadata docs pending the gated Content Specification page; it
 * uses the canonical http MRSS namespace like every other MSN feed.
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

/** Container type from a file extension ('' when it is not a video container we know). */
function mmgrf_video_mime_from_url( $url ) {
    $ext = strtolower( pathinfo( (string) parse_url( (string) $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
    return [ 'mp4' => 'video/mp4', 'm4v' => 'video/x-m4v', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'ogv' => 'video/ogg', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg' ][ $ext ] ?? '';
}

/**
 * Find the post's video in the rendered body.
 *
 * Only sources of <video> elements are considered (an [audio] player above
 * the video must not win), every source is tried in order, and the first one
 * in an accepted container is used. The file must be THIS site's: a resolved
 * media-library attachment, or at least a URL on the site's own host. A
 * video block pointed at someone else's file ("Insert from URL") is never
 * syndicated under our name.
 *
 * Returns null when the body has no video element at all, otherwise
 * ['url','mime','duration','width','height','filesize','attachment_id',
 *  'file_missing','poster','self_hosted','rejected'=>[mime,…]].
 * 'url' is '' when no source qualified.
 */
function mmgrf_resolve_post_video( $post_id, $content ) {
    if ( stripos( (string) $content, '<video' ) === false ) {
        return null;
    }
    $loaded = mmgrf_dom_load( $content );
    if ( ! $loaded ) {
        return null;
    }
    [ $doc, $root ] = $loaded;

    $sources = [];
    $poster  = '';
    foreach ( mmgrf_dom_query( $doc, $root, './/video' ) as $video ) {
        if ( $poster === '' ) {
            $poster = trim( $video->getAttribute( 'poster' ) );
        }
        if ( trim( $video->getAttribute( 'src' ) ) !== '' ) {
            $sources[] = trim( $video->getAttribute( 'src' ) );
        }
        // libxml nests <source> siblings inside each other; take them all.
        foreach ( $video->getElementsByTagName( 'source' ) as $source ) {
            if ( trim( $source->getAttribute( 'src' ) ) !== '' ) {
                $sources[] = trim( $source->getAttribute( 'src' ) );
            }
        }
    }
    if ( ! $sources ) {
        return null;
    }

    $home_host = strtolower( (string) parse_url( home_url(), PHP_URL_HOST ) );
    $up_host   = strtolower( (string) parse_url( (string) ( wp_get_upload_dir()['baseurl'] ?? '' ), PHP_URL_HOST ) );
    $found     = [ 'url' => '', 'mime' => '', 'duration' => 0, 'width' => 0, 'height' => 0, 'filesize' => 0, 'attachment_id' => 0, 'file_missing' => false, 'poster' => $poster, 'self_hosted' => false, 'rejected' => [] ];
    $foreign   = false;

    foreach ( $sources as $raw ) {
        // The DOM hands back decoded attribute values; make the URL absolute
        // and drop the cache-busting query the [video] shortcode appends
        // ("?_=1" — it changes from request to request).
        $url    = mmgrf_absolutize_url( $raw, home_url() );
        $url    = (string) preg_replace( '/[?#].*$/', '', $url );
        $att_id = mmgrf_find_attachment( $url );
        $mime   = $att_id ? (string) ( get_post_mime_type( $att_id ) ?: '' ) : '';
        if ( $mime === '' ) {
            $mime = mmgrf_video_mime_from_url( $url );
        }
        if ( ! in_array( $mime, MMGRF_VIDEO_MIMES, true ) ) {
            $found['rejected'][] = $mime !== '' ? $mime : 'unknown type';
            continue;
        }
        $host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
        if ( ! $att_id && $host !== $home_host && $host !== $up_host ) {
            $foreign = true;
            continue;
        }

        $meta  = $att_id ? wp_get_attachment_metadata( $att_id ) : [];
        $meta  = is_array( $meta ) ? $meta : [];
        $file  = $att_id ? get_attached_file( $att_id ) : false;
        $bytes = (int) ( $meta['filesize'] ?? 0 );
        if ( ! $bytes && $file && file_exists( $file ) ) {
            $bytes = (int) filesize( $file ); // disk fallback (audit V4)
        }
        // The canonical attachment URL when we have one — stable across
        // renders and hosts.
        $canonical = $att_id ? wp_get_attachment_url( $att_id ) : '';

        $found['url']           = $canonical ?: $url;
        $found['mime']          = $mime;
        $found['duration']      = (int) ( $meta['length'] ?? 0 );
        $found['width']         = (int) ( $meta['width'] ?? 0 );
        $found['height']        = (int) ( $meta['height'] ?? 0 );
        $found['filesize']      = $bytes;
        $found['attachment_id'] = (int) $att_id;
        // Never advertise a dead video URL — a partner's multi-hundred-MB
        // fetch failing is the image "didn't download" lesson at scale
        // (audit V3). Enforced only when the upload directory is local.
        $found['file_missing']  = (bool) ( $file && is_dir( dirname( $file ) ) && ! file_exists( $file ) );
        $found['self_hosted']   = true;
        return $found;
    }

    $found['foreign'] = $foreign;
    return $found;
}

/**
 * Yahoo's published video sizes: landscape 1920x1080 or 1280x720, vertical
 * 1080x1920 or 720x1280, square 1080x1080. Returns true when the clip meets
 * the smallest size for its orientation.
 */
function mmgrf_video_meets_yahoo_minimum( $w, $h ) {
    if ( $w > $h ) {
        return $w >= 1280 && $h >= 720;
    }
    if ( $h > $w ) {
        return $w >= 720 && $h >= 1280;
    }
    return $w >= 1080;
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
    return mmgrf_quiet( function() use ( $network, $opts ) {
        mmgrf_skip_log_begin();
        try {
            return mmgrf_render_video_document( $network, $opts );
        } finally {
            wp_reset_postdata();
            mmgrf_skip_log_end();
        }
    } );
}

function mmgrf_render_video_document( $network, $opts ) {
    $log = $network . '-videos';
    $ns  = [
        // Yahoo's video doc declares the https mrss namespace; MSN (and
        // every other MRSS consumer) expects the canonical http one — a
        // namespace-aware parser sees no media elements at all otherwise.
        'media'   => $network === 'msn' ? 'http://search.yahoo.com/mrss/' : 'https://search.yahoo.com/mrss/',
        'dc'      => 'http://purl.org/dc/elements/1.1/',
        'dcterms' => 'http://purl.org/dc/terms/',
        'atom'    => 'http://www.w3.org/2005/Atom',
    ];
    if ( $network === 'msn' ) {
        $ns['mi'] = 'http://schemas.ingestion.microsoft.com/common/';
    }
    $xml = mmgrf_typed_channel_open( $ns, $opts );

    // A post tagged both ways is a slideshow, not a video.
    $posts = mmgrf_typed_feed_posts( $network, mmgrf_video_marker_ids(), $opts['post_count'], $log, mmgrf_slideshow_marker_ids() );
    foreach ( $posts as $post ) {
        try {
            $xml .= mmgrf_video_item_xml( $post, $network, $log );
        } catch ( \Throwable $e ) {
            mmgrf_log_render_error( $log, $post->ID, mmgrf_text_field( $post->post_title ?? '' ), $e );
        }
    }

    return $xml . "  </channel>\n</rss>\n";
}

/** One video <item>, or '' when the post is withheld (always with a log entry). */
function mmgrf_video_item_xml( $post, $network, $log ) {
    $post_id = $post->ID;
    $GLOBALS['post'] = $post;
    setup_postdata( $post );
    $yahoo = $network !== 'msn';

    $title = mmgrf_text_field( get_the_title( $post_id ) );
    if ( $title === '' || ( $yahoo && mmgrf_count_words( mmgrf_xml( $title ) ) < 2 ) ) {
        mmgrf_skip_log_add( $log, $post_id, $title !== '' ? $title : '(untitled)', 'title_empty', $yahoo ? 'Yahoo video titles need at least 2 words' : '' );
        return '';
    }
    $content = mmgrf_utf8( mmgrf_rendered_post_content( $post, $network ) );
    $video   = mmgrf_resolve_post_video( $post_id, $content );
    if ( ! $video ) {
        mmgrf_skip_log_add( $log, $post_id, $title, 'video_missing', 'post is tagged video but carries no uploaded video file — it is withheld from the article feed too; add the video or remove the tag' );
        return '';
    }
    if ( $video['url'] === '' ) {
        if ( ! empty( $video['foreign'] ) ) {
            mmgrf_skip_log_add( $log, $post_id, $title, 'video_not_self_hosted', 'the video file is hosted on another site — only files uploaded to this site\'s media library can be syndicated' );
        } else {
            mmgrf_skip_log_add( $log, $post_id, $title, 'video_format_unsupported', implode( ', ', array_unique( $video['rejected'] ) ) . ' — networks accept MP4/H.264 or MOV video; audio-only podcasts cannot syndicate here' );
        }
        return '';
    }
    if ( $video['file_missing'] ) {
        mmgrf_skip_log_add( $log, $post_id, $title, 'video_file_missing', 'attachment ' . (int) $video['attachment_id'] . ' — video file missing on disk; re-upload before this item can syndicate' );
        return '';
    }
    $dims_known = $video['width'] > 0 && $video['height'] > 0;
    if ( $yahoo && $dims_known && ! mmgrf_video_meets_yahoo_minimum( $video['width'], $video['height'] ) ) {
        mmgrf_skip_log_add( $log, $post_id, $title, 'video_below_minimum', $video['width'] . 'x' . $video['height'] . ' — Yahoo needs at least 1280x720 (landscape), 720x1280 (vertical) or 1080x1080 (square)' );
        return '';
    }
    if ( ! $yahoo && $video['duration'] <= 0 ) {
        mmgrf_skip_log_add( $log, $post_id, $title, 'video_duration_unknown', 'no duration in the attachment metadata — MSN requires it; re-upload the file through the media library' );
        return '';
    }

    $pub_ts = (int) get_post_time( 'U', true, $post_id );
    $mod_ts = (int) get_post_modified_time( 'U', true, $post_id );
    $now    = time();
    if ( $pub_ts > $now ) {
        mmgrf_skip_log_add( $log, $post_id, $title, 'pubdate_future', gmdate( 'c', $pub_ts ) );
        return '';
    }
    if ( ! $yahoo && $pub_ts < $now - 365 * 86400 ) {
        mmgrf_skip_log_add( $log, $post_id, $title, 'pubdate_too_old', gmdate( 'c', $pub_ts ) . ' (MSN limit: 365 days)' );
        return '';
    }

    // Thumbnail is REQUIRED for video on both networks. Vertical clips
    // accept a vertical 720x1280 thumbnail per Yahoo's video spec (audit
    // V2); the poster attribute is the fallback when no featured image fits
    // (audit V5). MSN: an image flagged no-syndication-rights is never used.
    $vertical   = $video['height'] > $video['width'] && $video['width'] > 0;
    $pick_thumb = function( $img ) use ( $vertical, $yahoo ) {
        if ( ! $img || ( ! $yahoo && ! empty( $img['no_synd_rights'] ) ) ) {
            return null;
        }
        $picked = mmgrf_pick_image_fit( $img, 1280, 720, 5242880 );
        if ( ! $picked && $vertical ) {
            $picked = mmgrf_pick_image_fit( $img, 720, 1280, 5242880 );
        }
        return $picked;
    };
    $thumb = $pick_thumb( mmgrf_resolve_image( $post_id, 'full', '' ) );
    if ( ! $thumb && $video['poster'] !== '' ) {
        $poster_att = mmgrf_find_attachment( mmgrf_absolutize_url( $video['poster'], home_url() ) );
        if ( $poster_att ) {
            $poster_img = mmgrf_attachment_image_data( $poster_att, 'full' );
            if ( $poster_img ) {
                $poster_img['no_synd_rights'] = (bool) get_post_meta( $poster_att, '_mmgrf_no_syndication_rights', true );
            }
            $thumb = $pick_thumb( $poster_img );
        }
    }
    if ( ! $thumb ) {
        mmgrf_skip_log_add( $log, $post_id, $title, 'no_featured_image', 'video items require a thumbnail (1280x720, or 720x1280 for vertical clips)' . ( $yahoo ? '' : ' that MMG holds syndication rights to' ) );
        return '';
    }

    // Every gate passed — only now note what shipped imperfect.
    if ( $video['duration'] <= 0 ) {
        mmgrf_skip_log_add( $log, $post_id, $title, 'video_duration_unknown', 'no duration in the attachment metadata — shipped without it', 'warn' );
    }
    if ( ! $dims_known ) {
        mmgrf_skip_log_add( $log, $post_id, $title, 'video_dimensions_unknown', 'no width/height in the attachment metadata — shipped without them; size could not be checked against the network minimum', 'warn' );
    }

    // Description: the post's own text (player, captions and embeds
    // removed), else the excerpt, else the title. Plain text.
    $desc = mmgrf_lede_text( $content );
    if ( $desc === '' ) {
        $desc = has_excerpt( $post_id ) ? mmgrf_text_field( get_the_excerpt( $post_id ) ) : $title;
    }
    $desc = mmgrf_xml_chars( mmgrf_plain_text( wp_trim_words( $desc, 55, '…' ) ) );

    $categories = get_the_category( $post_id );
    $category   = ! empty( $categories ) ? mmgrf_text_field( $categories[0]->name ) : 'General';
    $permalink  = get_permalink( $post_id );
    $keywords   = mmgrf_post_keywords( $post_id, 10 ); // Yahoo: max 10 terms
    $author     = (string) get_the_author();
    if ( trim( $author ) === '' ) {
        $author = (string) get_the_author_meta( 'display_name', $post->post_author );
    }

    $xml  = "    <item>\n";
    $xml .= '      ' . mmgrf_el( 'title', $title, [], true ) . "\n";
    $xml .= '      ' . mmgrf_el( 'link', $permalink ) . "\n";
    $xml .= '      ' . mmgrf_el( 'pubDate', mmgrf_rfc822( $pub_ts ) ) . "\n";
    if ( ! $yahoo ) {
        if ( $mod_ts > $pub_ts + MMGRF_MODIFIED_JITTER ) {
            $xml .= '      ' . mmgrf_el( 'dcterms:modified', gmdate( 'Y-m-d\TH:i:s\Z', $mod_ts ) ) . "\n";
        }
    } else {
        // Yahoo's video spec lists <updated> as a required item element
        // (unlike articles, where unnecessary churn is penalized).
        $xml .= '      ' . mmgrf_el( 'updated', mmgrf_rfc822( max( $mod_ts, $pub_ts ) ) ) . "\n";
    }
    $xml .= '      <guid isPermaLink="true">' . mmgrf_xml( $permalink ) . "</guid>\n";
    $xml .= '      ' . mmgrf_el( 'dc:creator', mmgrf_text_field( $author ), [], true ) . "\n";
    $xml .= '      ' . mmgrf_el( 'description', $desc, [], true ) . "\n";
    $xml .= '      ' . mmgrf_el( 'category', $category ) . "\n";
    if ( $keywords ) {
        $xml .= '      ' . mmgrf_el( 'media:keywords', implode( ', ', $keywords ) ) . "\n";
    }

    $vattrs = ' url="' . mmgrf_xml( $video['url'] ) . '" type="' . mmgrf_xml( $video['mime'] ) . '" medium="video"';
    if ( $video['duration'] > 0 ) {
        $vattrs .= ' duration="' . (int) $video['duration'] . '"';
    }
    if ( $dims_known ) {
        $vattrs .= ' width="' . (int) $video['width'] . '" height="' . (int) $video['height'] . '"';
    }
    if ( $video['filesize'] > 0 ) {
        $vattrs .= ' fileSize="' . (int) $video['filesize'] . '"';
    }
    $xml .= '      <media:content' . $vattrs . "/>\n";
    $xml .= '      <media:thumbnail url="' . mmgrf_xml( $thumb['url'] ) . '" width="' . (int) $thumb['width'] . '" height="' . (int) $thumb['height'] . '"/>' . "\n";
    if ( ! $yahoo ) {
        // MSN requires attribution (copyright information) for video.
        $xml .= '      <media:copyright>© ' . gmdate( 'Y', $pub_ts ) . ' ' . mmgrf_xml( mmgrf_site_text( 'name' ) ) . "</media:copyright>\n";
    }
    return $xml . "    </item>\n";
}

/** WP feed callback: headers, conditional GET, shared cache. */
function mmgrf_output_video_feed( $network ) {
    if ( mmgrf_feed_headers_and_maybe_304() ) {
        return;
    }
    $opts = mmgrf_video_options( $network );
    echo mmgrf_cached_render( 'video|' . $network . '|' . $opts['feed_slug'], function() use ( $network, $opts ) {
        return mmgrf_render_video_feed( $network, $opts );
    }, $network . '-videos' );
}
