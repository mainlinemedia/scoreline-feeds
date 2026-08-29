<?php
// Renders one sample feed per profile from realistic fixtures into samples/.
// Not part of the test suite — a human-inspection artifact.

require __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/scoreline-feeds/scoreline-feeds.php';

$GLOBALS['mmgrf_test']['home_url'] = 'https://gamedaychatter.com';
$GLOBALS['mmgrf_test']['blog'] = [ 'name' => 'Game Day Chatter', 'description' => 'Sports news and analysis', 'language' => 'en-US', 'charset' => 'UTF-8' ];

$body = '<div class="wp-block-group"><p style="margin-top:0">The <b>Kansas City Chiefs</b> reshaped their secondary Tuesday, agreeing to terms with veteran cornerback Marcus Webb on a two-year deal worth up to $18 million, league sources confirmed.</p>'
      . '<p>Webb, 29, started 15 games last season and allowed a passer rating of just 74.2 in coverage — “the best value corner on the market,” one AFC executive said. ' . implode( ' ', array_fill( 0, 120, 'Additional reporting detail sentence words here for realistic body length.' ) ) . '</p>'
      . '<script>trackPageview();</script>'
      . '<iframe src="https://www.youtube.com/embed/pressconf1"></iframe>'
      . '<iframe src="https://www.tiktok.com/embed/v2/74812"></iframe>'
      . '<img src="/wp-content/uploads/2026/08/webb-camp.jpg" srcset="a 1x, b 2x" loading="lazy" data-attach="9">'
      . '</div>';

$id = mmgrf_test_add_post( [
    'post_title'        => "Chiefs Land Marcus Webb & Bolster Secondary Before Camp",
    'post_content'      => $body,
    'post_date_gmt'     => gmdate( 'Y-m-d H:i:s', time() - 3 * 3600 ),
    'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 1 * 3600 ),
    'post_name'         => 'chiefs-land-marcus-webb',
] );
mmgrf_test_add_attachment( 9001, [ 'large' => [ 'https://gamedaychatter.com/wp-content/uploads/2026/08/webb-large.jpg', 1024, 576 ], 'full' => [ 'https://gamedaychatter.com/wp-content/uploads/2026/08/webb.jpg', 1920, 1080 ] ], 'image/jpeg', [ 'alt' => 'Marcus Webb in coverage', 'caption' => 'Webb during last season' ] );
$GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9001;
mmgrf_test_set_terms( $id, 'category', [ 'NFL' ] );
mmgrf_test_set_terms( $id, 'post_tag', [ 'chiefs', 'free-agency' ] );
update_post_meta( $id, '_mmgrf_score', '2.5' );
update_post_meta( $id, 'photo_credit', 'AP Photo/J. Ramirez' );
update_post_meta( $id, '_mmgrf_short_title', 'Chiefs land CB Marcus Webb' );

// A second post that trips the Yahoo word-count gate + MSN title gate
$thin = mmgrf_test_add_post( [
    'post_title'        => 'Quick Hit: Camp Notes',
    'post_content'      => '<p>Short practice-report body, only a handful of words.</p>',
    'post_date_gmt'     => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
    'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
    'post_name'         => 'quick-hit-camp-notes',
] );
mmgrf_test_add_attachment( 9002, [ 'large' => [ 'https://gamedaychatter.com/i2-large.jpg', 1024, 576 ], 'full' => [ 'https://gamedaychatter.com/i2.jpg', 1600, 900 ] ] );
$GLOBALS['mmgrf_test']['posts'][ $thin ]->thumbnail_id = 9002;
mmgrf_test_set_terms( $thin, 'category', [ 'NFL' ] );

@mkdir( __DIR__ . '/../samples' );
foreach ( [ 'aigeon', 'newsbreak', 'msn', 'yahoo' ] as $network ) {
    $xml = mmgrf_render_feed( mmgrf_get_profile( $network ), mmgrf_network_options( $network ), [] );
    file_put_contents( __DIR__ . "/../samples/sample-$network.xml", $xml );
    $ok = simplexml_load_string( $xml ) !== false ? 'well-formed' : 'MALFORMED';
    $items = substr_count( $xml, '<item>' );
    echo str_pad( $network, 10 ) . " $ok, $items item(s)\n";
}
echo "\nSkip log:\n";
foreach ( mmgrf_skip_log_get() as $e ) {
    echo "  [{$e['network']}] ({$e['level']}) {$e['post_title']}: {$e['code']} — {$e['detail']}\n";
}
