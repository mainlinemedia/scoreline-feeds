<?php
// Audit round 5 (2026-11-22) — settings, meta box, editor readiness check,
// self-updater, health checks, default-feed enhancement.

function r5_manifest( $data ) {
    $GLOBALS['mmgrf_test']['remote'][ MMGRF_UPDATE_MANIFEST ] = [ 'body' => is_string( $data ) ? $data : json_encode( $data ) ];
    delete_transient( 'mmgrf_update_manifest' );
}

function r5_pkg( $v ) {
    return "https://github.com/mainlinemedia/scoreline-feeds/releases/download/v$v/scoreline-feeds-$v.zip";
}

// ── Self-updater ─────────────────────────────────────────────────

t( 'r5 updater: the package must be this repo\'s release asset for that exact version', function() {
    $ok = [ 'version' => '99.1.0', 'package' => r5_pkg( '99.1.0' ) ];
    expect_eq( mmgrf_validate_update_manifest( $ok ), $ok );
    $bad = [
        'another repo via ../'    => 'https://github.com/mainlinemedia/scoreline-feeds/releases/../../../evil/repo/releases/download/v99.1.0/scoreline-feeds-99.1.0.zip',
        'stale package'           => r5_pkg( '99.0.0' ),
        'foreign host'            => 'https://evil.example.com/scoreline-feeds-99.1.0.zip',
        'query string'            => r5_pkg( '99.1.0' ) . '?x=1',
        'trailing newline'        => r5_pkg( '99.1.0' ) . "\n",
        'look-alike host'         => 'https://github.com.evil.test/mainlinemedia/scoreline-feeds/releases/download/v99.1.0/scoreline-feeds-99.1.0.zip',
    ];
    foreach ( $bad as $label => $package ) {
        expect_eq( mmgrf_validate_update_manifest( [ 'version' => '99.1.0', 'package' => $package ] ), null, $label );
    }
    expect_eq( mmgrf_validate_update_manifest( [ 'version' => "99.1.0\n", 'package' => r5_pkg( '99.1.0' ) ] ), null, 'version with trailing newline' );
    expect_eq( mmgrf_validate_update_manifest( [ 'version' => '1', 'package' => r5_pkg( '1' ) ] ), null, 'not a dotted version' );
    expect_eq( mmgrf_validate_update_manifest( 'nope' ), null );
} );

t( 'r5 updater: a failed fetch is cached briefly, a good one for hours', function() {
    $ttls = [];
    r5_manifest( '<html>rate limited</html>' );
    expect_eq( mmgrf_fetch_update_manifest(), null );
    expect_eq( $GLOBALS['mmgrf_test']['transient_ttl']['mmgrf_update_manifest'], 900 );
    r5_manifest( [ 'version' => '99.1.0', 'package' => r5_pkg( '99.1.0' ) ] );
    expect_eq( mmgrf_fetch_update_manifest()['version'], '99.1.0' );
    expect_eq( $GLOBALS['mmgrf_test']['transient_ttl']['mmgrf_update_manifest'], 6 * 3600 );
} );

t( 'r5 updater: "Check again" bypasses the cached answer', function() {
    r5_manifest( [ 'version' => '99.1.0', 'package' => r5_pkg( '99.1.0' ) ] );
    mmgrf_fetch_update_manifest();
    $GLOBALS['mmgrf_test']['remote'][ MMGRF_UPDATE_MANIFEST ] = [ 'body' => json_encode( [ 'version' => '99.2.0', 'package' => r5_pkg( '99.2.0' ) ] ) ];
    expect_eq( mmgrf_fetch_update_manifest()['version'], '99.1.0', 'cached' );
    $_GET['force-check'] = '1';
    $fresh = mmgrf_fetch_update_manifest()['version'];
    unset( $_GET['force-check'] );
    expect_eq( $fresh, '99.2.0' );
} );

t( 'r5 updater: "View details" is answered locally, only for this plugin', function() {
    r5_manifest( [ 'version' => '99.1.0', 'package' => r5_pkg( '99.1.0' ) ] );
    $info = mmgrf_plugins_api( false, 'plugin_information', (object) [ 'slug' => 'scoreline-feeds' ] );
    expect_eq( $info->name, 'Scoreline Feeds' );
    expect_eq( $info->version, '99.1.0' );
    expect_eq( mmgrf_plugins_api( false, 'plugin_information', (object) [ 'slug' => 'akismet' ] ), false );
    expect_eq( mmgrf_plugins_api( false, 'query_plugins', (object) [ 'slug' => 'scoreline-feeds' ] ), false );
} );

// ── Settings ─────────────────────────────────────────────────────

t( 'r5 settings: a network slug can never shadow a core WordPress feed', function() {
    foreach ( [ 'feed', 'rss2', 'atom', 'rdf', 'rss' ] as $reserved ) {
        $clean = mmgrf_sanitize_networks( [ 'yahoo' => [ 'enabled' => 1, 'slug' => $reserved ] ] );
        expect_eq( $clean['yahoo']['slug'], 'yahoo', "$reserved rejected, previous slug kept" );
    }
    expect_true( count( $GLOBALS['mmgrf_test']['settings_errors'] ) >= 5, 'and the operator is told why' );
} );

t( 'r5 settings: two feeds can never share a route', function() {
    $clean = mmgrf_sanitize_networks( [
        'newsbreak' => [ 'enabled' => 1, 'slug' => 'partner' ],
        'msn'       => [ 'enabled' => 1, 'slug' => 'partner' ],
        'yahoo'     => [ 'enabled' => 1, 'slug' => 'msn-videos' ],
    ] );
    expect_eq( $clean['newsbreak']['slug'], 'partner' );
    expect_eq( $clean['msn']['slug'], 'msn', 'duplicate of newsbreak rejected' );
    expect_eq( $clean['yahoo']['slug'], 'yahoo', 'collision with msn typed feed rejected' );

    update_option( 'mmgrf_tag_feeds', [ [ 'slug' => 'nfl', 'tags' => 'nfl' ] ] );
    expect_true( mmgrf_tag_feed_slug_taken( 'nfl', mmgrf_get_tag_feeds() ), 'duplicate tag feed' );
    expect_false( mmgrf_tag_feed_slug_taken( 'nfl', mmgrf_get_tag_feeds(), 0 ), 'editing itself is fine' );
    expect_true( mmgrf_tag_feed_slug_taken( 'raw-feed', mmgrf_get_tag_feeds() ), 'would duplicate the main feed route' );
    expect_false( mmgrf_tag_feed_slug_taken( 'nascar', mmgrf_get_tag_feeds() ) );
} );

t( 'r5 settings: settle window is stored, clamped, and shown as a field', function() {
    expect_eq( mmgrf_sanitize_options( [ 'settle_seconds' => '120' ] )['settle_seconds'], 120 );
    expect_eq( mmgrf_sanitize_options( [ 'settle_seconds' => '99999' ] )['settle_seconds'], 900 );
    expect_eq( mmgrf_sanitize_options( [ 'settle_seconds' => '0' ] )['settle_seconds'], 0 );
    expect_eq( mmgrf_sanitize_options( [] )['settle_seconds'], 60, 'default when the field is absent' );
} );

t( 'r5 settings: tag-feed fields are unslashed (no backslash in the channel title)', function() {
    // WordPress hands handlers a slashed $_POST.
    $entry = mmgrf_clean_tag_feed( wp_unslash( [ 'tf_slug' => 'nascar', 'tf_title' => 'Dale\\\'s NASCAR Feed', 'tf_tags' => 'nascar' ] ) );
    expect_eq( $entry['title'], "Dale's NASCAR Feed" );
} );

t( 'r5 settings: the two marker tags cannot be the same tag', function() {
    $clean = mmgrf_sanitize_options( [ 'slideshow_tag' => 'gallery', 'video_tag' => 'gallery' ] );
    expect_true( $clean['slideshow_tag'] !== $clean['video_tag'] );
} );

// ── Syndication meta box ─────────────────────────────────────────

t( 'r5 meta box: a Preview autosave revision cannot clear the live post\'s exclusion flags', function() {
    $live = mmgrf_test_add_post();
    update_post_meta( $live, '_mmgrf_exclude_yahoo', '1' );
    $rev  = mmgrf_test_add_post( [ 'post_type' => 'revision', 'post_parent' => $live, 'post_name' => $live . '-autosave-v1' ] );
    $form = [ 'post_ID' => (string) $live ]; // box submitted with "Sponsored" now unticked
    expect_false( mmgrf_is_edited_post( $rev, $form ), 'revision save is ignored' );
    expect_true( mmgrf_is_edited_post( $live, $form ), 'the real save is honoured' );
} );

t( 'r5 meta box: another post saved during the same request does not inherit the flags', function() {
    $edited = mmgrf_test_add_post();
    $other  = mmgrf_test_add_post();
    expect_false( mmgrf_is_edited_post( $other, [ 'post_ID' => (string) $edited ] ) );
    $page = mmgrf_test_add_post( [ 'post_type' => 'page' ] );
    expect_false( mmgrf_is_edited_post( $page, [ 'post_ID' => (string) $page ] ), 'only syndicated post types' );
} );

// ── Readiness check ──────────────────────────────────────────────

function r5_check_codes( $post_id ) {
    return array_column( mmgrf_syndication_check( $post_id )['issues'], 'code' );
}

function r5_ready_post( $content, $networks ) {
    update_option( 'mmgrf_networks', array_fill_keys( $networks, [ 'enabled' => 1 ] ) );
    $id = mmgrf_test_add_post( [ 'post_title' => 'A Headline Long Enough To Clear Every Network Floor', 'post_content' => $content ] );
    mmgrf_test_add_attachment( 9800, [ 'full' => [ 'https://example-brand.com/up/ready.jpg', 1920, 1080 ] ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9800;
    return $id;
}

t( 'r5 readiness: links and images injected at render time are checked, as the feed sees them', function() {
    $id = r5_ready_post( '<p>' . r5_words( 160 ) . '</p>[odds]', [ 'yahoo' ] );
    add_filter( 'the_content', fn( $c ) => str_replace( '[odds]', '<p><a href="https://sportsbook.draftkings.com/x">Bet now</a></p>', $c ) );
    expect_true( in_array( 'affiliate_domain_matched', r5_check_codes( $id ), true ), 'a shortcode-injected operator link is caught' );
} );

t( 'r5 readiness: the affiliate message states the action actually configured', function() {
    $id = r5_ready_post( '<p>' . r5_words( 160 ) . ' <a href="https://www.fanduel.com/x">odds</a></p>', [ 'yahoo' ] );
    $msg = mmgrf_syndication_check( $id )['issues'][0]['message'];
    expect_contains( $msg, 'entire article will be excluded from the Yahoo feed' );
    update_option( 'mmgrf_options', [ 'affiliate_domain_action' => 'unwrap' ] );
    $msg = mmgrf_syndication_check( $id )['issues'][0]['message'];
    expect_contains( $msg, 'link will be removed' );
    expect_not_contains( $msg, 'entire article' );
} );

t( 'r5 readiness: a NewsBreak-only site is not held to Yahoo\'s image floor or link rules', function() {
    $id = r5_ready_post( '<p>short body <a href="https://www.fanduel.com/x">odds</a></p>', [ 'newsbreak' ] );
    $GLOBALS['mmgrf_test']['attachments'][9800]['sizes']['full'] = [ 'https://example-brand.com/up/ready.jpg', 1000, 600 ];
    expect_eq( r5_check_codes( $id ), [] );
} );

t( 'r5 readiness: an MSN-only site is held to MSN\'s floor, and told about rights-flagged images', function() {
    $id = r5_ready_post( '<p>' . r5_words( 60 ) . '</p>', [ 'msn' ] );
    $GLOBALS['mmgrf_test']['attachments'][9800]['sizes']['full'] = [ 'https://example-brand.com/up/ready.jpg', 1000, 600 ];
    expect_eq( r5_check_codes( $id ), [], '1000x600 clears MSN 640x360' );
    $GLOBALS['mmgrf_test']['attachments'][9800]['sizes']['full'] = [ 'https://example-brand.com/up/ready.jpg', 600, 338 ];
    $issues = mmgrf_syndication_check( $id )['issues'];
    expect_eq( $issues[0]['code'], 'image_below_minimum' );
    expect_contains( $issues[0]['message'], 'MSN requires at least 640×360' );
    $GLOBALS['mmgrf_test']['attachments'][9800]['sizes']['full'] = [ 'https://example-brand.com/up/ready.jpg', 1920, 1080 ];
    update_post_meta( 9800, '_mmgrf_no_syndication_rights', '1' );
    expect_eq( r5_check_codes( $id ), [ 'no_syndication_rights' ] );
} );

t( 'r5 readiness: Yahoo body floor and MSN headline length are predicted', function() {
    $id = r5_ready_post( '<p>' . r5_words( 90 ) . '</p>', [ 'yahoo', 'msn' ] );
    expect_eq( r5_check_codes( $id ), [ 'body_below_word_floor' ] );
    get_post( $id )->post_title = 'Too Short';
    expect_true( in_array( 'title_too_short', r5_check_codes( $id ), true ) );
} );

t( 'r5 readiness: a tagged slideshow or video that will ship nowhere says so', function() {
    $id = r5_ready_post( '<p>Intro words.</p><h2>One</h2><img src="https://example-brand.com/up/ready.jpg"><p>Text.</p>', [ 'yahoo', 'msn' ] );
    mmgrf_test_set_terms( $id, 'post_tag', [ 'Slideshow' ] );
    $issues = mmgrf_syndication_check( $id )['issues'];
    expect_eq( array_column( $issues, 'code' ), [ 'slideshow_too_few_slides' ] );
    expect_contains( $issues[0]['message'], 'at least 4', 'the strictest enabled network' );

    $v = r5_ready_post( '<p>No video in here at all.</p>', [ 'yahoo' ] );
    mmgrf_test_set_terms( $v, 'post_tag', [ 'Video' ] );
    expect_eq( r5_check_codes( $v ), [ 'video_missing' ] );
} );

t( 'r5 readiness: a content filter that throws on the edit screen cannot break the editor', function() {
    $id = r5_ready_post( '<p>' . r5_words( 160 ) . '</p>', [ 'yahoo' ] );
    add_filter( 'the_content', function() { throw new RuntimeException( 'frontend-only filter' ); } );
    expect_eq( r5_check_codes( $id ), [], 'falls back to the saved content' );
} );

t( 'r5 readiness: the REST check is refused to a user who cannot edit the post', function() {
    $GLOBALS['mmgrf_test']['caps'] = [];
    expect_false( mmgrf_syndication_check_permission( [ 'post_id' => 5 ] ) );
    $GLOBALS['mmgrf_test']['caps'] = [ 'edit_post' ];
    expect_true( mmgrf_syndication_check_permission( [ 'post_id' => 5 ] ) );
} );

// ── Health ───────────────────────────────────────────────────────

t( 'r5 health: plain permalinks and a still-active legacy NewsBreak plugin are reported', function() {
    update_option( 'mmgrf_networks', [ 'newsbreak' => [ 'enabled' => 1 ] ] );
    update_option( 'permalink_structure', '/%postname%/' );
    expect_eq( array_column( mmgrf_health_problems(), 'code' ), [] );

    update_option( 'permalink_structure', '' );
    expect_eq( array_column( mmgrf_health_problems(), 'code' ), [ 'plain_permalinks' ], 'the liveforever.golf failure' );

    update_option( 'permalink_structure', '/%postname%/' );
    add_filter( 'mmgrf_legacy_newsbreak_active', '__return_true' );
    expect_eq( array_column( mmgrf_health_problems(), 'code' ), [ 'legacy_newsbreak_active' ] );

    $test = mmgrf_site_health_test();
    expect_eq( $test['status'], 'recommended' );
    expect_contains( $test['description'], 'NewsBreak RSS Feed' );
} );

t( 'r5 health: a clean site reports good', function() {
    update_option( 'permalink_structure', '/%postname%/' );
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    expect_eq( mmgrf_site_health_test()['status'], 'good' );
    expect_eq( mmgrf_missing_requirements(), [] );
} );

// ── Default /feed/ enhancement ───────────────────────────────────

t( 'r5 default feed: media tags carry the true rendition size, not the theme-constrained one', function() {
    $id = fixture_post();
    $att = $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id;
    // The theme's $content_width makes core report 696x392 for the 1024px file.
    $GLOBALS['mmgrf_test']['attachments'][ $att ]['reported']['large'] = [ 696, 392 ];
    $GLOBALS['mmgrf_test']['query_vars']['feed'] = 'rss2';
    $GLOBALS['post'] = get_post( $id );
    ob_start();
    mmgrf_default_feed_media_tags();
    $out = ob_get_clean();
    expect_contains( $out, 'width="1024" height="576"' );
    expect_not_contains( $out, '696' );
} );

t( 'r5 default feed: a texturized title is not double-escaped in the lead figure alt', function() {
    $id = fixture_post( [ 'post_title' => 'Yamaha&#8217;s New Bike &amp; More' ] );
    $att = $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id;
    $GLOBALS['mmgrf_test']['attachments'][ $att ]['alt'] = '';
    unset( $GLOBALS['mmgrf_test']['meta'][ $att ]['_wp_attachment_image_alt'] );
    $GLOBALS['mmgrf_test']['query_vars']['feed'] = 'rss2';
    $GLOBALS['post'] = get_post( $id );
    $html = mmgrf_default_feed_prepend_image( '<p>Body.</p>' );
    expect_contains( $html, 'alt="Yamaha’s New Bike &amp; More"' );
    expect_not_contains( $html, '&amp;#8217;' );
} );

t( 'r5: a server missing a required extension answers 503, never a broken 200', function() {
    fixture_post();
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    mmgrf_register_all_feeds();
    add_filter( 'mmgrf_missing_requirements', fn() => [ 'the PHP "dom" extension' ] );
    foreach ( [ 'yahoo', 'yahoo-slideshows', 'yahoo-videos', 'raw-feed-raw-feed' ] as $slug ) {
        $GLOBALS['mmgrf_test']['status_header'] = null;
        ob_start();
        @$GLOBALS['mmgrf_test']['feeds'][ $slug ]();
        $body = ob_get_clean();
        expect_eq( $GLOBALS['mmgrf_test']['status_header'], 503, $slug );
        expect_not_contains( $body, '<rss', $slug );
    }
    expect_eq( mmgrf_site_health_test()['status'], 'critical' );
} );
