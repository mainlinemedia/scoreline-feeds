<?php
/**
 * MSN profile. All hard values verified against Microsoft support docs
 * 2026-08-04 (spec v3.1 §4.4): mi namespace, title 21–150 (short title unlocks
 * >150), pubDate in the past and <365 days, dcterms:modified updates, tag
 * allowlist, YouTube iframes rejected at ingestion.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMGRF_Profile_MSN extends MMGRF_Profile_Base {

    const TITLE_MIN     = 21;   // "more than 20 characters"
    const TITLE_MAX     = 150;
    const MAX_AGE_DAYS  = 365;
    const IMG_MIN_W     = 640;
    const IMG_MIN_H     = 360;
    const IMG_WARN_W    = 1280;
    const IMG_WARN_H    = 720;
    const IMG_MAX_BYTES = 2097152; // 2MB (verified MSN thumbnail limit)

    public function get_key() {
        return 'msn';
    }

    public function get_namespaces() {
        return [
            'content' => 'http://purl.org/rss/1.0/modules/content/',
            'dc'      => 'http://purl.org/dc/elements/1.1/',
            'media'   => 'http://search.yahoo.com/mrss/',
            'atom'    => 'http://www.w3.org/2005/Atom',
            'dcterms' => 'http://purl.org/dc/terms/',
            'mi'      => 'http://schemas.ingestion.microsoft.com/common/',
        ];
    }

    public function get_sanitizer_rules() {
        return [
            // Verified MSN tag set. h6 intentionally absent (h1–h5 only).
            'allowed_tags'         => [ 'b', 'i', 'em', 'strong', 'sub', 'sup', 'small', 'h1', 'h2', 'h3', 'h4', 'h5', 'a', 'img', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'col', 'caption', 'colgroup', 'ul', 'ol', 'li', 'p', 'div', 'span', 'br', 'blockquote', 'iframe', 'figure', 'figcaption' ],
            'allowed_attributes'   => [
                'a'      => [ 'href', 'title' ],
                'img'    => [ 'src', 'alt', 'width', 'height', 'title' ],
                'iframe' => [ 'src', 'width', 'height', 'allowfullscreen', 'frameborder' ],
                'td'     => [ 'colspan', 'rowspan' ],
                'th'     => [ 'colspan', 'rowspan', 'scope' ],
                'col'    => [ 'span' ],
            ],
            'strip_style_attr'     => true,
            'href_schemes'         => [ 'https', 'http', 'mailto' ],
            // Verified platform list — YouTube deliberately excluded (rejected at ingestion).
            'iframe_hosts'         => [ 'x.com', 'twitter.com', 'facebook.com', 'instagram.com', 'pinterest.com', 'spotify.com', 'infogram.com', 'maps.google.com', 'google.com/maps', 'giphy.com', 'flourish.studio', 'flo.uri.sh', 'reddit.com', 'redditmedia.com', 'tiktok.com' ],
            'blocked_iframe_hosts' => [ 'youtube.com', 'youtube-nocookie.com', 'youtu.be' ],
            'base_url'             => home_url(),
        ];
    }

    public function get_default_image_size() {
        return 'full'; // MSN recommends 1280×720+; 'large' (1024px) falls short
    }

    public function get_default_post_count() {
        return 30; // MSN recommends holding under 30 fresh items
    }

    public function get_max_items() {
        return 30;
    }

    public function prepare_item( $item, $opts = [] ) {
        $item = parent::prepare_item( $item, $opts );
        $item['san']['html'] = mmgrf_prune_empty_nodes( $item['san']['html'] );
        $img = $item['image'];
        if ( $img ) {
            if ( ! empty( $img['no_synd_rights'] ) ) {
                // MMG does not hold syndication rights (e.g. Getty) — rights-safe
                // default is to withhold the image entirely for MSN.
                $item['image_dropped'] = [ 'code' => 'no_syndication_rights', 'detail' => 'attachment flagged no-syndication-rights' ];
                $item['image']         = null;
            } else {
                $picked = $this->pick_image_candidate( $img, self::IMG_MIN_W, self::IMG_MIN_H, self::IMG_MAX_BYTES );
                if ( ! $picked && ! empty( $img['file_missing'] ) ) {
                    $item['image_dropped'] = [ 'code' => 'image_file_missing', 'detail' => 'attachment ' . (int) $img['attachment_id'] . ' — no rendition file exists on disk; re-upload or re-save the image' ];
                } elseif ( ! $picked ) {
                    $item['image_dropped'] = ! empty( $img['dims_unknown'] )
                        ? [ 'code' => 'image_dimensions_unknown', 'detail' => 'attachment ' . (int) $img['attachment_id'] . ' at ' . $img['file'] . ' — dimensions unverifiable' ]
                        : [ 'code' => 'image_below_minimum', 'detail' => (int) $img['width'] . 'x' . (int) $img['height'] . ' fails MSN limits (min 640x360, max 2MB)' ];
                }
                $item['image'] = $picked;
            }
        }
        return $item;
    }

    public function validate_item( $item ) {
        $title_len = mb_strlen( trim( (string) $item['title'] ) );
        if ( $title_len < self::TITLE_MIN ) {
            return $this->fail( 'title_too_short', "{$title_len} chars; MSN requires more than 20" );
        }
        if ( $title_len > self::TITLE_MAX && trim( (string) $item['short_title'] ) === '' ) {
            return $this->fail( 'title_too_long_no_short_title', "{$title_len} chars; set a short title in the Syndication box" );
        }
        $now = time();
        if ( $item['pub_ts'] > $now ) {
            return $this->fail( 'pubdate_future', gmdate( 'c', $item['pub_ts'] ) );
        }
        if ( $item['pub_ts'] < $now - self::MAX_AGE_DAYS * 86400 ) {
            return $this->fail( 'pubdate_too_old', gmdate( 'c', $item['pub_ts'] ) . ' (MSN limit: 365 days)' );
        }
        if ( trim( $item['san']['html'] ) === '' ) {
            return $this->fail( 'body_empty', 'empty after sanitization' );
        }
        if ( trim( (string) $item['permalink'] ) === '' ) {
            return $this->fail( 'link_missing' );
        }
        if ( empty( $item['image'] ) ) {
            return $this->fail( 'no_featured_image', 'no image with syndication rights (MSN will not auto-publish imageless articles)' );
        }
        return $this->ok();
    }

    public function render_item( $item, $opts ) {
        $out  = "    <item>\n";
        $out .= '      ' . mmgrf_el( 'title', $item['title'], [], true ) . "\n";
        if ( trim( (string) $item['short_title'] ) !== '' ) {
            $out .= '      ' . mmgrf_el( 'mi:shortTitle', trim( $item['short_title'] ) ) . "\n";
        }
        $out .= '      ' . mmgrf_el( 'link', $item['link'] ) . "\n";
        $out .= '      <guid isPermaLink="false">' . mmgrf_xml( $item['permalink'] ) . "</guid>\n";
        $out .= '      ' . mmgrf_el( 'pubDate', mmgrf_rfc822( $item['pub_ts'] ) ) . "\n";
        if ( $mod = $this->modified_el( $item ) ) {
            $out .= '      ' . $mod . "\n";
        }
        $out .= '      ' . mmgrf_el( 'dc:creator', $item['author'], [], true ) . "\n";
        foreach ( $item['categories'] as $cat ) {
            $out .= '      ' . mmgrf_el( 'category', $cat, [], true ) . "\n";
        }
        $out .= '      ' . mmgrf_el( 'description', $item['excerpt'], [], true ) . "\n"; // abstract: plain text
        if ( $img = $item['image'] ) {
            $out .= '      <media:content url="' . mmgrf_xml( $img['url'] ) . '" width="' . (int) $img['width'] . '" height="' . (int) $img['height'] . '" type="' . mmgrf_xml( $img['type'] ) . '" medium="image">' . "\n";
            $out .= '        <media:thumbnail url="' . mmgrf_xml( $img['url'] ) . '"/>' . "\n";
            if ( $img['caption'] !== '' || $img['credit'] !== '' ) {
                $out .= '        <media:description type="plain">' . mmgrf_xml( $img['caption'] !== '' ? $img['caption'] : $img['credit'] ) . "</media:description>\n";
            }
            $out .= "      </media:content>\n";
        }
        $out .= '      ' . mmgrf_el( 'content:encoded', $item['san']['html'], [], true ) . "\n";
        $out .= "    </item>\n";
        return $out;
    }
}
