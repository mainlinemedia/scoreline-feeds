<?php
// Routing/back-compat (G5), default-feed enhancement toggle (N3), admin save
// logic, and aigeon item-shape parity with v2.1.0.

t( 'routes: legacy raw-feed slug and tag feeds always registered; networks only when enabled', function() {
    update_option( 'mmgrf_options', [ 'feed_slug' => 'raw-feed' ] );
    update_option( 'mmgrf_tag_feeds', [ [ 'slug' => 'nfl', 'tags' => 'nfl', 'title' => 'NFL' ] ] );
    update_option( 'mmgrf_networks', [ 'newsbreak' => [ 'enabled' => 1 ] ] );
    mmgrf_register_all_feeds();
    $feeds = array_keys( $GLOBALS['mmgrf_test']['feeds'] );
    expect_true( in_array( 'raw-feed-raw-feed', $feeds, true ), 'legacy main feed route' );
    expect_true( in_array( 'nfl-raw-feed', $feeds, true ), 'legacy tag feed route' );
    expect_true( in_array( 'newsbreak', $feeds, true ), 'enabled network route' );
    expect_false( in_array( 'msn', $feeds, true ), 'disabled network not routed' );
    expect_false( in_array( 'yahoo', $feeds, true ), 'disabled network not routed' );
} );

t( 'default-feed enhancement: filters registered only when toggle on', function() {
    update_option( 'mmgrf_options', [ 'enhance_default_feeds' => 1 ] );
    mmgrf_register_default_feed_enhancement();
    expect_true( isset( $GLOBALS['mmgrf_test']['actions']['rss2_ns'] ), 'rss2_ns hooked' );
    expect_true( isset( $GLOBALS['mmgrf_test']['filters']['the_content_feed'] ), 'content filter hooked' );

    mmgrf_test_reset();
    update_option( 'mmgrf_options', [ 'enhance_default_feeds' => 0 ] );
    mmgrf_register_default_feed_enhancement();
    expect_false( isset( $GLOBALS['mmgrf_test']['actions']['rss2_ns'] ), 'not hooked when off' );
} );

t( 'meta box save: sponsored master checkbox sets every per-network exclusion', function() {
    $id = mmgrf_test_add_post();
    mmgrf_save_syndication_meta( $id, [
        'mmgrf_score'       => '2.5',
        'mmgrf_sponsored'   => '1',
        'mmgrf_short_title' => 'Short',
    ] );
    expect_eq( get_post_meta( $id, '_mmgrf_score', true ), '2.5' );
    expect_eq( get_post_meta( $id, '_mmgrf_short_title', true ), 'Short' );
    foreach ( [ 'newsbreak', 'msn', 'yahoo' ] as $n ) {
        expect_eq( get_post_meta( $id, '_mmgrf_exclude_' . $n, true ), '1', "excluded from $n" );
    }
} );

t( 'meta box save: unchecking an exclusion clears it', function() {
    $id = mmgrf_test_add_post();
    update_post_meta( $id, '_mmgrf_exclude_msn', '1' );
    mmgrf_save_syndication_meta( $id, [ 'mmgrf_score' => '0' ] ); // nothing checked
    expect_eq( get_post_meta( $id, '_mmgrf_exclude_msn', true ), '', 'cleared' );
} );

t( 'network settings sanitization: slug sanitized, enabled cast, scripts preserved', function() {
    $clean = mmgrf_sanitize_networks( [
        'newsbreak' => [ 'enabled' => '1', 'slug' => 'News Break!!', 'post_count' => '75', 'nb_scripts' => '<img src="https://t.co/p.gif"/>' ],
        'yahoo'     => [ 'slug' => '' ],
    ] );
    expect_eq( $clean['newsbreak']['slug'], 'news-break' );
    expect_true( $clean['newsbreak']['enabled'] );
    expect_eq( $clean['newsbreak']['post_count'], 75 );
    expect_contains( $clean['newsbreak']['nb_scripts'], 'https://t.co/p.gif' );
    expect_eq( $clean['yahoo']['slug'], 'yahoo', 'empty slug falls back to network key' );
    expect_false( $clean['yahoo']['enabled'] );
} );

t( 'parity: aigeon item element sequence matches v2.1.0 exactly', function() {
    $id = mmgrf_test_add_post( [
        'post_title'    => "Team Wins & Fans Rejoice: A Headline",
        'post_content'  => '<p>Body text of reasonable length for the item.</p>',
        'post_date_gmt' => '2026-08-01 12:00:00',
        'post_modified_gmt' => '2026-08-01 12:00:00',
    ] );
    mmgrf_test_add_attachment( 9500, [ 'large' => [ 'https://example-brand.com/i.jpg', 1024, 576 ] ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9500;
    mmgrf_test_set_terms( $id, 'category', [ 'Sports' ] );
    mmgrf_test_set_terms( $id, 'post_tag', [ 'nfl' ] );
    update_post_meta( $id, '_mmgrf_score', '1.5' );

    $xml = mmgrf_render_feed( mmgrf_get_profile( 'aigeon' ), mmgrf_network_options( 'aigeon' ), [] );

    // v2.1.0 channel shape incl. sy: elements
    foreach ( [ '<sy:updatePeriod>hourly</sy:updatePeriod>', '<sy:updateFrequency>1</sy:updateFrequency>' ] as $el ) {
        expect_contains( $xml, $el );
    }
    // v2.1.0 item element order
    $sequence = [ '<item>', '<title><![CDATA[', '<link>', '<pubDate>', '<dc:creator><![CDATA[',
        '<category><![CDATA[Sports]]></category>', '<category domain="tag"><![CDATA[nfl]]></category>',
        '<category domain="score">1.5</category>', '<guid isPermaLink="true">', '<description><![CDATA[',
        '<content:encoded><![CDATA[', '<wfw:commentRss>', '<slash:comments>', '<media:content', '<media:thumbnail', '</item>' ];
    $pos = 0;
    foreach ( $sequence as $needle ) {
        $found = strpos( $xml, $needle, $pos );
        expect_true( $found !== false, "element in order: $needle" );
        $pos = $found;
    }
    // D1 fix visible in legacy shape: raw ampersand inside CDATA title
    expect_contains( $xml, '<title><![CDATA[Team Wins & Fans Rejoice: A Headline]]></title>' );
    // UTM on link, clean guid
    expect_contains( $xml, "test-post-$id/?utm_source=feed&amp;utm_medium=email</link>" );
    expect_contains( $xml, "<guid isPermaLink=\"true\">https://example-brand.com/test-post-$id/</guid>" );
    expect_true( simplexml_load_string( $xml ) !== false, 'well-formed' );
} );
