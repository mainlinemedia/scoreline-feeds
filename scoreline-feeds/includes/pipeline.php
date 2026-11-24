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
    return mmgrf_resolve_slugs( $slugs_string, $taxonomy )['ids'];
}

/**
 * Resolve a comma list of term slugs. Returns the matched IDs AND the slugs
 * that matched nothing — a renamed, merged or mistyped term must be visible,
 * never a silent no-op (an include filter that quietly stops filtering turns
 * a topic feed into the whole site; an exclusion that quietly stops excluding
 * ships sponsored content).
 */
function mmgrf_resolve_slugs( $slugs_string, $taxonomy ) {
    $out = [ 'ids' => [], 'missing' => [] ];
    foreach ( explode( ',', (string) $slugs_string ) as $slug ) {
        $slug = trim( $slug );
        if ( $slug === '' ) {
            continue;
        }
        $term = get_term_by( 'slug', $slug, $taxonomy );
        if ( $term ) {
            $out['ids'][] = (int) $term->term_id;
        } else {
            $out['missing'][] = $slug;
        }
    }
    return $out;
}

/**
 * Term IDs plus all their descendants. WP_Query's category__not_in matches
 * the listed terms only (include_children is false), so a post filed under a
 * CHILD of an excluded category would otherwise ship.
 */
function mmgrf_with_term_children( $ids, $taxonomy ) {
    $all = array_map( 'intval', (array) $ids );
    foreach ( (array) $ids as $id ) {
        $kids = get_term_children( (int) $id, $taxonomy );
        if ( is_array( $kids ) ) {
            $all = array_merge( $all, array_map( 'intval', $kids ) );
        }
    }
    return array_values( array_unique( $all ) );
}

/**
 * Plain-text fields (title, excerpt) are emitted inside CDATA, where entities
 * are NOT decoded by the XML parser — a stored "&#8217;" would appear as
 * literal text at the partner. Decode once at build time.
 */
function mmgrf_plain_text( $value ) {
    return html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * Remove real markup from a value destined for a plain-text field. Only
 * well-formed tags are removed: strip_tags() would truncate a headline like
 * "Lakers <3 their chances" at the stray bracket.
 */
function mmgrf_strip_markup( $value ) {
    $value = (string) $value;
    $out   = preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $value );
    $out   = preg_replace( '#</?[a-z][a-z0-9]*\b[^<>]*>#i', '', $out ?? $value );
    return $out ?? $value;
}

/**
 * A WordPress-stored string as clean plain text: no tags, entities decoded
 * once, and nothing XML forbids (decoding "&#12;" manufactures a raw form
 * feed; a Latin-1 byte from a wire import breaks every later /u regex).
 */
function mmgrf_text_field( $value ) {
    return trim( mmgrf_xml_chars( mmgrf_plain_text( mmgrf_strip_markup( mmgrf_utf8( $value ) ) ) ) );
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
    // Bytes of the rendition actually served — metadata first (works for
    // offloaded media), disk second.
    $meta  = wp_get_attachment_metadata( $att_id );
    $bytes = mmgrf_rendition_bytes( $meta, get_attached_file( $att_id ), $src[0] );
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
        'alt'           => mmgrf_text_field( get_post_meta( $att_id, '_wp_attachment_image_alt', true ) ),
        'caption'       => mmgrf_text_field( wp_get_attachment_caption( $att_id ) ),
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
            $img['candidates'][] = [
                'url'      => $alt[0],
                'width'    => $aw,
                'height'   => $ah,
                'filesize' => mmgrf_rendition_bytes( $meta, get_attached_file( $att_id ), $alt[0] ),
            ];
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
                $img['filesize']  = (int) ( $c['filesize'] ?? 0 );
            } else {
                $img['file_missing'] = true;
            }
        }
    }
    return $img;
}

/**
 * Byte size of the file a rendition URL points at. The top-level metadata
 * 'filesize' is the ORIGINAL's; a sub-size has its own entry (WP 6.0+) or is
 * measured on disk. 0 = unknown (callers treat unknown as "cannot prove it is
 * over the limit").
 */
function mmgrf_rendition_bytes( $meta, $file, $url ) {
    $base        = wp_basename( (string) parse_url( (string) $url, PHP_URL_PATH ) );
    $is_original = ! is_array( $meta ) || empty( $meta['file'] ) || wp_basename( $meta['file'] ) === $base;
    if ( $is_original ) {
        if ( is_array( $meta ) && ! empty( $meta['filesize'] ) ) {
            return (int) $meta['filesize'];
        }
        return ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0;
    }
    foreach ( (array) ( $meta['sizes'] ?? [] ) as $s ) {
        if ( ( $s['file'] ?? '' ) === $base && ! empty( $s['filesize'] ) ) {
            return (int) $s['filesize'];
        }
    }
    $path = $file ? dirname( $file ) . '/' . $base : '';
    return ( $path !== '' && file_exists( $path ) ) ? (int) filesize( $path ) : 0;
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
    $url = (string) $url;
    if ( ! isset( $GLOBALS['mmgrf_url_memo'][ $url ] ) ) {
        $GLOBALS['mmgrf_url_memo'][ $url ] = (int) attachment_url_to_postid( $url );
    }
    return $GLOBALS['mmgrf_url_memo'][ $url ];
}

/**
 * Map any URL of a media-library image or video — a sized rendition, a
 * "-scaled" big-image original, a CDN/Photon host, a cache-busting query
 * string — to its attachment ID. 0 when it is not one of this site's uploads.
 *
 * attachment_url_to_postid() matches the stored _wp_attached_file exactly, so
 * on its own it misses every rendition of an upload wider than 2560px (stored
 * as photo-scaled.jpg while renditions are photo-1024x683.jpg) and anything
 * served from another host. URLs outside the uploads tree cost no query.
 *
 * $hint_id: an attachment ID suggested by the markup (wp-image-{id}).
 */
function mmgrf_find_attachment( $url, $hint_id = 0 ) {
    $url = html_entity_decode( trim( (string) $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    if ( $url === '' ) {
        return 0;
    }
    // A wp-image-{id} class is a free answer — but content pasted from
    // another site carries foreign IDs, so it is trusted only when that
    // attachment's own file is the same photo.
    if ( $hint_id > 0 ) {
        $own = wp_get_attachment_url( $hint_id );
        if ( $own && mmgrf_image_identity( $own ) === mmgrf_image_identity( $url ) ) {
            $GLOBALS['mmgrf_find_memo'][ $url ] = (int) $hint_id;
            return (int) $hint_id;
        }
    }
    if ( isset( $GLOBALS['mmgrf_find_memo'][ $url ] ) ) {
        return $GLOBALS['mmgrf_find_memo'][ $url ];
    }
    $id   = 0;
    $norm = mmgrf_normalize_upload_url( $url );
    if ( $norm !== '' ) {
        $stripped = mmgrf_strip_size_suffix( $norm );
        $scaled   = preg_replace( '/(\.[a-z0-9]+)$/i', '-scaled$1', $stripped );
        foreach ( array_unique( [ $stripped, $scaled, $norm ] ) as $try ) {
            $id = mmgrf_url_to_attachment( $try );
            if ( $id ) {
                break;
            }
        }
    }
    $GLOBALS['mmgrf_find_memo'][ $url ] = $id;
    return $id;
}

/**
 * Rewrite a media URL onto this site's uploads base URL (dropping query and
 * fragment), or '' when it does not point into the uploads tree at all.
 */
function mmgrf_normalize_upload_url( $url ) {
    $upload = wp_get_upload_dir();
    $base   = rtrim( (string) ( $upload['baseurl'] ?? '' ), '/' );
    if ( substr( $url, 0, 2 ) === '//' ) {
        $url = 'https:' . $url;
    }
    $parts = parse_url( $url );
    if ( $base === '' || ! is_array( $parts ) || empty( $parts['path'] ) ) {
        return '';
    }
    // The uploads path alone proves nothing: every WordPress site has
    // /wp-content/uploads/YYYY/MM/, and another site's photo.jpg must never
    // be taken for ours. The host has to be one of this site's.
    $path = $parts['path'];
    $host = strtolower( (string) ( $parts['host'] ?? '' ) );
    if ( $host !== '' && ! mmgrf_is_own_media_host( $host, $path, $base ) ) {
        return '';
    }
    $base_path = rtrim( (string) parse_url( $base, PHP_URL_PATH ), '/' );
    if ( $base_path !== '' ) {
        // The uploads path may sit behind a prefix (Photon puts the site
        // host in front of it).
        $pos = strpos( $path, $base_path . '/' );
        if ( $pos === false ) {
            return '';
        }
        return $base . substr( $path, $pos + strlen( $base_path ) );
    }
    return $base . $path;
}

/**
 * Is $host one that serves THIS site's media library? The site itself, its
 * uploads host, their www/sub-domain forms (cdn.example.com), Jetpack Photon
 * when it is proxying this site, or a host declared through the
 * mmgrf_media_hosts filter (CloudFront and other off-domain CDNs).
 */
function mmgrf_is_own_media_host( $host, $path, $upload_base ) {
    $own = [];
    foreach ( [ home_url(), $upload_base ] as $u ) {
        $h = strtolower( (string) parse_url( (string) $u, PHP_URL_HOST ) );
        if ( $h !== '' ) {
            $own[ $h ]                               = true;
            $own[ preg_replace( '/^www\./', '', $h ) ] = true;
        }
    }
    foreach ( array_keys( $own ) as $o ) {
        if ( $host === $o || substr( $host, -strlen( ".$o" ) ) === ".$o" ) {
            return true;
        }
    }
    if ( preg_match( '/^i\d\.wp\.com$/', $host ) ) {
        $first = strtolower( (string) strtok( ltrim( $path, '/' ), '/' ) );
        return isset( $own[ $first ] ) || isset( $own[ preg_replace( '/^www\./', '', $first ) ] );
    }
    $extra = array_map( 'strtolower', (array) apply_filters( 'mmgrf_media_hosts', [] ) );
    return in_array( $host, $extra, true );
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
        $c_bytes = (int) ( $c['filesize'] ?? 0 );
        if ( $fits( (int) $c['width'], (int) $c['height'], $c_bytes ) ) {
            $fit = array_merge( $img, [ 'url' => $c['url'], 'width' => (int) $c['width'], 'height' => (int) $c['height'], 'filesize' => $c_bytes ] );
            unset( $fit['file_missing'] ); // this rendition exists; only the original was gone
            return $fit;
        }
    }
    return null;
}

function mmgrf_resolve_image( $post_id, $size, $content ) {
    $thumb_id = get_post_thumbnail_id( $post_id );
    if ( $thumb_id ) {
        $img = mmgrf_attachment_image_data( $thumb_id, $size );
        if ( $img ) {
            $img['credit']         = mmgrf_text_field( mmgrf_resolve_credit( $post_id, $thumb_id ) );
            $img['no_synd_rights'] = (bool) get_post_meta( $thumb_id, '_mmgrf_no_syndication_rights', true );
            return $img;
        }
    }
    if ( preg_match_all( '/<img\b[^>]*?\ssrc=["\']([^"\']+)["\']/i', (string) $content, $m ) ) {
        foreach ( $m[1] as $src ) {
            // Rendered content carries entity-encoded URLs (&#038;) — decode
            // once here; the emitter escapes exactly once on the way out.
            $src = html_entity_decode( $src, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
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
            $att_id = mmgrf_find_attachment( $url );
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
 * Seconds a just-published post is held back from the network feeds.
 *
 * Syndication is irreversible: partners do not retract an item that later
 * drops out of the feed. In the block editor the "publish" status commits
 * BEFORE the post's categories, featured image and Syndication box are
 * saved, so a partner poll landing inside that gap would ship a sponsored
 * post without its exclusion. A short settle window closes the gap (and
 * gives an editor a moment to pull back an accidental publish). 0 disables.
 */
function mmgrf_settle_seconds() {
    $opts = mmgrf_get_options();
    return max( 0, min( 900, (int) ( $opts['settle_seconds'] ?? 60 ) ) );
}

/**
 * Terms withheld from every syndication network by policy: the configured
 * sponsored/partner categories INCLUDING their child categories, and the
 * configured tags. Shared by the article, slideshow and video feeds.
 */
function mmgrf_policy_exclusions() {
    $base    = mmgrf_get_options();
    $cats    = mmgrf_resolve_slugs( $base['exclude_networks_cats'] ?? '', 'category' );
    $tags    = mmgrf_resolve_slugs( $base['exclude_networks_tags'] ?? '', 'post_tag' );
    $missing = [];
    if ( $cats['missing'] ) {
        $missing[] = 'Network-Excluded Categories: ' . implode( ', ', $cats['missing'] );
    }
    if ( $tags['missing'] ) {
        $missing[] = 'Network-Excluded Tags: ' . implode( ', ', $tags['missing'] );
    }
    return [
        'cats'    => mmgrf_with_term_children( $cats['ids'], 'category' ),
        'tags'    => $tags['ids'],
        'missing' => $missing,
    ];
}

/** Log a policy exclusion that currently matches no term (it excludes nothing). */
function mmgrf_log_missing_exclusions( $log_network, $missing ) {
    if ( $missing ) {
        mmgrf_skip_log_add( $log_network, 0, '(feed settings)', 'exclusion_term_missing', implode( '; ', $missing ) . ' — no such term on this site, so nothing is being excluded for it', 'warn' );
    }
}

/**
 * Raw post body for rendering. get_the_content() returns only the first page
 * of a post split with <!--nextpage-->; a partner must receive the whole
 * article, so paginated posts are rebuilt from every page.
 */
function mmgrf_raw_post_content( $post ) {
    $raw = (string) get_the_content( null, false, $post );
    if ( strpos( (string) $post->post_content, '<!--nextpage-->' ) !== false && ! post_password_required( $post ) ) {
        $raw = str_replace( '<!--nextpage-->', "\n\n", (string) $post->post_content );
        $raw = (string) preg_replace( '/<!--more(.*?)?-->/', '', $raw );
    }
    return $raw;
}

/**
 * The readable text of a rendered body: photo captions and credits, players,
 * embeds, scripts and form chrome removed, tags stripped, whitespace
 * collapsed. A space per tag boundary keeps adjacent blocks from merging
 * words ("...ends here.</p><p>Second..." → "here. Second").
 */
function mmgrf_lede_text( $content ) {
    $content = (string) $content;
    $lede    = preg_replace( '#<(figure|figcaption|script|style|noscript|iframe|video|audio|svg|form|button|select)\b[^>]*>.*?</\1\s*>#is', ' ', $content ) ?? $content;
    $lede    = wp_strip_all_tags( str_replace( '<', ' <', $lede ) );
    return trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $lede ) ?? $lede );
}

/**
 * Posts for a typed (slideshow/video) feed, under exactly the withholding
 * rules the article feed applies: published, not password-protected, not in
 * a policy-excluded category or tag, not excluded per post for this network
 * ("Exclude from networks" / "Sponsored content"), and past the settle
 * window. A typed feed that skipped these shipped sponsored posts.
 */
function mmgrf_typed_feed_posts( $network, $marker_ids, $post_count, $log_network, $not_marker_ids = [] ) {
    if ( ! $marker_ids ) {
        return []; // the marker tag does not exist on this site: nothing is tagged
    }
    $policy = mmgrf_policy_exclusions();
    mmgrf_log_missing_exclusions( $log_network, $policy['missing'] );

    $args = [
        'post_type'           => 'post',
        'post_status'         => 'publish',
        'posts_per_page'      => max( 1, (int) $post_count ),
        'orderby'             => 'date',
        'order'               => 'DESC',
        'tag__in'             => array_values( array_unique( array_map( 'intval', $marker_ids ) ) ),
        'ignore_sticky_posts' => true,
        'has_password'        => false,
        'no_found_rows'       => true,
    ];
    if ( $policy['cats'] ) {
        $args['category__not_in'] = $policy['cats'];
    }
    $not_tags = array_values( array_unique( array_merge( $policy['tags'], array_map( 'intval', (array) $not_marker_ids ) ) ) );
    if ( $not_tags ) {
        $args['tag__not_in'] = $not_tags;
    }

    $hold  = mmgrf_settle_seconds();
    $posts = [];
    foreach ( ( new WP_Query( $args ) )->posts as $post ) {
        if ( get_post_meta( $post->ID, '_mmgrf_exclude_' . $network, true ) === '1' ) {
            continue;
        }
        if ( $hold > 0 && (int) get_post_time( 'U', true, $post->ID ) > time() - $hold ) {
            continue;
        }
        $posts[] = $post;
    }
    return $posts;
}

/** XML declaration through the channel header for a typed feed. */
function mmgrf_typed_channel_open( $namespaces, $opts ) {
    $ns_block = '';
    foreach ( $namespaces as $prefix => $uri ) {
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
    return $xml;
}

/**
 * A post's tags as keyword terms: names decoded, commas removed (the list is
 * comma-separated), and the routing marker tags left out — "slideshow" and
 * "video" describe the feed, not the story. $max 0 = no cap.
 */
function mmgrf_post_keywords( $post_id, $max = 0 ) {
    $tags = get_the_tags( $post_id );
    if ( ! is_array( $tags ) ) {
        return [];
    }
    $markers  = array_merge( mmgrf_slideshow_marker_ids(), mmgrf_video_marker_ids() );
    $keywords = [];
    foreach ( $tags as $tag ) {
        if ( in_array( (int) $tag->term_id, $markers, true ) ) {
            continue;
        }
        $name = trim( (string) preg_replace( '/\s+/', ' ', str_replace( ',', ' ', mmgrf_text_field( $tag->name ) ) ) );
        if ( $name !== '' ) {
            $keywords[] = $name;
        }
    }
    $keywords = array_values( array_unique( $keywords ) );
    return $max > 0 ? array_slice( $keywords, 0, $max ) : $keywords;
}

/** Run the_content for a post between the integrator hooks (always paired). */
function mmgrf_rendered_post_content( $post, $network ) {
    // Let ad-injection / related-post plugins be unhooked before the
    // content build (D6): hook mmgrf_pre_content / mmgrf_post_content.
    do_action( 'mmgrf_pre_content', $post->ID, $network );
    try {
        return (string) apply_filters( 'the_content', mmgrf_raw_post_content( $post ) );
    } finally {
        do_action( 'mmgrf_post_content', $post->ID, $network );
    }
}

/**
 * Run a render with all stray output swallowed. A the_content filter that
 * echoes, or a PHP notice with display_errors on, would otherwise land in
 * front of the XML declaration and make the whole document ill-formed.
 */
function mmgrf_quiet( callable $fn ) {
    $level = ob_get_level();
    ob_start();
    try {
        return $fn();
    } finally {
        while ( ob_get_level() > $level ) {
            ob_end_clean();
        }
    }
}

/** One post failed to build or render: record it, the feed carries on. */
function mmgrf_log_render_error( $log_network, $post_id, $title, $e ) {
    $detail = get_class( $e ) . ': ' . mb_substr( (string) $e->getMessage(), 0, 200 )
        . ' (' . wp_basename( $e->getFile() ) . ':' . $e->getLine() . ') — this item was withheld; the rest of the feed shipped';
    mmgrf_skip_log_add( $log_network, $post_id, $title !== '' ? $title : '(untitled)', 'render_error', $detail );
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( 'Scoreline Feeds [' . $log_network . '] post ' . (int) $post_id . ': ' . $e ); // phpcs:ignore
    }
}

/**
 * Query posts and build normalized items for a profile. Applies per-post
 * network exclusions and (for network profiles) the policy category/tag
 * exclusion. Items returned are NOT yet profile-prepared/validated.
 */
function mmgrf_build_items( $profile, $opts, $filters ) {
    $network   = $profile->get_key();
    $log_label = $network . ( isset( $opts['feed_slug'] ) && $network === 'aigeon' ? ':' . $opts['feed_slug'] : '' );

    $query_args = [
        'post_type'           => 'post',
        'post_status'         => 'publish',
        'posts_per_page'      => max( 1, (int) $opts['post_count'] ),
        'orderby'             => 'date',
        'order'               => 'DESC',
        // Without this a secondary query counts as "home": core prepends
        // sticky posts via a bare post__in query that ignores the NOT IN
        // exclusions below and the item cap.
        'ignore_sticky_posts' => true,
        // A password-protected post is "published"; its content is the
        // password form. Never syndicate it.
        'has_password'        => false,
        'no_found_rows'       => true, // no pagination here; skip the COUNT pass
    ];

    // Include filters fail CLOSED: when a configured include list matches no
    // existing term (tag renamed, merged or deleted), dropping the filter
    // would turn a topic feed into every post on the site.
    foreach ( [ 'categories' => [ 'category', 'category__in' ], 'tags' => [ 'post_tag', 'tag__in' ] ] as $key => [ $taxonomy, $arg ] ) {
        $raw = trim( (string) ( $filters[ $key ] ?? '' ) );
        if ( $raw === '' ) {
            continue;
        }
        $resolved = mmgrf_resolve_slugs( $raw, $taxonomy );
        if ( ! $resolved['ids'] ) {
            mmgrf_skip_log_add( $log_label, 0, '(feed settings)', 'filter_term_missing', "include {$key} \"{$raw}\" matches no existing term — feed left empty rather than filled with every post" );
            return [];
        }
        $query_args[ $arg ] = $resolved['ids'];
    }

    $cat_out = mmgrf_resolve_slugs( $filters['exclude_cats'] ?? '', 'category' );
    $tag_out = mmgrf_resolve_slugs( $filters['exclude_tags'] ?? '', 'post_tag' );
    $missing = [];
    if ( $cat_out['missing'] ) {
        $missing[] = 'Exclude Categories: ' . implode( ', ', $cat_out['missing'] );
    }
    if ( $tag_out['missing'] ) {
        $missing[] = 'Exclude Tags: ' . implode( ', ', $tag_out['missing'] );
    }
    $not_cats = $cat_out['ids'];
    $not_tags = $tag_out['ids'];

    // Policy exclusion: sponsored/partner content withheld from syndication
    // networks by category/tag, without touching the aigeon legacy feed (G4).
    if ( $network !== 'aigeon' ) {
        $policy   = mmgrf_policy_exclusions();
        $not_cats = array_merge( $not_cats, $policy['cats'] );
        $not_tags = array_merge( $not_tags, $policy['tags'] );
        $missing  = array_merge( $missing, $policy['missing'] );
        // Slideshow and video posts ship through their typed feeds, never as
        // articles. (That includes NewsBreak, which has no typed feed: it
        // has always been withheld there, and starting to ship those posts
        // on an update would deliver a backlog that cannot be recalled.)
        $not_tags = array_merge( $not_tags, mmgrf_slideshow_marker_ids(), mmgrf_video_marker_ids() );
    }
    mmgrf_log_missing_exclusions( $log_label, $missing );

    if ( $not_cats ) {
        $query_args['category__not_in'] = array_values( array_unique( $not_cats ) );
    }
    if ( $not_tags ) {
        $query_args['tag__not_in'] = array_values( array_unique( $not_tags ) );
    }

    $query = new WP_Query( $query_args );
    $hold  = $network === 'aigeon' ? 0 : mmgrf_settle_seconds();
    $items = [];

    foreach ( $query->posts as $post ) {
        // Containment: one post whose shortcode or block throws must cost
        // that one item, not the whole feed on every network.
        try {
            $item = mmgrf_build_item( $post, $network, $opts, $hold );
        } catch ( \Throwable $e ) {
            $item = null;
            mmgrf_log_render_error( $network, $post->ID, mmgrf_text_field( $post->post_title ?? '' ), $e );
        }
        if ( $item ) {
            $items[] = $item;
        }
    }

    wp_reset_postdata();
    return $items;
}

/** Build one normalized item, or null when the post is withheld. */
function mmgrf_build_item( $post, $network, $opts, $hold = 0 ) {
    $post_id = $post->ID;

    if ( get_post_meta( $post_id, '_mmgrf_exclude_' . $network, true ) === '1' ) {
        return null;
    }
    if ( $hold > 0 && (int) get_post_time( 'U', true, $post_id ) > time() - $hold ) {
        return null; // inside the settle window — ships on a later poll
    }

    // Shortcodes, block renderers, and third-party the_content filters read
    // the global post — set it up per item exactly as a the_post() loop would.
    $GLOBALS['post'] = $post;
    setup_postdata( $post );

    // Scrubbed once here: an invalid byte would make every later /u regex
    // (excerpt, word counts) fail outright.
    $content = mmgrf_utf8( mmgrf_rendered_post_content( $post, $network ) );

    if ( has_excerpt( $post_id ) ) {
        $excerpt = mmgrf_text_field( get_the_excerpt( $post_id ) );
    } else {
        // Captions, credits and embed scaffolding are not the article's lede:
        // without this a lead photo's "Mandatory Credit: …" becomes the
        // description. A space per tag boundary keeps adjacent blocks from
        // merging words ("...ends here.</p><p>Second..." → "here. Second").
        // Literal ellipsis, not &hellip; — the value lands inside CDATA where
        // entities are not decoded by the XML parser.
        $excerpt = mmgrf_xml_chars( mmgrf_plain_text( wp_trim_words( mmgrf_lede_text( $content ), 55, '…' ) ) );
    }

    $categories = get_the_category( $post_id );
    $post_tags  = get_the_tags( $post_id );
    $permalink  = get_permalink( $post_id );

    // the_author honours byline plugins (Co-Authors Plus, PublishPress
    // Authors); the raw user display name is only the fallback.
    $author = (string) get_the_author();
    if ( trim( $author ) === '' ) {
        $author = (string) get_the_author_meta( 'display_name', $post->post_author );
    }

    return [
        'id'            => $post_id,
        'title'         => mmgrf_text_field( get_the_title( $post_id ) ),
        'short_title'   => mmgrf_text_field( get_post_meta( $post_id, '_mmgrf_short_title', true ) ),
        'permalink'     => $permalink,
        'link'          => mmgrf_build_link( $permalink, $opts ),
        'pub_ts'        => (int) get_post_time( 'U', true, $post_id ),
        'mod_ts'        => (int) get_post_modified_time( 'U', true, $post_id ),
        'author'        => mmgrf_text_field( $author ),
        // Term and display names are stored entity-encoded ("Texas A&amp;M");
        // they are emitted inside CDATA, so decode once here.
        'categories'    => array_values( array_map( fn( $c ) => mmgrf_text_field( $c->name ), is_array( $categories ) ? $categories : [] ) ),
        'tags'          => is_array( $post_tags ) ? array_values( array_map( fn( $t ) => mmgrf_text_field( $t->name ), $post_tags ) ) : [],
        'score'         => mmgrf_get_score( $post_id ),
        'excerpt'       => $excerpt,
        'content'       => $content,
        'image'         => mmgrf_resolve_image( $post_id, $opts['image_size'], $content ),
        'comments_feed' => get_post_comments_feed_link( $post_id ),
        'comment_count' => (int) get_comments_number( $post_id ),
    ];
}

/** Render a complete feed document for a profile. Returns the XML string. */
function mmgrf_render_feed( $profile, $opts, $filters ) {
    return mmgrf_quiet( function() use ( $profile, $opts, $filters ) {
        mmgrf_skip_log_begin();
        try {
            return mmgrf_render_feed_document( $profile, $opts, $filters );
        } finally {
            mmgrf_skip_log_end();
        }
    } );
}

function mmgrf_render_feed_document( $profile, $opts, $filters ) {
    $items = mmgrf_build_items( $profile, $opts, $filters );

    $body = '';
    foreach ( $items as $item ) {
        try {
            $body .= mmgrf_render_feed_item( $profile, $item, $opts );
        } catch ( \Throwable $e ) {
            mmgrf_log_render_error( $profile->get_key(), $item['id'], (string) $item['title'], $e );
        }
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

/** Prepare, gate, log and render one item. '' when the item is withheld. */
function mmgrf_render_feed_item( $profile, $item, $opts ) {
    $key  = $profile->get_key();
    $item = $profile->prepare_item( $item, $opts );
    $v    = $profile->validate_item( $item );
    if ( ! $v['pass'] ) {
        $code   = $v['code'];
        $detail = $v['detail'];
        // "No image" is the symptom; when the profile dropped the image for
        // a specific reason (too small, no rights, file missing), log THAT —
        // it is what the editor has to fix.
        if ( $code === 'no_featured_image' ) {
            if ( ! empty( $item['image_dropped']['code'] ) ) {
                $code   = $item['image_dropped']['code'];
                $detail = (string) ( $item['image_dropped']['detail'] ?? '' );
            } elseif ( ! empty( $item['image_note']['code'] ) ) {
                $code   = $item['image_note']['code'];
                $detail = (string) ( $item['image_note']['detail'] ?? '' );
            }
        }
        mmgrf_skip_log_add( $key, $item['id'], $item['title'], $code, $detail );
        return '';
    }
    if ( ! empty( $item['image_dropped'] ) ) {
        mmgrf_skip_log_add( $key, $item['id'], $item['title'], $item['image_dropped']['code'], $item['image_dropped']['detail'], 'warn' );
    }
    $notes = array_merge( $item['inline_image_log'] ?? [], $item['affiliate_log'] ?? [] );
    if ( ! empty( $item['image_note'] ) ) {
        $notes[] = $item['image_note'];
    }
    if ( ! empty( $item['image']['file_note'] ) ) {
        $notes[] = [ 'code' => 'image_file_missing', 'detail' => $item['image']['file_note'] ];
    }
    foreach ( $notes as $ev ) {
        mmgrf_skip_log_add( $key, $item['id'], $item['title'], $ev['code'], $ev['detail'], 'warn' );
    }
    if ( ! empty( $item['san']['has_embeds'] ) && $key === 'yahoo' ) {
        // Yahoo mangles embeds not matching its expected markup — ship, but flag for review.
        mmgrf_skip_log_add( 'yahoo', $item['id'], $item['title'], 'embed_review', 'post contains iframe embeds', 'warn' );
    }
    if ( $key === 'msn' && ! empty( $item['image'] )
        && ( $item['image']['width'] < MMGRF_Profile_MSN::IMG_WARN_W || $item['image']['height'] < MMGRF_Profile_MSN::IMG_WARN_H ) ) {
        mmgrf_skip_log_add( 'msn', $item['id'], $item['title'], 'image_below_recommended', $item['image']['width'] . 'x' . $item['image']['height'] . ' under MSN recommended 1280x720', 'warn' );
    }
    return $profile->render_item( $item, $opts );
}

const MMGRF_CACHE_TTL   = 300;   // seconds a rendered feed is served unchanged
const MMGRF_CACHE_STALE = 86400; // how long the last good copy is kept as a fallback

/**
 * Serve a feed document through the shared cache.
 *
 * One STABLE transient per feed holds [signature, built-at, xml]. (The old
 * scheme put the last-modified stamp in the key, which orphaned a multi-
 * hundred-KB wp_options row on every publish until the daily cleanup.) The
 * copy is reused while its signature — last change to posts or settings,
 * plus plugin version — still matches and it is under MMGRF_CACHE_TTL old.
 *
 * The retained copy doubles as a last-known-good: if a rebuild throws, the
 * partner gets the previous document instead of an error page.
 *
 * Logged-in viewers bypass the cache in both directions: content rendered
 * for an editor (members-only blocks, edit links) must never be stored and
 * handed to a partner.
 */
function mmgrf_cached_render( $cache_id, callable $render, $log_network = '' ) {
    if ( is_user_logged_in() ) {
        return $render();
    }
    $key = 'mmgrf_feed_' . md5( (string) $cache_id );
    $sig = mmgrf_cache_signature();
    $ttl = max( 0, (int) apply_filters( 'mmgrf_cache_ttl', MMGRF_CACHE_TTL, $cache_id ) );
    $hit = get_transient( $key );
    $ok  = is_array( $hit ) && isset( $hit['sig'], $hit['built'], $hit['xml'] ) && is_string( $hit['xml'] ) && $hit['xml'] !== '';

    if ( $ok && $hit['sig'] === $sig && (int) $hit['built'] > time() - $ttl ) {
        return $hit['xml'];
    }
    try {
        $xml = $render();
    } catch ( \Throwable $e ) {
        if ( ! $ok ) {
            throw $e;
        }
        mmgrf_log_render_error( $log_network !== '' ? $log_network : (string) $cache_id, 0, '(whole feed)', $e );
        return $hit['xml'];
    }
    set_transient( $key, [ 'sig' => $sig, 'built' => time(), 'xml' => $xml ], MMGRF_CACHE_STALE );
    return $xml;
}

/**
 * Resolve a network's feed XML, via the shared cache for network profiles.
 * At 104 brands with MSN polling every ~15 min and Yahoo every ~5 min,
 * uncached full-content renders are real database load. The aigeon legacy
 * feed stays uncached — matches current production behavior.
 */
function mmgrf_get_feed_output( $network, $filters = [] ) {
    $profile = mmgrf_get_profile( $network );
    $opts    = mmgrf_network_options( $network );
    $render  = function() use ( $profile, $opts, $filters ) {
        return mmgrf_render_feed( $profile, $opts, $filters );
    };
    if ( $network === 'aigeon' ) {
        return $render();
    }
    return mmgrf_cached_render( 'article|' . $network . '|' . $opts['feed_slug'], $render, $network );
}

/**
 * The last moment anything feed-affecting really changed: the newest post
 * change, or mmgrf_config_touched — settings saves, publish/unpublish
 * transitions and media-library edits. (None of the latter advance core's
 * "last post modified"; an unpublish even moves it BACKWARDS, so without the
 * stamp a polling partner would be answered 304 Not Modified indefinitely.)
 */
function mmgrf_real_lastmod( $posts_ts ) {
    return max( (int) $posts_ts, (int) get_option( 'mmgrf_config_touched', 0 ) );
}

/**
 * The Last-Modified the feed REPORTS, which accounts for the settle window.
 *
 * A change at time T is only fully reflected in the feed at T + settle (a
 * new post is held back until then). So the feed reports T + settle — and,
 * until that moment arrives, simply "now".
 *
 * That makes a partner that polled during the hold safe whichever validator
 * it sends back: one that echoes our Last-Modified (some moment before
 * T + settle) and one that sends the time of its own last fetch (also before
 * T + settle) are both older than T + settle, so both get a full body — with
 * the post — on their next request, never a 304.
 */
function mmgrf_effective_lastmod( $posts_ts, $now = null ) {
    $now  = $now === null ? time() : (int) $now;
    $real = mmgrf_real_lastmod( $posts_ts );
    return $real > 0 ? min( $real + mmgrf_settle_seconds(), $now ) : 0;
}

/** Core's newest-post-change timestamp, WITHOUT this plugin's own filter applied. */
function mmgrf_posts_lastmod_ts() {
    $GLOBALS['mmgrf_lastmod_raw'] = (int) ( $GLOBALS['mmgrf_lastmod_raw'] ?? 0 ) + 1;
    try {
        $value = get_lastpostmodified( 'GMT' );
    } finally {
        $GLOBALS['mmgrf_lastmod_raw']--;
    }
    return $value ? ( (int) strtotime( $value . ' UTC' ) ) : 0;
}

function mmgrf_feed_lastmod() {
    return mmgrf_effective_lastmod( mmgrf_posts_lastmod_ts() );
}

/**
 * What a cached document is valid for: the last real change, and whether its
 * settle window is still open (when it closes, held posts are released and
 * the document must be rebuilt once). Deliberately NOT the reported
 * Last-Modified, which ticks every second during the window — that would
 * make every request in the minute after any edit a cache miss.
 */
function mmgrf_cache_signature( $now = null ) {
    $now  = $now === null ? time() : (int) $now;
    $real = mmgrf_real_lastmod( mmgrf_posts_lastmod_ts() );
    return $real . '|' . ( $now < $real + mmgrf_settle_seconds() ? 'settling' : 'settled' ) . '|' . MMGRF_VERSION;
}

/** Record that something feed-affecting changed outside a post save. */
function mmgrf_touch_config() {
    update_option( 'mmgrf_config_touched', time(), 'no' );
}

/** True while WordPress is serving one of this plugin's feed routes. */
function mmgrf_is_own_feed_request() {
    $feed = '';
    if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && ! empty( $GLOBALS['wp']->query_vars['feed'] ) ) {
        $feed = (string) $GLOBALS['wp']->query_vars['feed'];
    }
    return $feed !== '' && in_array( $feed, mmgrf_registered_feed_slugs(), true );
}

/**
 * WordPress core answers conditional GETs on feeds itself — in
 * WP::send_headers(), BEFORE the feed callback runs — from
 * get_lastpostmodified() alone, and exits on a match. So the plugin's own
 * last-modified logic never gets a say unless core is given the same value.
 * This filter does that, for this plugin's feed routes only.
 */
add_filter( 'get_lastpostmodified', 'mmgrf_filter_lastpostmodified', 10, 3 );
function mmgrf_filter_lastpostmodified( $value, $timezone = 'server', $post_type = 'any' ) {
    if ( ! empty( $GLOBALS['mmgrf_lastmod_raw'] ) ) {
        return $value; // our own read of the raw value
    }
    if ( strtolower( (string) $timezone ) !== 'gmt' || ! mmgrf_is_own_feed_request() ) {
        return $value;
    }
    $posts_ts  = $value ? (int) strtotime( $value . ' UTC' ) : 0;
    $effective = mmgrf_effective_lastmod( $posts_ts );
    return $effective > 0 ? gmdate( 'Y-m-d H:i:s', $effective ) : $value;
}

// A post leaving (or entering) "publish" changes what the feed must carry,
// yet unpublishing moves core's last-modified BACKWARDS — partners holding
// the newer stamp would be told 304 and never see the retraction.
add_action( 'transition_post_status', 'mmgrf_on_transition_post_status', 10, 3 );
function mmgrf_on_transition_post_status( $new_status, $old_status, $post ) {
    if ( $new_status === $old_status || ( $new_status !== 'publish' && $old_status !== 'publish' ) ) {
        return;
    }
    if ( ! is_object( $post ) || ( $post->post_type ?? '' ) !== 'post' ) {
        return;
    }
    mmgrf_touch_config();
}

// Media-library edits (alt text, caption/credit, the syndication-rights flag,
// a replaced or deleted file) change feed output without touching any post.
add_action( 'edit_attachment', 'mmgrf_on_media_change' );
add_action( 'delete_attachment', 'mmgrf_on_media_change' );
function mmgrf_on_media_change() {
    mmgrf_touch_config();
}

/** Shared conditional-GET + cache headers. Returns true when 304 was served. */
function mmgrf_feed_headers_and_maybe_304() {
    // Without the DOM or mbstring extension a render would die mid-document.
    // Say "temporarily unavailable" instead — a partner retries a 503; a
    // half-written or empty 200 reads as "this feed has no articles".
    if ( mmgrf_missing_requirements() ) {
        status_header( 503 );
        header( 'Retry-After: 3600' );
        header( 'Content-Type: text/plain; charset=UTF-8' );
        echo 'Feed temporarily unavailable.';
        return true;
    }
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
