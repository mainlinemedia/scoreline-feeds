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
 * The same withholding rules as the article feeds apply here: per-post
 * "Exclude from networks" / "Sponsored content", the policy categories and
 * tags, password protection, and the settle window.
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

/** Image formats Yahoo's slideshow spec accepts. */
const MMGRF_SLIDESHOW_YAHOO_MIMES = [ 'image/jpeg', 'image/png', 'image/gif' ];

/** MSN promo-card short title: only when over 54 chars, word-boundary cut. */
function mmgrf_msn_short_title( $title ) {
    if ( mb_strlen( $title ) <= 54 ) {
        return '';
    }
    $cut = mb_substr( $title, 0, 53 );
    $sp  = mb_strrpos( $cut, ' ' );
    if ( $sp !== false && $sp > 20 ) {
        $cut = mb_substr( $cut, 0, $sp );
    }
    return rtrim( $cut, " ,;:-–—" ) . '…';
}

function mmgrf_slideshow_marker_ids() {
    return mmgrf_tag_slugs_to_ids( mmgrf_get_options()['slideshow_tag'] ?? 'slideshow' );
}

/**
 * Sanitizer rules for reading a slideshow body. Everything that is not
 * headings, text blocks or images is dropped WITH the usual protections:
 * scripts, styles, comments, players and share chrome never reach a slide
 * description, and wrapper blocks (groups, columns) are unwrapped so a
 * heading nested inside one is still found.
 */
function mmgrf_slideshow_rules() {
    return [
        'allowed_tags'       => [ 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'ul', 'ol', 'li', 'blockquote', 'figure', 'figcaption', 'img', 'table', 'tr', 'td', 'th', 'br' ],
        'allowed_attributes' => [ 'img' => [ 'src', 'alt' ] ],
        'strip_style_attr'   => true,
        'base_url'           => home_url(),
    ];
}

/** Collapse whitespace (incl. non-breaking spaces) in a text run. */
function mmgrf_squash( $text ) {
    $text = (string) $text;
    return trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) ?? $text );
}

/**
 * Parse a rendered post body into slides.
 * Returns ['slides'=>[], 'intro'=>'', 'events'=>[], 'layout'=>'heading-first'|'image-first'|'ambiguous'].
 *
 * Two authoring layouts are understood, and never guessed between:
 *   heading-first  <h2>Title</h2> <img> text…      (the documented model)
 *   image-first    <img> <h2>Title</h2> text…      (photo above its heading)
 * Read with the wrong layout, every title is paired with the NEXT slide's
 * photo — a misidentified person shipped to a partner. When the body gives
 * no consistent evidence which layout it is, the post is reported
 * 'ambiguous' and the caller withholds it.
 */
function mmgrf_parse_slideshow( $content ) {
    $result = [ 'slides' => [], 'intro' => '', 'events' => [], 'layout' => 'heading-first' ];
    if ( trim( (string) $content ) === '' ) {
        return $result;
    }
    $clean  = mmgrf_prune_empty_nodes( mmgrf_sanitize( $content, mmgrf_slideshow_rules() )['html'] );
    $loaded = mmgrf_dom_load( $clean );
    if ( ! $loaded ) {
        return $result;
    }
    [ $doc, $root ] = $loaded;
    $xpath = new DOMXPath( $doc );

    // A line break separates words ("5.<br>Tiger Woods" is not "5.Tiger Woods").
    foreach ( mmgrf_dom_query( $doc, $root, './/br' ) as $br ) {
        $br->parentNode->replaceChild( $doc->createTextNode( ' ' ), $br );
    }

    // Every text node is read exactly once and filed under the nearest
    // enclosing text block, so a list item or quote that has text of its
    // own AND nested blocks loses neither.
    $block_tags = array_fill_keys( [ 'p', 'li', 'blockquote', 'h3', 'h4', 'h5', 'h6', 'td', 'th' ], true );
    $nodes      = mmgrf_dom_query( $doc, $root, './/h2 | .//img | .//text()' );

    $new_region = fn( $title ) => [ 'title' => $title, 'imgs' => [], 'chunks' => [], 'has_text' => false ];
    $regions    = [ $new_region( '' ) ]; // region 0 is everything above the first heading
    $r          = 0;

    foreach ( $nodes as $node ) {
        // A photo caption is the photo's caption, not slide copy; a
        // heading's own text is the slide title.
        if ( $xpath->query( 'ancestor-or-self::figcaption', $node )->length ) {
            continue;
        }
        if ( $node instanceof DOMElement && strtolower( $node->tagName ) === 'h2' ) {
            $regions[] = $new_region( mmgrf_squash( $node->textContent ) );
            $r++;
            continue;
        }
        if ( $node instanceof DOMElement ) { // img
            $src = trim( $node->getAttribute( 'src' ) );
            if ( $src !== '' ) {
                $regions[ $r ]['imgs'][] = [ 'src' => $src, 'alt' => mmgrf_squash( $node->getAttribute( 'alt' ) ), 'before_text' => ! $regions[ $r ]['has_text'] ];
            }
            continue;
        }
        if ( $xpath->query( 'ancestor::h2', $node )->length ) {
            continue;
        }
        $block = $root;
        for ( $a = $node->parentNode; $a instanceof DOMElement && $a !== $root; $a = $a->parentNode ) {
            if ( isset( $block_tags[ strtolower( $a->tagName ) ] ) ) {
                $block = $a;
                break;
            }
        }
        $key  = spl_object_id( $block );
        $last = count( $regions[ $r ]['chunks'] ) - 1;
        if ( $last >= 0 && $regions[ $r ]['chunks'][ $last ]['block'] === $key ) {
            $regions[ $r ]['chunks'][ $last ]['text'] .= $node->textContent;
        } else {
            $regions[ $r ]['chunks'][] = [ 'block' => $key, 'text' => $node->textContent ];
        }
        if ( mmgrf_squash( $node->textContent ) !== '' ) {
            $regions[ $r ]['has_text'] = true;
        }
    }
    foreach ( $regions as $i => $region ) {
        $texts = array_values( array_filter( array_map( fn( $c ) => mmgrf_squash( $c['text'] ), $region['chunks'] ), 'strlen' ) );
        $regions[ $i ]['text'] = implode( ' ', $texts );
        // MSN's accepted gallery shape carries HTML paragraphs in the slide
        // description CDATA; keep the markup alongside the text.
        $regions[ $i ]['html'] = implode( '', array_map( fn( $t ) => '<p>' . mmgrf_xml( $t ) . '</p>', $texts ) );
    }

    $intro    = array_shift( $regions );
    $sections = $regions;
    $n        = count( $sections );
    $result['intro'] = $intro['text'];
    if ( ! $n ) {
        return $result;
    }

    // Layout: only in doubt when a photo sits above the first heading AND
    // the last section has none — the signature of image-first authoring
    // (but equally of a cover photo plus an image-less closing section).
    $shift = false;
    if ( $n >= 2 && $intro['imgs'] && ! $sections[ $n - 1 ]['imgs'] ) {
        $heading_first = 0;
        $image_first   = 0;
        foreach ( $sections as $s ) {
            if ( ! $s['imgs'] || $s['text'] === '' ) {
                continue; // a section without both gives no evidence
            }
            if ( $s['imgs'][0]['before_text'] ) {
                $heading_first++;
            }
            if ( ! $s['imgs'][ count( $s['imgs'] ) - 1 ]['before_text'] ) {
                $image_first++;
            }
        }
        if ( $image_first > 0 && $heading_first === 0 ) {
            $shift            = true;
            $result['layout'] = 'image-first';
        } elseif ( ! ( $heading_first > 0 && $image_first === 0 ) ) {
            $result['layout']   = 'ambiguous';
            $result['events'][] = [ 'code' => 'slideshow_layout_ambiguous', 'detail' => 'cannot tell which photo belongs to which heading (a photo sits above the first heading and the last section has none) — place each photo directly under its heading' ];
            return $result;
        }
    }
    if ( $shift ) {
        // Each heading takes the photo immediately above it.
        $above = $intro['imgs'];
        foreach ( $sections as $i => $s ) {
            $own                  = $s['imgs'];
            $sections[ $i ]['imgs'] = $above ? [ $above[ count( $above ) - 1 ] ] : [];
            $above                = $own;
        }
    }

    foreach ( $sections as $section ) {
        $label = '"' . mb_substr( $section['title'], 0, 60 ) . '"';
        if ( $section['title'] === '' ) {
            $result['events'][] = [ 'code' => 'slide_missing_title', 'detail' => 'a heading with no text (an image-only H2?) cannot be a slide title — section dropped' ];
            continue;
        }
        if ( ! $section['imgs'] ) {
            $result['events'][] = [ 'code' => 'slide_missing_image', 'detail' => 'section ' . $label . ' has no image — slide dropped' ];
            continue;
        }
        // First image that is one of this site's uploads wins; an emoji,
        // tracking pixel or hotlinked graphic ahead of it must not.
        $chosen = null;
        $att_id = 0;
        foreach ( $section['imgs'] as $candidate ) {
            $id = mmgrf_find_attachment( $candidate['src'] );
            if ( $id ) {
                $chosen = $candidate;
                $att_id = $id;
                break;
            }
        }
        if ( ! $chosen ) {
            $chosen = $section['imgs'][0];
        }
        $image = $att_id ? mmgrf_attachment_image_data( $att_id, 'full' ) : null;
        if ( ! $image ) {
            // External/hotlinked: keep the URL, mark dims unknown — gates decide.
            $image = [ 'attachment_id' => 0, 'url' => $chosen['src'], 'width' => 0, 'height' => 0, 'type' => '', 'filesize' => 0, 'alt' => $chosen['alt'], 'caption' => '', 'candidates' => [], 'dims_unknown' => true ];
            $result['events'][] = [ 'code' => 'slide_image_unresolvable', 'detail' => $label . ': ' . $chosen['src'] . ' — not in this site\'s media library, size unverifiable' ];
        }
        if ( $image['alt'] === '' && $chosen['alt'] !== '' ) {
            $image['alt'] = $chosen['alt'];
        }
        $result['slides'][] = [
            'title'     => $section['title'],
            'text'      => $section['text'],
            'html'      => $section['html'],
            'image'     => $image,
            'no_rights' => $att_id ? (bool) get_post_meta( $att_id, '_mmgrf_no_syndication_rights', true ) : false,
        ];
    }
    return $result;
}

/** Resolved options for a network's slideshow feed. */
function mmgrf_slideshow_options( $network ) {
    $opts = mmgrf_network_options( $network );
    $opts['feed_slug']  = $opts['feed_slug'] . '-slideshows';
    $opts['feed_url']   = mmgrf_network_feed_url( $opts['feed_slug'] );
    $opts['feed_title'] = $opts['feed_title'] . ' - Slideshows'; // matches the accepted live-feed convention
    $opts['post_count'] = 20;
    return $opts;
}

function mmgrf_render_slideshow_feed( $network, $opts ) {
    return mmgrf_quiet( function() use ( $network, $opts ) {
        mmgrf_skip_log_begin();
        try {
            return mmgrf_render_slideshow_document( $network, $opts );
        } finally {
            wp_reset_postdata();
            mmgrf_skip_log_end();
        }
    } );
}

function mmgrf_render_slideshow_document( $network, $opts ) {
    $log = $network . '-slideshows';
    $ns  = [
        'media' => 'http://search.yahoo.com/mrss/',
        'dc'    => 'http://purl.org/dc/elements/1.1/',
        'atom'  => 'http://www.w3.org/2005/Atom',
    ];
    if ( $network === 'msn' ) {
        $ns['dcterms'] = 'http://purl.org/dc/terms/';
        $ns['mi']      = 'http://schemas.ingestion.microsoft.com/common/';
    }
    $xml = mmgrf_typed_channel_open( $ns, $opts );

    foreach ( mmgrf_typed_feed_posts( $network, mmgrf_slideshow_marker_ids(), $opts['post_count'], $log ) as $post ) {
        try {
            $xml .= mmgrf_slideshow_item_xml( $post, $network, $log );
        } catch ( \Throwable $e ) {
            mmgrf_log_render_error( $log, $post->ID, mmgrf_text_field( $post->post_title ?? '' ), $e );
        }
    }

    return $xml . "  </channel>\n</rss>\n";
}

/** One slideshow <item>, or '' when the post is withheld (always with a log entry). */
function mmgrf_slideshow_item_xml( $post, $network, $log ) {
    $post_id = $post->ID;
    $GLOBALS['post'] = $post;
    setup_postdata( $post );

    $yahoo      = $network !== 'msn';
    $min_slides = MMGRF_SLIDESHOW_MIN_SLIDES[ $network ] ?? 3;
    // Slide image floors: Yahoo ≥1280×720/5MB (spec); MSN ≥600px (public docs).
    [ $min_w, $min_h, $max_b ] = $yahoo ? [ 1280, 720, 5242880 ] : [ 600, 1, 0 ];

    $title       = mmgrf_text_field( get_the_title( $post_id ) );
    $title_words = mmgrf_count_words( mmgrf_xml( $title ) );
    if ( $title === '' || ( $yahoo && $title_words < 2 ) ) {
        mmgrf_skip_log_add( $log, $post_id, $title !== '' ? $title : '(untitled)', 'title_empty', $yahoo ? 'Yahoo slideshow titles need more than one word' : '' );
        return '';
    }

    $parsed = mmgrf_parse_slideshow( mmgrf_utf8( mmgrf_rendered_post_content( $post, $network ) ) );
    foreach ( $parsed['events'] as $ev ) {
        mmgrf_skip_log_add( $log, $post_id, $title, $ev['code'], $ev['detail'], $ev['code'] === 'slideshow_layout_ambiguous' ? 'skip' : 'warn' );
    }
    if ( $parsed['layout'] === 'ambiguous' ) {
        return '';
    }

    // Per-slide gates via the shared ladder. MSN additionally drops
    // rights-flagged slides (mi:hasSyndicationRights is per-slide).
    $slides = [];
    $seen   = [];
    foreach ( $parsed['slides'] as $slide ) {
        $label = '"' . mb_substr( $slide['title'], 0, 60 ) . '"';
        if ( $slide['title'] === '' ) {
            continue;
        }
        if ( ! $yahoo && $slide['no_rights'] ) {
            mmgrf_skip_log_add( $log, $post_id, $title, 'no_syndication_rights', $label . ': attachment flagged no-syndication-rights — slide dropped', 'warn' );
            continue;
        }
        $picked = mmgrf_pick_image_fit( $slide['image'], $min_w, $min_h, $max_b );
        if ( ! $picked ) {
            mmgrf_skip_log_add( $log, $post_id, $title, 'slide_below_minimum', $label . ': ' . (int) $slide['image']['width'] . 'x' . (int) $slide['image']['height'] . " under {$min_w}px floor — slide dropped", 'warn' );
            continue;
        }
        if ( $yahoo && ! in_array( $picked['type'], MMGRF_SLIDESHOW_YAHOO_MIMES, true ) ) {
            mmgrf_skip_log_add( $log, $post_id, $title, 'image_format_unsupported', $label . ': ' . $picked['type'] . ' — Yahoo slideshows accept JPEG, PNG or GIF; slide dropped', 'warn' );
            continue;
        }
        // Yahoo flags an item that lists the same image URL twice.
        if ( isset( $seen[ $picked['url'] ] ) ) {
            mmgrf_skip_log_add( $log, $post_id, $title, 'slide_duplicate_image', $label . ': same photo as an earlier slide — slide dropped', 'warn' );
            continue;
        }
        $seen[ $picked['url'] ] = true;
        $slide['image']         = $picked;
        $slides[]               = $slide;
    }
    $slides = array_slice( $slides, 0, MMGRF_SLIDESHOW_MAX_SLIDES );

    if ( count( $slides ) < $min_slides ) {
        mmgrf_skip_log_add( $log, $post_id, $title, 'slideshow_too_few_slides', count( $slides ) . " usable slides (minimum {$min_slides} for {$network})" );
        return '';
    }

    // Description: the intro above the first heading, else the manual
    // excerpt, else the first slide's own text.
    $desc = $parsed['intro'];
    if ( $desc === '' && has_excerpt( $post_id ) ) {
        $desc = mmgrf_text_field( get_the_excerpt( $post_id ) );
    }
    if ( $desc === '' ) {
        $desc = $slides[0]['text'];
    }
    $desc       = mmgrf_xml_chars( mmgrf_plain_text( wp_trim_words( $desc, 100, '…' ) ) );
    $desc_words = mmgrf_count_words( mmgrf_xml( $desc ) );
    $desc_floor = $yahoo ? 4 : 1; // Yahoo: "more than 3 words"
    if ( $desc_words < $desc_floor ) {
        mmgrf_skip_log_add( $log, $post_id, $title, 'description_below_word_floor', "{$desc_words} words (needs at least {$desc_floor}) — add an intro paragraph above the first heading, or a manual excerpt" );
        return '';
    }

    $pub_ts = (int) get_post_time( 'U', true, $post_id );
    $mod_ts = (int) get_post_modified_time( 'U', true, $post_id );
    if ( $pub_ts > time() ) {
        mmgrf_skip_log_add( $log, $post_id, $title, 'pubdate_future', gmdate( 'c', $pub_ts ) );
        return '';
    }

    // Cover thumbnail: the featured image via the ladder; fallback slide 1.
    // MSN rights parity: a rights-flagged featured image is withheld from
    // the gallery cover exactly as flagged slides are.
    $thumb = mmgrf_resolve_image( $post_id, 'full', '' );
    if ( $thumb && ! $yahoo && ! empty( $thumb['no_synd_rights'] ) ) {
        mmgrf_skip_log_add( $log, $post_id, $title, 'no_syndication_rights', 'cover attachment flagged no-syndication-rights — first slide used as thumbnail', 'warn' );
        $thumb = null;
    }
    $thumb = $thumb ? mmgrf_pick_image_fit( $thumb, $min_w, $min_h, $max_b ) : null;
    if ( $thumb && $yahoo && ! in_array( $thumb['type'], MMGRF_SLIDESHOW_YAHOO_MIMES, true ) ) {
        $thumb = null;
    }
    // The cover must not repeat a slide's URL — the usual habit of using
    // slide 1's photo as the featured image would otherwise list it twice.
    // Prefer another rendition of the same photo; reuse only as a last resort.
    $source = $thumb ?: $slides[0]['image'];
    $cover  = mmgrf_alternate_rendition( $source, array_keys( $seen ), $min_w, $min_h, $max_b );
    if ( ! $cover ) {
        $cover = $source; // a thumbnail is required — lesser evil, made visible
        mmgrf_skip_log_add( $log, $post_id, $title, 'thumbnail_duplicates_slide', 'the cover photo is also a slide and has no second rendition large enough — the cover repeats that slide\'s URL; set a different featured image (or upload the photo above 2048px wide) to clear this', 'warn' );
    }

    $categories = get_the_category( $post_id );
    $category   = ! empty( $categories ) ? mmgrf_text_field( $categories[0]->name ) : 'General';
    $permalink  = get_permalink( $post_id );
    $author     = (string) get_the_author();
    if ( trim( $author ) === '' ) {
        $author = (string) get_the_author_meta( 'display_name', $post->post_author );
    }

    $xml  = "    <item>\n";
    $xml .= '      ' . mmgrf_el( 'title', $title, [], true ) . "\n";
    $xml .= '      ' . mmgrf_el( 'link', $permalink ) . "\n";
    $xml .= '      ' . mmgrf_el( 'pubDate', mmgrf_rfc822( $pub_ts ) ) . "\n";
    if ( ! $yahoo ) {
        // Mirrors the live MSN-accepted gallery shape (gamedaychatter):
        // durable numeric guid, mi:shortTitle, keywords from tags.
        if ( $mod_ts > $pub_ts + MMGRF_MODIFIED_JITTER ) {
            $xml .= '      ' . mmgrf_el( 'dcterms:modified', gmdate( 'Y-m-d\TH:i:s\Z', $mod_ts ) ) . "\n";
        }
        $xml .= '      <guid isPermaLink="false">' . (int) $post_id . "</guid>\n";
        $short = mmgrf_msn_short_title( $title );
        if ( $short !== '' ) {
            $xml .= '      ' . mmgrf_el( 'mi:shortTitle', $short ) . "\n";
        }
    } else {
        $xml .= '      <guid isPermaLink="true">' . mmgrf_xml( $permalink ) . "</guid>\n";
    }
    $xml .= '      ' . mmgrf_el( 'dc:creator', mmgrf_text_field( $author ), [], true ) . "\n";
    $xml .= '      ' . mmgrf_el( 'description', $desc, [], true ) . "\n";
    $xml .= '      ' . mmgrf_el( 'category', $category ) . "\n";
    if ( ! $yahoo ) {
        $keywords = mmgrf_post_keywords( $post_id, 0 );
        if ( $keywords ) {
            $xml .= '      ' . mmgrf_el( 'media:keywords', implode( ',', $keywords ) ) . "\n";
        }
    }
    $xml .= '      <media:thumbnail url="' . mmgrf_xml( $cover['url'] ) . '" width="' . (int) $cover['width'] . '" height="' . (int) $cover['height'] . '"/>' . "\n";

    foreach ( $slides as $slide ) {
        $img = $slide['image'];
        if ( ! $yahoo ) {
            $xml .= '      <media:content url="' . mmgrf_xml( $img['url'] ) . '">' . "\n";
        } else {
            $xml .= '      <media:content url="' . mmgrf_xml( $img['url'] ) . '" type="' . mmgrf_xml( $img['type'] ) . '" medium="image" width="' . (int) $img['width'] . '" height="' . (int) $img['height'] . '">' . "\n";
        }
        $xml .= '        ' . mmgrf_el( 'media:title', $slide['title'] ) . "\n";
        if ( $img['alt'] !== '' ) {
            $xml .= '        ' . mmgrf_el( 'media:text', $img['alt'] ) . "\n";
        }
        $credit = ( $img['credit'] ?? '' ) !== '' ? $img['credit'] : ( $img['caption'] ?? '' );
        if ( $credit !== '' ) {
            $xml .= '        ' . mmgrf_el( 'media:credit', $credit ) . "\n";
        }
        if ( ! $yahoo ) {
            if ( $slide['html'] !== '' ) {
                $xml .= '        <media:description>' . mmgrf_cdata( $slide['html'] ) . "</media:description>\n";
            }
            $xml .= "        <mi:hasSyndicationRights>1</mi:hasSyndicationRights>\n";
        } elseif ( $slide['text'] !== '' ) {
            $xml .= '        ' . mmgrf_el( 'media:description', $slide['text'] ) . "\n";
        }
        $xml .= "      </media:content>\n";
    }
    return $xml . "    </item>\n";
}

/**
 * A rendition of $img that meets the limits and whose URL is not in $avoid.
 * The primary is tried first, then the smaller candidates.
 */
function mmgrf_alternate_rendition( $img, $avoid, $min_w, $min_h, $max_bytes ) {
    if ( ! $img ) {
        return null;
    }
    $options = $img['candidates'] ?? [];
    if ( empty( $img['file_missing'] ) ) {
        array_unshift( $options, [ 'url' => $img['url'], 'width' => (int) $img['width'], 'height' => (int) $img['height'], 'filesize' => (int) ( $img['filesize'] ?? 0 ) ] );
    }
    foreach ( $options as $c ) {
        if ( in_array( $c['url'], $avoid, true ) ) {
            continue;
        }
        $one = array_merge( $img, [ 'url' => $c['url'], 'width' => (int) $c['width'], 'height' => (int) $c['height'], 'filesize' => (int) ( $c['filesize'] ?? 0 ), 'candidates' => [] ] );
        unset( $one['file_missing'] );
        if ( mmgrf_pick_image_fit( $one, $min_w, $min_h, $max_bytes ) ) {
            return $one;
        }
    }
    return null;
}

/** WP feed callback: headers, conditional GET, shared cache. */
function mmgrf_output_slideshow_feed( $network ) {
    if ( mmgrf_feed_headers_and_maybe_304() ) {
        return;
    }
    $opts = mmgrf_slideshow_options( $network );
    echo mmgrf_cached_render( 'slideshow|' . $network . '|' . $opts['feed_slug'], function() use ( $network, $opts ) {
        return mmgrf_render_slideshow_feed( $network, $opts );
    }, $network . '-slideshows' );
}
