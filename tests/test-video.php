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

// ── Audit round (2026-10-02): V1-V5 ──────────────────────────────

t( 'audit-V1: data-src lazy attribute never mistaken for the video source', function() {
    $id = video_post( [], false );
    video_attachment( 7700, 'https://example-brand.com/up/real.mp4' );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->post_content =
        '<video controls data-src="https://cdn.lazy.com/placeholder.mp4" src="https://example-brand.com/up/real.mp4"></video>';
    $v = mmgrf_resolve_post_video( $id, get_post( $id )->post_content );
    expect_eq( $v['url'], 'https://example-brand.com/up/real.mp4' );
    // and when ONLY data-src exists, nothing resolves
    $v2 = mmgrf_resolve_post_video( $id, '<video controls data-src="https://cdn.lazy.com/x.mp4"></video>' );
    expect_eq( $v2, null );
} );

t( 'audit-V2: vertical clip accepts a vertical 720x1280 thumbnail', function() {
    $id = video_post( [], false, false );
    video_attachment( 7701, 'https://example-brand.com/up/short.mp4', 'video/mp4', [ 'width' => 1080, 'height' => 1920 ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->post_content .= '<video src="https://example-brand.com/up/short.mp4"></video>';
    mmgrf_test_add_attachment( 7702, [ 'full' => [ 'https://example-brand.com/up/vert-thumb.jpg', 720, 1280 ] ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 7702;
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_video_feed( 'yahoo', mmgrf_video_options( 'yahoo' ) );
    expect_eq( substr_count( $xml, '<item>' ), 1, 'vertical thumb accepted for vertical video' );
    expect_contains( $xml, 'vert-thumb.jpg' );
} );

t( 'audit-V3: video file missing from local disk withholds the item with video_file_missing', function() {
    $dir = sys_get_temp_dir() . '/mmgrf-vid-' . getmypid() . '-' . mt_rand();
    mkdir( $dir ); // dir exists, file does not
    $id = video_post( [], false );
    video_attachment( 7703, 'https://example-brand.com/up/ghost.mp4' );
    $GLOBALS['mmgrf_test']['attachments'][7703]['file'] = "$dir/ghost.mp4";
    $GLOBALS['mmgrf_test']['posts'][ $id ]->post_content .= '<video src="https://example-brand.com/up/ghost.mp4"></video>';
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_video_feed( 'yahoo', mmgrf_video_options( 'yahoo' ) );
    expect_eq( substr_count( $xml, '<item>' ), 0, 'never advertise a dead video URL' );
    expect_eq( mmgrf_skip_log_get()[0]['code'], 'video_file_missing' );
} );

t( 'audit-V4: unknown duration ships with a warn; disk filesize recovered', function() {
    $dir = sys_get_temp_dir() . '/mmgrf-vid-' . getmypid() . '-' . mt_rand();
    mkdir( $dir );
    file_put_contents( "$dir/clip.mp4", str_repeat( 'x', 4096 ) );
    $id = video_post( [], false );
    video_attachment( 7704, 'https://example-brand.com/up/noduration.mp4', 'video/mp4', [ 'length' => 0, 'filesize' => 0, 'width' => 1920, 'height' => 1080 ] );
    $GLOBALS['mmgrf_test']['attachments'][7704]['file'] = "$dir/clip.mp4";
    $GLOBALS['mmgrf_test']['posts'][ $id ]->post_content .= '<video src="https://example-brand.com/up/noduration.mp4"></video>';
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_video_feed( 'yahoo', mmgrf_video_options( 'yahoo' ) );
    expect_eq( substr_count( $xml, '<item>' ), 1, 'ships without duration' );
    expect_contains( $xml, 'fileSize="4096"', 'filesize recovered from disk' );
    $log = mmgrf_skip_log_get();
    expect_eq( $log[0]['code'], 'video_duration_unknown' );
    expect_eq( $log[0]['level'], 'warn' );
} );

t( 'audit-V5: video poster attribute serves as thumbnail fallback', function() {
    $id = video_post( [], false, false ); // no featured image
    video_attachment( 7705, 'https://example-brand.com/up/pclip.mp4' );
    mmgrf_test_add_attachment( 7706, [ 'full' => [ 'https://example-brand.com/up/poster.jpg', 1920, 1080 ] ] );
    $GLOBALS['mmgrf_test']['url_to_attachment']['https://example-brand.com/up/poster.jpg'] = 7706;
    $GLOBALS['mmgrf_test']['posts'][ $id ]->post_content .=
        '<video controls poster="https://example-brand.com/up/poster.jpg" src="https://example-brand.com/up/pclip.mp4"></video>';
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_video_feed( 'yahoo', mmgrf_video_options( 'yahoo' ) );
    expect_eq( substr_count( $xml, '<item>' ), 1, 'poster rescues the missing featured image' );
    expect_contains( $xml, '<media:thumbnail url="https://example-brand.com/up/poster.jpg"' );
} );

t( 'audit-V7: msn video items carry attribution copyright', function() {
    video_post();
    update_option( 'mmgrf_networks', [ 'msn' => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_video_feed( 'msn', mmgrf_video_options( 'msn' ) );
    expect_contains( $xml, '<media:copyright>' );
} );

t( 'video: routes register when networks enabled', function() {
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ], 'msn' => [ 'enabled' => 1 ] ] );
    mmgrf_register_all_feeds();
    $feeds = array_keys( $GLOBALS['mmgrf_test']['feeds'] );
    expect_true( in_array( 'yahoo-videos', $feeds, true ) );
    expect_true( in_array( 'msn-videos', $feeds, true ) );
} );
