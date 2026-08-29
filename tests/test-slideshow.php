<?php
// Slideshow feeds (Yahoo + MSN): a post marked with the slideshow tag, whose
// H2-followed-by-image sections become slides. Cover = post title + featured.

function slides_attachment( $att_id, $base, $w = 1920, $h = 1080 ) {
    mmgrf_test_add_attachment( $att_id, [
        'full'  => [ "$base.jpg", $w, $h ],
        'large' => [ "$base-1024x683.jpg", 1024, 683 ],
    ], 'image/jpeg', [ 'alt' => "Alt $att_id", 'caption' => "Credit $att_id" ] );
    $GLOBALS['mmgrf_test']['url_to_attachment'][ "$base.jpg" ] = $att_id;
    return $att_id;
}

function slideshow_post( $sections = 5, $over = [] ) {
    $body = '<p>Intro paragraph with enough words to serve as the description.</p>';
    for ( $i = 1; $i <= $sections; $i++ ) {
        slides_attachment( 7000 + $i, "https://example-brand.com/up/slide$i" );
        $body .= "<h2>Slide Title Number $i</h2>";
        $body .= '<img src="https://example-brand.com/up/slide' . $i . '.jpg" alt="Alt ' . ( 7000 + $i ) . '">';
        $body .= "<p>Detail text for slide $i with several words.</p>";
    }
    $id = mmgrf_test_add_post( array_merge( [
        'post_title'    => 'Ten Best Golf Bags Of The Twenty Twenty Six Season',
        'post_content'  => $body,
        'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
    ], $over ) );
    slides_attachment( 6999, 'https://example-brand.com/up/cover', 2560, 1440 );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 6999;
    mmgrf_test_set_terms( $id, 'post_tag', [ 'Slideshow' ] );
    return $id;
}

t( 'slideshow: parser extracts H2+image sections with titles, text, images', function() {
    $id = slideshow_post( 3 );
    $content = get_post( $id )->post_content;
    $parsed  = mmgrf_parse_slideshow( $content );
    expect_eq( count( $parsed['slides'] ), 3 );
    expect_eq( $parsed['slides'][0]['title'], 'Slide Title Number 1' );
    expect_contains( $parsed['slides'][1]['text'], 'Detail text for slide 2' );
    expect_eq( $parsed['slides'][2]['image']['url'], 'https://example-brand.com/up/slide3.jpg' );
    expect_contains( $parsed['intro'], 'Intro paragraph' );
} );

t( 'slideshow: section without an image is dropped from slides with a warn event', function() {
    slides_attachment( 7101, 'https://example-brand.com/up/a' );
    $content = '<h2>Has Image</h2><img src="https://example-brand.com/up/a.jpg"><p>t</p><h2>No Image Here</h2><p>text only section</p>';
    $parsed = mmgrf_parse_slideshow( $content );
    expect_eq( count( $parsed['slides'] ), 1 );
    $codes = array_column( $parsed['events'], 'code' );
    expect_true( in_array( 'slide_missing_image', $codes, true ) );
} );

t( 'slideshow: yahoo feed renders spec shape — one thumbnail, media:content per slide', function() {
    slideshow_post( 5 );
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_slideshow_feed( 'yahoo', mmgrf_slideshow_options( 'yahoo' ) );
    expect_true( simplexml_load_string( $xml ) !== false, 'well-formed' );
    expect_contains( $xml, 'xmlns:media="http://search.yahoo.com/mrss/"', 'slideshow spec uses the mrss namespace' );
    expect_eq( substr_count( $xml, '<media:thumbnail' ), 1, 'exactly one thumbnail' );
    expect_contains( $xml, 'cover.jpg' );
    expect_eq( substr_count( $xml, '<media:content' ), 5, 'one per slide' );
    expect_contains( $xml, '<media:title>Slide Title Number 1</media:title>' );
    expect_contains( $xml, '<media:credit>Credit 7001</media:credit>' );
    expect_match( $xml, '/<media:text>Alt \d+<\/media:text>/' );
    expect_eq( substr_count( $xml, '<category>' ), 1, 'one category per slideshow' );
} );

t( 'slideshow: sub-floor slide image falls back through the ladder or is dropped', function() {
    $id = slideshow_post( 4 );
    // slide 2's original shrinks under Yahoo's floor with no bigger rendition
    $GLOBALS['mmgrf_test']['attachments'][7002]['sizes'] = [ 'full' => [ 'https://example-brand.com/up/slide2.jpg', 900, 500 ] ];
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_slideshow_feed( 'yahoo', mmgrf_slideshow_options( 'yahoo' ) );
    expect_eq( substr_count( $xml, '<media:content' ), 3, 'non-compliant slide dropped' );
    expect_not_contains( $xml, 'slide2.jpg' );
    $codes = array_column( mmgrf_skip_log_get(), 'code' );
    expect_true( in_array( 'slide_below_minimum', $codes, true ) );
} );

t( 'slideshow: msn requires four surviving slides; three-slide show skipped and logged', function() {
    slideshow_post( 3 );
    update_option( 'mmgrf_networks', [ 'msn' => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_slideshow_feed( 'msn', mmgrf_slideshow_options( 'msn' ) );
    expect_eq( substr_count( $xml, '<item>' ), 0 );
    expect_eq( mmgrf_skip_log_get()[0]['code'], 'slideshow_too_few_slides' );

    mmgrf_test_reset();
    $id = slideshow_post( 4 );
    update_option( 'mmgrf_networks', [ 'msn' => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_slideshow_feed( 'msn', mmgrf_slideshow_options( 'msn' ) );
    expect_eq( substr_count( $xml, '<item>' ), 1, 'four slides ships' );
    expect_contains( $xml, 'xmlns:mi=', 'msn namespace present' );
} );

t( 'slideshow: msn shape mirrors the live MSN-accepted gallery feed', function() {
    $id = slideshow_post( 4 );
    mmgrf_test_set_terms( $id, 'post_tag', [ 'golf gear', 'stand bags' ] );
    update_option( 'mmgrf_networks', [ 'msn' => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_slideshow_feed( 'msn', mmgrf_slideshow_options( 'msn' ) );
    expect_contains( $xml, '<guid isPermaLink="false">' . $id . '</guid>', 'durable numeric guid per live shape' );
    expect_eq( substr_count( $xml, '<mi:hasSyndicationRights>1</mi:hasSyndicationRights>' ), 4, 'rights element per slide' );
    expect_contains( $xml, '<media:keywords>' );
    expect_contains( $xml, 'golf gear' );
    expect_match( $xml, '/<media:description><!\[CDATA\[\s*<p>Detail text for slide 1/', 'HTML slide descriptions in CDATA' );
    expect_true( simplexml_load_string( $xml ) !== false, 'well-formed' );
} );

t( 'slideshow: msn drops a rights-flagged slide with a warn', function() {
    $id = slideshow_post( 5 );
    update_post_meta( 7002, '_mmgrf_no_syndication_rights', '1' ); // slide 2 attachment flagged
    update_option( 'mmgrf_networks', [ 'msn' => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_slideshow_feed( 'msn', mmgrf_slideshow_options( 'msn' ) );
    expect_eq( substr_count( $xml, '<media:content' ), 4, 'flagged slide dropped' );
    expect_not_contains( $xml, 'slide2.jpg' );
    $codes = array_column( mmgrf_skip_log_get(), 'code' );
    expect_true( in_array( 'no_syndication_rights', $codes, true ) );
    // Yahoo has no rights concept in its spec — slide ships there.
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    $y = mmgrf_render_slideshow_feed( 'yahoo', mmgrf_slideshow_options( 'yahoo' ) );
    expect_contains( $y, 'slide2.jpg' );
} );

t( 'slideshow: slideshow-tagged posts excluded from article feeds; only they enter slideshow feeds', function() {
    slideshow_post( 5 );
    $plain = mmgrf_test_add_post( [
        'post_title'    => 'A Regular Article Comfortably Above Twenty Characters',
        'post_content'  => '<p>' . implode( ' ', array_fill( 0, 200, 'word' ) ) . '</p>',
        'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
    ] );
    slides_attachment( 7300, 'https://example-brand.com/up/plainlead' );
    $GLOBALS['mmgrf_test']['posts'][ $plain ]->thumbnail_id = 7300;

    $article_xml = mmgrf_render_feed( mmgrf_get_profile( 'yahoo' ), mmgrf_network_options( 'yahoo' ), [] );
    expect_not_contains( $article_xml, 'Ten Best Golf Bags', 'slideshow post kept out of article feed' );
    expect_contains( $article_xml, 'A Regular Article' );

    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    $show_xml = mmgrf_render_slideshow_feed( 'yahoo', mmgrf_slideshow_options( 'yahoo' ) );
    expect_contains( $show_xml, 'Ten Best Golf Bags' );
    expect_not_contains( $show_xml, 'A Regular Article', 'plain article kept out of slideshow feed' );
} );

t( 'slideshow: routes register when enabled', function() {
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ], 'msn' => [ 'enabled' => 1 ] ] );
    mmgrf_register_all_feeds();
    $feeds = array_keys( $GLOBALS['mmgrf_test']['feeds'] );
    expect_true( in_array( 'yahoo-slideshows', $feeds, true ) );
    expect_true( in_array( 'msn-slideshows', $feeds, true ) );
} );
