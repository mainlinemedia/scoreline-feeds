<?php
// Hardening round 2 (2026-08-29): remaining approved debts + new audit items.

function r2_post( $over = [], $sizes = null ) {
    $id = mmgrf_test_add_post( array_merge( [
        'post_title'    => 'A Headline Comfortably Above Twenty Characters Long',
        'post_content'  => '<p>' . implode( ' ', array_fill( 0, 200, 'word' ) ) . '</p>',
        'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
    ], $over ) );
    $att = 8800 + $id;
    mmgrf_test_add_attachment( $att, $sizes ?: [
        'large' => [ 'https://example-brand.com/up/w-1024x683.jpg', 1024, 683 ],
        'full'  => [ 'https://example-brand.com/up/w-scaled.jpg', 2560, 1707 ],
    ], 'image/jpeg', [ 'alt' => 'Alt text', 'caption' => 'Imagn Images' ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = $att;
    return $id;
}

// 4. Version diagnosis from the feed itself
t( 'r2: every profile channel carries a generator tag with the plugin version', function() {
    r2_post();
    foreach ( [ 'yahoo', 'newsbreak', 'msn', 'aigeon' ] as $network ) {
        $xml = mmgrf_render_feed( mmgrf_get_profile( $network ), mmgrf_network_options( $network ), [] );
        expect_contains( $xml, '<generator>Scoreline Feeds ' . MMGRF_VERSION . '</generator>', $network );
    }
} );

// 1. Metadata-sourced dimensions (the content_width/696 lesson, ported)
t( 'r2: reported dims constrained by content_width are corrected from metadata', function() {
    mmgrf_test_add_attachment( 8901, [
        'large' => [ 'https://x.test/u/pic-1024x683.jpg', 1024, 683 ],
        'full'  => [ 'https://x.test/u/pic.jpg', 1600, 1067 ],
    ] );
    $GLOBALS['mmgrf_test']['attachments'][8901]['reported'] = [ 'large' => [ 696, 464 ] ];
    $data = mmgrf_attachment_image_data( 8901, 'large' );
    expect_eq( $data['width'], 1024, 'true rendition width from metadata' );
    expect_eq( $data['height'], 683 );
} );

// 2. NewsBreak full-size images
t( 'r2: newsbreak ships the full rendition, with true dimensions', function() {
    r2_post();
    $xml = mmgrf_render_feed( mmgrf_get_profile( 'newsbreak' ), mmgrf_network_options( 'newsbreak' ), [] );
    expect_contains( $xml, 'w-scaled.jpg' );
    expect_not_contains( $xml, 'w-1024x683.jpg' );
    expect_contains( $xml, 'width="2560"' );
} );

// 3. Pruning extended beyond Yahoo
t( 'r2: newsbreak and msn prune trailing empty paragraphs', function() {
    r2_post( [ 'post_content' => '<p>' . implode( ' ', array_fill( 0, 60, 'w' ) ) . '</p><p><br></p>' ] );
    foreach ( [ 'newsbreak', 'msn' ] as $network ) {
        $xml = mmgrf_render_feed( mmgrf_get_profile( $network ), mmgrf_network_options( $network ), [] );
        expect_not_contains( $xml, '<p><br></p>', $network );
    }
} );

// 5. Skip-log refresh throttling
t( 'r2: dedupe refresh within the hour does not rewrite the entry', function() {
    mmgrf_skip_log_add( 'yahoo', 4, 'P', 'image_below_minimum', '1099x735' );
    $ts1 = mmgrf_skip_log_get()[0]['ts'];
    $GLOBALS['mmgrf_test']['options'][ MMGRF_SKIP_LOG_OPTION ][0]['ts'] = $ts1 - 120; // 2 min old
    mmgrf_skip_log_add( 'yahoo', 4, 'P', 'image_below_minimum', '1099x735' );
    expect_eq( mmgrf_skip_log_get()[0]['ts'], $ts1 - 120, 'recent entry untouched' );

    $GLOBALS['mmgrf_test']['options'][ MMGRF_SKIP_LOG_OPTION ][0]['ts'] = $ts1 - 7200; // 2 h old
    mmgrf_skip_log_add( 'yahoo', 4, 'P', 'image_below_minimum', '1099x735' );
    expect_true( mmgrf_skip_log_get()[0]['ts'] >= $ts1, 'stale entry refreshed' );
    expect_eq( count( mmgrf_skip_log_get() ), 1 );
} );

// 6. Conditional GET decision
t( 'r2: not-modified decision honors If-Modified-Since against lastpostmodified', function() {
    $lastmod = strtotime( '2026-08-29 10:00:00 UTC' );
    expect_true( mmgrf_feed_not_modified( gmdate( 'D, d M Y H:i:s', $lastmod ) . ' GMT', $lastmod ), 'same time → 304' );
    expect_true( mmgrf_feed_not_modified( gmdate( 'D, d M Y H:i:s', $lastmod + 60 ) . ' GMT', $lastmod ), 'client newer → 304' );
    expect_false( mmgrf_feed_not_modified( gmdate( 'D, d M Y H:i:s', $lastmod - 60 ) . ' GMT', $lastmod ), 'client older → full body' );
    expect_false( mmgrf_feed_not_modified( '', $lastmod ), 'no header → full body' );
    expect_false( mmgrf_feed_not_modified( 'not a date', $lastmod ), 'garbage header → full body' );
} );

// 9. Welcome-kit finding: iframe/embed sources must be HTTPS

t( 'r2: http iframe and embed sources upgraded to https (yahoo and msn)', function() {
    $y = mmgrf_sanitize( '<iframe src="http://www.youtube.com/embed/x"></iframe><embed src="http://a.com/x.swf" type="application/x">', mmgrf_get_profile( 'yahoo' )->get_sanitizer_rules() );
    expect_contains( $y['html'], 'src="https://www.youtube.com/embed/x"' );
    expect_contains( $y['html'], 'src="https://a.com/x.swf"' );
    expect_not_contains( $y['html'], 'http://' );

    $m = mmgrf_sanitize( '<iframe src="http://www.tiktok.com/embed/v2/1"></iframe>', mmgrf_get_profile( 'msn' )->get_sanitizer_rules() );
    expect_contains( $m['html'], 'src="https://www.tiktok.com/embed/v2/1"' );
} );

// 8. GitHub self-updates (native WP Update URI mechanism)

function r2_manifest( $body ) {
    $GLOBALS['mmgrf_test']['remote'][ MMGRF_UPDATE_MANIFEST ] = [ 'body' => $body ];
    delete_transient( 'mmgrf_update_manifest' );
}

t( 'updater: newer manifest version yields an update object with a github package', function() {
    r2_manifest( json_encode( [ 'version' => '99.0.0', 'package' => 'https://github.com/mainlinemedia/scoreline-feeds/releases/download/v99.0.0/scoreline-feeds-99.0.0.zip' ] ) );
    $u = mmgrf_github_update_check( false, [], 'scoreline-feeds/scoreline-feeds.php' );
    expect_true( is_array( $u ), 'update offered' );
    expect_eq( $u['version'], '99.0.0' );
    expect_contains( $u['package'], 'github.com/mainlinemedia/scoreline-feeds/releases' );
    expect_eq( $u['plugin'], 'scoreline-feeds/scoreline-feeds.php' );
} );

t( 'updater: current or older manifest version yields no update', function() {
    r2_manifest( json_encode( [ 'version' => MMGRF_VERSION, 'package' => 'https://github.com/mainlinemedia/scoreline-feeds/releases/download/x/x.zip' ] ) );
    expect_false( is_array( mmgrf_github_update_check( false, [], 'scoreline-feeds/scoreline-feeds.php' ) ) );
} );

t( 'updater: package on a foreign host is rejected outright', function() {
    r2_manifest( json_encode( [ 'version' => '99.0.0', 'package' => 'https://evil.example.com/payload.zip' ] ) );
    expect_false( is_array( mmgrf_github_update_check( false, [], 'scoreline-feeds/scoreline-feeds.php' ) ), 'foreign package refused' );
} );

t( 'updater: malformed manifest and missing fixture are ignored quietly', function() {
    r2_manifest( '{not json' );
    expect_false( is_array( mmgrf_github_update_check( false, [], 'scoreline-feeds/scoreline-feeds.php' ) ) );
    unset( $GLOBALS['mmgrf_test']['remote'][ MMGRF_UPDATE_MANIFEST ] );
    delete_transient( 'mmgrf_update_manifest' );
    expect_false( is_array( mmgrf_github_update_check( false, [], 'scoreline-feeds/scoreline-feeds.php' ) ) );
} );

t( 'updater: other plugins on the same host filter pass through untouched', function() {
    r2_manifest( json_encode( [ 'version' => '99.0.0', 'package' => 'https://github.com/mainlinemedia/scoreline-feeds/releases/download/x/x.zip' ] ) );
    $passthrough = [ 'existing' => 'value' ];
    expect_eq( mmgrf_github_update_check( $passthrough, [], 'some-other/plugin.php' ), $passthrough );
} );

// 7. attachment_url_to_postid memo
t( 'r2: url-to-attachment lookups are memoized per request', function() {
    $GLOBALS['mmgrf_test']['url_to_attachment']['https://x.test/u/a.jpg'] = 42;
    expect_eq( mmgrf_url_to_attachment( 'https://x.test/u/a.jpg' ), 42 );
    unset( $GLOBALS['mmgrf_test']['url_to_attachment']['https://x.test/u/a.jpg'] );
    expect_eq( mmgrf_url_to_attachment( 'https://x.test/u/a.jpg' ), 42, 'served from memo, no second lookup' );
} );
