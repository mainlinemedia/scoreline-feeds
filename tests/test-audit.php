<?php
// Deep-audit regression tests (A1–A8). Each reproduces a defect found in the
// 2026-08-05 world-class audit pass.

t( 'A1: global $post is set up per item during the_content filtering', function() {
    $a = mmgrf_test_add_post( [ 'post_content' => '<p>alpha body</p>', 'post_date_gmt' => '2026-08-01 10:00:00' ] );
    $b = mmgrf_test_add_post( [ 'post_content' => '<p>beta body</p>', 'post_date_gmt' => '2026-08-01 11:00:00' ] );
    // Simulates a shortcode/block filter that depends on the global post.
    add_filter( 'the_content', function( $c ) {
        $pid = isset( $GLOBALS['post'] ) && $GLOBALS['post'] ? $GLOBALS['post']->ID : 'none';
        return $c . '<p>PID:' . $pid . '</p>';
    } );
    $items = mmgrf_build_items( mmgrf_get_profile( 'aigeon' ), mmgrf_network_options( 'aigeon' ), [] );
    expect_contains( $items[0]['content'], 'PID:' . $b, 'newest item filtered with its own global post' );
    expect_contains( $items[1]['content'], 'PID:' . $a, 'older item filtered with its own global post' );
    expect_true( empty( $GLOBALS['post'] ), 'postdata reset after the loop' );
} );

t( 'A2: skip log dedupes repeated identical skips, refreshing the timestamp', function() {
    for ( $i = 0; $i < 50; $i++ ) {
        mmgrf_skip_log_add( 'yahoo', 7, 'Same Post', 'body word count 8 under Yahoo 150-word floor (post-sanitization)' );
    }
    mmgrf_skip_log_add( 'yahoo', 7, 'Same Post', 'different reason' );
    $log = mmgrf_skip_log_get();
    expect_eq( count( $log ), 2, 'one entry per (network, post, reason)' );
} );

t( 'A3: aigeon honors the saved image_size setting (v2 parity)', function() {
    update_option( 'mmgrf_options', [ 'image_size' => 'medium' ] );
    expect_eq( mmgrf_network_options( 'aigeon' )['image_size'], 'medium' );
} );

t( 'A4: network feeds are served from a 60s cache that a new publish busts', function() {
    $id = mmgrf_test_add_post( [ 'post_content' => '<p>' . implode( ' ', array_fill( 0, 30, 'w' ) ) . '</p>' ] );
    mmgrf_test_add_attachment( 9300, [ 'large' => [ 'https://example-brand.com/a.jpg', 1024, 576 ] ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9300;

    $first = mmgrf_get_feed_output( 'newsbreak' );
    // Mutate the post directly WITHOUT touching modified date — a cached feed won't see it.
    $GLOBALS['mmgrf_test']['posts'][ $id ]->post_title = 'Changed Behind The Cache';
    $second = mmgrf_get_feed_output( 'newsbreak' );
    expect_eq( $second, $first, 'served from cache' );

    // A new publish changes lastpostmodified → cache key rotates → fresh render.
    $new = mmgrf_test_add_post( [ 'post_date_gmt' => gmdate( 'Y-m-d H:i:s' ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + 10 ) ] );
    mmgrf_test_add_attachment( 9301, [ 'large' => [ 'https://example-brand.com/b.jpg', 1024, 576 ] ] );
    $GLOBALS['mmgrf_test']['posts'][ $new ]->thumbnail_id = 9301;
    $third = mmgrf_get_feed_output( 'newsbreak' );
    expect_contains( $third, 'Changed Behind The Cache', 'publish busts the cache' );

    // Legacy aigeon feed stays uncached (current production behavior).
    $a1 = mmgrf_get_feed_output( 'aigeon' );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->post_title = 'Aigeon Sees This Instantly';
    $a2 = mmgrf_get_feed_output( 'aigeon' );
    expect_contains( $a2, 'Aigeon Sees This Instantly', 'aigeon uncached' );
} );

t( 'A5: msn allows Google Maps embeds but not other google.com iframes', function() {
    $p = mmgrf_get_profile( 'msn' );
    $r = mmgrf_sanitize(
        '<iframe src="https://www.google.com/maps/embed?pb=xyz"></iframe><iframe src="https://calendar.google.com/calendar/embed?src=x"></iframe><p>t</p>',
        $p->get_sanitizer_rules()
    );
    expect_contains( $r['html'], 'google.com/maps/embed' );
    expect_not_contains( $r['html'], 'calendar.google.com' );
} );

t( 'A6: inline image fallback skips data: URI placeholders', function() {
    $content = '<img src="data:image/gif;base64,R0lGODlhAQABAAAAACw="><img src="https://example-brand.com/real.jpg"><p>' . implode( ' ', array_fill( 0, 30, 'w' ) ) . '</p>';
    $id = mmgrf_test_add_post( [ 'post_content' => $content ], );
    $items = mmgrf_build_items( mmgrf_get_profile( 'newsbreak' ), mmgrf_network_options( 'newsbreak' ), [] );
    expect_eq( $items[0]['image']['url'], 'https://example-brand.com/real.jpg' );
    // and when ONLY a data: URI exists, no image at all
    mmgrf_test_reset();
    mmgrf_test_add_post( [ 'post_content' => '<img src="data:image/gif;base64,AAAA"><p>text</p>' ] );
    $items = mmgrf_build_items( mmgrf_get_profile( 'newsbreak' ), mmgrf_network_options( 'newsbreak' ), [] );
    expect_eq( $items[0]['image'], null );
} );

t( 'A7: derived excerpt uses a literal ellipsis, not the &hellip; entity', function() {
    $id = mmgrf_test_add_post( [ 'post_content' => '<p>' . implode( ' ', array_fill( 0, 80, 'word' ) ) . '</p>' ] );
    $items = mmgrf_build_items( mmgrf_get_profile( 'newsbreak' ), mmgrf_network_options( 'newsbreak' ), [] );
    expect_not_contains( $items[0]['excerpt'], '&hellip;' );
    expect_contains( $items[0]['excerpt'], '…' );
} );

t( 'A9: html entities in stored titles/excerpts are decoded to characters', function() {
    // Live-feed finding (leadlapracing.com): DB titles contain literal &#8217;
    // entities; inside CDATA those render as visible text at the partner.
    $id = mmgrf_test_add_post( [
        'post_title'   => 'Yamaha&#8217;s &#8216;Unforgettable&#8217; Season &amp; Beyond',
        'post_excerpt' => 'The team&#8217;s review &amp; outlook',
        'post_content' => '<p>' . implode( ' ', array_fill( 0, 30, 'w' ) ) . '</p>',
    ] );
    $items = mmgrf_build_items( mmgrf_get_profile( 'newsbreak' ), mmgrf_network_options( 'newsbreak' ), [] );
    expect_eq( $items[0]['title'], 'Yamaha’s ‘Unforgettable’ Season & Beyond' );
    expect_eq( $items[0]['excerpt'], 'The team’s review & outlook' );
} );

t( 'A10: yahoo logs a warning when it drops an under-floor image (live finding)', function() {
    // leadlapracing/gamedayatlanta wire recaps: 1099x735 featured images are
    // withheld per Yahoo's 1280x720 floor — must be visible in the skip log.
    $id = mmgrf_test_add_post( [
        'post_title'    => 'Wire Recap With An Undersized Featured Image',
        'post_content'  => '<p>' . implode( ' ', array_fill( 0, 200, 'word' ) ) . '</p>',
        'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
    ] );
    mmgrf_test_add_attachment( 9400, [ 'full' => [ 'https://example-brand.com/wire.jpg', 1099, 735 ] ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9400;

    $xml = mmgrf_render_feed( mmgrf_get_profile( 'yahoo' ), mmgrf_network_options( 'yahoo' ), [] );
    expect_eq( substr_count( $xml, '<item>' ), 1, 'item still ships' );
    expect_not_contains( $xml, 'wire.jpg', 'undersized image withheld' );
    $log = mmgrf_skip_log_get();
    expect_eq( count( $log ), 1 );
    expect_eq( $log[0]['level'], 'warn' );
    expect_eq( $log[0]['code'], 'image_below_minimum' );
    expect_contains( $log[0]['detail'], '1099x735' );
    expect_contains( $log[0]['detail'], '1280x720' );
} );

t( 'A11: yahoo falls back to the 2048 rendition when the full image is over 5MB (live finding)', function() {
    // golferinsight: raw 8K wire uploads (8092x5395, >5MB) — Yahoo dropped the
    // image entirely even though WP has a compliant 2048px rendition.
    $id = mmgrf_test_add_post( [
        'post_title'    => 'Wire Story With A Raw 8K Featured Image Upload',
        'post_content'  => '<p>' . implode( ' ', array_fill( 0, 200, 'word' ) ) . '</p>',
        'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
    ] );
    $dir = sys_get_temp_dir() . '/mmgrf-a11-' . getmypid() . '-' . mt_rand();
    mkdir( $dir );
    foreach ( [ 'raw-8k.jpg', 'raw-8k-2048.jpg', 'raw-8k-1024.jpg' ] as $f ) {
        file_put_contents( "$dir/$f", 'x' );
    }
    mmgrf_test_add_attachment( 9500, [
        'full'      => [ 'https://example-brand.com/raw-8k.jpg', 8092, 5395 ],
        '2048x2048' => [ 'https://example-brand.com/raw-8k-2048.jpg', 2048, 1365 ],
        'large'     => [ 'https://example-brand.com/raw-8k-1024.jpg', 1024, 683 ],
    ], 'image/jpeg', [ 'file' => "$dir/raw-8k.jpg" ] );
    // Simulate a >5MB original without a real 5MB fixture file.
    $GLOBALS['mmgrf_test']['attachments'][9500]['fake_filesize'] = 9000000;
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9500;

    $xml = mmgrf_render_feed( mmgrf_get_profile( 'yahoo' ), mmgrf_network_options( 'yahoo' ), [] );
    expect_contains( $xml, 'raw-8k-2048.jpg', 'compliant 2048 rendition used' );
    expect_not_contains( $xml, 'raw-8k.jpg"', 'oversized original not used' );
    expect_eq( count( mmgrf_skip_log_get() ), 0, 'no warn — a compliant image shipped' );
} );

t( 'A12: msn respects its 2MB image limit via the same rendition fallback', function() {
    $id = mmgrf_test_add_post( [
        'post_title'    => 'A Headline Long Enough To Clear The Twenty Character Floor',
        'post_content'  => '<p>body words here</p>',
        'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
    ] );
    $dir = sys_get_temp_dir() . '/mmgrf-a12-' . getmypid() . '-' . mt_rand();
    mkdir( $dir );
    foreach ( [ 'big.jpg', 'big-2048.jpg' ] as $f ) {
        file_put_contents( "$dir/$f", 'x' );
    }
    mmgrf_test_add_attachment( 9501, [
        'full'      => [ 'https://example-brand.com/big.jpg', 8000, 5000 ],
        '2048x2048' => [ 'https://example-brand.com/big-2048.jpg', 2048, 1280 ],
    ], 'image/jpeg', [ 'file' => "$dir/big.jpg" ] );
    $GLOBALS['mmgrf_test']['attachments'][9501]['fake_filesize'] = 9000000;
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9501;

    $xml = mmgrf_render_feed( mmgrf_get_profile( 'msn' ), mmgrf_network_options( 'msn' ), [] );
    expect_eq( substr_count( $xml, '<item>' ), 1, 'item ships' );
    expect_contains( $xml, 'big-2048.jpg' );
    expect_not_contains( $xml, 'big.jpg"' );
} );

// A13-15: live finding (golferinsight → Yahoo "Image didn't download"): a
// media-library edit created a new -e{ts} original whose file is missing on
// disk; the feed advertised the dead URL and Yahoo's fetch 404'd.

function a13_fixture( $create_original, $create_candidate ) {
    $dir = sys_get_temp_dir() . '/mmgrf-img-' . getmypid() . '-' . mt_rand();
    mkdir( $dir );
    if ( $create_original ) { file_put_contents( "$dir/wire-e123.jpg", 'x' ); }
    if ( $create_candidate ) { file_put_contents( "$dir/wire-e123-2048x1365.jpg", 'x' ); }
    $id = mmgrf_test_add_post( [
        'post_title'    => 'A Headline Comfortably Above Twenty Characters Long',
        'post_content'  => '<p>' . implode( ' ', array_fill( 0, 200, 'word' ) ) . '</p>',
        'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
    ] );
    mmgrf_test_add_attachment( 9980, [
        'full'      => [ 'https://example-brand.com/up/wire-e123.jpg', 2560, 1707 ],
        '2048x2048' => [ 'https://example-brand.com/up/wire-e123-2048x1365.jpg', 2048, 1365 ],
    ], 'image/jpeg', [ 'file' => "$dir/wire-e123.jpg" ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9980;
    return $id;
}

t( 'A13: missing original promotes to an existing rendition file, with a warn', function() {
    a13_fixture( false, true ); // original gone, 2048 rendition file present
    $xml = mmgrf_render_feed( mmgrf_get_profile( 'yahoo' ), mmgrf_network_options( 'yahoo' ), [] );
    expect_contains( $xml, 'wire-e123-2048x1365.jpg', 'existing rendition shipped' );
    expect_not_contains( $xml, 'up/wire-e123.jpg"', 'dead original URL not advertised' );
    $log = mmgrf_skip_log_get();
    expect_eq( $log[0]['code'], 'image_file_missing' );
    expect_eq( $log[0]['level'], 'warn' );
} );

t( 'A14: all files missing drops the image with image_file_missing; item ships on yahoo', function() {
    a13_fixture( false, false );
    $xml = mmgrf_render_feed( mmgrf_get_profile( 'yahoo' ), mmgrf_network_options( 'yahoo' ), [] );
    expect_eq( substr_count( $xml, '<item>' ), 1, 'item ships imageless' );
    expect_not_contains( $xml, 'wire-e123' );
    $codes = array_column( mmgrf_skip_log_get(), 'code' );
    expect_true( in_array( 'image_file_missing', $codes, true ) );
} );

t( 'A15: offloaded media (no local dir) skips the existence check entirely', function() {
    $id = mmgrf_test_add_post( [
        'post_title'    => 'A Headline Comfortably Above Twenty Characters Long',
        'post_content'  => '<p>' . implode( ' ', array_fill( 0, 200, 'word' ) ) . '</p>',
        'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
    ] );
    mmgrf_test_add_attachment( 9981, [ 'full' => [ 'https://cdn.offloaded.com/wire.jpg', 2560, 1707 ] ], 'image/jpeg', [ 'file' => '/no/such/dir/at/all/wire.jpg' ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9981;
    $xml = mmgrf_render_feed( mmgrf_get_profile( 'yahoo' ), mmgrf_network_options( 'yahoo' ), [] );
    expect_contains( $xml, 'cdn.offloaded.com/wire.jpg', 'offloaded image ships untouched' );
    expect_eq( count( mmgrf_skip_log_get() ), 0 );
} );

t( 'A8: yahoo keeps src/type on allowed embed elements', function() {
    $p = mmgrf_get_profile( 'yahoo' );
    $r = mmgrf_sanitize( '<embed src="https://a.com/x.swf" type="application/x-shockwave-flash" onload="x()">', $p->get_sanitizer_rules() );
    expect_contains( $r['html'], 'src="https://a.com/x.swf"' );
    expect_contains( $r['html'], 'type=' );
    expect_not_contains( $r['html'], 'onload' );
} );
