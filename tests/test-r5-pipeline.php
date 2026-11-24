<?php
// Audit round 5 (2026-11-22) — pipeline, query correctness, containment,
// cache, conditional GET, skip-log batching. Each test names the production
// failure it prevents.

function r5_ids( $network, $filters = [] ) {
    $items = mmgrf_build_items( mmgrf_get_profile( $network ), mmgrf_network_options( $network ), $filters );
    return array_column( $items, 'id' );
}

function r5_codes( $network = null ) {
    $log = mmgrf_skip_log_get();
    if ( $network !== null ) {
        $log = array_filter( $log, fn( $e ) => $e['network'] === $network );
    }
    return array_values( array_column( $log, 'code' ) );
}

// ── Query correctness ────────────────────────────────────────────

t( 'r5: a sticky post neither jumps the queue nor bypasses the policy exclusion', function() {
    $sticky = fixture_post( [ 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 ) ] );
    mmgrf_test_set_terms( $sticky, 'post_tag', [ 'sponsored' ] );
    $normal = fixture_post();
    update_option( 'mmgrf_options', [ 'exclude_networks_tags' => 'sponsored' ] );
    $GLOBALS['mmgrf_test']['sticky'] = [ $sticky ];
    expect_eq( r5_ids( 'yahoo' ), [ $normal ], 'sponsored sticky must not ship' );
} );

t( 'r5: a sticky post does not push the feed past its item cap', function() {
    $ids = [];
    for ( $i = 0; $i < 4; $i++ ) {
        $ids[] = fixture_post( [ 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( $i + 2 ) * 86400 ) ] );
    }
    $GLOBALS['mmgrf_test']['sticky'] = [ $ids[3] ];
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1, 'post_count' => 3 ] ] );
    expect_eq( r5_ids( 'yahoo' ), array_slice( $ids, 0, 3 ), 'newest three, in date order' );
} );

t( 'r5: password-protected posts never syndicate on any feed', function() {
    $locked = fixture_post( [ 'post_password' => 'hunter2' ] );
    $open   = fixture_post( [ 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * 86400 ) ] );
    foreach ( [ 'aigeon', 'newsbreak', 'msn', 'yahoo' ] as $network ) {
        expect_eq( r5_ids( $network ), [ $open ], $network );
    }
} );

t( 'r5: the feed query skips the row-count pass', function() {
    fixture_post();
    r5_ids( 'yahoo' );
    expect_true( ! empty( $GLOBALS['mmgrf_test']['last_query_args']['no_found_rows'] ) );
} );

t( 'r5: a paginated post ships every page, not just page one', function() {
    fixture_post( [ 'post_content' => '<p>Page one text.</p><!--nextpage--><p>Page two text.</p><!--nextpage--><p>Page three text.</p>' ] );
    $items = mmgrf_build_items( mmgrf_get_profile( 'newsbreak' ), mmgrf_network_options( 'newsbreak' ), [] );
    expect_contains( $items[0]['content'], 'Page two text.' );
    expect_contains( $items[0]['content'], 'Page three text.' );
    expect_not_contains( $items[0]['content'], 'nextpage' );
} );

t( 'r5: an include filter naming a term that no longer exists yields an EMPTY feed, never every post', function() {
    fixture_post();
    fixture_post();
    // The Cowboys tag feed after someone renamed/merged the tag.
    expect_eq( r5_ids( 'aigeon', [ 'tags' => 'dallas-cowboys' ] ), [] );
    expect_true( in_array( 'filter_term_missing', r5_codes(), true ), 'and it says why' );
} );

t( 'r5: an include filter with one live and one dead term still filters on the live one', function() {
    $in  = fixture_post();
    $out = fixture_post();
    mmgrf_test_set_terms( $in, 'post_tag', [ 'cowboys' ] );
    expect_eq( r5_ids( 'aigeon', [ 'tags' => 'cowboys, gone-tag' ] ), [ $in ] );
} );

t( 'r5: a policy exclusion naming a missing term is surfaced as a warning', function() {
    fixture_post();
    update_option( 'mmgrf_options', [ 'exclude_networks_cats' => 'sponsered' ] ); // typo
    expect_eq( count( r5_ids( 'yahoo' ) ), 1 );
    $entry = array_values( array_filter( mmgrf_skip_log_get(), fn( $e ) => $e['code'] === 'exclusion_term_missing' ) );
    expect_eq( count( $entry ), 1 );
    expect_contains( $entry[0]['detail'], 'sponsered' );
    expect_eq( $entry[0]['level'], 'warn' );
} );

t( 'r5: the policy category exclusion covers child categories', function() {
    $child_post = fixture_post();
    mmgrf_test_set_terms( $child_post, 'category', [ 'Betting Partners' ] );
    $clean = fixture_post( [ 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * 86400 ) ] );
    $child_id = $GLOBALS['mmgrf_test']['terms']['category']['betting-partners']->term_id;
    $GLOBALS['mmgrf_test']['terms']['category']['sponsored'] = (object) [ 'term_id' => 990, 'name' => 'Sponsored', 'slug' => 'sponsored' ];
    $GLOBALS['mmgrf_test']['term_children'][990] = [ $child_id ];
    update_option( 'mmgrf_options', [ 'exclude_networks_cats' => 'sponsored' ] );
    expect_eq( r5_ids( 'msn' ), [ $clean ], 'post filed only under the child must be withheld' );
    expect_eq( count( r5_ids( 'aigeon' ) ), 2, 'legacy feed unaffected' );
} );

// ── Settle window ────────────────────────────────────────────────

t( 'r5: a just-published post is held back from network feeds for the settle window', function() {
    $fresh   = fixture_post( [ 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 5 ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ] );
    $settled = fixture_post();
    expect_eq( r5_ids( 'yahoo' ), [ $settled ] );
    expect_eq( r5_ids( 'aigeon' ), [ $fresh, $settled ], 'legacy feed is not delayed' );
    update_option( 'mmgrf_options', [ 'settle_seconds' => 0 ] );
    expect_eq( r5_ids( 'yahoo' ), [ $fresh, $settled ], '0 disables the hold' );
} );

// ── Containment ──────────────────────────────────────────────────

t( 'r5: one post whose content filter throws costs that item, not the feed', function() {
    $bad  = fixture_post( [ 'post_title' => 'The Post With The Exploding Shortcode Inside', 'post_content' => '<p>BOOM ' . implode( ' ', array_fill( 0, 200, 'w' ) ) . '</p>' ] );
    $good = fixture_post( [ 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * 86400 ) ] );
    add_filter( 'the_content', function( $c ) {
        if ( strpos( $c, 'BOOM' ) !== false ) {
            throw new RuntimeException( 'shortcode blew up' );
        }
        return $c;
    } );
    $xml = mmgrf_render_feed( mmgrf_get_profile( 'newsbreak' ), mmgrf_network_options( 'newsbreak' ), [] );
    expect_eq( substr_count( $xml, '<item>' ), 1, 'the healthy post still ships' );
    $entry = array_values( array_filter( mmgrf_skip_log_get(), fn( $e ) => $e['code'] === 'render_error' ) );
    expect_eq( count( $entry ), 1 );
    expect_eq( $entry[0]['post_id'], $bad );
    expect_contains( $entry[0]['detail'], 'shortcode blew up' );
    expect_not_contains( $entry[0]['detail'], __DIR__, 'no absolute server path in an author-visible log' );
} );

t( 'r5: the integrator post-content hook fires even when the content build throws', function() {
    fixture_post();
    $fired = 0;
    add_action( 'mmgrf_post_content', function() use ( &$fired ) { $fired++; } );
    add_filter( 'the_content', function( $c ) { throw new RuntimeException( 'x' ); } );
    mmgrf_render_feed( mmgrf_get_profile( 'newsbreak' ), mmgrf_network_options( 'newsbreak' ), [] );
    expect_eq( $fired, 1, 'a plugin unhooked in pre_content must always be re-hooked' );
} );

t( 'r5: output echoed during a render never reaches the feed document', function() {
    fixture_post();
    add_filter( 'the_content', function( $c ) { echo 'STRAY NOTICE'; return $c; } );
    ob_start();
    $xml  = mmgrf_render_feed( mmgrf_get_profile( 'newsbreak' ), mmgrf_network_options( 'newsbreak' ), [] );
    $leak = ob_get_clean();
    expect_eq( $leak, '', 'nothing printed ahead of the XML declaration' );
    expect_not_contains( $xml, 'STRAY NOTICE' );
    expect_eq( substr( $xml, 0, 5 ), '<?xml' );
} );

// ── Plain-text fields ────────────────────────────────────────────

t( 'r5: term and author names stored entity-encoded reach the feed decoded once', function() {
    $id = fixture_post();
    // What WordPress actually returns for "Texas A&M" / "Staff & Wire".
    $GLOBALS['mmgrf_test']['terms']['category']['texas-am'] = (object) [ 'term_id' => 771, 'name' => 'Texas A&amp;M Aggies', 'slug' => 'texas-am' ];
    $GLOBALS['mmgrf_test']['post_terms'][ $id ]['category'][] = 'texas-am';
    $GLOBALS['mmgrf_test']['terms']['post_tag']['qa'] = (object) [ 'term_id' => 772, 'name' => 'Q&amp;A', 'slug' => 'qa' ];
    $GLOBALS['mmgrf_test']['post_terms'][ $id ]['post_tag'][] = 'qa';
    $GLOBALS['mmgrf_test']['authors'][1] = 'Staff &amp; Wire Reports';
    $item = mmgrf_build_items( mmgrf_get_profile( 'newsbreak' ), mmgrf_network_options( 'newsbreak' ), [] )[0];
    expect_eq( $item['categories'], [ 'Texas A&M Aggies' ] );
    expect_eq( $item['tags'], [ 'Q&A' ] );
    expect_eq( $item['author'], 'Staff & Wire Reports' );
    $xml = mmgrf_render_feed( mmgrf_get_profile( 'newsbreak' ), mmgrf_network_options( 'newsbreak' ), [] );
    expect_not_contains( $xml, '&amp;amp;', 'no double-encoding anywhere' );
    expect_not_contains( $xml, 'A&amp;M Aggies]]>', 'no literal entity inside CDATA' );
} );

t( 'r5: markup in a headline, manual excerpt, caption or credit never ships as text', function() {
    $id = fixture_post( [
        'post_title'   => 'Why <em>This</em> Game Matters More Than The Last One',
        'post_excerpt' => 'Read <a href="https://example-brand.com/x">our <strong>full</strong> preview</a> here today',
    ] );
    update_post_meta( $id, 'photo_credit', 'Photo: <a href="https://x.test">Jane Doe</a>/Getty Images' );
    $item = mmgrf_build_items( mmgrf_get_profile( 'yahoo' ), mmgrf_network_options( 'yahoo' ), [] )[0];
    expect_eq( $item['title'], 'Why This Game Matters More Than The Last One' );
    expect_eq( $item['excerpt'], 'Read our full preview here today' );
    expect_eq( $item['image']['credit'], 'Photo: Jane Doe/Getty Images' );
} );

t( 'r5: a literal angle bracket in a headline is not mistaken for markup', function() {
    expect_eq( mmgrf_text_field( 'Lakers <3 their chances, 5 < 7 and A &amp; B' ), 'Lakers <3 their chances, 5 < 7 and A & B' );
} );

t( 'r5: the auto-excerpt skips a lead photo caption and credit', function() {
    fixture_post( [ 'post_content' => '<figure class="wp-block-image"><img src="https://example-brand.com/a.jpg"><figcaption>Mandatory Credit: Kirby Lee-Imagn Images</figcaption></figure><p>The Cowboys opened the second half with a statement drive.</p>' ] );
    $item = mmgrf_build_items( mmgrf_get_profile( 'newsbreak' ), mmgrf_network_options( 'newsbreak' ), [] )[0];
    expect_eq( $item['excerpt'], 'The Cowboys opened the second half with a statement drive.' );
} );

t( 'r5: byline plugins hooked on the_author are honoured', function() {
    fixture_post();
    add_filter( 'the_author', fn( $name ) => 'Jane Doe and John Roe' );
    $item = mmgrf_build_items( mmgrf_get_profile( 'newsbreak' ), mmgrf_network_options( 'newsbreak' ), [] )[0];
    expect_eq( $item['author'], 'Jane Doe and John Roe' );
} );

t( 'r5: site name and tagline stored entity-encoded do not double-escape the channel', function() {
    $GLOBALS['mmgrf_test']['blog']['name']        = 'Bob&#039;s Sports &amp; More';
    $GLOBALS['mmgrf_test']['blog']['description'] = 'Scores &amp; stories';
    fixture_post();
    $xml = mmgrf_render_feed( mmgrf_get_profile( 'yahoo' ), mmgrf_network_options( 'yahoo' ), [] );
    $sx  = simplexml_load_string( $xml );
    expect_eq( (string) $sx->channel->title, "Bob's Sports & More" );
    expect_eq( (string) $sx->channel->description, 'Scores & stories' );
} );

// ── Attachment resolution ────────────────────────────────────────

t( 'r5: a rendition of a "-scaled" big-image original resolves to its attachment', function() {
    // WordPress stores uploads over 2560px as photo-scaled.jpg while the
    // renditions are photo-1024x683.jpg — stripping the size suffix alone
    // yields photo.jpg, which matches nothing.
    $GLOBALS['mmgrf_test']['url_to_attachment']['https://example-brand.com/up/wire-scaled.jpg'] = 8801;
    expect_eq( mmgrf_find_attachment( 'https://example-brand.com/up/wire-1024x683.jpg' ), 8801 );
    expect_eq( mmgrf_find_attachment( 'https://example-brand.com/up/wire-scaled.jpg' ), 8801 );
} );

t( 'r5: query strings, entity-encoded URLs and CDN hosts still resolve; foreign URLs cost no lookup', function() {
    $GLOBALS['mmgrf_test']['upload_baseurl'] = 'https://example-brand.com/wp-content/uploads';
    $GLOBALS['mmgrf_test']['url_to_attachment']['https://example-brand.com/wp-content/uploads/2026/11/hero.jpg'] = 8802;
    expect_eq( mmgrf_find_attachment( 'https://example-brand.com/wp-content/uploads/2026/11/hero-1024x576.jpg?ver=3&#038;x=1' ), 8802, 'query + entity' );
    expect_eq( mmgrf_find_attachment( 'https://i0.wp.com/example-brand.com/wp-content/uploads/2026/11/hero.jpg?resize=1024%2C576&ssl=1' ), 8802, 'photon' );
    expect_eq( mmgrf_find_attachment( '//cdn.example-brand.com/wp-content/uploads/2026/11/hero-300x200.jpg' ), 8802, 'cdn host' );
    $before = $GLOBALS['mmgrf_test']['url_lookups'] ?? 0;
    expect_eq( mmgrf_find_attachment( 'https://pbs.twimg.com/media/abc.jpg' ), 0 );
    expect_eq( $GLOBALS['mmgrf_test']['url_lookups'] ?? 0, $before, 'no database lookup for a URL outside the uploads tree' );
} );

t( 'r5: the byte ceiling is judged on the rendition actually served', function() {
    // 9 MB original; its 2048px rendition is 600 KB and must qualify.
    mmgrf_test_add_attachment( 8810, [
        'full'      => [ 'https://example-brand.com/up/big.png', 6000, 4000 ],
        '2048x2048' => [ 'https://example-brand.com/up/big-2048x1365.png', 2048, 1365 ],
    ], 'image/png', [ 'fake_filesize' => 9 * 1048576 ] );
    $img = mmgrf_attachment_image_data( 8810, 'full' );
    expect_eq( $img['filesize'], 9 * 1048576 );
    $fit = mmgrf_pick_image_fit( $img, 1280, 720, 5 * 1048576 );
    expect_eq( $fit['url'], 'https://example-brand.com/up/big-2048x1365.png', 'falls back to the rendition' );
    // …and a rendition KNOWN to be over the limit is not assumed to fit.
    $img['candidates'][0]['filesize'] = 6 * 1048576;
    expect_eq( mmgrf_pick_image_fit( $img, 1280, 720, 5 * 1048576 ), null );
} );

// ── Cache ────────────────────────────────────────────────────────

t( 'r5: the feed cache keeps ONE row per feed (no orphaned row per publish)', function() {
    $id = fixture_post();
    mmgrf_get_feed_output( 'yahoo' );
    $rows = count( $GLOBALS['mmgrf_test']['transients'] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->post_modified_gmt = gmdate( 'Y-m-d H:i:s', time() - 3600 );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->post_title        = 'A Retitled Headline That Is Long Enough For Everyone';
    $xml = mmgrf_get_feed_output( 'yahoo' );
    expect_contains( $xml, 'A Retitled Headline', 'a post edit busts the cache immediately' );
    expect_eq( count( $GLOBALS['mmgrf_test']['transients'] ), $rows, 'same row reused' );
} );

t( 'r5: a rebuild that throws serves the last good copy instead of an error', function() {
    $id   = fixture_post();
    $good = mmgrf_cached_render( 'unit|a', fn() => '<rss>good</rss>', 'yahoo' );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->post_modified_gmt = gmdate( 'Y-m-d H:i:s', time() - 3600 ); // invalidate
    $out  = mmgrf_cached_render( 'unit|a', function() { throw new RuntimeException( 'db went away' ); }, 'yahoo' );
    expect_eq( $out, $good );
    expect_true( in_array( 'render_error', r5_codes( 'yahoo' ), true ), 'and the failure is recorded' );
} );

t( 'r5: with no last good copy a failing render is not papered over', function() {
    fixture_post();
    $thrown = false;
    try {
        mmgrf_cached_render( 'unit|b', function() { throw new RuntimeException( 'x' ); } );
    } catch ( RuntimeException $e ) {
        $thrown = true;
    }
    expect_true( $thrown );
} );

t( 'r5: a logged-in viewer neither reads nor writes the shared feed cache', function() {
    fixture_post();
    $GLOBALS['mmgrf_test']['logged_in'] = true;
    $calls = 0;
    $fn    = function() use ( &$calls ) { $calls++; return '<rss>editor view</rss>'; };
    mmgrf_cached_render( 'unit|c', $fn );
    mmgrf_cached_render( 'unit|c', $fn );
    expect_eq( $calls, 2, 'rendered for the viewer each time' );
    expect_eq( count( $GLOBALS['mmgrf_test']['transients'] ), 0, 'never stored for partners to receive' );
} );

// ── Conditional GET ──────────────────────────────────────────────

t( 'r5: WordPress core is given the plugin last-modified on our feed routes only', function() {
    // Core answers If-Modified-Since / If-None-Match itself, before the feed
    // callback runs, from get_lastpostmodified() alone.
    fixture_post();
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    $posts   = get_lastpostmodified( 'GMT' );
    $touched = time() - 3600;
    update_option( 'mmgrf_config_touched', $touched );

    $GLOBALS['wp'] = (object) [ 'query_vars' => [ 'feed' => 'yahoo' ] ];
    $reported = gmdate( 'Y-m-d H:i:s', $touched + 60 ); // the change plus the settle window
    expect_eq( mmgrf_filter_lastpostmodified( $posts, 'GMT', 'any' ), $reported, 'settings save is visible to core' );
    expect_eq( mmgrf_filter_lastpostmodified( $posts, 'blog', 'any' ), $posts, 'only the GMT form core uses is rewritten' );

    $GLOBALS['wp']->query_vars['feed'] = 'yahoo-slideshows';
    expect_eq( mmgrf_filter_lastpostmodified( $posts, 'GMT', 'any' ), $reported, 'typed feeds too' );

    // The plugin's own read of the raw post timestamp passes through the
    // filter untouched (otherwise the window would be added twice).
    $GLOBALS['mmgrf_lastmod_raw'] = 1;
    expect_eq( mmgrf_filter_lastpostmodified( $posts, 'GMT', 'any' ), $posts );
    $GLOBALS['mmgrf_lastmod_raw'] = 0;

    $GLOBALS['wp']->query_vars['feed'] = 'rss2';
    expect_eq( mmgrf_filter_lastpostmodified( $posts, 'GMT', 'any' ), $posts, 'the site main feed is left alone' );
    unset( $GLOBALS['wp'] );
    expect_eq( mmgrf_filter_lastpostmodified( $posts, 'GMT', 'any' ), $posts, 'ordinary page views are left alone' );
} );

t( 'r5: unpublishing a post advances last-modified, so the retraction is not hidden behind a 304', function() {
    $id     = fixture_post();
    $before = mmgrf_feed_lastmod();
    $post   = get_post( $id );
    mmgrf_on_transition_post_status( 'draft', 'draft', $post );
    expect_eq( mmgrf_feed_lastmod(), $before, 'a draft-to-draft save is not feed-affecting' );
    mmgrf_on_transition_post_status( 'trash', 'publish', $post );
    expect_true( mmgrf_feed_lastmod() > $before );
    expect_false(
        mmgrf_feed_not_modified( gmdate( 'D, d M Y H:i:s', $before ) . ' GMT', mmgrf_feed_lastmod() ),
        'a partner that cached before the unpublish gets a full body'
    );
} );

t( 'r5: a media-library edit advances last-modified (rights flag, credit, replaced file)', function() {
    fixture_post();
    $before = mmgrf_feed_lastmod();
    mmgrf_on_media_change( 9001 );
    expect_true( mmgrf_feed_lastmod() > $before );
} );

t( 'r5: feed entry point sends headers, honours If-Modified-Since, and emits the document', function() {
    fixture_post();
    update_option( 'mmgrf_networks', [ 'newsbreak' => [ 'enabled' => 1 ] ] );
    mmgrf_register_all_feeds();
    $cb = $GLOBALS['mmgrf_test']['feeds']['newsbreak'];

    ob_start();
    @$cb();
    $body = ob_get_clean();
    expect_eq( substr( $body, 0, 5 ), '<?xml' );
    expect_eq( substr_count( $body, '<item>' ), 1 );
    expect_eq( $GLOBALS['mmgrf_test']['status_header'], null, 'no 304 for an unconditional request' );

    $_SERVER['HTTP_IF_MODIFIED_SINCE'] = gmdate( 'D, d M Y H:i:s', time() ) . ' GMT';
    ob_start();
    @$cb();
    $body = ob_get_clean();
    expect_eq( $GLOBALS['mmgrf_test']['status_header'], 304 );
    expect_eq( $body, '', 'a 304 carries no body' );
} );

t( 'r5: a tag feed entry point carries only its tagged posts', function() {
    $in  = fixture_post( [ 'post_title' => 'The Tagged Nascar Story Headline Goes Here' ] );
    $out = fixture_post( [ 'post_title' => 'An Unrelated Story Headline Goes Right Here' ] );
    mmgrf_test_set_terms( $in, 'post_tag', [ 'nascar' ] );
    update_option( 'mmgrf_tag_feeds', [ [ 'slug' => 'nascar', 'title' => 'NASCAR', 'tags' => 'nascar', 'post_count' => 10 ] ] );
    mmgrf_register_all_feeds();
    ob_start();
    @$GLOBALS['mmgrf_test']['feeds']['nascar-raw-feed']();
    $body = ob_get_clean();
    expect_contains( $body, 'The Tagged Nascar Story' );
    expect_not_contains( $body, 'An Unrelated Story' );
} );

// ── Skip log ─────────────────────────────────────────────────────

t( 'r5: several same-code notes for one post coexist and settle to zero writes', function() {
    $batch = function( $details ) {
        mmgrf_skip_log_begin();
        foreach ( $details as $d ) {
            mmgrf_skip_log_add( 'yahoo', 5, 'Post', 'inline_image_below_minimum', $d, 'warn' );
        }
        mmgrf_skip_log_end();
    };
    $batch( [ 'a.jpg 900x600', 'b.jpg 800x500', 'c.jpg 640x480' ] );
    expect_eq( count( mmgrf_skip_log_get() ), 3, 'all three images visible, not just the last' );
    expect_eq( $GLOBALS['mmgrf_test']['option_writes']['mmgrf_skip_log'], 1, 'one write for the whole render' );

    $batch( [ 'a.jpg 900x600', 'b.jpg 800x500', 'c.jpg 640x480' ] );
    expect_eq( $GLOBALS['mmgrf_test']['option_writes']['mmgrf_skip_log'], 1, 'steady state: no write per poll' );

    $batch( [ 'a.jpg 900x600' ] ); // editor fixed two of them
    $details = array_column( mmgrf_skip_log_get(), 'detail' );
    expect_eq( $details, [ 'a.jpg 900x600' ], 'resolved notes drop out' );
} );

t( 'r5: a whole feed render writes the skip log at most once', function() {
    for ( $i = 0; $i < 5; $i++ ) {
        fixture_post( [ 'post_content' => '<p>too short</p>', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( $i + 1 ) * 86400 ) ] );
    }
    mmgrf_render_feed( mmgrf_get_profile( 'yahoo' ), mmgrf_network_options( 'yahoo' ), [] );
    expect_eq( count( mmgrf_skip_log_get() ), 5 );
    expect_eq( $GLOBALS['mmgrf_test']['option_writes']['mmgrf_skip_log'], 1 );
} );

// ── Rewrites ─────────────────────────────────────────────────────

t( 'r5: route self-heal runs on wp_loaded with an autoloaded signature and retires the old option', function() {
    update_option( 'mmgrf_rewrite_sig', 'legacy' );
    update_option( 'mmgrf_networks', [ 'newsbreak' => [ 'enabled' => 1 ] ] );
    mmgrf_heal_rewrites();
    expect_eq( $GLOBALS['mmgrf_test']['flush_count'], 1 );
    expect_eq( get_option( 'mmgrf_rewrite_sig', 'gone' ), 'gone' );
    mmgrf_heal_rewrites();
    expect_eq( $GLOBALS['mmgrf_test']['flush_count'], 1, 'stable config never re-flushes' );
    expect_true( in_array( 'newsbreak', mmgrf_registered_feed_slugs(), true ) );
} );
