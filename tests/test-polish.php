<?php
// Regressions found by inspecting sample output.

t( 'excerpt derivation does not merge words across block boundaries', function() {
    $id = mmgrf_test_add_post( [
        'post_content' => '<p>First sentence ends here.</p><p>Second starts now.</p>',
        'post_excerpt' => '',
    ] );
    $items = mmgrf_build_items( mmgrf_get_profile( 'newsbreak' ), mmgrf_network_options( 'newsbreak' ), [] );
    expect_not_contains( $items[0]['excerpt'], 'here.Second' );
    expect_contains( $items[0]['excerpt'], 'here. Second' );
} );

t( 'msn: default image size is full (recommended 1280x720 floor)', function() {
    expect_eq( mmgrf_get_profile( 'msn' )->get_default_image_size(), 'full' );
} );

t( 'coexistence: with legacy NewsBreak plugin active, v3 defers on default-feed enhancement and the newsbreak slug', function() {
    if ( ! class_exists( 'NewsBreak_RSS_Feed' ) ) {
        eval( 'class NewsBreak_RSS_Feed {}' ); // simulate the standalone plugin being active
    }
    update_option( 'mmgrf_options', [ 'enhance_default_feeds' => 1 ] );
    mmgrf_register_default_feed_enhancement();
    expect_false( isset( $GLOBALS['mmgrf_test']['actions']['rss2_ns'] ), 'no double enhancement while legacy plugin owns /feed/' );

    update_option( 'mmgrf_networks', [ 'newsbreak' => [ 'enabled' => 1, 'slug' => 'newsbreak' ] ] );
    mmgrf_register_all_feeds();
    expect_false( isset( $GLOBALS['mmgrf_test']['feeds']['newsbreak'] ), 'v3 does not fight the legacy plugin for ?feed=newsbreak' );

    update_option( 'mmgrf_networks', [ 'newsbreak' => [ 'enabled' => 1, 'slug' => 'newsbreak-v3' ] ] );
    $GLOBALS['mmgrf_test']['feeds'] = [];
    mmgrf_register_all_feeds();
    expect_true( isset( $GLOBALS['mmgrf_test']['feeds']['newsbreak-v3'] ), 'parallel validation slug registers fine' );
} );

t( 'msn: under-recommended image logs a warning but ships', function() {
    $id = mmgrf_test_add_post( [
        'post_title'    => 'A Headline Long Enough To Clear The Twenty Character Floor',
        'post_content'  => '<p>Body words for the msn feed item validation to pass fine.</p>',
        'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
    ] );
    mmgrf_test_add_attachment( 9100, [ 'full' => [ 'https://example-brand.com/mid.jpg', 800, 450 ] ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9100;
    $xml = mmgrf_render_feed( mmgrf_get_profile( 'msn' ), mmgrf_network_options( 'msn' ), [] );
    expect_eq( substr_count( $xml, '<item>' ), 1, 'item ships (800x450 is above the 640x360 hard floor)' );
    $log = mmgrf_skip_log_get();
    expect_eq( count( $log ), 1 );
    expect_eq( $log[0]['level'], 'warn' );
    expect_contains( $log[0]['detail'], '1280' );
} );
