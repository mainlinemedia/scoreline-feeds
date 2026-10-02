<?php
// Video feeds (Yahoo + MSN): a post tagged video whose uploaded video file
// becomes the item. Self-hosted files only — both networks reject YouTube.

function video_attachment( $att_id, $url, $mime = 'video/mp4', $meta = [] ) {
    $GLOBALS['mmgrf_test']['attachments'][ $att_id ] = [
        'sizes' => [], 'mime' => $mime, 'file' => null, 'alt' => '', 'caption' => '',
        'meta'  => array_merge( [ 'length' => 185, 'width' => 1920, 'height' => 1080, 'filesize' => 52000000 ], $meta ),
    ];
    $GLOBALS['mmgrf_test']['url_to_attachment'][ $url ] = $att_id;
    return $att_id;
}

function video_post( $over = [], $with_video = true, $with_thumb = true ) {
    $vurl = 'https://example-brand.com/up/clip.mp4';
    if ( $with_video ) {
        video_attachment( 7500, $vurl );
    }
    $body = '<p>Intro paragraph describing this exclusive interview in several words.</p>';
    if ( $with_video ) {
        $body .= '<figure class="wp-block-video"><video controls src="' . $vurl . '"></video></figure>';
    }
    $id = mmgrf_test_add_post( array_merge( [
        'post_title'    => 'Exclusive Athlete Interview On The Season Ahead',
        'post_content'  => $body,
        'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
    ], $over ) );
    if ( $with_thumb ) {
        mmgrf_test_add_attachment( 7499, [ 'full' => [ 'https://example-brand.com/up/vthumb.jpg', 1920, 1080 ] ], 'image/jpeg', [ 'alt' => 'Interview thumb', 'caption' => 'MMG Original' ] );
        $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 7499;
    }
    mmgrf_test_set_terms( $id, 'post_tag', [ 'Video', 'interviews' ] );
    return $id;
}

t( 'video: resolver finds the uploaded file with duration, dimensions, mime', function() {
    $id = video_post();
    $v = mmgrf_resolve_post_video( $id, get_post( $id )->post_content );
    expect_eq( $v['url'], 'https://example-brand.com/up/clip.mp4' );
    expect_eq( $v['mime'], 'video/mp4' );
    expect_eq( $v['duration'], 185 );
    expect_eq( $v['width'], 1920 );
} );

t( 'video: yahoo feed renders spec shape — video media:content + required thumbnail', function() {
    video_post();
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_video_feed( 'yahoo', mmgrf_video_options( 'yahoo' ) );
    expect_true( simplexml_load_string( $xml ) !== false, 'well-formed' );
    expect_contains( $xml, 'xmlns:media="https://search.yahoo.com/mrss/"', 'video spec declares the https mrss namespace' );
    expect_contains( $xml, 'xmlns:dcterms=' );
    expect_match( $xml, '/<media:content[^>]+url="https:\/\/example-brand\.com\/up\/clip\.mp4"[^>]+type="video\/mp4"[^>]*duration="185"/' );
    expect_contains( $xml, '<media:thumbnail url="https://example-brand.com/up/vthumb.jpg"' );
    expect_eq( substr_count( $xml, '<category>' ), 1, 'single category' );
    expect_contains( $xml, '<media:keywords>' );
    expect_contains( $xml, '<guid isPermaLink="true">' );
} );

t( 'video: tagged post with no video file skipped with video_missing', function() {
    video_post( [], false );
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_video_feed( 'yahoo', mmgrf_video_options( 'yahoo' ) );
    expect_eq( substr_count( $xml, '<item>' ), 0 );
    expect_eq( mmgrf_skip_log_get()[0]['code'], 'video_missing' );
} );

t( 'video: audio-only file skipped with video_format_unsupported (podcasts must be video)', function() {
    $id = video_post( [], false );
    video_attachment( 7600, 'https://example-brand.com/up/episode.mp3', 'audio/mpeg' );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->post_content .= '<video src="https://example-brand.com/up/episode.mp3"></video>';
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_video_feed( 'yahoo', mmgrf_video_options( 'yahoo' ) );
    expect_eq( substr_count( $xml, '<item>' ), 0 );
    expect_eq( mmgrf_skip_log_get()[0]['code'], 'video_format_unsupported' );
} );

t( 'video: missing thumbnail skips the item (Yahoo requires one per video)', function() {
    video_post( [], true, false );
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_video_feed( 'yahoo', mmgrf_video_options( 'yahoo' ) );
    expect_eq( substr_count( $xml, '<item>' ), 0 );
    expect_eq( mmgrf_skip_log_get()[0]['code'], 'no_featured_image' );
} );

t( 'video: msn enforces the 365-day window; dcterms:modified only when modified', function() {
    video_post( [ 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 400 * 86400 ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 400 * 86400 ) ] );
    update_option( 'mmgrf_networks', [ 'msn' => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_video_feed( 'msn', mmgrf_video_options( 'msn' ) );
    expect_eq( substr_count( $xml, '<item>' ), 0 );
    expect_eq( mmgrf_skip_log_get()[0]['code'], 'pubdate_too_old' );

    mmgrf_test_reset();
    video_post();
    update_option( 'mmgrf_networks', [ 'msn' => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_video_feed( 'msn', mmgrf_video_options( 'msn' ) );
    expect_eq( substr_count( $xml, '<item>' ), 1 );
    expect_contains( $xml, 'xmlns:mi=' );
    expect_not_contains( $xml, '<dcterms:modified>', 'unmodified post carries no modified element' );
} );

t( 'video: video-tagged posts excluded from article feeds and vice versa', function() {
    video_post();
    $plain = mmgrf_test_add_post( [
        'post_title'    => 'A Regular Article Comfortably Above Twenty Characters',
        'post_content'  => '<p>' . implode( ' ', array_fill( 0, 200, 'word' ) ) . '</p>',
        'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
    ] );
    mmgrf_test_add_attachment( 7601, [ 'full' => [ 'https://example-brand.com/up/plain.jpg', 1920, 1080 ] ] );
    $GLOBALS['mmgrf_test']['posts'][ $plain ]->thumbnail_id = 7601;

    $article_xml = mmgrf_render_feed( mmgrf_get_profile( 'yahoo' ), mmgrf_network_options( 'yahoo' ), [] );
    expect_not_contains( $article_xml, 'Exclusive Athlete Interview' );
    expect_contains( $article_xml, 'A Regular Article' );

    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    $video_xml = mmgrf_render_video_feed( 'yahoo', mmgrf_video_options( 'yahoo' ) );
    expect_contains( $video_xml, 'Exclusive Athlete Interview' );
    expect_not_contains( $video_xml, 'A Regular Article' );
} );

t( 'video: routes register when networks enabled', function() {
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ], 'msn' => [ 'enabled' => 1 ] ] );
    mmgrf_register_all_feeds();
    $feeds = array_keys( $GLOBALS['mmgrf_test']['feeds'] );
    expect_true( in_array( 'yahoo-videos', $feeds, true ) );
    expect_true( in_array( 'msn-videos', $feeds, true ) );
} );
