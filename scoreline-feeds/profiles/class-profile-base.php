<?php
/**
 * Layer 2 — profile base. Each network profile owns: namespace block, item
 * element shape, HTML sanitizer ruleset, validation gate, image policy.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Modified must exceed published by this much before an update element is emitted (save-time jitter guard). */
const MMGRF_MODIFIED_JITTER = 60;

abstract class MMGRF_Profile_Base {

    abstract public function get_key();
    abstract public function get_namespaces();
    abstract public function get_sanitizer_rules();
    abstract public function validate_item( $item );
    abstract public function render_item( $item, $opts );

    /**
     * Run the profile's sanitizer over the raw content once; both validate and
     * render read the cached result from $item['san'].
     */
    public function prepare_item( $item, $opts = [] ) {
        $item['opts'] = $opts;
        $item['san']  = mmgrf_sanitize( $item['content'] ?? '', $this->get_sanitizer_rules() );
        return $item;
    }

    public function get_channel_extras( $opts ) { return ''; }
    public function get_image_policy()          { return 'require'; } // 'require' | 'optional'
    public function get_default_image_size()    { return 'large'; }
    public function get_default_post_count()    { return 25; }
    public function get_max_items()             { return 30; }
    public function utm_default()               { return false; }

    /** Default channel opening block (title/atom:link/link/description/lastBuildDate/language). */
    public function get_channel_open( $opts ) {
        $out  = '    ' . mmgrf_el( 'title', $opts['feed_title'] ) . "\n";
        $out .= '    <atom:link href="' . mmgrf_xml( $opts['feed_url'] ) . '" rel="self" type="application/rss+xml"/>' . "\n";
        $out .= '    ' . mmgrf_el( 'link', $opts['feed_link'] ) . "\n";
        $out .= '    ' . mmgrf_el( 'description', $opts['feed_description'] ) . "\n";
        $out .= '    ' . mmgrf_el( 'lastBuildDate', mmgrf_rfc822( time() ) ) . "\n";
        $out .= '    ' . mmgrf_el( 'language', get_bloginfo( 'language' ) ) . "\n";
        return $out;
    }

    /**
     * Pick the largest image rendition satisfying the profile's constraints:
     * the primary (requested size) first, then smaller candidates. Candidate
     * renditions have unknown byte sizes, which is safe — a ≤2048px JPEG is
     * far below any network's byte limit. Returns null when nothing fits.
     */
    protected function pick_image_candidate( $img, $min_w, $min_h, $max_bytes = 0 ) {
        return mmgrf_pick_image_fit( $img, $min_w, $min_h, $max_bytes );
    }

    /** Validation verdicts: stable code + human detail ('reason' kept as the code for compat). */
    protected function fail( $code, $detail = '' ) {
        return [ 'pass' => false, 'code' => $code, 'detail' => $detail, 'reason' => $code ];
    }

    protected function ok() {
        return [ 'pass' => true, 'code' => '', 'detail' => '', 'reason' => '' ];
    }

    protected function is_modified( $item ) {
        return ( $item['mod_ts'] ?? 0 ) > ( $item['pub_ts'] ?? 0 ) + MMGRF_MODIFIED_JITTER;
    }

    /** dcterms:modified element (or '' when not genuinely modified). */
    protected function modified_el( $item ) {
        return $this->is_modified( $item )
            ? mmgrf_el( 'dcterms:modified', gmdate( 'Y-m-d\TH:i:s\Z', $item['mod_ts'] ) )
            : '';
    }
}
