<?php
/**
 * Layer 1 — shared content pipeline. Builds normalized, network-agnostic item
 * arrays; profiles own everything network-shaped. Layer 3 orchestration
 * (mmgrf_render_feed) also lives here.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function mmgrf_tag_slugs_to_ids( $slugs_string ) {
    return mmgrf_slugs_to_ids( $slugs_string, 'post_tag' );
}

function mmgrf_cat_slugs_to_ids( $slugs_string ) {
    return mmgrf_slugs_to_ids( $slugs_string, 'category' );
}

function mmgrf_slugs_to_ids( $slugs_string, $taxonomy ) {
    if ( trim( (string) $slugs_string ) === '' ) {
        return [];
    }
    $ids = [];
    foreach ( array_filter( array_map( 'trim', explode( ',', $slugs_string ) ) ) as $slug ) {
        $term = get_term_by( 'slug', $slug, $taxonomy );
        if ( $term ) {
            $ids[] = $term->term_id;
        }
    }
    return $ids;
}

/**
 * Plain-text fields (title, excerpt) are emitted inside CDATA, where entities
 * are NOT decoded by the XML parser — a stored "&#8217;" would appear as
 * literal text at the partner. Decode once at build time.
 */
function mmgrf_plain_text( $value ) {
    return html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

function mmgrf_get_score( $post_id ) {
    $score = get_post_meta( $post_id, '_mmgrf_score', true );
    return ( $score !== '' && is_numeric( $score ) ) ? (float) $score : 0;
}

/**
 * Image resolution cascade (D4): featured image → first inline <img> in the
 * post body → null. Inline fallbacks have unknown dimensions (0×0), which the
 * MSN/Yahoo profiles treat as under-floor and drop.
 */
/**
 * Everything the profiles need to know about one attachment at one size:
 * primary rendition + smaller fallback candidates + dimension recovery.
 * Shared by the featured-image cascade and the Fix 9 inline-image gate.
 */
function mmgrf_attachment_image_data( $att_id, $size ) {
    $src = wp_get_attachment_image_src( $att_id, $size );
    if ( ! $src ) {
        return null;
    }
    // Metadata filesize first (works for offloaded media); disk second.
    $meta  = wp_get_attachment_metadata( $att_id );
    $bytes = is_array( $meta ) && ! empty( $meta['filesize'] ) ? (int) $meta['filesize'] : 0;
    if ( ! $bytes ) {
        $file  = get_attached_file( $att_id );
        $bytes = ( $file && file_exists( $file ) ) ? filesize( $file ) : 0;
    }
    // True rendition dimensions from metadata, not image_downsize()'s return —
    // themes that set $content_width make image_downsize report editor-
    // constrained dims (e.g. 696×464) against a 1024px rendition URL.
    [ $real_w, $real_h ] = mmgrf_real_dims( $meta, $src[0], (int) $src[1], (int) $src[2] );
    $img = [
        'attachment_id' => $att_id,
        'url'           => $src[0],
        'width'         => $real_w,
        'height'        => $real_h,
        'type'          => get_post_mime_type( $att_id ) ?: 'image/jpeg',
        'filesize'      => $bytes,
        'alt'           => (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true ),
        'caption'       => (string) wp_get_attachment_caption( $att_id ),
        'candidates'    => [],
    ];
    // Fix 2 amendment: metadata with no dimensions (API side-loads that
    // skipped resize) reports 0×0 — indistinguishable from a tiny image.
    // Recover real dimensions from the file before gating; only when that
    // also fails is the image marked unverifiable.
    if ( ! $img['width'] || ! $img['height'] ) {
        $file = get_attached_file( $att_id );
        $real = $file ? wp_getimagesize( $file ) : false;
        if ( $real ) {
            $img['width']  = (int) $real[0];
            $img['height'] = (int) $real[1];
        } else {
            $img['dims_unknown'] = true;
            $img['file']         = $file ?: '(no local file)';
        }
    }
    // Smaller renditions, largest first — profiles fall back to the biggest
    // one that fits their dimension/byte constraints (raw 8K wire uploads
    // exceed Yahoo's 5MB / MSN's 2MB limits at full size).
    foreach ( [ '2048x2048', 'large' ] as $alt_size ) {
        $alt = wp_get_attachment_image_src( $att_id, $alt_size );
        if ( $alt && $alt[0] !== $img['url'] ) {
            [ $aw, $ah ] = mmgrf_real_dims( $meta, $alt[0], (int) $alt[1], (int) $alt[2] );
            $img['candidates'][] = [ 'url' => $alt[0], 'width' => $aw, 'height' => $ah ];
        }
    }

    // Never advertise a file that is missing from local disk — media-library
    // edits can orphan metadata (a -e{ts} original that no longer exists),
    // and the partner's fetch then 404s ("Image didn't download" at Yahoo).
    // Enforced only when the upload directory itself is local; offloaded
    // media (S3 etc.) is exempt because nothing is on disk by design.
    $file = get_attached_file( $att_id );
    if ( $file && is_dir( dirname( $file ) ) ) {
        $dir = dirname( $file );
        $img['candidates'] = array_values( array_filter( $img['candidates'], function( $c ) use ( $dir ) {
            return file_exists( $dir . '/' . basename( (string) parse_url( $c['url'], PHP_URL_PATH ) ) );
        } ) );
        if ( ! file_exists( $file ) ) {
            if ( $img['candidates'] ) {
                $c = array_shift( $img['candidates'] );
                $img['file_note'] = 'original file missing on disk (media-library edit?) — served ' . basename( $c['url'] );
                $img['url']       = $c['url'];
                $img['width']     = (int) $c['width'];
                $img['height']    = (int) $c['height'];
                $img['filesize']  = 0;
            } else {
                $img['file_missing'] = true;
            }
        }
    }
    return $img;
}

/**
 * True rendition dimensions for a URL, from attachment metadata when it can
 * be matched by filename; falls back to the reported values otherwise.
 */
function mmgrf_real_dims( $meta, $url, $fallback_w, $fallback_h ) {
    if ( is_array( $meta ) ) {
        $base = wp_basename( (string) parse_url( $url, PHP_URL_PATH ) );
        if ( ! empty( $meta['file'] ) && wp_basename( $meta['file'] ) === $base
            && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
            return [ (int) $meta['width'], (int) $meta['height'] ];
        }
        foreach ( (array) ( $meta['sizes'] ?? [] ) as $s ) {
            if ( ! empty( $s['file'] ) && $s['file'] === $base && ! empty( $s['width'] ) && ! empty( $s['height'] ) ) {
                return [ (int) $s['width'], (int) $s['height'] ];
            }
        }
    }
    return [ $fallback_w, $fallback_h ];
}

/** Request-memoized attachment_url_to_postid (a DB query per distinct URL). */
function mmgrf_url_to_attachment( $url ) {
    static $memo = [];
    if ( ! array_key_exists( $url, $memo ) ) {
        $memo[ $url ] = (int) attachment_url_to_postid( $url );
    }
    return $memo[ $url ];
}

/**
 * Conditional-GET decision: serve 304 when the client's If-Modified-Since is
 * at or past the site's last publish. Malformed or absent headers → full body.
 */
function mmgrf_feed_not_modified( $if_modified_since, $lastmod_ts ) {
    if ( ! is_string( $if_modified_since ) || trim( $if_modified_since ) === '' ) {
        return false;
    }
    $since = strtotime( $if_modified_since );
    return $since !== false && $since >= (int) $lastmod_ts;
}

/** Standalone rendition picker (profile method delegates here). */
function mmgrf_pick_image_fit( $img, $min_w, $min_h, $max_bytes = 0 ) {
    if ( ! $img ) {
        return null;
    }
    $fits = function( $w, $h, $bytes ) use ( $min_w, $min_h, $max_bytes ) {
        return $w >= $min_w && $h >= $min_h && ( ! $max_bytes || ! $bytes || $bytes <= $max_bytes );
    };
    if ( empty( $img['file_missing'] ) && $fits( (int) $img['width'], (int) $img['height'], (int) ( $img['filesize'] ?? 0 ) ) ) {
        return $img;
    }
    foreach ( $img['candidates'] ?? [] as $c ) {
        if ( $fits( (int) $c['width'], (int) $c['height'], 0 ) ) {
            return array_merge( $img, [ 'url' => $c['url'], 'width' => (int) $c['width'], 'height' => (int) $c['height'], 'filesize' => 0 ] );
        }
    }
    return null;
}

function mmgrf_resolve_image( $post_id, $size, $content ) {
    $thumb_id = get_post_thumbnail_id( $post_id );
    if ( $thumb_id ) {
        $img = mmgrf_attachment_image_data( $thumb_id, $size );
        if ( $img ) {
            $img['credit']         = mmgrf_resolve_credit( $post_id, $thumb_id );
            $img['no_synd_rights'] = (bool) get_post_meta( $thumb_id, '_mmgrf_no_syndication_rights', true );
            return $img;
        }
    }
    if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $content, $m ) ) {
        foreach ( $m[1] as $src ) {
            // Skip data: URIs (lazy-load placeholders) and anything not
            // http(s), protocol-relative, or a rooted path.
            if ( preg_match( '#^https?://#i', $src ) ) {
                $url = $src;
            } elseif ( substr( $src, 0, 2 ) === '//' ) {
                $url = 'https:' . $src;
            } elseif ( $src !== '' && $src[0] === '/' ) {
                $url = rtrim( home_url(), '/' ) . $src;
            } else {
                continue;
            }
            // Fix 9 amendment: real mime when the URL maps to a local
            // attachment — this path previously hardcoded image/jpeg and
            // mislabeled every non-JPEG body-scraped image.
            $att_id = mmgrf_url_to_attachment( mmgrf_strip_size_suffix( $url ) );
            if ( ! $att_id ) {
                $att_id = mmgrf_url_to_attachment( $url );
            }
            $mime = $att_id ? ( get_post_mime_type( $att_id ) ?: 'image/jpeg' ) : 'image/jpeg';
            return [
                'attachment_id'  => $att_id ?: 0,
                'url'            => $url,
                'width'          => 0,
                'height'         => 0,
                'type'           => $mime,
                'filesize'       => 0,
                'alt'            => '',
                'caption'        => '',
                'credit'         => '',
                'no_synd_rights' => false,
            ];
        }
    }
    return null;
}

/** "…/img/wire-1024x683.jpg" → "…/img/wire.jpg" (WP rendition suffix). */
function mmgrf_strip_size_suffix( $url ) {
    return preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $url );
}

/** photo_credit → _photo_credit → attachment caption (live NewsBreak-plugin order). */
function mmgrf_resolve_credit( $post_id, $thumb_id ) {
    $credit = get_post_meta( $post_id, 'photo_credit', true );
    if ( ! $credit ) {
        $credit = get_post_meta( $post_id, '_photo_credit', true );
    }
    if ( ! $credit ) {
        $credit = wp_get_attachment_caption( $thumb_id );
    }
    return (string) $credit;
}

function mmgrf_build_link( $post_link, $opts ) {
    if ( empty( $opts['utm_enabled'] ) ) {
        return $post_link;
    }
    $sep = ( strpos( $post_link, '?' ) !== false ) ? '&' : '?';
    return $post_link . $sep . 'utm_source=' . urlencode( $opts['utm_source'] ) . '&utm_medium=' . urlencode( $opts['utm_medium'] );
}

/**
 * Query posts and build normalized items for a profile. Applies per-post
 * network exclusions and (for network profiles) the policy category/tag
 * exclusion. Items returned are NOT yet profile-prepared/validated.
 */
function mmgrf_build_items( $profile, $opts, $filters ) {
    $network = $profile->get_key();

    $query_args = [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => max( 1, (int) $opts['post_count'] ),
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];
    $cat_in  = mmgrf_cat_slugs_to_ids( $filters['categories'] ?? '' );
    $cat_out = mmgrf_cat_slugs_to_ids( $filters['exclude_cats'] ?? '' );
    $tag_in  = mmgrf_tag_slugs_to_ids( $filters['tags'] ?? '' );
    $tag_out = mmgrf_tag_slugs_to_ids( $filters['exclude_tags'] ?? '' );

    // Policy exclusion: sponsored/partner content withheld from syndication
    // networks by category/tag, without touching the aigeon legacy feed (G4).
    if ( $network !== 'aigeon' ) {
        $cat_out = array_merge( $cat_out, mmgrf_cat_slugs_to_ids( $opts['exclude_networks_cats'] ?? '' ) );
        $tag_out = array_merge( $tag_out, mmgrf_tag_slugs_to_ids( $opts['exclude_networks_tags'] ?? '' ) );
        // Slideshow posts ship through the slideshow feeds, never as articles.
        $tag_out = array_merge( $tag_out, mmgrf_slideshow_marker_ids() );
    }

    if ( $cat_in )  { $query_args['category__in']     = $cat_in; }
    if ( $cat_out ) { $query_args['category__not_in'] = array_values( array_unique( $cat_out ) ); }
    if ( $tag_in )  { $query_args['tag__in']          = $tag_in; }
    if ( $tag_out ) { $query_args['tag__not_in']      = array_values( array_unique( $tag_out ) ); }

    $query = new WP_Query( $query_args );
    $items = [];

    foreach ( $query->posts as $post ) {
        $post_id = $post->ID;

        if ( get_post_meta( $post_id, '_mmgrf_exclude_' . $network, true ) === '1' ) {
            continue;
        }

        // Shortcodes, block renderers, and third-party the_content filters read
        // the global post — set it up per item exactly as a the_post() loop would.
        $GLOBALS['post'] = $post;
        setup_postdata( $post );

        // Let ad-injection / related-post plugins be unhooked before the
        // content build (D6): hook mmgrf_pre_content / mmgrf_post_content.
        do_action( 'mmgrf_pre_content', $post_id, $network );
        $content = apply_filters( 'the_content', get_the_content( null, false, $post_id ) );
        do_action( 'mmgrf_post_content', $post_id, $network );

        // A space per tag boundary keeps adjacent blocks from merging words
        // ("...ends here.</p><p>Second..." → "here. Second", not "here.Second").
        // Literal ellipsis, not &hellip; — the value lands inside CDATA where
        // entities are not decoded by the XML parser.
        $excerpt = has_excerpt( $post_id )
            ? get_the_excerpt( $post_id )
            : wp_trim_words( trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( str_replace( '<', ' <', $content ) ) ) ), 55, '…' );

        $categories = get_the_category( $post_id );
        $post_tags  = get_the_tags( $post_id );
        $permalink  = get_permalink( $post_id );

        $items[] = [
            'id'            => $post_id,
            'title'         => mmgrf_plain_text( get_the_title( $post_id ) ),
            'short_title'   => (string) get_post_meta( $post_id, '_mmgrf_short_title', true ),
            'permalink'     => $permalink,
            'link'          => mmgrf_build_link( $permalink, $opts ),
            'pub_ts'        => (int) get_post_time( 'U', true, $post_id ),
            'mod_ts'        => (int) get_post_modified_time( 'U', true, $post_id ),
            'author'        => get_the_author_meta( 'display_name', $post->post_author ),
            'categories'    => array_map( fn( $c ) => $c->name, $categories ?: [] ),
            'tags'          => $post_tags ? array_map( fn( $t ) => $t->name, $post_tags ) : [],
            'score'         => mmgrf_get_score( $post_id ),
            'excerpt'       => mmgrf_plain_text( $excerpt ),
            'content'       => $content,
            'image'         => mmgrf_resolve_image( $post_id, $opts['image_size'], $content ),
            'comments_feed' => get_post_comments_feed_link( $post_id ),
            'comment_count' => (int) get_comments_number( $post_id ),
        ];
    }

    wp_reset_postdata();
    return $items;
}

/** Render a complete feed document for a profile. Returns the XML string. */
function mmgrf_render_feed( $profile, $opts, $filters ) {
    $items = mmgrf_build_items( $profile, $opts, $filters );

    $body = '';
    foreach ( $items as $item ) {
        $item = $profile->prepare_item( $item, $opts );
        $v    = $profile->validate_item( $item );
        if ( ! $v['pass'] ) {
            mmgrf_skip_log_add( $profile->get_key(), $item['id'], $item['title'], $v['code'], $v['detail'] );
            continue;
        }
        if ( ! empty( $item['image_dropped'] ) ) {
            mmgrf_skip_log_add( $profile->get_key(), $item['id'], $item['title'], $item['image_dropped']['code'], $item['image_dropped']['detail'], 'warn' );
        }
        $notes = array_merge( $item['inline_image_log'] ?? [], $item['affiliate_log'] ?? [] );
        if ( ! empty( $item['image_note'] ) ) {
            $notes[] = $item['image_note'];
        }
        if ( ! empty( $item['image']['file_note'] ) ) {
            $notes[] = [ 'code' => 'image_file_missing', 'detail' => $item['image']['file_note'] ];
        }
        foreach ( $notes as $ev ) {
            mmgrf_skip_log_add( $profile->get_key(), $item['id'], $item['title'], $ev['code'], $ev['detail'], 'warn' );
        }
        if ( ! empty( $item['san']['has_embeds'] ) && $profile->get_key() === 'yahoo' ) {
            // Yahoo mangles embeds not matching its expected markup — ship, but flag for review.
            mmgrf_skip_log_add( 'yahoo', $item['id'], $item['title'], 'embed_review', 'post contains iframe embeds', 'warn' );
        }
        if ( $profile->get_key() === 'msn' && ! empty( $item['image'] )
            && ( $item['image']['width'] < MMGRF_Profile_MSN::IMG_WARN_W || $item['image']['height'] < MMGRF_Profile_MSN::IMG_WARN_H ) ) {
            mmgrf_skip_log_add( 'msn', $item['id'], $item['title'], 'image_below_recommended', $item['image']['width'] . 'x' . $item['image']['height'] . ' under MSN recommended 1280x720', 'warn' );
        }
        $body .= $profile->render_item( $item, $opts );
    }

    $ns = '';
    foreach ( $profile->get_namespaces() as $prefix => $uri ) {
        $ns .= "\n     xmlns:{$prefix}=\"{$uri}\"";
    }

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<rss version="2.0"' . $ns . ">\n";
    $xml .= "  <channel>\n";
    $xml .= $profile->get_channel_open( $opts );
    // Version diagnosable from the feed itself — deployed-version skew across
    // the portfolio has cost real diagnosis time twice.
    $xml .= '    <generator>Scoreline Feeds ' . mmgrf_xml( MMGRF_VERSION ) . "</generator>\n";
    $xml .= $profile->get_channel_extras( $opts );
    $xml .= $body;
    $xml .= "  </channel>\n";
    $xml .= "</rss>\n";
    return $xml;
}

/**
 * Resolve a network's feed XML, via a short server-side cache for network
 * profiles. At 104 brands with MSN polling every ~15 min and Yahoo every
 * ~5 min, uncached full-content renders are real database load. The cache key
 * includes lastpostmodified, so a new publish busts it instantly; a 60s TTL
 * bounds staleness for everything else (settings edits, meta changes).
 * The aigeon legacy feed stays uncached — matches current production behavior.
 */
function mmgrf_get_feed_output( $network, $filters = [] ) {
    $profile = mmgrf_get_profile( $network );
    $opts    = mmgrf_network_options( $network );

    if ( $network === 'aigeon' ) {
        return mmgrf_render_feed( $profile, $opts, $filters );
    }

    $key    = 'mmgrf_feed_' . md5( $network . '|' . $opts['feed_slug'] . '|' . mmgrf_feed_lastmod() . '|' . MMGRF_VERSION );
    $cached = get_transient( $key );
    if ( $cached !== false ) {
        return $cached;
    }
    $xml = mmgrf_render_feed( $profile, $opts, $filters );
    set_transient( $key, $xml, 60 );
    return $xml;
}

/**
 * Feed last-modified moment: latest publish OR latest settings save —
 * without the config component, a settings change with no new post would
 * serve 304s (and cached bodies) to partners indefinitely.
 */
function mmgrf_feed_lastmod() {
    $posts = strtotime( get_lastpostmodified( 'GMT' ) . ' UTC' ) ?: 0;
    return max( $posts, (int) get_option( 'mmgrf_config_touched', 0 ) );
}

/** Record that feed-affecting configuration changed. */
function mmgrf_touch_config() {
    update_option( 'mmgrf_config_touched', time(), 'no' );
}

/** Shared conditional-GET + cache headers. Returns true when 304 was served. */
function mmgrf_feed_headers_and_maybe_304() {
    $lastmod = mmgrf_feed_lastmod();
    header( 'Content-Type: application/rss+xml; charset=UTF-8' );
    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
    header( 'Pragma: no-cache' );
    header( 'Expires: Thu, 01 Jan 1970 00:00:00 GMT' );
    if ( $lastmod ) {
        header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $lastmod ) . ' GMT' );
        if ( mmgrf_feed_not_modified( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '', $lastmod ) ) {
            status_header( 304 );
            return true;
        }
    }
    return false;
}

/** WP feed callback: headers + echo. */
function mmgrf_output_network_feed( $network, $filters = [] ) {
    if ( mmgrf_feed_headers_and_maybe_304() ) {
        return;
    }
    echo mmgrf_get_feed_output( $network, $filters );
}
