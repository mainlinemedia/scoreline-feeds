<?php
/**
 * Shared HTML sanitizer engine (Layer 2 support). DOMDocument-based — regex
 * against block-editor HTML is not survivable (spec §4.4).
 *
 * $rules:
 *   allowed_tags          array of tag names, or null for permissive mode
 *   allowed_attributes    [tag => [attr,...]] allowlist (only enforced when allowed_tags is set)
 *   strip_style_attr      bool  — drop style="" everywhere (Yahoo/MSN)
 *   strip_attr_patterns   [names...] — dropped in ANY mode; 'data-*' matches prefix
 *   iframe_hosts          null = any host allowed; array = only these host suffixes survive
 *   blocked_iframe_hosts  host suffixes always deleted (MSN: YouTube)
 *   href_schemes          allowed <a href> schemes; anchor is unwrapped otherwise
 *   rename_tags           [from => to] applied before the allowlist check
 *   base_url              absolute-URL base for relative src/href
 *
 * Returns: ['html','word_count','has_embeds','body_has_image','first_image_src']
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Tags whose subtree is executable/meaningless for syndication — always deleted. */
function mmgrf_sanitize_delete_tags() {
    return [
        'script', 'style', 'noscript', 'meta', 'param', 'applet', 'form', 'button', 'input', 'select', 'textarea',
        // Document-level and scripted-animation vectors (SVG <set>/<animate>
        // can write event-handler attributes at runtime).
        'base', 'link', 'template', 'set', 'animate', 'animatetransform', 'animatemotion', 'foreignobject',
    ];
}

/**
 * Deleted WITH their content in allowlist mode unless the profile allows the
 * tag. Unwrapping these would leak fallback and chrome text into the article
 * ("Your browser does not support the audio element", a share button's SVG
 * <title>, the [video] shortcode's fallback link).
 */
function mmgrf_sanitize_strict_delete_tags() {
    return [ 'video', 'audio', 'svg', 'math', 'canvas', 'title', 'head', 'label', 'map', 'datalist' ];
}

/** Block-level tags: an unwrapped one must not fuse its text with its neighbours. */
function mmgrf_sanitize_block_tags() {
    static $set = null;
    if ( $set === null ) {
        $set = array_fill_keys( [
            'address', 'article', 'aside', 'blockquote', 'center', 'dd', 'details', 'div', 'dl', 'dt', 'fieldset',
            'figcaption', 'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main',
            'nav', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
        ], true );
    }
    return $set;
}

/** Elements that count as media when deciding whether a container is "empty". */
function mmgrf_sanitize_media_tags() {
    return [ 'img', 'iframe', 'embed', 'video', 'audio', 'svg', 'picture', 'object', 'canvas' ];
}

/**
 * Parse an HTML fragment into [DOMDocument, root element], or null.
 *
 * Hardening, each line earned by a real truncation/garbling case:
 *  - invalid UTF-8 is scrubbed first: one bad byte makes libxml reinterpret
 *    the REST of the article as Latin-1 (every later accent double-encoded);
 *  - <meta> is removed before parsing: a pasted charset declaration does the
 *    same thing;
 *  - processing instructions (<?php ?>, Word's <?xml:namespace />) are removed;
 *  - nodes that escape the wrapper are adopted back: a stray </div> in the
 *    content closes the wrapper early, and everything after it used to be
 *    silently dropped from the feed.
 */
function mmgrf_dom_load( $html ) {
    if ( ! class_exists( 'DOMDocument' ) ) {
        return null;
    }
    $html = mmgrf_utf8( $html );
    $html = preg_replace( '#<meta\b[^>]*>#i', '', $html ) ?? $html;
    $html = preg_replace( '#<\?[^>]*>#', '', $html ) ?? $html;

    $prev = libxml_use_internal_errors( true );
    $doc  = new DOMDocument( '1.0', 'UTF-8' );
    $opts = LIBXML_HTML_NODEFDTD;
    if ( defined( 'LIBXML_PARSEHUGE' ) ) {
        $opts |= LIBXML_PARSEHUGE; // deep runs of unclosed tags must not cut the parse short
    }
    // The xml PI forces UTF-8 interpretation. The fragment sits in a wrapper
    // div inside an explicit <body>: when a stray closing tag in the content
    // closes the wrapper early, what follows lands in <body> — where every
    // libxml version keeps it (text included) — and is adopted back below.
    // (With no <body>, older libxml silently discards escaped text.)
    $ok = $doc->loadHTML( '<?xml encoding="utf-8"?><html><body><div id="mmgrf-root">' . $html . '</div></body></html>', $opts );
    libxml_clear_errors();
    libxml_use_internal_errors( $prev );
    if ( ! $ok ) {
        return null;
    }

    $root = null;
    foreach ( $doc->getElementsByTagName( 'div' ) as $node ) {
        if ( $node->getAttribute( 'id' ) === 'mmgrf-root' ) {
            $root = $node;
            break; // document order: ours is the first, whatever the content holds
        }
    }
    if ( ! $root ) {
        return null;
    }
    // Adopt everything that escaped the wrapper: later siblings of the
    // wrapper, then of each ancestor up to the document.
    for ( $anchor = $root; $anchor && $anchor->parentNode; $anchor = $anchor->parentNode ) {
        $escaped = [];
        for ( $n = $anchor->nextSibling; $n; $n = $n->nextSibling ) {
            $escaped[] = $n;
        }
        foreach ( $escaped as $n ) {
            if ( $n instanceof DOMElement && in_array( strtolower( $n->tagName ), [ 'body', 'html', 'head' ], true ) ) {
                while ( $n->firstChild ) {
                    $root->appendChild( $n->firstChild );
                }
                $n->parentNode->removeChild( $n );
            } elseif ( $n instanceof DOMElement || $n instanceof DOMText || $n instanceof DOMComment ) {
                $root->appendChild( $n );
            }
        }
        if ( $anchor->parentNode instanceof DOMDocument ) {
            break;
        }
    }
    return [ $doc, $root ];
}

/** Serialize the children of a parsed root back to an HTML string. */
function mmgrf_dom_inner_html( DOMDocument $doc, DOMElement $root ) {
    $out = '';
    foreach ( $root->childNodes as $child ) {
        $out .= $doc->saveHTML( $child );
    }
    return trim( $out );
}

/**
 * Static snapshot of the nodes matching an XPath under $root. Iterating a
 * live getElementsByTagName('*') list while editing is quadratic before
 * PHP 8.3 (37s for one 8,000-row stats table).
 */
function mmgrf_dom_query( DOMDocument $doc, DOMElement $root, $xpath ) {
    $nodes = [];
    $found = ( new DOMXPath( $doc ) )->query( $xpath, $root );
    if ( $found ) {
        foreach ( $found as $node ) {
            $nodes[] = $node;
        }
    }
    return $nodes;
}

/** Words in a chunk of HTML; a space per tag boundary keeps adjacent blocks from merging. */
function mmgrf_count_words( $html ) {
    $text = strip_tags( str_replace( '<', ' <', (string) $html ) );
    $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $text = trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) ?? $text );
    if ( $text === '' ) {
        return 0;
    }
    $words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
    return is_array( $words ) ? count( $words ) : 0;
}

/** The scheme of a URL as a browser would read it (control chars and spaces ignored). */
function mmgrf_url_scheme( $url ) {
    $compact = preg_replace( '/[\x00-\x20]+/', '', (string) $url ) ?? (string) $url;
    return preg_match( '/^([a-z][a-z0-9+.\-]*):/i', $compact, $m ) ? strtolower( $m[1] ) : '';
}

/**
 * Make a URL absolute against the site root. Rooted and protocol-relative
 * forms resolve exactly; document-relative forms ("img/a.jpg", "../x") resolve
 * against the root as a best effort — a relative URL is meaningless at a
 * partner. Fragments and already-absolute URLs are returned unchanged.
 */
function mmgrf_absolutize_url( $url, $base ) {
    $url = trim( (string) $url );
    if ( $url === '' || $base === '' || $url[0] === '#' || mmgrf_url_scheme( $url ) !== '' ) {
        return $url;
    }
    if ( substr( $url, 0, 2 ) === '//' ) {
        return 'https:' . $url;
    }
    $base = rtrim( $base, '/' );
    if ( $url[0] === '/' ) {
        return $base . $url;
    }
    if ( $url[0] === '?' ) {
        return $base . '/' . $url;
    }
    return $base . '/' . preg_replace( '#^(\.{1,2}/)+#', '', $url );
}

/** Does the host/path of a URL match one of the "host" or "host/path" suffix rules? */
function mmgrf_host_matches( $url, $rules ) {
    $host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
    $path = (string) parse_url( $url, PHP_URL_PATH );
    if ( $host === '' ) {
        return false;
    }
    foreach ( (array) $rules as $rule ) {
        $rule      = strtolower( trim( (string) $rule ) );
        $rule_path = '';
        if ( strpos( $rule, '/' ) !== false ) {
            [ $rule, $rule_path ] = explode( '/', $rule, 2 );
            $rule_path = '/' . trim( $rule_path, '/' );
        }
        if ( $rule === '' ) {
            continue;
        }
        $host_ok = ( $host === $rule || substr( $host, -strlen( ".$rule" ) ) === ".$rule" );
        // The path rule matches whole segments: "google.com/maps" allows
        // /maps and /maps/embed, never /mapsevil.
        $path_ok = $rule_path === '' || $path === $rule_path || strpos( $path, $rule_path . '/' ) === 0;
        if ( $host_ok && $path_ok ) {
            return true;
        }
    }
    return false;
}

/**
 * Remove an image (or media element) from the tree — together with its
 * <figure> when that figure holds no other media, so a caption is never left
 * pointing at nothing.
 */
function mmgrf_dom_remove_media( DOMElement $el, DOMElement $root ) {
    $victim = $el;
    for ( $a = $el->parentNode; $a instanceof DOMElement && $a !== $root; $a = $a->parentNode ) {
        if ( strtolower( $a->tagName ) === 'figure' ) {
            $others = 0;
            foreach ( mmgrf_sanitize_media_tags() as $t ) {
                foreach ( $a->getElementsByTagName( $t ) as $m ) {
                    if ( $m !== $el ) {
                        $others++;
                    }
                }
            }
            if ( ! $others ) {
                $victim = $a;
            }
            break;
        }
    }
    if ( $victim->parentNode ) {
        $victim->parentNode->removeChild( $victim );
    }
}

function mmgrf_sanitize( $html, $rules ) {
    $result = [
        'html'            => '',
        'word_count'      => 0,
        'has_embeds'      => false,
        'body_has_image'  => false,
        'first_image_src' => null,
    ];
    $html = (string) $html;
    if ( trim( $html ) === '' ) {
        return $result;
    }
    $loaded = mmgrf_dom_load( $html );
    if ( ! $loaded ) {
        return $result;
    }
    [ $doc, $root ] = $loaded;

    $allowed = isset( $rules['allowed_tags'] ) && is_array( $rules['allowed_tags'] )
        ? array_fill_keys( array_map( 'strtolower', $rules['allowed_tags'] ), true )
        : null;
    $rename  = array_change_key_case( (array) ( $rules['rename_tags'] ?? [] ), CASE_LOWER );
    $base    = (string) ( $rules['base_url'] ?? '' );

    $delete_tags = array_fill_keys( mmgrf_sanitize_delete_tags(), true );
    if ( $allowed === null || ! isset( $allowed['object'] ) ) {
        $delete_tags['object'] = true;
    }
    if ( $allowed !== null ) {
        foreach ( mmgrf_sanitize_strict_delete_tags() as $t ) {
            if ( ! isset( $allowed[ $t ] ) ) {
                $delete_tags[ $t ] = true;
            }
        }
    }
    $embed_allowed = $allowed !== null && isset( $allowed['embed'] );

    // Snapshot the tree depth-first so structural edits don't upset iteration.
    $elements = [];
    $collect  = function( $node ) use ( &$collect, &$elements ) {
        foreach ( $node->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $collect( $child );
                $elements[] = $child;
            } elseif ( $child instanceof DOMComment ) {
                $elements[] = $child;
            }
        }
    };
    $collect( $root );

    foreach ( $elements as $el ) {
        if ( ! $el->parentNode ) {
            continue; // already removed with a container
        }
        if ( $el instanceof DOMComment ) {
            $el->parentNode->removeChild( $el );
            continue;
        }
        $tag = strtolower( $el->tagName );

        // libxml predates HTML5 and treats <embed> as a container: everything
        // after it in the parent gets nested INSIDE it. Hoist that content
        // back out, or deleting the embed deletes the rest of the article.
        if ( $tag === 'embed' ) {
            $after = $el->nextSibling;
            while ( $el->firstChild ) {
                $el->parentNode->insertBefore( $el->firstChild, $after );
            }
            if ( ! $embed_allowed ) {
                $el->parentNode->removeChild( $el );
                continue;
            }
        }

        if ( isset( $delete_tags[ $tag ] ) ) {
            $el->parentNode->removeChild( $el );
            continue;
        }

        if ( $tag === 'img' ) {
            mmgrf_sanitize_promote_lazy_src( $el );
        }

        // Absolutize first: every later check reads the final URL.
        if ( $base !== '' ) {
            foreach ( [ 'src', 'href', 'poster' ] as $urlattr ) {
                if ( $el->hasAttribute( $urlattr ) ) {
                    $v = $el->getAttribute( $urlattr );
                    $a = mmgrf_absolutize_url( $v, $base );
                    if ( $a !== $v ) {
                        $el->setAttribute( $urlattr, $a );
                    }
                }
            }
        }

        // Yahoo and MSN both require HTTPS embed sources — upgrade http://
        // sources in place.
        if ( ( $tag === 'iframe' || $tag === 'embed' ) && $el->hasAttribute( 'src' ) ) {
            $esrc = trim( $el->getAttribute( 'src' ) );
            if ( stripos( $esrc, 'http://' ) === 0 ) {
                $esrc = 'https://' . substr( $esrc, 7 );
            }
            $el->setAttribute( 'src', $esrc );
        }

        if ( $tag === 'iframe' ) {
            $src      = $el->getAttribute( 'src' );
            $scheme   = mmgrf_url_scheme( $src );
            $host     = strtolower( (string) parse_url( $src, PHP_URL_HOST ) );
            $blocked  = ! empty( $rules['blocked_iframe_hosts'] ) && mmgrf_host_matches( $src, $rules['blocked_iframe_hosts'] );
            $unlisted = isset( $rules['iframe_hosts'] ) && is_array( $rules['iframe_hosts'] )
                && ! mmgrf_host_matches( $src, $rules['iframe_hosts'] );
            if ( $blocked || $unlisted || $host === '' || ( $scheme !== 'https' && $scheme !== 'http' ) ) {
                // Take the embed's figure with it when nothing else is in
                // there — never leave "Watch: …" captioning a removed video.
                mmgrf_dom_remove_media( $el, $root );
                continue;
            }
        }

        if ( $tag === 'a' && ! empty( $rules['href_schemes'] ) ) {
            $href   = trim( $el->getAttribute( 'href' ) );
            $scheme = mmgrf_url_scheme( $href );
            if ( $href === '' || $href[0] === '#' || ! in_array( $scheme, $rules['href_schemes'], true ) ) {
                mmgrf_sanitize_unwrap( $el );
                continue;
            }
        }

        $final = isset( $rename[ $tag ] ) ? strtolower( (string) $rename[ $tag ] ) : $tag;

        if ( $allowed !== null && ! isset( $allowed[ $final ] ) ) {
            mmgrf_sanitize_drop_tag( $doc, $el, $tag, $allowed, $root );
            continue;
        }

        if ( $tag === 'img' ) {
            $src    = $el->getAttribute( 'src' );
            $scheme = mmgrf_url_scheme( $src );
            // A strict feed carries only fetchable images: lazy-load
            // placeholders (data:), blob: and scriptable sources are dropped.
            if ( $allowed !== null && $scheme !== 'https' && $scheme !== 'http' ) {
                mmgrf_dom_remove_media( $el, $root );
                continue;
            }
            // WordPress stamps inserted images with wp-image-{id}. Use it
            // (verified against the file name) to resolve the attachment
            // without a database search — before the class is stripped.
            if ( preg_match( '/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $el->getAttribute( 'class' ), $m ) ) {
                mmgrf_find_attachment( $src, (int) $m[1] );
            }
        }

        // Attributes are filtered against the FINAL tag's allowlist, and
        // before any rename, so nothing rides through on the old name.
        mmgrf_sanitize_attributes( $el, $final, $rules, $allowed );

        // Fix 10: rename_tags => ['h1' => 'h2'] — a body h1 duplicates the
        // item title on the partner page. Children and attributes preserved.
        if ( $final !== $tag ) {
            mmgrf_sanitize_rename( $doc, $el, $final );
        }
    }

    $out  = mmgrf_dom_inner_html( $doc, $root );
    $imgs = $root->getElementsByTagName( 'img' );

    $result['html']            = $out;
    $result['word_count']      = mmgrf_count_words( $out );
    $result['has_embeds']      = $root->getElementsByTagName( 'iframe' )->length > 0;
    $result['body_has_image']  = $imgs->length > 0;
    $result['first_image_src'] = $imgs->length > 0 ? ( $imgs->item( 0 )->getAttribute( 'src' ) ?: null ) : null;
    return $result;
}

/**
 * Lazy-load plugins park the real URL in data-src and leave a placeholder
 * (data: URI, 1x1 gif, or nothing) in src. Promote it before data-* is stripped.
 */
function mmgrf_sanitize_promote_lazy_src( DOMElement $img ) {
    $src    = trim( $img->getAttribute( 'src' ) );
    $scheme = mmgrf_url_scheme( $src );
    if ( $src !== '' && $scheme !== 'data' ) {
        return;
    }
    foreach ( [ 'data-src', 'data-lazy-src', 'data-original', 'data-orig-file' ] as $attr ) {
        $real = trim( $img->getAttribute( $attr ) );
        if ( $real !== '' && mmgrf_url_scheme( $real ) !== 'data' ) {
            $img->setAttribute( 'src', $real );
            return;
        }
    }
}

/**
 * Body-image pass over already-sanitized HTML. Walks every <img> once:
 *
 *   drop_no_rights   remove images whose attachment is flagged "no
 *                    syndication rights" (MSN)
 *   gate (min_w/…)   Fix 9 dimension gate (Yahoo):
 *                      resolvable + qualifying rendition → src/width/height rewritten
 *                      resolvable + nothing qualifies    → image removed (with
 *                                                          its figure/caption)
 *                      unresolvable (external/hotlinked) → left alone; logged
 *   dedupe           a photo already present earlier in the body is removed
 *                    — Yahoo flags an item listing the same image twice
 *   lead_*           the featured image: left alone when it already LEADS the
 *                    body (no text before it), removed from the body when it
 *                    sits further down — the caller then prepends the
 *                    canonical lead figure, and it appears exactly once.
 *
 * Returns ['html','events'=>[['code','detail'],…],'first_image_attachment',
 *          'images'=>[['url','attachment'],…],'lead'=>null|'leading'|'moved'].
 */
function mmgrf_process_body_images( $html, $opts = [] ) {
    $result = [ 'html' => $html, 'events' => [], 'first_image_attachment' => 0, 'images' => [], 'lead' => null, 'lead_caption' => '', 'lead_alt' => '' ];
    if ( trim( (string) $html ) === '' || stripos( $html, '<img' ) === false ) {
        return $result;
    }
    $loaded = mmgrf_dom_load( $html );
    if ( ! $loaded ) {
        return $result;
    }
    [ $doc, $root ] = $loaded;

    $min_w     = (int) ( $opts['min_w'] ?? 0 );
    $min_h     = (int) ( $opts['min_h'] ?? 0 );
    $max_bytes = (int) ( $opts['max_bytes'] ?? 0 );
    $gate      = $min_w > 0 || $min_h > 0;
    $dedupe    = ! empty( $opts['dedupe'] );
    $no_rights = ! empty( $opts['drop_no_rights'] );
    $lead_att  = (int) ( $opts['lead_attachment'] ?? 0 );
    $lead_key  = ! empty( $opts['lead_url'] ) ? mmgrf_image_identity( $opts['lead_url'] ) : '';
    $seen      = [];
    $xpath     = new DOMXPath( $doc );

    foreach ( mmgrf_dom_query( $doc, $root, './/img' ) as $img ) {
        if ( ! $img->parentNode ) {
            continue; // went with an earlier figure
        }
        $src = trim( $img->getAttribute( 'src' ) );
        if ( $src === '' ) {
            continue;
        }
        $att_id = mmgrf_find_attachment( $src );

        if ( $no_rights && $att_id && get_post_meta( $att_id, '_mmgrf_no_syndication_rights', true ) ) {
            mmgrf_dom_remove_media( $img, $root );
            $result['events'][] = [ 'code' => 'no_syndication_rights', 'detail' => "inline image (attachment $att_id) flagged no-syndication-rights — removed from the feed body" ];
            continue;
        }

        if ( $gate ) {
            if ( ! $att_id ) {
                $result['events'][] = [ 'code' => 'inline_image_unresolvable', 'detail' => $src . ' — not in this site\'s media library, size unverifiable' ];
            } else {
                $data   = mmgrf_attachment_image_data( $att_id, 'full' );
                $picked = mmgrf_pick_image_fit( $data, $min_w, $min_h, $max_bytes );
                if ( ! $picked ) {
                    mmgrf_dom_remove_media( $img, $root );
                    $result['events'][] = [ 'code' => 'inline_image_below_minimum', 'detail' => 'attachment ' . $att_id . ': ' . (int) ( $data['width'] ?? 0 ) . 'x' . (int) ( $data['height'] ?? 0 ) . ' with no qualifying rendition — removed from feed body' ];
                    continue;
                }
                if ( $picked['url'] !== $src ) {
                    $orig = $img->getAttribute( 'width' ) . 'x' . $img->getAttribute( 'height' );
                    $img->setAttribute( 'src', $picked['url'] );
                    $img->setAttribute( 'width', (string) $picked['width'] );
                    $img->setAttribute( 'height', (string) $picked['height'] );
                    $result['events'][] = [ 'code' => 'inline_image_rewritten', 'detail' => "attachment $att_id: $orig rendition → {$picked['width']}x{$picked['height']}" ];
                    $src = $picked['url'];
                }
            }
        }

        $identity = $att_id ? 'att:' . $att_id : mmgrf_image_identity( $src );
        $is_lead  = ( $lead_att && $att_id === $lead_att ) || ( $lead_key !== '' && mmgrf_image_identity( $src ) === $lead_key );

        if ( $is_lead && $result['lead'] === null ) {
            // "Leading" = nothing visible ahead of it: no text, no other image.
            $text_before = (int) $xpath->evaluate( 'count(preceding::text()[normalize-space(translate(., "' . "\xC2\xA0" . '", " ")) != ""])', $img );
            if ( $text_before === 0 && ! $result['images'] ) {
                $result['lead'] = 'leading';
            } else {
                // Moving it to the top must not lose what the editor typed
                // on the block: its caption/credit line and alt text live in
                // the post body, not on the attachment.
                $result['lead_alt'] = trim( $img->getAttribute( 'alt' ) );
                for ( $fig = $img->parentNode; $fig instanceof DOMElement && $fig !== $root; $fig = $fig->parentNode ) {
                    if ( strtolower( $fig->tagName ) === 'figure' ) {
                        foreach ( $fig->getElementsByTagName( 'figcaption' ) as $cap ) {
                            $result['lead_caption'] = trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $cap->textContent ) ?? $cap->textContent );
                            break;
                        }
                        break;
                    }
                }
                mmgrf_dom_remove_media( $img, $root );
                $result['lead'] = 'moved';
                continue;
            }
        } elseif ( $is_lead ) {
            // A further copy of the lead photo: it must appear exactly once.
            mmgrf_dom_remove_media( $img, $root );
            $result['events'][] = [ 'code' => 'inline_image_duplicate', 'detail' => 'the featured image appears more than once in the body — the repeat was removed (networks flag duplicate photos)' ];
            continue;
        } elseif ( $dedupe && isset( $seen[ $identity ] ) ) {
            mmgrf_dom_remove_media( $img, $root );
            $result['events'][] = [ 'code' => 'inline_image_duplicate', 'detail' => ( $att_id ? "attachment $att_id" : $src ) . ' appears more than once in the body — the repeat was removed (networks flag duplicate photos)' ];
            continue;
        }
        $seen[ $identity ] = true;

        if ( ! $result['first_image_attachment'] && ! $result['images'] ) {
            $result['first_image_attachment'] = $att_id;
        }
        $result['images'][] = [ 'url' => $src, 'attachment' => $att_id ];
    }

    $result['html'] = mmgrf_dom_inner_html( $doc, $root );
    return $result;
}

/** Back-compat wrapper: the Fix 9 inline gate alone. */
function mmgrf_gate_inline_images( $html, $min_w, $min_h, $max_bytes, $opts = [] ) {
    return mmgrf_process_body_images( $html, array_merge( $opts, [ 'min_w' => $min_w, 'min_h' => $min_h, 'max_bytes' => $max_bytes ] ) );
}

/**
 * A size-independent key for "the same photo": the upload path with the
 * rendition suffix and "-scaled" removed. Lets a 1024px body copy be matched
 * to the full-size lead even when the attachment cannot be resolved.
 */
function mmgrf_image_identity( $url ) {
    $url  = html_entity_decode( trim( (string) $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $norm = mmgrf_normalize_upload_url( $url );
    if ( $norm === '' ) {
        // Not one of our uploads: the query string can BE the image
        // (chart and image-proxy endpoints), so only the fragment goes.
        $norm = (string) preg_replace( '/#.*$/', '', $url );
    }
    $norm = mmgrf_strip_size_suffix( $norm );
    return strtolower( (string) preg_replace( '/-scaled(\.[a-z0-9]+)$/i', '$1', $norm ) );
}

/**
 * Fix 4 — prune empty and whitespace-only nodes. Runs on already-sanitized
 * HTML (after the Fix 9 inline gate and Fix 10 demotion), iterating until
 * stable (max 5 passes — removing an inner element can empty its parent).
 *
 * NO content-based text blocklist here by design: artifacts like
 * <span>ap</span> contain text and must survive — they're upstream content
 * garbage, and suppressing them at the feed layer would hide the generator bug.
 */
function mmgrf_prune_empty_nodes( $html ) {
    if ( trim( (string) $html ) === '' ) {
        return $html;
    }
    // Fast path: skip the DOM parse entirely when nothing prunable can exist.
    // Conservative — any <br>, empty attribute, blank element (whitespace or
    // non-breaking-space content, entity OR the raw U+00A0 the serializer
    // emits) triggers the full pass; a false negative here only means a
    // cosmetic no-prune, never a wrong prune.
    if ( ! preg_match( '/<br[\s\/>]|=""|>(?:\s|\xC2\xA0|&nbsp;)*<\/(p|span|em|strong|b|i|a|figcaption|li|h[1-6]|ul|ol)>/i', $html ) ) {
        return $html;
    }
    $loaded = mmgrf_dom_load( $html );
    if ( ! $loaded ) {
        return $html;
    }
    [ $doc, $root ] = $loaded;

    // 'a': a link emptied by the sanitizer (its icon/SVG removed) is dead markup.
    $prunable = array_fill_keys( [ 'p', 'span', 'em', 'strong', 'b', 'i', 'a', 'figcaption', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li' ], true );
    $inline   = array_fill_keys( [ 'span', 'em', 'strong', 'b', 'i', 'a' ], true );
    // Attributes that are meaningful when empty: booleans, and alt="" (the
    // correct markup for a decorative image).
    $keep_empty = array_fill_keys( [
        'alt', 'allowfullscreen', 'async', 'autoplay', 'checked', 'controls', 'default', 'defer', 'disabled', 'download',
        'hidden', 'ismap', 'itemscope', 'loop', 'multiple', 'muted', 'nomodule', 'novalidate', 'open', 'playsinline',
        'readonly', 'required', 'reversed', 'selected',
    ], true );
    $media_xpath = './/' . implode( '|.//', mmgrf_sanitize_media_tags() );
    $xpath       = new DOMXPath( $doc );
    $is_blank    = fn( $text ) => preg_replace( '/[\s\x{00A0}]+/u', '', $text ) === '';
    $meaningful_prev = function( $node ) {
        for ( $s = $node->previousSibling; $s; $s = $s->previousSibling ) {
            if ( $s instanceof DOMElement ) return $s;
            if ( $s instanceof DOMText && trim( $s->textContent ) !== '' ) return $s;
        }
        return null;
    };
    $meaningful_last_child = function( $el ) {
        for ( $s = $el->lastChild; $s; $s = $s->previousSibling ) {
            if ( $s instanceof DOMElement ) return $s;
            if ( $s instanceof DOMText && trim( $s->textContent ) !== '' ) return $s;
        }
        return null;
    };

    for ( $pass = 0; $pass < 5; $pass++ ) {
        $changed = false;
        $all     = mmgrf_dom_query( $doc, $root, './/*' );

        // 4. Drop attributes whose trimmed value is empty.
        foreach ( $all as $el ) {
            $drop = [];
            foreach ( $el->attributes as $attr ) {
                if ( trim( $attr->value ) === '' && ! isset( $keep_empty[ strtolower( $attr->name ) ] ) ) {
                    $drop[] = $attr->name;
                }
            }
            foreach ( $drop as $name ) {
                $el->removeAttribute( $name );
                $changed = true;
            }
        }

        // 3. Collapse runs of consecutive <br> (whitespace between counts as adjacent).
        foreach ( $all as $el ) {
            if ( $el->parentNode && strtolower( $el->tagName ) === 'br' ) {
                $prev_el = $meaningful_prev( $el );
                if ( $prev_el instanceof DOMElement && strtolower( $prev_el->tagName ) === 'br' ) {
                    $el->parentNode->removeChild( $el );
                    $changed = true;
                }
            }
        }

        // 2. Remove trailing <br> elements that end their parent.
        foreach ( $all as $el ) {
            if ( ! $el->parentNode ) continue;
            while ( ( $last = $meaningful_last_child( $el ) ) instanceof DOMElement && strtolower( $last->tagName ) === 'br' ) {
                $el->removeChild( $last );
                $changed = true;
            }
        }

        // 1. Remove blank prunable elements with no media descendant.
        // 5. Remove lists left with no <li>.
        foreach ( array_reverse( $all ) as $el ) { // deepest-ish first
            if ( ! $el->parentNode ) continue;
            $tag = strtolower( $el->tagName );
            if ( isset( $prunable[ $tag ] ) ) {
                if ( $is_blank( $el->textContent ) && ! $xpath->query( $media_xpath, $el )->length ) {
                    // A whitespace-only inline element IS the space between
                    // two words (Google Docs pastes <span> </span>) — keep
                    // the space, drop the tag.
                    if ( isset( $inline[ $tag ] ) && $el->textContent !== '' ) {
                        $el->parentNode->replaceChild( $doc->createTextNode( ' ' ), $el );
                    } else {
                        $el->parentNode->removeChild( $el );
                    }
                    $changed = true;
                }
            } elseif ( ( $tag === 'ul' || $tag === 'ol' ) && $el->getElementsByTagName( 'li' )->length === 0 ) {
                $el->parentNode->removeChild( $el );
                $changed = true;
            }
        }

        if ( ! $changed ) {
            break;
        }
    }

    return mmgrf_dom_inner_html( $doc, $root );
}

/**
 * Fix 5 — affiliate/commerce link guard. Matches HREF VALUES ONLY, never body
 * text (prose like "FanDuel Sportsbook" must never trip it).
 *   domain list: exact host or subdomain — unambiguous operator links
 *   path list:   case-insensitive substring of the full URL — weaker signal
 * Actions: 'skip_item' (flag the item for the validator), 'unwrap' (drop the
 * <a>, keep inner text), 'strip_paragraph' (remove the containing <p>).
 * Every match is logged regardless of action.
 *
 * Returns ['html','events'=>[…],'skip'=>null|['code','detail']].
 */
function mmgrf_guard_affiliate_links( $html, $domains, $paths, $domain_action = 'skip_item', $path_action = 'unwrap' ) {
    $result = [ 'html' => $html, 'events' => [], 'skip' => null ];
    if ( trim( (string) $html ) === '' || stripos( $html, '<a' ) === false || ( ! $domains && ! $paths ) ) {
        return $result;
    }
    $loaded = mmgrf_dom_load( $html );
    if ( ! $loaded ) {
        return $result;
    }
    [ $doc, $root ] = $loaded;

    $apply = function( $a, $action ) use ( $root ) {
        if ( $action === 'strip_paragraph' ) {
            $p = $a->parentNode;
            while ( $p instanceof DOMElement && $p !== $root && strtolower( $p->tagName ) !== 'p' ) {
                $p = $p->parentNode;
            }
            $victim = ( $p instanceof DOMElement && $p !== $root ) ? $p : $a;
            $victim->parentNode->removeChild( $victim );
        } else { // unwrap
            mmgrf_sanitize_unwrap( $a );
        }
    };

    foreach ( mmgrf_dom_query( $doc, $root, './/a' ) as $a ) {
        if ( ! $a->parentNode ) {
            continue; // removed with an earlier paragraph
        }
        $href = trim( $a->getAttribute( 'href' ) );
        if ( $href === '' ) {
            continue;
        }
        $host = strtolower( (string) parse_url( $href, PHP_URL_HOST ) );

        $matched = null;
        foreach ( $domains as $d ) {
            $d = strtolower( trim( $d ) );
            if ( $d !== '' && ( $host === $d || substr( $host, -strlen( ".$d" ) ) === ".$d" ) ) {
                $matched = [ 'code' => 'affiliate_domain_matched', 'pattern' => $d, 'action' => $domain_action ];
                break;
            }
        }
        if ( ! $matched ) {
            foreach ( $paths as $p ) {
                $p = trim( $p );
                if ( $p !== '' && stripos( $href, $p ) !== false ) {
                    $matched = [ 'code' => 'affiliate_path_matched', 'pattern' => $p, 'action' => $path_action ];
                    break;
                }
            }
        }
        if ( ! $matched ) {
            continue;
        }

        $detail = 'pattern "' . $matched['pattern'] . '" in ' . $href . ' — action: ' . $matched['action'];
        $result['events'][] = [ 'code' => $matched['code'], 'detail' => $detail ];
        if ( $matched['action'] === 'skip_item' ) {
            $result['skip'] = [ 'code' => $matched['code'], 'detail' => $detail ];
            // Item is being withheld entirely; no need to rewrite its body.
            return $result;
        }
        $apply( $a, $matched['action'] );
    }

    $result['html'] = mmgrf_dom_inner_html( $doc, $root );
    return $result;
}

/**
 * Rename an element in place, keeping attributes and children. Attribute
 * names libxml accepted but the DOM API rejects (".foo") are dropped rather
 * than allowed to throw and take the item down.
 */
function mmgrf_sanitize_rename( DOMDocument $doc, DOMElement $el, $new_tag ) {
    $new = $doc->createElement( $new_tag );
    foreach ( $el->attributes as $attr ) {
        try {
            $new->setAttribute( $attr->name, $attr->value );
        } catch ( \Throwable $e ) {
            continue;
        }
    }
    while ( $el->firstChild ) {
        $new->appendChild( $el->firstChild );
    }
    $el->parentNode->replaceChild( $new, $el );
    return $new;
}

/** Replace an element with its children (preserve content, drop the tag). */
function mmgrf_sanitize_unwrap( DOMElement $el ) {
    $parent = $el->parentNode;
    while ( $el->firstChild ) {
        $parent->insertBefore( $el->firstChild, $el );
    }
    $parent->removeChild( $el );
}

/** Text or inline element — content that would run into its neighbour without a separator. */
function mmgrf_dom_is_inline_content( $node ) {
    if ( $node instanceof DOMText ) {
        return trim( str_replace( "\xC2\xA0", ' ', $node->textContent ) ) !== '';
    }
    return $node instanceof DOMElement && ! isset( mmgrf_sanitize_block_tags()[ strtolower( $node->tagName ) ] );
}

/**
 * Drop a tag the profile does not allow, keeping its content readable.
 *
 * Inline tags are simply unwrapped. A block-level tag that directly holds
 * text ("<div>First sentence.</div><div>Second.</div>", <dt>/<dd>) becomes a
 * paragraph — unwrapping it would fuse the sentences ("…here.Second…") and
 * under-count words for the body floor. A block that only wraps other blocks
 * is unwrapped, with a space where its edges would otherwise touch text.
 */
function mmgrf_sanitize_drop_tag( DOMDocument $doc, DOMElement $el, $tag, $allowed, $root = null ) {
    $blocks = mmgrf_sanitize_block_tags();
    if ( $tag !== 'caption' && ! isset( $blocks[ $tag ] ) ) {
        mmgrf_sanitize_unwrap( $el );
        return;
    }
    // A paragraph cannot live inside a heading, another paragraph, or an
    // inline element (a block-level link, a span): there the block is just
    // unwrapped — "<h2><div>Title</div></h2>" must stay a heading.
    $phrasing_parent = false;
    for ( $a = $el->parentNode; $a instanceof DOMElement && $a !== $root; $a = $a->parentNode ) {
        $at = strtolower( $a->tagName );
        if ( $at === 'p' || preg_match( '/^h[1-6]$/', $at ) || ! isset( $blocks[ $at ] ) ) {
            $phrasing_parent = true;
            break;
        }
    }

    $has_block_child = false;
    foreach ( $el->childNodes as $child ) {
        if ( $child instanceof DOMElement && isset( $blocks[ strtolower( $child->tagName ) ] ) ) {
            $has_block_child = true;
            break;
        }
    }
    $has_text = trim( str_replace( "\xC2\xA0", ' ', $el->textContent ) ) !== '';

    if ( $has_text && ! $has_block_child && ! $phrasing_parent && isset( $allowed['p'] ) ) {
        $p = $doc->createElement( 'p' );
        while ( $el->firstChild ) {
            $p->appendChild( $el->firstChild );
        }
        if ( $tag === 'caption' ) {
            // A paragraph cannot live directly inside <table>: hoist the
            // caption text in front of its table.
            $table = $el->parentNode;
            while ( $table instanceof DOMElement && strtolower( $table->tagName ) !== 'table' ) {
                $table = $table->parentNode;
            }
            $el->parentNode->removeChild( $el );
            if ( $table instanceof DOMElement && $table->parentNode ) {
                $table->parentNode->insertBefore( $p, $table );
            }
            return;
        }
        $el->parentNode->replaceChild( $p, $el );
        return;
    }

    $parent = $el->parentNode;
    if ( $el->firstChild && $el->previousSibling
        && mmgrf_dom_is_inline_content( $el->previousSibling ) && mmgrf_dom_is_inline_content( $el->firstChild ) ) {
        $parent->insertBefore( $doc->createTextNode( ' ' ), $el );
    }
    if ( $el->lastChild && $el->nextSibling
        && mmgrf_dom_is_inline_content( $el->nextSibling ) && mmgrf_dom_is_inline_content( $el->lastChild ) ) {
        $parent->insertBefore( $doc->createTextNode( ' ' ), $el->nextSibling );
    }
    mmgrf_sanitize_unwrap( $el );
}

function mmgrf_sanitize_attributes( DOMElement $el, $tag, $rules, $allowed ) {
    $attr_allow = null;
    if ( $allowed !== null ) {
        $map        = $rules['allowed_attributes'] ?? [];
        $attr_allow = array_fill_keys(
            array_merge( $map[ $tag ] ?? [], $map['*'] ?? [] ),
            true
        );
    }
    $patterns = $rules['strip_attr_patterns'] ?? [];
    // Attributes that carry a URL a browser will fetch or execute.
    static $url_attrs = [ 'href' => 1, 'src' => 1, 'xlink:href' => 1, 'action' => 1, 'formaction' => 1, 'poster' => 1, 'data' => 1, 'background' => 1 ];

    $to_remove = [];
    foreach ( $el->attributes as $attr ) {
        $name = strtolower( $attr->nodeName ); // nodeName keeps a namespace prefix (xlink:href)
        if ( ! empty( $rules['strip_style_attr'] ) && $name === 'style' ) {
            $to_remove[] = $attr;
            continue;
        }
        // onclick, onerror, … and srcdoc (an inline document) are never
        // legitimate in a feed.
        if ( strpos( $name, 'on' ) === 0 || $name === 'srcdoc' ) {
            $to_remove[] = $attr;
            continue;
        }
        if ( isset( $url_attrs[ $name ] ) ) {
            $scheme = mmgrf_url_scheme( $attr->value );
            // Scriptable schemes in every mode; data: everywhere except as
            // an inline image source.
            if ( in_array( $scheme, [ 'javascript', 'vbscript', 'livescript', 'mocha' ], true )
                || ( $scheme === 'data' && ! ( $tag === 'img' && $name === 'src' ) ) ) {
                $to_remove[] = $attr;
                continue;
            }
        }
        foreach ( $patterns as $p ) {
            if ( $p === $name || ( substr( $p, -1 ) === '*' && strpos( $name, rtrim( $p, '*' ) ) === 0 ) ) {
                $to_remove[] = $attr;
                continue 2;
            }
        }
        if ( $attr_allow !== null && ! isset( $attr_allow[ $name ] ) ) {
            $to_remove[] = $attr;
        }
    }
    foreach ( $to_remove as $attr ) {
        $el->removeAttributeNode( $attr );
    }
}
