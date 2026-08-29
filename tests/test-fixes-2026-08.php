<?php
// Fix spec 2026-08-18: acceptance tests, one block per fix.

// ── Fix 7: channel <description> never empty ─────────────────────

t( 'fix7: empty tagline falls back to site name in channel description', function() {
    $GLOBALS['mmgrf_test']['blog']['description'] = '';
    $opts = mmgrf_network_options( 'yahoo' );
    expect_eq( $opts['feed_description'], 'Example Brand', 'site name fallback' );
    $xml = mmgrf_render_feed( mmgrf_get_profile( 'yahoo' ), $opts, [] );
    expect_contains( $xml, '<description>Example Brand</description>' );
    expect_not_contains( $xml, '<description></description>' );
} );

t( 'fix7: configured description wins over both fallbacks', function() {
    $GLOBALS['mmgrf_test']['blog']['description'] = '';
    update_option( 'mmgrf_options', [ 'feed_description' => 'Custom network description' ] );
    expect_eq( mmgrf_network_options( 'yahoo' )['feed_description'], 'Custom network description' );
} );

// ── Fix 6: skip log — stable codes, detail field, visibility ─────

t( 'fix6: log entries carry a stable code and separate detail', function() {
    mmgrf_skip_log_add( 'yahoo', 5, 'Post Five', 'body_below_word_floor', '87 words after sanitization' );
    $log = mmgrf_skip_log_get();
    expect_eq( $log[0]['code'], 'body_below_word_floor' );
    expect_eq( $log[0]['detail'], '87 words after sanitization' );
} );

t( 'fix6: yahoo validator emits codes through the render pipeline', function() {
    $id = mmgrf_test_add_post( [
        'post_title'   => 'A Headline Comfortably Above Twenty Characters',
        'post_content' => '<p>much too short a body</p>',
        'post_date_gmt'=> gmdate( 'Y-m-d H:i:s', time() - 3600 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
    ] );
    mmgrf_render_feed( mmgrf_get_profile( 'yahoo' ), mmgrf_network_options( 'yahoo' ), [] );
    $log = mmgrf_skip_log_get();
    expect_eq( $log[0]['code'], 'body_below_word_floor' );
    expect_contains( $log[0]['detail'], '5', 'real word count in detail' );
} );

t( 'fix6: dedupe keyed on network+post+code', function() {
    mmgrf_skip_log_add( 'yahoo', 9, 'P', 'image_below_minimum', '1099x735' );
    mmgrf_skip_log_add( 'yahoo', 9, 'P', 'image_below_minimum', '1099x735' );
    mmgrf_skip_log_add( 'yahoo', 9, 'P', 'body_below_word_floor', '10 words' );
    expect_eq( count( mmgrf_skip_log_get() ), 2 );
} );

t( 'fix6: non-admin visibility filtered to own posts', function() {
    $mine   = mmgrf_test_add_post( [ 'post_author' => 7 ] );
    $theirs = mmgrf_test_add_post( [ 'post_author' => 8 ] );
    mmgrf_skip_log_add( 'yahoo', $mine, 'Mine', 'no_featured_image' );
    mmgrf_skip_log_add( 'yahoo', $theirs, 'Theirs', 'no_featured_image' );
    $admin_view  = mmgrf_skip_log_visible_entries( 7, true );
    $author_view = mmgrf_skip_log_visible_entries( 7, false );
    expect_eq( count( $admin_view ), 2, 'admin sees all' );
    expect_eq( count( $author_view ), 1, 'author sees own only' );
    expect_eq( $author_view[0]['post_id'], $mine );
} );

// ── Fix 2 amendment: unknown-dimension recovery ──────────────────

function fix2_post_with_dimensionless_attachment( $file_path, $real_dims ) {
    $id = mmgrf_test_add_post( [
        'post_title'    => 'A Headline Comfortably Above Twenty Characters Long',
        'post_content'  => '<p>' . implode( ' ', array_fill( 0, 200, 'word' ) ) . '</p>',
        'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
    ] );
    // Attachment whose metadata carries no dimensions (API side-load).
    mmgrf_test_add_attachment( 9600, [ 'full' => [ 'https://example-brand.com/chastain.webp', 0, 0 ] ], 'image/webp', [ 'file' => $file_path ] );
    if ( $real_dims && $file_path ) {
        $GLOBALS['mmgrf_test']['file_dims'][ $file_path ] = $real_dims;
    }
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9600;
    return $id;
}

t( 'fix2: 0x0 metadata with a readable >=1280 file is recovered and ships (no log)', function() {
    fix2_post_with_dimensionless_attachment( '/uploads/chastain.webp', [ 2048, 1365 ] );
    $xml = mmgrf_render_feed( mmgrf_get_profile( 'yahoo' ), mmgrf_network_options( 'yahoo' ), [] );
    expect_contains( $xml, 'chastain.webp', 'image recovered and shipped' );
    expect_eq( count( mmgrf_skip_log_get() ), 0, 'expected recovery logs nothing' );
} );

t( 'fix2: unreadable file drops the image with image_dimensions_unknown; item ships', function() {
    fix2_post_with_dimensionless_attachment( false, null ); // no file at all
    $xml = mmgrf_render_feed( mmgrf_get_profile( 'yahoo' ), mmgrf_network_options( 'yahoo' ), [] );
    expect_eq( substr_count( $xml, '<item>' ), 1, 'item still ships' );
    expect_not_contains( $xml, 'chastain.webp' );
    $log = mmgrf_skip_log_get();
    expect_eq( $log[0]['code'], 'image_dimensions_unknown' );
    expect_contains( $log[0]['detail'], '9600', 'attachment id in detail' );
} );

t( 'fix2: recovered dimensions still respect the floor (small real file dropped as below_minimum)', function() {
    fix2_post_with_dimensionless_attachment( '/uploads/chastain.webp', [ 800, 450 ] );
    mmgrf_render_feed( mmgrf_get_profile( 'yahoo' ), mmgrf_network_options( 'yahoo' ), [] );
    $log = mmgrf_skip_log_get();
    expect_eq( $log[0]['code'], 'image_below_minimum', 'recovered dims gate normally' );
    expect_contains( $log[0]['detail'], '800x450' );
} );

// ── Amendment: default-feed enhancement scoped to core feeds only ─

t( 'amendment: enhancement callbacks no-op inside a non-core feed (legacy newsbreak)', function() {
    $id = mmgrf_test_add_post();
    mmgrf_test_add_attachment( 9700, [ 'large' => [ 'https://example-brand.com/l.jpg', 1024, 576 ] ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9700;
    $GLOBALS['post'] = get_post( $id );

    // Simulate rendering inside a third-party custom feed reusing the rss2 template.
    $GLOBALS['mmgrf_test']['query_vars']['feed'] = 'newsbreak';
    expect_eq( mmgrf_default_feed_prepend_image( '<p>body</p>' ), '<p>body</p>', 'no figure injected into foreign feed' );
    ob_start(); mmgrf_default_feed_media_ns(); $ns = ob_get_clean();
    expect_eq( $ns, '', 'no namespace echoed into foreign feed' );
    ob_start(); mmgrf_default_feed_media_tags(); $tags = ob_get_clean();
    expect_eq( $tags, '', 'no media tags echoed into foreign feed' );

    // Core rss2 feed: everything still fires.
    $GLOBALS['mmgrf_test']['query_vars']['feed'] = 'rss2';
    expect_contains( mmgrf_default_feed_prepend_image( '<p>body</p>' ), '<figure>', 'core feed still enhanced' );
    ob_start(); mmgrf_default_feed_media_ns(); $ns2 = ob_get_clean();
    expect_contains( $ns2, 'xmlns:media' );
    expect_eq( substr( $ns2, 0, 1 ), ' ', 'leading space prevents attribute jam' );
} );

// ── Fix 3: media:content always emitted with the lead image ──────

t( 'fix3: exactly one media:content per imaged item; none for imageless; no empty description', function() {
    $p = mmgrf_get_profile( 'yahoo' );
    $imaged = $p->render_item( $p->prepare_item( mk_item() ), profile_opts() );
    expect_eq( substr_count( $imaged, '<media:content' ), 1 );

    $no_credit = mk_item();
    $no_credit['image']['credit']  = '';
    $no_credit['image']['caption'] = '';
    $xml = $p->render_item( $p->prepare_item( $no_credit ), profile_opts() );
    expect_not_contains( $xml, '<media:description', 'no empty description element' );
    expect_contains( $xml, '<media:content' );

    $imageless = $p->render_item( $p->prepare_item( mk_item( [ 'image' => null ] ) ), profile_opts() );
    expect_not_contains( $imageless, '<media:', 'no empty media elements' );
} );

t( 'fix3: webp lead image declares image/webp in media:content type', function() {
    $item = mk_item();
    $item['image']['url']  = 'https://example-brand.com/img/lead.webp';
    $item['image']['type'] = 'image/webp';
    $xml = mmgrf_get_profile( 'yahoo' )->render_item( mmgrf_get_profile( 'yahoo' )->prepare_item( $item ), profile_opts() );
    expect_contains( $xml, 'type="image/webp"' );
} );

// ── Fix 9: dimension gate on inline body images (Yahoo) ──────────

function fix9_attachment( $att_id, $base ) {
    // Original 1600x1067 with a 2048 rendition and the small size WP inserted.
    mmgrf_test_add_attachment( $att_id, [
        'full'      => [ "$base.jpg", 1600, 1067 ],
        '2048x2048' => [ "$base-2048x1365.jpg", 2048, 1365 ],
        'large'     => [ "$base-1024x683.jpg", 1024, 683 ],
    ] );
    $GLOBALS['mmgrf_test']['url_to_attachment'][ "$base.jpg" ] = $att_id;
}

function fix9_item( $body ) {
    $item = mk_item( [ 'content' => $body . '<p>' . implode( ' ', array_fill( 0, 200, 'word' ) ) . '</p>' ] );
    $p = mmgrf_get_profile( 'yahoo' );
    return [ $p, $p->prepare_item( $item, profile_opts() ) ];
}

t( 'fix9: undersized inline rendition rewritten to a qualifying one with corrected attrs', function() {
    fix9_attachment( 9800, 'https://example-brand.com/img/wire' );
    [ $p, $item ] = fix9_item( '<figure><img width="1024" height="683" src="https://example-brand.com/img/wire-1024x683.jpg" alt="a"><figcaption>credit</figcaption></figure>' );
    $html = $item['san']['html'];
    expect_contains( $html, 'src="https://example-brand.com/img/wire.jpg"', 'rewritten to the largest qualifying rendition (full, 1600x1067)' );
    expect_not_contains( $html, 'wire-1024x683.jpg' );
    expect_contains( $html, 'width="1600" height="1067"', 'attrs corrected to the chosen rendition' );
    expect_contains( $html, '<figcaption>credit</figcaption>', 'figure kept' );
} );

t( 'fix9: inline image with no qualifying rendition removed with its whole figure; logged', function() {
    mmgrf_test_add_attachment( 9801, [ 'full' => [ 'https://example-brand.com/img/small.jpg', 900, 600 ] ] );
    $GLOBALS['mmgrf_test']['url_to_attachment']['https://example-brand.com/img/small.jpg'] = 9801;
    [ $p, $item ] = fix9_item( '<figure><img src="https://example-brand.com/img/small-600x400.jpg"><figcaption>orphan credit</figcaption></figure>' );
    $html = $item['san']['html'];
    expect_not_contains( $html, 'small-600x400.jpg' );
    expect_not_contains( $html, 'orphan credit', 'no orphaned figcaption' );
    $codes = array_column( $item['inline_image_log'] ?? [], 'code' );
    expect_true( in_array( 'inline_image_below_minimum', $codes, true ), 'logged' );
} );

t( 'fix9: external hotlinked image untouched but logged unresolvable', function() {
    [ $p, $item ] = fix9_item( '<p><img src="https://cdn.elsewhere.com/pic.jpg"></p>' );
    expect_contains( $item['san']['html'], 'cdn.elsewhere.com/pic.jpg' );
    $codes = array_column( $item['inline_image_log'] ?? [], 'code' );
    expect_true( in_array( 'inline_image_unresolvable', $codes, true ) );
} );

t( 'fix9: render pipeline logs inline events as warnings', function() {
    $id = mmgrf_test_add_post( [
        'post_title'    => 'A Headline Comfortably Above Twenty Characters Long',
        'post_content'  => '<p><img src="https://cdn.elsewhere.com/pic.jpg"></p><p>' . implode( ' ', array_fill( 0, 200, 'word' ) ) . '</p>',
        'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
    ] );
    mmgrf_test_add_attachment( 9802, [ 'full' => [ 'https://example-brand.com/lead.jpg', 1920, 1080 ] ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9802;
    mmgrf_render_feed( mmgrf_get_profile( 'yahoo' ), mmgrf_network_options( 'yahoo' ), [] );
    $log = mmgrf_skip_log_get();
    $codes = array_column( $log, 'code' );
    expect_true( in_array( 'inline_image_unresolvable', $codes, true ) );
    expect_eq( $log[ array_search( 'inline_image_unresolvable', $codes, true ) ]['level'], 'warn' );
} );

t( 'fix9: no duplicate lead when body already carries the featured attachment at another size', function() {
    fix9_attachment( 9803, 'https://example-brand.com/img/hero' );
    $id = mmgrf_test_add_post( [
        'post_title'    => 'A Headline Comfortably Above Twenty Characters Long',
        'post_content'  => '<figure><img width="1024" height="683" src="https://example-brand.com/img/hero-1024x683.jpg"></figure><p>' . implode( ' ', array_fill( 0, 200, 'word' ) ) . '</p>',
        'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
    ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9803;
    $xml = mmgrf_render_feed( mmgrf_get_profile( 'yahoo' ), mmgrf_network_options( 'yahoo' ), [] );
    preg_match( '/<content:encoded><!\[CDATA\[(.*?)\]\]>/s', $xml, $m );
    expect_eq( substr_count( $m[1], '<figure' ), 1, 'one figure only — same attachment not prepended twice' );
} );

t( 'fix9 amendment: body-scraped fallback image carries the real attachment mime', function() {
    mmgrf_test_add_attachment( 9804, [ 'full' => [ 'https://example-brand.com/img/pic.webp', 1600, 900 ] ], 'image/webp' );
    $GLOBALS['mmgrf_test']['url_to_attachment']['https://example-brand.com/img/pic.webp'] = 9804;
    $id = mmgrf_test_add_post( [ 'post_content' => '<img src="https://example-brand.com/img/pic.webp"><p>text body here</p>' ] );
    $items = mmgrf_build_items( mmgrf_get_profile( 'newsbreak' ), mmgrf_network_options( 'newsbreak' ), [] );
    expect_eq( $items[0]['image']['type'], 'image/webp' );
} );

// ── Fix 10: body h1 demoted to h2 (Yahoo) ────────────────────────

t( 'fix10: h1 renamed to h2 preserving children; other headings untouched', function() {
    $p = mmgrf_get_profile( 'yahoo' );
    $body = '<h1>Logano Wins <em>Richmond</em></h1><h2>Kept</h2><p>' . implode( ' ', array_fill( 0, 200, 'w' ) ) . '</p>';
    $item = $p->prepare_item( mk_item( [ 'content' => $body ] ), profile_opts() );
    $html = $item['san']['html'];
    expect_not_contains( $html, '<h1' );
    expect_contains( $html, '<h2>Logano Wins <em>Richmond</em></h2>' );
    expect_contains( $html, '<h2>Kept</h2>' );
} );

t( 'fix10: newsbreak profile does not demote h1 (yahoo-only)', function() {
    $p = mmgrf_get_profile( 'newsbreak' );
    $item = $p->prepare_item( mk_item( [ 'content' => '<h1>Headline</h1><p>body</p>' ] ), profile_opts() );
    expect_contains( $item['san']['html'], '<h1>Headline</h1>' );
} );

// ── Fix 4: prune empty and whitespace-only nodes (Yahoo) ─────────

function fix4_body( $html ) {
    $p = mmgrf_get_profile( 'yahoo' );
    $item = $p->prepare_item( mk_item( [ 'content' => $html . '<p>' . implode( ' ', array_fill( 0, 200, 'w' ) ) . '</p>' ] ), profile_opts() );
    return $item['san']['html'];
}

t( 'fix4: trailing <p><br></p> and bare <p></p> removed', function() {
    $html = fix4_body( '<p>Real text</p><p><br></p><p></p><p><br><br></p>' );
    expect_contains( $html, '<p>Real text</p>' );
    expect_not_contains( $html, '<p><br>' );
    expect_not_contains( $html, '<p></p>' );
} );

t( 'fix4: nested emptying — span emptied by attr-strip empties its parent within passes', function() {
    $html = fix4_body( '<p><span title="">   </span></p><p>kept</p>' );
    expect_not_contains( $html, '<span' );
    expect_not_contains( $html, '<p></p>' );
    expect_contains( $html, '<p>kept</p>' );
} );

t( 'fix4: mid-paragraph br preserved; runs collapsed to one', function() {
    $html = fix4_body( '<p>Race: Iowa Corn 350<br>Green flag: 3:30pm<br>TV: USA</p><p>a<br><br><br>b</p>' );
    expect_contains( $html, 'Iowa Corn 350<br>Green flag' );
    expect_match( $html, '/<p>a<br>\s*b<\/p>/' );
} );

t( 'fix4: empty attributes dropped, non-empty kept', function() {
    $html = fix4_body( '<p><a href="https://a.com/x" title="">link</a></p>' );
    expect_not_contains( $html, 'title=' );
    expect_contains( $html, 'href="https://a.com/x"' );
} );

t( 'fix4: list with no li removed; element containing only an image survives', function() {
    $html = fix4_body( '<ul></ul><p><img src="https://example-brand.com/i.jpg"></p>' );
    expect_not_contains( $html, '<ul>' );
    expect_contains( $html, '<img src="https://example-brand.com/i.jpg"' );
} );

t( 'fix4 (superseded r2): newsbreak now prunes too — legacy-parity rationale retired', function() {
    $p = mmgrf_get_profile( 'newsbreak' );
    $item = $p->prepare_item( mk_item( [ 'content' => '<p>text</p><p><br></p>' ] ), profile_opts() );
    expect_not_contains( $item['san']['html'], '<p><br></p>' );
    expect_contains( $item['san']['html'], '<p>text</p>' );
} );

// ── Fix 5: path-aware affiliate and commerce link guard ──────────

function fix5_opts() {
    return profile_opts( [
        'affiliate_domains'      => [ 'draftkings.com', 'fanduel.com', 'sportsbook.fanatics.com' ],
        'affiliate_paths'        => [ 'sportsbook', 'promo-code', 'promocode', '/betting/', '/aff/', '?tag=', 'utm_campaign=affiliate', '/affiliate/', '?affiliate=' ],
        'affiliate_domain_action'=> 'skip_item',
        'affiliate_path_action'  => 'unwrap',
    ] );
}

function fix5_item( $link_html ) {
    $p = mmgrf_get_profile( 'yahoo' );
    $body = '<p>' . implode( ' ', array_fill( 0, 160, 'word' ) ) . ' ' . $link_html . '</p>';
    return [ $p, $p->prepare_item( mk_item( [ 'content' => $body ] ), fix5_opts() ) ];
}

t( 'fix5: operator-domain link skips the whole item with affiliate_domain_matched', function() {
    [ $p, $item ] = fix5_item( '<a href="https://sportsbook.draftkings.com/promo">bet now</a>' );
    $v = $p->validate_item( $item );
    expect_false( $v['pass'] );
    expect_eq( $v['code'], 'affiliate_domain_matched' );
    expect_contains( $v['detail'], 'draftkings.com' );
} );

t( 'fix5: promo path on a legit domain unwraps the link, keeps text, ships item (live nypost case)', function() {
    [ $p, $item ] = fix5_item( '<a href="https://nypost.com/2026/08/11/betting/fanatics-sportsbook-promo-code-nypost26/">Fanatics promo</a>' );
    expect_true( $p->validate_item( $item )['pass'], 'item still ships' );
    expect_contains( $item['san']['html'], 'Fanatics promo', 'inner text preserved' );
    expect_not_contains( $item['san']['html'], 'nypost.com/2026/08/11/betting', 'href removed' );
    $codes = array_column( $item['affiliate_log'] ?? [], 'code' );
    expect_true( in_array( 'affiliate_path_matched', $codes, true ), 'logged' );
} );

t( 'fix5: ordinary external links and prose brand mentions untouched', function() {
    [ $p, $item ] = fix5_item( '<a href="https://www.espn.com/mlb/boxscore/_/gameId/401">box score</a> FanDuel Sportsbook offered odds.' );
    expect_true( $p->validate_item( $item )['pass'] );
    expect_contains( $item['san']['html'], 'href="https://www.espn.com/mlb/boxscore/_/gameId/401"' );
    expect_contains( $item['san']['html'], 'FanDuel Sportsbook offered odds' );
    expect_eq( $item['affiliate_log'] ?? [], [], 'nothing logged' );
} );

t( 'fix5: matches log through the render pipeline with pattern and URL', function() {
    $id = mmgrf_test_add_post( [
        'post_title'    => 'A Headline Comfortably Above Twenty Characters Long',
        'post_content'  => '<p>' . implode( ' ', array_fill( 0, 160, 'w' ) ) . ' <a href="https://nypost.com/betting/promo-code-x/">deal</a></p>',
        'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
    ] );
    mmgrf_test_add_attachment( 9900, [ 'full' => [ 'https://example-brand.com/lead9900.jpg', 1920, 1080 ] ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9900;
    mmgrf_render_feed( mmgrf_get_profile( 'yahoo' ), mmgrf_network_options( 'yahoo' ), [] );
    $log   = mmgrf_skip_log_get();
    $codes = array_column( $log, 'code' );
    expect_true( in_array( 'affiliate_path_matched', $codes, true ) );
    $e = $log[ array_search( 'affiliate_path_matched', $codes, true ) ];
    expect_contains( $e['detail'], 'promo-code' );
    expect_contains( $e['detail'], 'nypost.com' );
} );

t( 'fix5: default option seeds match the spec lists', function() {
    $opts = mmgrf_network_options( 'yahoo' );
    expect_true( in_array( 'draftkings.com', $opts['affiliate_domains'], true ), 'domain seed' );
    expect_true( in_array( 'espnbet.com', $opts['affiliate_domains'], true ), 'domain seed' );
    expect_true( in_array( 'promo-code', $opts['affiliate_paths'], true ), 'path seed' );
    expect_false( in_array( 'affiliate', $opts['affiliate_paths'], true ), 'bare affiliate deliberately excluded' );
    expect_eq( $opts['affiliate_domain_action'], 'skip_item' );
    expect_eq( $opts['affiliate_path_action'], 'unwrap' );
} );

// ── Fix 11: publish-time syndication checks (server side) ────────

function fix11_enable_yahoo() {
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
}

t( 'fix11: gating — disabled networks render nothing; enabled yahoo gates', function() {
    expect_false( mmgrf_syndication_gating()['enabled'], 'nothing enabled' );
    fix11_enable_yahoo();
    $g = mmgrf_syndication_gating();
    expect_true( $g['enabled'] );
    expect_eq( $g['networks'], [ 'yahoo' ] );
} );

t( 'fix11: clean post with a compliant featured image reports no issues', function() {
    fix11_enable_yahoo();
    $id = mmgrf_test_add_post( [ 'post_content' => '<p>fine body</p>' ] );
    mmgrf_test_add_attachment( 9910, [ 'full' => [ 'https://example-brand.com/big-scaled.jpg', 2560, 1707 ] ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9910;
    $r = mmgrf_syndication_check( $id );
    expect_true( $r['enabled'] );
    expect_eq( $r['issues'], [] );
} );

t( 'fix11: missing featured image warns with plain-language consequence', function() {
    fix11_enable_yahoo();
    $id = mmgrf_test_add_post( [ 'post_content' => '<p>text only body</p>' ] );
    $r = mmgrf_syndication_check( $id );
    expect_eq( $r['issues'][0]['code'], 'no_featured_image' );
    expect_eq( $r['issues'][0]['severity'], 'warning' );
    expect_contains( $r['issues'][0]['message'], 'Yahoo' );
    expect_contains( $r['issues'][0]['message'], 'without an image' );
} );

t( 'fix11: undersized featured image warns with real dimensions and detail', function() {
    fix11_enable_yahoo();
    $id = mmgrf_test_add_post( [ 'post_content' => '<p>b</p>' ] );
    mmgrf_test_add_attachment( 9911, [ 'full' => [ 'https://example-brand.com/wire-small.jpg', 1099, 735 ] ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9911;
    $r = mmgrf_syndication_check( $id );
    expect_eq( $r['issues'][0]['code'], 'image_below_minimum' );
    expect_contains( $r['issues'][0]['message'], '1099×735' );
    expect_eq( $r['issues'][0]['detail']['width'], 1099 );
    expect_eq( $r['issues'][0]['detail']['attachment_id'], 9911 );
} );

t( 'fix11: unverifiable featured image gets its own message', function() {
    fix11_enable_yahoo();
    $id = mmgrf_test_add_post();
    mmgrf_test_add_attachment( 9912, [ 'full' => [ 'https://example-brand.com/x.webp', 0, 0 ] ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9912;
    $r = mmgrf_syndication_check( $id );
    expect_eq( $r['issues'][0]['code'], 'image_dimensions_unknown' );
    expect_contains( $r['issues'][0]['message'], "can't be verified" );
} );

t( 'fix11: inline sub-minimum image counted; hotlinked image counted separately', function() {
    fix11_enable_yahoo();
    mmgrf_test_add_attachment( 9913, [ 'full' => [ 'https://example-brand.com/img/tiny.jpg', 800, 500 ] ] );
    $GLOBALS['mmgrf_test']['url_to_attachment']['https://example-brand.com/img/tiny.jpg'] = 9913;
    $id = mmgrf_test_add_post( [ 'post_content' => '<img src="https://example-brand.com/img/tiny.jpg"><img src="https://cdn.elsewhere.com/x.jpg"><p>body</p>' ] );
    mmgrf_test_add_attachment( 9914, [ 'full' => [ 'https://example-brand.com/lead.jpg', 1920, 1080 ] ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9914;
    $r = mmgrf_syndication_check( $id );
    $codes = array_column( $r['issues'], 'code' );
    expect_true( in_array( 'inline_image_below_minimum', $codes, true ) );
    expect_true( in_array( 'inline_image_unresolvable', $codes, true ) );
    $below = $r['issues'][ array_search( 'inline_image_below_minimum', $codes, true ) ];
    expect_contains( $below['message'], '1 image' );
    expect_contains( $below['message'], 'still appear on the site' );
} );

t( 'fix11: affiliate checks state their differing consequences', function() {
    fix11_enable_yahoo();
    $ok = mmgrf_test_add_attachment( 9915, [ 'full' => [ 'https://example-brand.com/l.jpg', 1920, 1080 ] ] );
    $a = mmgrf_test_add_post( [ 'post_content' => '<p><a href="https://www.draftkings.com/offer">bet</a></p>' ] );
    $GLOBALS['mmgrf_test']['posts'][ $a ]->thumbnail_id = 9915;
    $ra = mmgrf_syndication_check( $a );
    $codes = array_column( $ra['issues'], 'code' );
    $dom = $ra['issues'][ array_search( 'affiliate_domain_matched', $codes, true ) ];
    expect_contains( $dom['message'], 'entire article will be excluded' );

    $b = mmgrf_test_add_post( [ 'post_content' => '<p><a href="https://nypost.com/betting/promo-code-x/">deal</a></p>' ] );
    $GLOBALS['mmgrf_test']['posts'][ $b ]->thumbnail_id = 9915;
    $rb = mmgrf_syndication_check( $b );
    $codes = array_column( $rb['issues'], 'code' );
    $path = $rb['issues'][ array_search( 'affiliate_path_matched', $codes, true ) ];
    expect_contains( $path['message'], 'link will be removed' );
    expect_contains( $path['message'], 'still syndicate' );
} );

t( 'fix11: ordinary external link raises nothing', function() {
    fix11_enable_yahoo();
    $id = mmgrf_test_add_post( [ 'post_content' => '<p><a href="https://www.espn.com/mlb/boxscore/_/gameId/4">box</a></p>' ] );
    mmgrf_test_add_attachment( 9916, [ 'full' => [ 'https://example-brand.com/l.jpg', 1920, 1080 ] ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9916;
    expect_eq( mmgrf_syndication_check( $id )['issues'], [] );
} );

// ── Fix 2 follow-up: NewsBreak and unverifiable image dimensions ─

t( 'fix2-nb: dimension recovery is shared — newsbreak ships recovered dims', function() {
    $id = mmgrf_test_add_post( [ 'post_content' => '<p>body text here for the item</p>' ] );
    mmgrf_test_add_attachment( 9950, [ 'full' => [ 'https://example-brand.com/side.jpg', 0, 0 ] ], 'image/jpeg', [ 'file' => '/uploads/side.jpg' ] );
    $GLOBALS['mmgrf_test']['file_dims']['/uploads/side.jpg'] = [ 1920, 1080 ];
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9950;
    $xml = mmgrf_render_feed( mmgrf_get_profile( 'newsbreak' ), mmgrf_network_options( 'newsbreak' ), [] );
    expect_contains( $xml, 'width="1920" height="1080"', 'recovered dimensions in media elements' );
} );

t( 'fix2-nb: unverifiable image still ships (no drop), zero-dims omitted, warn logged', function() {
    $id = mmgrf_test_add_post( [ 'post_content' => '<p>body text here for the item</p>' ] );
    mmgrf_test_add_attachment( 9951, [ 'full' => [ 'https://example-brand.com/mystery.jpg', 0, 0 ] ] ); // no file
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 9951;
    $xml = mmgrf_render_feed( mmgrf_get_profile( 'newsbreak' ), mmgrf_network_options( 'newsbreak' ), [] );
    expect_eq( substr_count( $xml, '<item>' ), 1, 'item ships — NewsBreak declares no dimension floor' );
    expect_contains( $xml, 'mystery.jpg', 'image ships' );
    expect_not_contains( $xml, 'width="0"', 'nonsense zero attributes omitted' );
    $log = mmgrf_skip_log_get();
    expect_eq( $log[0]['code'], 'image_dimensions_unknown' );
    expect_eq( $log[0]['level'], 'warn' );
    expect_contains( $log[0]['detail'], 'shipped' );
} );

// ── Fix 8: atom:link self URL matches the served form ────────────

t( 'fix8: pretty permalinks yield pretty self URL; plain yields query form', function() {
    update_option( 'permalink_structure', '/%postname%/' );
    expect_eq( mmgrf_network_options( 'yahoo' )['feed_url'], 'https://example-brand.com/feed/yahoo/' );
    $xml = mmgrf_render_feed( mmgrf_get_profile( 'yahoo' ), mmgrf_network_options( 'yahoo' ), [] );
    expect_contains( $xml, '<atom:link href="https://example-brand.com/feed/yahoo/"' );

    update_option( 'permalink_structure', '' );
    expect_eq( mmgrf_network_options( 'yahoo' )['feed_url'], 'https://example-brand.com/?feed=yahoo' );
} );

t( 'fix8: legacy aigeon feed URL is unchanged by the helper', function() {
    update_option( 'permalink_structure', '/%postname%/' );
    expect_eq( mmgrf_network_options( 'aigeon' )['feed_url'], 'https://example-brand.com/?feed=raw-feed-raw-feed', 'legacy shape preserved' );
} );
