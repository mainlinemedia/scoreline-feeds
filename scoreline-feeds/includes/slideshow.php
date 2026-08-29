<?php
/**
 * Slideshow feeds for Yahoo and MSN.
 *
 * Content model (Austin, 2026-08-29): a slideshow is a NORMAL post marked
 * with the slideshow tag, authored as a listicle — each <h2> followed by an
 * image becomes one slide (H2 = slide title, first image in the section =
 * slide image, the section's paragraphs = slide description). Cover = post
 * title + featured image (Yahoo's single media:thumbnail). Marked posts are
 * withheld from the network ARTICLE feeds so one guid never ships as two
 * content types.
 *
 * Yahoo shape per publishers.yahooinc.com/docs/slideshow-ingestion (note the
 * /mrss/ namespace — the slideshow spec differs from the article feed's /rss).
 * MSN shape is PROVISIONAL against the public metadata docs (min 4 / max 200
 * slides, image ≥600px) pending the login-gated Content Specification page.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const MMGRF_SLIDESHOW_MIN_SLIDES = [ 'yahoo' => 3, 'msn' => 4 ]; // yahoo floor is internal quality, msn is Microsoft's rule
const MMGRF_SLIDESHOW_MAX_SLIDES = 200;

function mmgrf_slideshow_marker_ids() {
    return mmgrf_tag_slugs_to_ids( mmgrf_get_options()['slideshow_tag'] ?? 'slideshow' );
}

/**
 * Parse a post body into slides. Returns ['slides'=>[], 'intro'=>'', 'events'=>[]].
 */
function mmgrf_parse_slideshow( $content ) {
    $result = [ 'slides' => [], 'intro' => '', 'events' => [] ];
    if ( trim( (string) $content ) === '' ) {
        return $result;
    }

    $prev = libxml_use_internal_errors( true );
    $doc  = new DOMDocument( '1.0', 'UTF-8' );
    $doc->loadHTML(
        '<?xml encoding="utf-8"?><div id="mmgrf-root">' . $content . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors( $prev );

    $root = null;
    foreach ( $doc->childNodes as $node ) {
        if ( $node instanceof DOMElement && $node->getAttribute( 'id' ) === 'mmgrf-root' ) {
            $root = $node;
            break;
        }
    }
    if ( ! $root ) {
        return $result;
    }

    $sections = [];
    $current  = null;
    $intro    = '';
    foreach ( $root->childNodes as $node ) {
        $tag = $node instanceof DOMElement ? strtolower( $node->tagName ) : '';
        if ( $tag === 'h2' ) {
            if ( $current ) {
                $sections[] = $current;
            }
            $current = [ 'title' => trim( $node->textContent ), 'img' => null, 'text' => '' ];
            continue;
        }
        if ( ! $current ) {
            if ( trim( $node->textContent ) !== '' ) {
                $intro .= ' ' . trim( $node->textContent );
            }
            continue;
        }
        // Inside a section: first image wins; text accumulates.
        if ( $node instanceof DOMElement ) {
            $imgs = $tag === 'img' ? [ $node ] : iterator_to_array( $node->getElementsByTagName( 'img' ) );
            if ( ! $current['img'] && $imgs ) {
                $current['img'] = [
                    'src' => $imgs[0]->getAttribute( 'src' ),
                    'alt' => $imgs[0]->getAttribute( 'alt' ),
                ];
            }
        }
        $text = trim( $node->textContent );
        if ( $text !== '' ) {
            $current['text'] .= ( $current['text'] === '' ? '' : ' ' ) . $text;
        }
    }
    if ( $current ) {
        $sections[] = $current;
    }
    $result['intro'] = trim( preg_replace( '/\s+/u', ' ', $intro ) );

    foreach ( $sections as $section ) {
        if ( empty( $section['img']['src'] ) ) {
            $result['events'][] = [ 'code' => 'slide_missing_image', 'detail' => 'section "' . mb_substr( $section['title'], 0, 60 ) . '" has no image — slide dropped' ];
            continue;
        }
        $src    = $section['img']['src'];
        $att_id = mmgrf_url_to_attachment( mmgrf_strip_size_suffix( $src ) );
        if ( ! $att_id ) {
            $att_id = mmgrf_url_to_attachment( $src );
        }
        $image = $att_id ? mmgrf_attachment_image_data( $att_id, 'full' ) : null;
        if ( ! $image ) {
            // External/hotlinked: keep the URL, mark dims unknown — gates decide.
            $image = [ 'attachment_id' => 0, 'url' => $src, 'width' => 0, 'height' => 0, 'type' => 'image/jpeg', 'filesize' => 0, 'alt' => $section['img']['alt'], 'caption' => '', 'candidates' => [], 'dims_unknown' => true ];
            $result['events'][] = [ 'code' => 'slide_image_unresolvable', 'detail' => $src . ' — external or hotlinked, size unverifiable' ];
        }
        if ( $image['alt'] === '' && $section['img']['alt'] !== '' ) {
            $image['alt'] = $section['img']['alt'];
        }
        $result['slides'][] = [
            'title' => $section['title'],
            'text'  => trim( preg_replace( '/\s+/u', ' ', $section['text'] ) ),
            'image' => $image,
        ];
    }
    return $result;
}

/** Resolved options for a network's slideshow feed. */
function mmgrf_slideshow_options( $network ) {
    $opts = mmgrf_network_options( $network );
    $opts['feed_slug']  = $opts['feed_slug'] . '-slideshows';
    $opts['feed_url']   = mmgrf_network_feed_url( $opts['feed_slug'] );
    $opts['post_count'] = 20;
    return $opts;
}

function mmgrf_render_slideshow_feed( $network, $opts ) {
    $marker_ids = mmgrf_slideshow_marker_ids();
    $query      = new WP_Query( [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => max( 1, (int) $opts['post_count'] ),
        'orderby'        => 'date',
        'order'          => 'DESC',
        'tag__in'        => $marker_ids ?: [ -1 ],
    ] );

    $min_slides = MMGRF_SLIDESHOW_MIN_SLIDES[ $network ] ?? 3;
    // Slide image floors: Yahoo ≥1280×720/5MB (spec); MSN ≥600px (public docs).
    [ $min_w, $min_h, $max_b ] = $network === 'msn' ? [ 600, 1, 0 ] : [ 1280, 720, 5242880 ];

    $ns  = [
        'media' => 'http://search.yahoo.com/mrss/',
        'dc'    => 'http://purl.org/dc/elements/1.1/',
        'atom'  => 'http://www.w3.org/2005/Atom',
    ];
    if ( $network === 'msn' ) {
        $ns['dcterms'] = 'http://purl.org/dc/terms/';
        $ns['mi']      = 'http://schemas.ingestion.microsoft.com/common/';
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

    foreach ( $query->posts as $post ) {
        $post_id = $post->ID;
        $GLOBALS['post'] = $post;
        setup_postdata( $post );

        $title   = mmgrf_plain_text( get_the_title( $post_id ) );
        $content = apply_filters( 'the_content', get_the_content( null, false, $post_id ) );
        $parsed  = mmgrf_parse_slideshow( $content );

        foreach ( $parsed['events'] as $ev ) {
            mmgrf_skip_log_add( $network . '-slideshows', $post_id, $title, $ev['code'], $ev['detail'], 'warn' );
        }

        // Per-slide dimension gate via the shared ladder.
        $slides = [];
        foreach ( $parsed['slides'] as $slide ) {
            $picked = mmgrf_pick_image_fit( $slide['image'], $min_w, $min_h, $max_b );
            if ( ! $picked ) {
                mmgrf_skip_log_add( $network . '-slideshows', $post_id, $title, 'slide_below_minimum', '"' . mb_substr( $slide['title'], 0, 60 ) . '": ' . (int) $slide['image']['width'] . 'x' . (int) $slide['image']['height'] . " under {$min_w}px floor — slide dropped", 'warn' );
                continue;
            }
            $slide['image'] = $picked;
            $slides[]       = $slide;
        }
        $slides = array_slice( $slides, 0, MMGRF_SLIDESHOW_MAX_SLIDES );

        if ( count( $slides ) < $min_slides ) {
            mmgrf_skip_log_add( $network . '-slideshows', $post_id, $title, 'slideshow_too_few_slides', count( $slides ) . " usable slides (minimum {$min_slides} for {$network})" );
            continue;
        }

        $desc = $parsed['intro'] !== '' ? $parsed['intro'] : mmgrf_plain_text( has_excerpt( $post_id ) ? get_the_excerpt( $post_id ) : '' );
        if ( count( preg_split( '/\s+/u', trim( $desc ), -1, PREG_SPLIT_NO_EMPTY ) ) < 3 ) {
            mmgrf_skip_log_add( $network . '-slideshows', $post_id, $title, 'description_below_word_floor' );
            continue;
        }
        $pub_ts = (int) get_post_time( 'U', true, $post_id );
        $mod_ts = (int) get_post_modified_time( 'U', true, $post_id );
        if ( $pub_ts > time() ) {
            mmgrf_skip_log_add( $network . '-slideshows', $post_id, $title, 'pubdate_future', gmdate( 'c', $pub_ts ) );
            continue;
        }

        // Cover thumbnail: featured image via the ladder; fallback first slide.
        $thumb = mmgrf_resolve_image( $post_id, 'full', '' );
        $thumb = $thumb ? mmgrf_pick_image_fit( $thumb, $min_w, $min_h, $max_b ) : null;
        if ( ! $thumb ) {
            $thumb = $slides[0]['image'];
        }

        $categories = get_the_category( $post_id );
        $category   = ! empty( $categories ) ? $categories[0]->name : 'General';
        $permalink  = get_permalink( $post_id );

        $xml .= "    <item>\n";
        $xml .= '      ' . mmgrf_el( 'title', $title, [], true ) . "\n";
        $xml .= '      ' . mmgrf_el( 'link', $permalink ) . "\n";
        $xml .= '      ' . mmgrf_el( 'pubDate', mmgrf_rfc822( $pub_ts ) ) . "\n";
        if ( $network === 'msn' && $mod_ts > $pub_ts + MMGRF_MODIFIED_JITTER ) {
            $xml .= '      ' . mmgrf_el( 'dcterms:modified', gmdate( 'Y-m-d\TH:i:s\Z', $mod_ts ) ) . "\n";
        }
        $xml .= '      <guid isPermaLink="true">' . mmgrf_xml( $permalink ) . "</guid>\n";
        $xml .= '      ' . mmgrf_el( 'dc:creator', mmgrf_plain_text( get_the_author_meta( 'display_name', $post->post_author ) ), [], true ) . "\n";
        $xml .= '      ' . mmgrf_el( 'description', mmgrf_plain_text( $desc ), [], true ) . "\n";
        $xml .= '      ' . mmgrf_el( 'category', mmgrf_plain_text( $category ) ) . "\n";
        $xml .= '      <media:thumbnail url="' . mmgrf_xml( $thumb['url'] ) . '" width="' . (int) $thumb['width'] . '" height="' . (int) $thumb['height'] . '"/>' . "\n";

        foreach ( $slides as $slide ) {
            $img = $slide['image'];
            $xml .= '      <media:content url="' . mmgrf_xml( $img['url'] ) . '" type="' . mmgrf_xml( $img['type'] ) . '" medium="image" width="' . (int) $img['width'] . '" height="' . (int) $img['height'] . '">' . "\n";
            $xml .= '        ' . mmgrf_el( 'media:title', mmgrf_plain_text( $slide['title'] ) ) . "\n";
            if ( $img['alt'] !== '' ) {
                $xml .= '        ' . mmgrf_el( 'media:text', mmgrf_plain_text( $img['alt'] ) ) . "\n";
            }
            if ( $slide['text'] !== '' ) {
                $xml .= '        ' . mmgrf_el( 'media:description', mmgrf_plain_text( $slide['text'] ) ) . "\n";
            }
            $credit = ( $img['credit'] ?? '' ) !== '' ? $img['credit'] : ( $img['caption'] ?? '' );
            if ( $credit !== '' ) {
                $xml .= '        ' . mmgrf_el( 'media:credit', mmgrf_plain_text( $credit ) ) . "\n";
            }
            $xml .= "      </media:content>\n";
        }
        $xml .= "    </item>\n";
    }
    wp_reset_postdata();

    $xml .= "  </channel>\n</rss>\n";
    return $xml;
}

/** WP feed callback: headers, conditional GET, 60s cache. */
function mmgrf_output_slideshow_feed( $network ) {
    if ( mmgrf_feed_headers_and_maybe_304() ) {
        return;
    }
    $opts   = mmgrf_slideshow_options( $network );
    $key    = 'mmgrf_feed_' . md5( 'slideshow|' . $network . '|' . get_lastpostmodified( 'GMT' ) . '|' . MMGRF_VERSION );
    $cached = get_transient( $key );
    if ( $cached === false ) {
        $cached = mmgrf_render_slideshow_feed( $network, $opts );
        set_transient( $key, $cached, 60 );
    }
    echo $cached;
}
