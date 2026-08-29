<?php
/**
 * Yahoo profile (spec v3.1 §4.5). Strict allowlist sanitization, 150-word
 * post-sanitization body floor, stable-guid/frozen-pubDate update semantics
 * with an <updated> element, 1280×720 image floor (reject the image, not the
 * item), affiliate-domain blocklist, lead image inlined as the first <figure>
 * of content:encoded.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMGRF_Profile_Yahoo extends MMGRF_Profile_Base {

    const BODY_MIN_WORDS = 150;
    const DESC_MIN_WORDS = 3;
    const IMG_MIN_W      = 1280;
    const IMG_MIN_H      = 720;
    const IMG_MAX_BYTES  = 5242880; // 5MB

    public function get_key() {
        return 'yahoo';
    }

    public function get_namespaces() {
        // Match Yahoo's published declaration exactly — note /rss, not /mrss/ (G2).
        return [
            'media'   => 'http://search.yahoo.com/rss',
            'content' => 'http://purl.org/rss/1.0/modules/content/',
            'dc'      => 'http://purl.org/dc/elements/1.1/',
            'atom'    => 'http://www.w3.org/2005/Atom',
        ];
    }

    public function get_sanitizer_rules() {
        return [
            'allowed_tags'       => [ 'a', 'b', 'blockquote', 'br', 'em', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'i', 'img', 'li', 'ol', 'ul', 'p', 'strong', 'iframe', 'embed', 'sub', 'sup', 'pre', 'figure', 'figcaption', 'section', 'span', 'table', 'tr', 'th', 'td' ],
            'allowed_attributes' => [
                'a'      => [ 'href', 'title' ],
                'img'    => [ 'src', 'alt', 'width', 'height' ],
                'iframe' => [ 'src', 'width', 'height', 'allowfullscreen' ],
                'embed'  => [ 'src', 'type', 'width', 'height' ],
                'th'     => [ 'colspan', 'rowspan', 'scope' ],
                'td'     => [ 'colspan', 'rowspan' ],
            ],
            'strip_style_attr'   => true, // the #1 silent-degradation source on block-editor sites
            'href_schemes'       => [ 'https', 'http', 'mailto' ],
            // Fix 10: a body h1 duplicates the item <title> on Yahoo's render.
            // h1 stays allowlisted (it's in Yahoo's published tag set) — this
            // is a rendering-quality demotion, not a compliance strip.
            'rename_tags'        => [ 'h1' => 'h2' ],
            'base_url'           => home_url(),
        ];
    }

    public function get_image_policy() {
        return 'optional'; // Yahoo does not mandate a lead image
    }

    public function get_default_image_size() {
        return 'full'; // 'large' is 1024px — under Yahoo's 1280×720 floor (D5)
    }

    public function get_default_post_count() {
        return 50;
    }

    public function get_max_items() {
        return 100;
    }

    public function prepare_item( $item, $opts = [] ) {
        $item = parent::prepare_item( $item, $opts );

        // Fix 5: affiliate/commerce guard — href values only, before the image
        // gate so unwraps/strips are swept by the pruning pass below.
        $guard = mmgrf_guard_affiliate_links(
            $item['san']['html'],
            $opts['affiliate_domains'] ?? [],
            $opts['affiliate_paths'] ?? [],
            $opts['affiliate_domain_action'] ?? 'skip_item',
            $opts['affiliate_path_action'] ?? 'unwrap'
        );
        $item['san']['html']    = $guard['html'];
        $item['affiliate_log']  = $guard['events'];
        $item['affiliate_skip'] = $guard['skip'];

        // Fix 9: gate inline body images with the same ladder as the lead —
        // WordPress inserts 1024px renditions that render fine on-site but
        // violate Yahoo's floor in the feed. Yahoo-only: NewsBreak and MSN
        // declare no inline minimums.
        $gated = mmgrf_gate_inline_images( $item['san']['html'], self::IMG_MIN_W, self::IMG_MIN_H, self::IMG_MAX_BYTES );
        // Fix 4: prune empty nodes AFTER the gate, so a figure emptied by an
        // image removal is swept in the same cycle.
        $gated['html'] = mmgrf_prune_empty_nodes( $gated['html'] );
        $item['san']['html'] = $gated['html'];
        $item['san']['first_image_attachment'] = $gated['first_image_attachment'];
        $item['inline_image_log'] = $gated['events'];
        // Recompute image facts the gate may have changed.
        $item['san']['body_has_image']  = stripos( $gated['html'], '<img' ) !== false;
        $item['san']['first_image_src'] = preg_match( '/<img[^>]+src="([^"]+)"/i', $gated['html'], $m ) ? $m[1] : null;

        $img = $item['image'];
        if ( $img ) {
            $picked = $this->pick_image_candidate( $img, self::IMG_MIN_W, self::IMG_MIN_H, self::IMG_MAX_BYTES );
            if ( ! $picked ) {
                // Reject the image, not the item — and surface why in the skip
                // log (an invisible drop looks like a missing featured image).
                if ( ! empty( $img['file_missing'] ) ) {
                    $item['image_dropped'] = [ 'code' => 'image_file_missing', 'detail' => 'attachment ' . (int) $img['attachment_id'] . ' — no rendition file exists on disk (media-library edit?); re-upload or re-save the image' ];
                } elseif ( ! empty( $img['dims_unknown'] ) ) {
                    // Yahoo's floor is a hard requirement; never ship an asset
                    // whose size cannot be verified.
                    $item['image_dropped'] = [ 'code' => 'image_dimensions_unknown', 'detail' => 'attachment ' . (int) $img['attachment_id'] . ' at ' . $img['file'] . ' — dimensions unverifiable' ];
                } elseif ( ! empty( $img['filesize'] ) && $img['filesize'] > self::IMG_MAX_BYTES ) {
                    $item['image_dropped'] = [ 'code' => 'image_over_size_limit', 'detail' => 'over Yahoo 5MB limit with no smaller rendition — shipped without image' ];
                } else {
                    $item['image_dropped'] = [ 'code' => 'image_below_minimum', 'detail' => (int) $img['width'] . 'x' . (int) $img['height'] . ' under Yahoo 1280x720 floor — shipped without image' ];
                }
            }
            $item['image'] = $picked;
        }
        return $item;
    }

    public function validate_item( $item ) {
        if ( trim( (string) $item['title'] ) === '' ) {
            return $this->fail( 'title_empty', 'Yahoo minimum: 1 word' );
        }
        $desc_words = preg_split( '/\s+/u', trim( (string) $item['excerpt'] ), -1, PREG_SPLIT_NO_EMPTY );
        if ( count( $desc_words ) < self::DESC_MIN_WORDS ) {
            return $this->fail( 'description_below_word_floor', count( $desc_words ) . ' words (floor 3)' );
        }
        if ( $item['san']['word_count'] < self::BODY_MIN_WORDS ) {
            return $this->fail( 'body_below_word_floor', $item['san']['word_count'] . ' words post-sanitization (floor 150)' );
        }
        if ( $item['pub_ts'] > time() ) {
            return $this->fail( 'pubdate_future', gmdate( 'c', $item['pub_ts'] ) );
        }
        if ( trim( (string) $item['permalink'] ) === '' ) {
            return $this->fail( 'guid_missing' );
        }
        if ( ! empty( $item['affiliate_skip'] ) ) {
            return $this->fail( $item['affiliate_skip']['code'], $item['affiliate_skip']['detail'] );
        }
        return $this->ok();
    }

    public function render_item( $item, $opts ) {
        $out  = "    <item>\n";
        $out .= '      ' . mmgrf_el( 'title', $item['title'], [], true ) . "\n";
        $out .= '      ' . mmgrf_el( 'link', $item['link'] ) . "\n";
        $out .= '      <guid isPermaLink="true">' . mmgrf_xml( $item['permalink'] ) . "</guid>\n";
        // pubDate is frozen on update (Yahoo rule 2); <updated> signals changes.
        $out .= '      ' . mmgrf_el( 'pubDate', mmgrf_rfc822( $item['pub_ts'] ) ) . "\n";
        if ( $this->is_modified( $item ) ) {
            $out .= '      ' . mmgrf_el( 'updated', mmgrf_rfc822( $item['mod_ts'] ) ) . "\n";
        }
        $out .= '      ' . mmgrf_el( 'dc:creator', $item['author'], [], true ) . "\n";
        foreach ( $item['categories'] as $cat ) {
            $out .= '      ' . mmgrf_el( 'category', $cat, [], true ) . "\n";
        }
        $out .= '      ' . mmgrf_el( 'description', $item['excerpt'], [], true ) . "\n";

        $body      = $item['san']['html'];
        $img       = $item['image'];
        $body_imgs = ! empty( $item['san']['body_has_image'] );

        // No duplicate lead: skip the prepend when the body already leads with
        // this exact URL OR any rendition of the same attachment (Fix 9 may
        // have rewritten the body copy to a different rendition URL).
        $same_attachment = ! empty( $img['attachment_id'] )
            && (int) ( $item['san']['first_image_attachment'] ?? 0 ) === (int) $img['attachment_id'];
        if ( $img && ! $same_attachment && ( $item['san']['first_image_src'] ?? null ) !== $img['url'] ) {
            // Preferred method: lead image as the FIRST element of content:encoded,
            // figure-wrapped with alt + caption (the only method that carries one).
            $figure = '<figure><img src="' . mmgrf_xml( $img['url'] ) . '" alt="' . mmgrf_xml( $img['alt'] !== '' ? $img['alt'] : $item['title'] ) . '"/>';
            $credit = $img['credit'] !== '' ? $img['credit'] : $img['caption'];
            if ( $credit !== '' ) {
                $figure .= '<figcaption>' . mmgrf_xml( $credit ) . '</figcaption>';
            }
            $figure .= '</figure>';
            $body    = $figure . "\n" . $body;
            $body_imgs = true;
        }

        $out .= '      ' . mmgrf_el( 'content:encoded', $body, [], true ) . "\n";

        // Fix 3: declared alongside the inline lead figure — same asset, with
        // explicit dimension metadata the inline <img> doesn't carry. Yahoo
        // treats body embeds as taking precedence, so this is a declared
        // fallback, never a second image.
        if ( $img ) {
            $out .= '      <media:content url="' . mmgrf_xml( $img['url'] ) . '" type="' . mmgrf_xml( $img['type'] ) . '" medium="image" width="' . (int) $img['width'] . '" height="' . (int) $img['height'] . '">' . "\n";
            $credit = $img['credit'] !== '' ? $img['credit'] : $img['caption'];
            if ( $credit !== '' ) {
                $out .= '        <media:description>' . mmgrf_xml( $credit ) . "</media:description>\n";
            }
            $out .= "      </media:content>\n";
        }

        $out .= "    </item>\n";
        return $out;
    }
}
