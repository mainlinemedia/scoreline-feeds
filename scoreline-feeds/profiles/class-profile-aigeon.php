<?php
/**
 * Aigeon profile — the legacy raw feed. Byte-shape parity with v2.1.0 output,
 * modulo the intended fixes: CDATA values no longer pre-escaped (D1), a
 * dcterms:modified element when a post is genuinely updated (D2, additive),
 * and executable markup stripped from content (D6). wfw/slash retained for
 * parity (v3.0 G3 applies to network profiles only).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMGRF_Profile_Aigeon extends MMGRF_Profile_Base {

    public function get_key() {
        return 'aigeon';
    }

    public function get_namespaces() {
        return [
            'content' => 'http://purl.org/rss/1.0/modules/content/',
            'wfw'     => 'http://wellformedweb.org/CommentAPI/',
            'dc'      => 'http://purl.org/dc/elements/1.1/',
            'atom'    => 'http://www.w3.org/2005/Atom',
            'sy'      => 'http://purl.org/rss/1.0/modules/syndication/',
            'slash'   => 'http://purl.org/rss/1.0/modules/slash/',
            'media'   => 'http://search.yahoo.com/mrss/',
            'dcterms' => 'http://purl.org/dc/terms/',
        ];
    }

    public function get_sanitizer_rules() {
        return [
            'allowed_tags'        => null, // permissive
            'strip_attr_patterns' => [ 'srcset', 'sizes', 'loading', 'decoding', 'fetchpriority', 'data-*' ],
            'base_url'            => home_url(),
        ];
    }

    public function get_image_policy() {
        return 'optional';
    }

    public function get_default_post_count() {
        return 25;
    }

    public function get_max_items() {
        return 100;
    }

    public function utm_default() {
        return true;
    }

    public function get_channel_open( $opts ) {
        // v2.1.0 order: title, atom:link, link, description, lastBuildDate, language, sy:*.
        $out  = '    ' . mmgrf_el( 'title', $opts['feed_title'] ) . "\n";
        $out .= '    <atom:link href="' . mmgrf_xml( $opts['feed_url'] ) . '" rel="self" type="application/rss+xml"/>' . "\n";
        $out .= '    ' . mmgrf_el( 'link', $opts['feed_link'] ) . "\n";
        $out .= '    ' . mmgrf_el( 'description', $opts['feed_description'] ) . "\n";
        $out .= '    ' . mmgrf_el( 'lastBuildDate', mmgrf_rfc822( time() ) ) . "\n";
        $out .= '    ' . mmgrf_el( 'language', get_bloginfo( 'language' ) ) . "\n";
        $out .= "    <sy:updatePeriod>hourly</sy:updatePeriod>\n";
        $out .= "    <sy:updateFrequency>1</sy:updateFrequency>\n";
        return $out;
    }

    public function validate_item( $item ) {
        if ( trim( (string) $item['title'] ) === '' ) {
            return $this->fail( 'title_empty' );
        }
        return $this->ok();
    }

    public function render_item( $item, $opts ) {
        $out  = "    <item>\n";
        $out .= '      ' . mmgrf_el( 'title', $item['title'], [], true ) . "\n";
        $out .= '      ' . mmgrf_el( 'link', $item['link'] ) . "\n";
        $out .= '      ' . mmgrf_el( 'pubDate', mmgrf_rfc822( $item['pub_ts'] ) ) . "\n";
        $out .= '      ' . mmgrf_el( 'dc:creator', $item['author'], [], true ) . "\n";
        foreach ( $item['categories'] as $cat ) {
            $out .= '      ' . mmgrf_el( 'category', $cat, [], true ) . "\n";
        }
        foreach ( $item['tags'] as $tag ) {
            $out .= '      <category domain="tag">' . mmgrf_cdata( $tag ) . "</category>\n";
        }
        $out .= '      <category domain="score">' . mmgrf_xml( $item['score'] ) . "</category>\n";
        $out .= '      <guid isPermaLink="true">' . mmgrf_xml( $item['permalink'] ) . "</guid>\n";
        $out .= '      ' . mmgrf_el( 'description', $item['excerpt'], [], true ) . "\n";
        $out .= '      ' . mmgrf_el( 'content:encoded', $item['san']['html'], [], true ) . "\n";
        if ( $mod = $this->modified_el( $item ) ) {
            $out .= '      ' . $mod . "\n";
        }
        $out .= '      ' . mmgrf_el( 'wfw:commentRss', $item['comments_feed'] ) . "\n";
        $out .= '      <slash:comments>' . (int) $item['comment_count'] . "</slash:comments>\n";
        if ( ! empty( $item['image'] ) ) {
            $img  = $item['image'];
            $out .= '      <media:content url="' . mmgrf_xml( $img['url'] ) . '" width="' . (int) $img['width'] . '" height="' . (int) $img['height'] . '" type="' . mmgrf_xml( $img['type'] ) . '" medium="image">' . "\n";
            $out .= '        <media:thumbnail url="' . mmgrf_xml( $img['url'] ) . '"/>' . "\n";
            $out .= "      </media:content>\n";
        }
        $out .= "    </item>\n";
        return $out;
    }
}
