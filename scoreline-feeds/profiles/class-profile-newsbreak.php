<?php
/**
 * NewsBreak profile — shape-compatible replacement for the standalone
 * "NewsBreak RSS Feed" v1.3.0 plugin, with its bugs fixed: true-GMT pubDate
 * (N2), CDATA values unescaped (N1/D1), attribute cleanup via DOMDocument
 * instead of regex. GUID = clean permalink, identical across every MMG feed
 * carrying the same post (NewsBreak cross-feed GUID consistency requirement).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMGRF_Profile_NewsBreak extends MMGRF_Profile_Base {

    public function get_key() {
        return 'newsbreak';
    }

    public function get_namespaces() {
        return [
            'content' => 'http://purl.org/rss/1.0/modules/content/',
            'dc'      => 'http://purl.org/dc/elements/1.1/',
            'media'   => 'http://search.yahoo.com/mrss/',
            'atom'    => 'http://www.w3.org/2005/Atom',
            'nb'      => 'https://www.newsbreak.com/',
            'dcterms' => 'http://purl.org/dc/terms/',
        ];
    }

    public function get_sanitizer_rules() {
        return [
            'allowed_tags'        => null, // permissive — NewsBreak renders close to as-supplied
            'strip_attr_patterns' => [ 'srcset', 'sizes', 'loading', 'decoding', 'fetchpriority', 'data-*' ],
            'base_url'            => home_url(),
        ];
    }

    public function get_default_image_size() {
        // Was 'large' (1024px) for byte-diffability against the retired
        // standalone plugin; that rationale is dead. Full + ladder gives
        // NewsBreak the same 2560px assets Yahoo gets.
        return 'full';
    }

    public function get_default_post_count() {
        return 50; // matches the live standalone plugin
    }

    public function get_max_items() {
        return 100;
    }

    public function prepare_item( $item, $opts = [] ) {
        $item = parent::prepare_item( $item, $opts );
        $item['san']['html'] = mmgrf_prune_empty_nodes( $item['san']['html'] );
        // Fix 2 follow-up: NewsBreak declares no dimension minimums, so an
        // unverifiable image SHIPS (dropping it would trip image_policy=require
        // and withhold the whole item — strictly worse on the one live
        // network). But the condition must be visible, not silent.
        if ( ! empty( $item['image']['file_missing'] ) ) {
            // A known-dead asset URL is worse than no image — the partner's
            // fetch will fail exactly like Yahoo's did.
            $item['image_note'] = [
                'code'   => 'image_file_missing',
                'detail' => 'attachment ' . (int) $item['image']['attachment_id'] . ' — no rendition file exists on disk; withheld',
            ];
            $item['image'] = null;
            return $item;
        }
        if ( ! empty( $item['image']['dims_unknown'] ) ) {
            $item['image_note'] = [
                'code'   => 'image_dimensions_unknown',
                'detail' => 'attachment ' . (int) $item['image']['attachment_id'] . ' at ' . $item['image']['file'] . ' — shipped with unverified dimensions (NewsBreak declares no floor)',
            ];
        }
        return $item;
    }

    public function get_channel_open( $opts ) {
        // Live plugin order: title, link, description, language, lastBuildDate, atom:link.
        $out  = '    ' . mmgrf_el( 'title', $opts['feed_title'] ) . "\n";
        $out .= '    ' . mmgrf_el( 'link', $opts['feed_link'] ) . "\n";
        $out .= '    ' . mmgrf_el( 'description', $opts['feed_description'] ) . "\n";
        $out .= '    ' . mmgrf_el( 'language', get_bloginfo( 'language' ) ) . "\n";
        $out .= '    ' . mmgrf_el( 'lastBuildDate', mmgrf_rfc822( time() ) ) . "\n";
        $out .= '    <atom:link href="' . mmgrf_xml( $opts['feed_url'] ) . '" rel="self" type="application/rss+xml"/>' . "\n";
        return $out;
    }

    public function get_channel_extras( $opts ) {
        if ( empty( $opts['nb_scripts'] ) || trim( $opts['nb_scripts'] ) === '' ) {
            return '';
        }
        // Executes on NewsBreak's property under MMG's name — populated only via
        // the admin textarea, which itself requires Austin's sign-off (spec §4.3).
        return '    <nb:scripts>' . mmgrf_cdata( trim( $opts['nb_scripts'] ) ) . "</nb:scripts>\n";
    }

    public function validate_item( $item ) {
        if ( trim( (string) $item['title'] ) === '' ) {
            return $this->fail( 'title_empty' );
        }
        if ( trim( $item['san']['html'] ) === '' ) {
            return $this->fail( 'body_empty' );
        }
        if ( empty( $item['image'] ) ) {
            return $this->fail( 'no_featured_image', 'no resolvable image (NewsBreak may pause imageless feeds)' );
        }
        return $this->ok();
    }

    public function render_item( $item, $opts ) {
        $img = $item['image'];

        $out  = "    <item>\n";
        $out .= '      ' . mmgrf_el( 'title', $item['title'] ) . "\n"; // live shape: plain-escaped, no CDATA
        $out .= '      ' . mmgrf_el( 'link', $item['link'] ) . "\n";
        $out .= '      <guid isPermaLink="true">' . mmgrf_xml( $item['permalink'] ) . "</guid>\n";
        $out .= '      ' . mmgrf_el( 'pubDate', mmgrf_rfc822( $item['pub_ts'] ) ) . "\n";
        $out .= '      ' . mmgrf_el( 'dc:creator', $item['author'], [], true ) . "\n";
        foreach ( $item['categories'] as $cat ) {
            $out .= '      ' . mmgrf_el( 'category', $cat, [], true ) . "\n";
        }
        $out .= '      ' . mmgrf_el( 'description', $item['excerpt'], [], true ) . "\n";

        if ( $img ) {
            // Omit width/height entirely when unverifiable — "0" asserts a
            // falsehood; absence asserts nothing.
            $dims = ( (int) $img['width'] > 0 && (int) $img['height'] > 0 )
                ? ' width="' . (int) $img['width'] . '" height="' . (int) $img['height'] . '"'
                : '';
            $out .= '      <media:thumbnail url="' . mmgrf_xml( $img['url'] ) . '"' . $dims . '/>' . "\n";
            $out .= '      <media:content medium="image" url="' . mmgrf_xml( $img['url'] ) . '"' . $dims . ' type="' . mmgrf_xml( $img['type'] ) . '">' . "\n";
            if ( $img['alt'] !== '' ) {
                $out .= '        <media:title type="plain">' . mmgrf_xml( $img['alt'] ) . "</media:title>\n";
            }
            if ( $img['caption'] !== '' ) {
                $out .= '        <media:description type="plain">' . mmgrf_xml( $img['caption'] ) . "</media:description>\n";
            }
            $out .= "      </media:content>\n";
            $out .= '      <enclosure url="' . mmgrf_xml( $img['url'] ) . '" length="' . (int) ( $img['filesize'] ?: 100000 ) . '" type="' . mmgrf_xml( $img['type'] ) . '"/>' . "\n";
        }

        $out .= '      <content:encoded>' . mmgrf_cdata( $this->build_content( $item ) ) . "</content:encoded>\n";
        if ( $mod = $this->modified_el( $item ) ) {
            $out .= '      ' . $mod . "\n";
        }
        $out .= "    </item>\n";
        return $out;
    }

    /** Figure-prepended featured image (live-plugin behavior) + sanitized body. */
    private function build_content( $item ) {
        $body = $item['san']['html'];
        $img  = $item['image'];
        if ( ! $img ) {
            return $body;
        }
        // Skip the prepend when the body already leads with this exact image.
        if ( ( $item['san']['first_image_src'] ?? null ) === $img['url'] ) {
            return $body;
        }
        $alt    = $img['alt'] !== '' ? $img['alt'] : $item['title'];
        $credit = $img['credit'] !== '' ? $img['credit'] : $img['caption'];

        $figure = '<figure><img src="' . mmgrf_xml( $img['url'] ) . '" alt="' . mmgrf_xml( $alt ) . '"/>';
        if ( $credit !== '' ) {
            $figure .= '<figcaption>' . mmgrf_xml( $credit ) . '</figcaption>';
        }
        $figure .= '</figure>';
        return $figure . "\n" . $body;
    }
}
