<?php
// Layer 1 pipeline: normalized item building, exclusions, image cascade,
// options, and full-feed orchestration.

function fixture_post( $over = [], $img = true ) {
    $id = mmgrf_test_add_post( array_merge( [
        'post_title'   => 'A Headline Long Enough To Clear Every Network Floor',
        'post_content' => '<p>' . implode( ' ', array_fill( 0, 200, 'word' ) ) . '</p>',
        'post_date_gmt'=> gmdate( 'Y-m-d H:i:s', time() - 86400 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 86400 ),
    ], $over ) );
    if ( $img ) {
        $att = 9000 + $id;
        mmgrf_test_add_attachment( $att, [ 'large' => [ "https://example-brand.com/img/$id-large.jpg", 1024, 576 ], 'full' => [ "https://example-brand.com/img/$id.jpg", 1920, 1080 ] ], 'image/jpeg', [ 'alt' => 'Alt ' . $id, 'caption' => 'Caption ' . $id ] );
        $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = $att;
    }
    return $id;
}

t( 'pipeline: normalized item has clean permalink, GMT timestamps, derived excerpt', function() {
    $id = fixture_post( [ 'post_date_gmt' => '2026-08-01 12:00:00', 'post_modified_gmt' => '2026-08-01 12:00:00' ] );
    $p = new MMGRF_Profile_NewsBreak();
    $items = mmgrf_build_items( $p, mmgrf_network_options( 'newsbreak' ), [] );
    expect_eq( count( $items ), 1 );
    $it = $items[0];
    expect_eq( $it['permalink'], "https://example-brand.com/test-post-$id/" );
    expect_eq( $it['link'], $it['permalink'], 'newsbreak default: no UTM' );
    expect_eq( $it['pub_ts'], strtotime( '2026-08-01 12:00:00 UTC' ) );
    expect_true( str_word_count( $it['excerpt'] ) > 10, 'excerpt derived from content' );
} );

t( 'pipeline: aigeon default applies UTM parameters to link but not guid', function() {
    fixture_post();
    $p = new MMGRF_Profile_Aigeon();
    $items = mmgrf_build_items( $p, mmgrf_network_options( 'aigeon' ), [] );
    expect_contains( $items[0]['link'], 'utm_source=feed' );
    expect_contains( $items[0]['link'], 'utm_medium=email' );
    expect_not_contains( $items[0]['permalink'], 'utm_', 'guid stays clean' );
} );

t( 'pipeline: image cascade falls back to first inline image when no featured image', function() {
    $id = fixture_post( [ 'post_content' => '<p>' . implode( ' ', array_fill( 0, 160, 'w' ) ) . '</p><img src="https://example-brand.com/inline.jpg">' ], false );
    $p = new MMGRF_Profile_NewsBreak();
    $items = mmgrf_build_items( $p, mmgrf_network_options( 'newsbreak' ), [] );
    expect_true( $items[0]['image'] !== null, 'inline image resolved' );
    expect_eq( $items[0]['image']['url'], 'https://example-brand.com/inline.jpg' );
} );

t( 'pipeline: featured image carries alt, caption, and photo_credit meta', function() {
    $id = fixture_post();
    update_post_meta( $id, 'photo_credit', 'AP Photo' );
    $p = new MMGRF_Profile_NewsBreak();
    $items = mmgrf_build_items( $p, mmgrf_network_options( 'newsbreak' ), [] );
    expect_eq( $items[0]['image']['credit'], 'AP Photo' );
    expect_eq( $items[0]['image']['alt'], 'Alt ' . $id );
} );

t( 'pipeline: per-network exclusion meta withholds the post from that network only', function() {
    $id = fixture_post();
    update_post_meta( $id, '_mmgrf_exclude_yahoo', '1' );
    expect_eq( count( mmgrf_build_items( new MMGRF_Profile_Yahoo(), mmgrf_network_options( 'yahoo' ), [] ) ), 0, 'yahoo excluded' );
    expect_eq( count( mmgrf_build_items( new MMGRF_Profile_NewsBreak(), mmgrf_network_options( 'newsbreak' ), [] ) ), 1, 'newsbreak unaffected' );
} );

t( 'pipeline: policy category exclusion applies to networks but not aigeon legacy', function() {
    $id = fixture_post();
    mmgrf_test_set_terms( $id, 'category', [ 'Sponsored' ] );
    update_option( 'mmgrf_options', [ 'exclude_networks_cats' => 'sponsored' ] );
    expect_eq( count( mmgrf_build_items( new MMGRF_Profile_MSN(), mmgrf_network_options( 'msn' ), [] ) ), 0, 'network excluded by policy' );
    expect_eq( count( mmgrf_build_items( new MMGRF_Profile_Aigeon(), mmgrf_network_options( 'aigeon' ), [] ) ), 1, 'aigeon legacy unaffected' );
} );

t( 'pipeline: short title and attachment rights meta reach the item', function() {
    $id = fixture_post();
    update_post_meta( $id, '_mmgrf_short_title', 'Short one' );
    update_post_meta( 9000 + $id, '_mmgrf_no_syndication_rights', '1' ); // attachment meta
    $items = mmgrf_build_items( new MMGRF_Profile_MSN(), mmgrf_network_options( 'msn' ), [] );
    expect_eq( $items[0]['short_title'], 'Short one' );
    expect_true( $items[0]['image']['no_synd_rights'], 'rights flag propagated' );
} );

t( 'render_feed: well-formed XML with profile namespaces and validated items only', function() {
    fixture_post();                                            // valid everywhere
    $thin = fixture_post( [ 'post_title' => 'Another Headline Comfortably Above Twenty Characters', 'post_content' => '<p>only ten words in this body here truly not enough</p>' ] );
    $xml = mmgrf_render_feed( new MMGRF_Profile_Yahoo(), mmgrf_network_options( 'yahoo' ), [] );
    $doc = simplexml_load_string( $xml );
    expect_true( $doc !== false, 'well-formed' );
    expect_contains( $xml, 'xmlns:media="http://search.yahoo.com/rss"' );
    expect_eq( substr_count( $xml, '<item>' ), 1, 'thin post skipped by gate' );
    $log = mmgrf_skip_log_get();
    expect_eq( count( $log ), 1 );
    expect_eq( $log[0]['network'], 'yahoo' );
    expect_eq( $log[0]['code'], 'body_below_word_floor' );
} );

t( 'render_feed: newsbreak emits nb namespace and honors nb_scripts option', function() {
    fixture_post();
    $opts = mmgrf_network_options( 'newsbreak' );
    $opts['nb_scripts'] = '<img src="https://sb.scorecardresearch.com/p?c1=2"/>';
    $xml = mmgrf_render_feed( new MMGRF_Profile_NewsBreak(), $opts, [] );
    expect_contains( $xml, 'xmlns:nb="https://www.newsbreak.com/"' );
    expect_contains( $xml, '<nb:scripts><![CDATA[' );
    expect_true( simplexml_load_string( $xml ) !== false, 'well-formed' );
} );

t( 'render_feed: respects post_count and orders newest first', function() {
    for ( $i = 0; $i < 5; $i++ ) {
        fixture_post( [ 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 86400 * ( $i + 1 ) ) ] );
    }
    $opts = mmgrf_network_options( 'newsbreak' );
    $opts['post_count'] = 3;
    $xml = mmgrf_render_feed( new MMGRF_Profile_NewsBreak(), $opts, [] );
    expect_eq( substr_count( $xml, '<item>' ), 3 );
    $first = strpos( $xml, gmdate( 'D, d M Y', time() - 86400 ) );
    expect_true( $first !== false && $first < strpos( $xml, gmdate( 'D, d M Y', time() - 2 * 86400 ) ), 'newest first' );
} );

t( 'options: network defaults are off with slug = network key; legacy keys untouched', function() {
    update_option( 'mmgrf_options', [ 'feed_slug' => 'custom', 'utm_source' => 'feed' ] );
    $nets = mmgrf_get_network_config();
    foreach ( [ 'newsbreak', 'msn', 'yahoo' ] as $n ) {
        expect_false( $nets[ $n ]['enabled'], "$n disabled by default" );
        expect_eq( $nets[ $n ]['slug'], $n );
    }
    $opts = mmgrf_get_options();
    expect_eq( $opts['feed_slug'], 'custom', 'legacy option key preserved' );
} );

t( 'options: utm defaults follow live behavior (aigeon on, networks off)', function() {
    expect_true( mmgrf_network_options( 'aigeon' )['utm_enabled'] );
    expect_false( mmgrf_network_options( 'newsbreak' )['utm_enabled'] );
    expect_false( mmgrf_network_options( 'msn' )['utm_enabled'] );
    expect_false( mmgrf_network_options( 'yahoo' )['utm_enabled'] );
} );
