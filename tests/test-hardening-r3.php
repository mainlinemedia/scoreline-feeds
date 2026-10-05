<?php
// Hardening round 3 (2026-08-29 deep audit): F1-F4 + P1.

t( 'r3-F1: settings change rotates cache key and defeats stale 304s', function() {
    $id = mmgrf_test_add_post( [ 'post_content' => '<p>' . implode( ' ', array_fill( 0, 30, 'w' ) ) . '</p>' ] );
    mmgrf_test_add_attachment( 9700, [ 'full' => [ 'https://example-brand.com/a.jpg', 1920, 1080 ] ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9700;

    $first = mmgrf_get_feed_output( 'newsbreak' );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->post_title = 'Changed But Cached Still';
    expect_eq( mmgrf_get_feed_output( 'newsbreak' ), $first, 'cached while nothing touched' );

    $before = mmgrf_feed_lastmod();
    $GLOBALS['mmgrf_test']['options']['mmgrf_config_touched'] = $before + 100; // simulate a settings save later
    expect_true( mmgrf_feed_lastmod() > $before, 'config touch advances lastmod' );
    expect_contains( mmgrf_get_feed_output( 'newsbreak' ), 'Changed But Cached Still', 'cache busted by config touch' );
    expect_false(
        mmgrf_feed_not_modified( gmdate( 'D, d M Y H:i:s', $before ) . ' GMT', mmgrf_feed_lastmod() ),
        'client cached before the config change must get a full body'
    );
} );

t( 'r3-F1: sanitize_options records the config touch', function() {
    mmgrf_sanitize_options( [ 'feed_slug' => 'raw-feed' ] );
    expect_true( (int) get_option( 'mmgrf_config_touched', 0 ) > 0 );
} );

t( 'r3-F2: rights-flagged cover falls back to first slide on msn gallery', function() {
    slideshow_post( 4 );
    update_post_meta( 6999, '_mmgrf_no_syndication_rights', '1' ); // the cover attachment
    update_option( 'mmgrf_networks', [ 'msn' => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_slideshow_feed( 'msn', mmgrf_slideshow_options( 'msn' ) );
    expect_not_contains( $xml, 'cover.jpg', 'flagged cover withheld' );
    // audit4-A refinement: fallback uses an ALTERNATE rendition of slide 1,
    // never the identical URL the slide already declares.
    expect_contains( $xml, '<media:thumbnail url="https://example-brand.com/up/slide1-1024x683.jpg"', 'slide-1 alternate rendition used' );
    // Yahoo has no rights concept — cover ships there.
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    expect_contains( mmgrf_render_slideshow_feed( 'yahoo', mmgrf_slideshow_options( 'yahoo' ) ), 'cover.jpg' );
} );

t( 'r3-F4: untitled slideshow post skipped with title_empty', function() {
    $id = slideshow_post( 4, [ 'post_title' => '' ] );
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_slideshow_feed( 'yahoo', mmgrf_slideshow_options( 'yahoo' ) );
    expect_eq( substr_count( $xml, '<item>' ), 0 );
    $codes = array_column( mmgrf_skip_log_get(), 'code' );
    expect_true( in_array( 'title_empty', $codes, true ) );
} );

t( 'r3-P1: prune fast-path skips clean bodies but still catches nbsp-only paragraphs', function() {
    $clean = '<p>Nothing to prune here at all</p><p>Second healthy paragraph</p>';
    expect_eq( mmgrf_prune_empty_nodes( $clean ), $clean, 'clean body passes through untouched' );
    $html = mmgrf_prune_empty_nodes( '<p>kept</p><p>&nbsp;</p>' );
    expect_not_contains( $html, '&nbsp;', 'nbsp-only paragraph still pruned' );
    expect_contains( $html, '<p>kept</p>' );
} );

t( 'r3: slideshow channel titled with the Slideshows suffix', function() {
    slideshow_post( 4 );
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    expect_contains( mmgrf_render_slideshow_feed( 'yahoo', mmgrf_slideshow_options( 'yahoo' ) ), '<title>Example Brand - Slideshows</title>' );
} );
