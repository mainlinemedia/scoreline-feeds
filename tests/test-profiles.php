<?php
// Profile validation gates + item rendering, per network. Items are hand-built
// in the pipeline's normalized shape, then run through prepare/validate/render.

function mk_item( $over = [] ) {
    $body = '<p>' . implode( ' ', array_fill( 0, 200, 'word' ) ) . '</p>';
    return array_merge( [
        'id'            => 42,
        'title'         => 'A Reasonable Headline For Testing Purposes',
        'short_title'   => '',
        'permalink'     => 'https://example-brand.com/story-slug/',
        'link'          => 'https://example-brand.com/story-slug/',
        'pub_ts'        => strtotime( '2026-08-01 12:00:00 UTC' ),
        'mod_ts'        => strtotime( '2026-08-01 12:00:00 UTC' ),
        'author'        => "O'Brien & Sons",
        'categories'    => [ 'News & Views' ],
        'tags'          => [ 'nfl' ],
        'score'         => 1.5,
        'excerpt'       => "Excerpt with & ampersand and 'quotes' in it",
        'content'       => $body,
        'image'         => [ 'url' => 'https://example-brand.com/img/lead.jpg', 'width' => 1600, 'height' => 900, 'type' => 'image/jpeg', 'filesize' => 250000, 'alt' => 'Lead alt', 'caption' => 'Lead caption', 'credit' => 'Getty via MMG', 'no_synd_rights' => false ],
        'comments_feed' => 'https://example-brand.com/story-slug/feed/',
        'comment_count' => 0,
    ], $over );
}

function profile_opts( $over = [] ) {
    return array_merge( [
        'feed_title'       => 'Example Brand',
        'feed_description' => 'Example description',
        'feed_link'        => 'https://example-brand.com',
        'feed_url'         => 'https://example-brand.com/?feed=test',
        'nb_scripts'       => '',
        'affiliate_domains'=> [],
    ], $over );
}

// ── Yahoo ────────────────────────────────────────────────────────

t( 'yahoo: body under 150 words post-sanitization is rejected with reason', function() {
    $p = new MMGRF_Profile_Yahoo();
    $item = $p->prepare_item( mk_item( [ 'content' => '<p>only a few words here</p>' ] ) );
    $v = $p->validate_item( $item );
    expect_false( $v['pass'] );
    expect_contains( $v['reason'], 'word' );
} );

t( 'yahoo: 150+ word body passes', function() {
    $p = new MMGRF_Profile_Yahoo();
    $item = $p->prepare_item( mk_item() );
    $v = $p->validate_item( $item );
    expect_true( $v['pass'], $v['reason'] ?? '' );
} );

t( 'yahoo: future-dated item is rejected', function() {
    $p = new MMGRF_Profile_Yahoo();
    $item = $p->prepare_item( mk_item( [ 'pub_ts' => time() + 86400 ] ) );
    $v = $p->validate_item( $item );
    expect_false( $v['pass'] );
} );

t( 'yahoo: short description rejected', function() {
    $p = new MMGRF_Profile_Yahoo();
    $item = $p->prepare_item( mk_item( [ 'excerpt' => 'two words' ] ) );
    expect_false( $p->validate_item( $item )['pass'] );
} );

t( 'yahoo: item with affiliate link is rejected when blocklist matches', function() {
    $p = new MMGRF_Profile_Yahoo();
    $body = '<p>' . implode( ' ', array_fill( 0, 160, 'word' ) ) . ' <a href="https://amzn.to/x">buy</a></p>';
    $item = $p->prepare_item( mk_item( [ 'content' => $body ] ), [ 'affiliate_domains' => [ 'amzn.to' ] ] );
    $v = $p->validate_item( $item );
    expect_false( $v['pass'] );
    expect_contains( $v['reason'], 'affiliate' );
} );

t( 'yahoo: lead figure inlined; NO media elements alongside (Yahoo flags the pair as duplicate photos)', function() {
    // Live regression evidence 2026-09-01: easysportz Yahoo portal warned
    // "Duplicate photos" on every item — inline figure + declared media tag
    // is the same photo twice. Yahoo spec: media:content is a fallback for
    // bodies with no image, never a companion.
    $p = new MMGRF_Profile_Yahoo();
    $item = $p->prepare_item( mk_item() );
    $xml = $p->render_item( $item, profile_opts() );
    expect_match( $xml, '/<content:encoded><!\[CDATA\[\s*<figure><img src="https:\/\/example-brand\.com\/img\/lead\.jpg"[^>]*alt="Lead alt"/' );
    expect_contains( $xml, '<figcaption>' );
    expect_not_contains( $xml, '<media:content', 'no duplicate declaration beside the inline figure' );
    expect_not_contains( $xml, '<media:thumbnail' );
} );

t( 'yahoo: sub-1280x720 image is dropped from item but item still ships', function() {
    $p = new MMGRF_Profile_Yahoo();
    $item = $p->prepare_item( mk_item( [ 'image' => [ 'url' => 'https://x.com/small.jpg', 'width' => 1024, 'height' => 576, 'type' => 'image/jpeg', 'filesize' => 5000, 'alt' => '', 'caption' => '', 'credit' => '', 'no_synd_rights' => false ] ] ) );
    expect_true( $p->validate_item( $item )['pass'] );
    $xml = $p->render_item( $item, profile_opts() );
    expect_not_contains( $xml, 'small.jpg' );
} );

t( 'yahoo: guid is stable clean permalink and pubDate is RFC822 GMT', function() {
    $p = new MMGRF_Profile_Yahoo();
    $item = $p->prepare_item( mk_item() );
    $xml = $p->render_item( $item, profile_opts() );
    expect_contains( $xml, '<guid isPermaLink="true">https://example-brand.com/story-slug/</guid>' );
    expect_contains( $xml, '<pubDate>Sat, 01 Aug 2026 12:00:00 +0000</pubDate>' );
} );

t( 'yahoo: updated element emitted only when genuinely modified', function() {
    $p = new MMGRF_Profile_Yahoo();
    $same = $p->render_item( $p->prepare_item( mk_item() ), profile_opts() );
    expect_not_contains( $same, '<updated>' );
    $mod = $p->render_item( $p->prepare_item( mk_item( [ 'mod_ts' => strtotime( '2026-08-02 09:00:00 UTC' ) ] ) ), profile_opts() );
    expect_contains( $mod, '<updated>Sun, 02 Aug 2026 09:00:00 +0000</updated>' );
} );

t( 'yahoo: style attributes never reach output', function() {
    $p = new MMGRF_Profile_Yahoo();
    $body = '<p style="color:red">' . implode( ' ', array_fill( 0, 160, 'word' ) ) . '</p>';
    $xml = $p->render_item( $p->prepare_item( mk_item( [ 'content' => $body ] ) ), profile_opts() );
    expect_not_contains( $xml, 'style=' );
} );

t( 'yahoo: namespace block matches published declaration', function() {
    $p = new MMGRF_Profile_Yahoo();
    $ns = $p->get_namespaces();
    expect_eq( $ns['media'], 'http://search.yahoo.com/rss' );
} );

// ── MSN ──────────────────────────────────────────────────────────

t( 'msn: title of 20 chars or fewer is rejected', function() {
    $p = new MMGRF_Profile_MSN();
    $item = $p->prepare_item( mk_item( [ 'title' => 'Too short a title' ] ) ); // 17 chars
    $v = $p->validate_item( $item );
    expect_false( $v['pass'] );
    expect_contains( $v['reason'], 'title' );
} );

t( 'msn: title over 150 chars rejected without short title, passes with one', function() {
    $p = new MMGRF_Profile_MSN();
    $long = str_repeat( 'Headline words here ', 9 ); // 180 chars
    expect_false( $p->validate_item( $p->prepare_item( mk_item( [ 'title' => $long ] ) ) )['pass'] );
    $with = $p->prepare_item( mk_item( [ 'title' => $long, 'short_title' => 'Short version' ] ) );
    expect_true( $p->validate_item( $with )['pass'] );
} );

t( 'msn: pubDate older than 365 days or in the future is rejected', function() {
    $p = new MMGRF_Profile_MSN();
    expect_false( $p->validate_item( $p->prepare_item( mk_item( [ 'pub_ts' => time() - 400 * 86400 ] ) ) )['pass'] );
    expect_false( $p->validate_item( $p->prepare_item( mk_item( [ 'pub_ts' => time() + 3600 ] ) ) )['pass'] );
} );

t( 'msn: imageless item rejected; rights-flagged image omitted causing rejection', function() {
    $p = new MMGRF_Profile_MSN();
    expect_false( $p->validate_item( $p->prepare_item( mk_item( [ 'image' => null, 'content' => '<p>no images at all in this body text</p>' ] ) ) )['pass'] );
    $img = [ 'url' => 'https://x.com/getty.jpg', 'width' => 1600, 'height' => 900, 'type' => 'image/jpeg', 'filesize' => 100, 'alt' => '', 'caption' => '', 'credit' => '', 'no_synd_rights' => true ];
    $item = $p->prepare_item( mk_item( [ 'image' => $img, 'content' => '<p>no inline image body</p>' ] ) );
    $v = $p->validate_item( $item );
    expect_false( $v['pass'] );
} );

t( 'msn: renders mi:shortTitle, dcterms:modified, non-permalink guid, clean link', function() {
    $p = new MMGRF_Profile_MSN();
    $item = $p->prepare_item( mk_item( [
        'title'       => 'A Headline Long Enough To Clear The Twenty Character Floor',
        'short_title' => 'Short headline',
        'pub_ts'      => time() - 7 * 86400,
        'mod_ts'      => time() - 6 * 86400,
    ] ) );
    $xml = $p->render_item( $item, profile_opts() );
    expect_contains( $xml, '<mi:shortTitle>Short headline</mi:shortTitle>' );
    expect_contains( $xml, '<dcterms:modified>' );
    expect_contains( $xml, '<guid isPermaLink="false">https://example-brand.com/story-slug/</guid>' );
    expect_contains( $xml, '<link>https://example-brand.com/story-slug/</link>' );
} );

t( 'msn: youtube iframe stripped from body, namespace map includes mi', function() {
    $p = new MMGRF_Profile_MSN();
    $body = '<p>' . implode( ' ', array_fill( 0, 30, 'word' ) ) . '</p><iframe src="https://www.youtube.com/embed/abc"></iframe>';
    $item = $p->prepare_item( mk_item( [ 'title' => 'A Headline Long Enough To Clear The Floor', 'pub_ts' => time() - 86400, 'mod_ts' => time() - 86400, 'content' => $body ] ) );
    $xml = $p->render_item( $item, profile_opts() );
    expect_not_contains( $xml, 'youtube.com' );
    expect_eq( $p->get_namespaces()['mi'], 'http://schemas.ingestion.microsoft.com/common/' );
} );

// ── NewsBreak ────────────────────────────────────────────────────

t( 'newsbreak: figure-prepended featured image with credit, enclosure, media trio', function() {
    $p = new MMGRF_Profile_NewsBreak();
    $item = $p->prepare_item( mk_item() );
    $xml = $p->render_item( $item, profile_opts() );
    expect_match( $xml, '/<content:encoded><!\[CDATA\[\s*<figure><img src="https:\/\/example-brand\.com\/img\/lead\.jpg" alt="Lead alt"\s*\/?><figcaption>Getty via MMG<\/figcaption><\/figure>/' );
    expect_contains( $xml, '<enclosure url="https://example-brand.com/img/lead.jpg" length="250000" type="image/jpeg"/>' );
    expect_contains( $xml, '<media:thumbnail url="https://example-brand.com/img/lead.jpg"' );
    expect_contains( $xml, '<media:title type="plain">Lead alt</media:title>' );
} );

t( 'newsbreak: guid is clean permalink, pubDate true GMT, creator CDATA unescaped', function() {
    $p = new MMGRF_Profile_NewsBreak();
    $xml = $p->render_item( $p->prepare_item( mk_item() ), profile_opts() );
    expect_contains( $xml, '<guid isPermaLink="true">https://example-brand.com/story-slug/</guid>' );
    expect_contains( $xml, '<pubDate>Sat, 01 Aug 2026 12:00:00 +0000</pubDate>' );
    expect_contains( $xml, "<dc:creator><![CDATA[O'Brien & Sons]]></dc:creator>", 'D1 fix: raw value inside CDATA' );
    expect_not_contains( $xml, '&amp; Sons', 'no double-escaping inside CDATA' );
} );

t( 'newsbreak: imageless item is rejected (image policy require)', function() {
    $p = new MMGRF_Profile_NewsBreak();
    $item = $p->prepare_item( mk_item( [ 'image' => null, 'content' => '<p>imageless body</p>' ] ) );
    expect_false( $p->validate_item( $item )['pass'] );
} );

t( 'newsbreak: inline body image satisfies the image requirement via cascade', function() {
    $p = new MMGRF_Profile_NewsBreak();
    // pipeline cascade resolves inline images into item[image]; profile-level test:
    // an item whose image came from the body cascade still passes.
    $item = $p->prepare_item( mk_item( [ 'image' => [ 'url' => 'https://example-brand.com/inline.jpg', 'width' => 800, 'height' => 600, 'type' => 'image/jpeg', 'filesize' => 0, 'alt' => '', 'caption' => '', 'credit' => '', 'no_synd_rights' => false ] ] ) );
    expect_true( $p->validate_item( $item )['pass'] );
} );

t( 'newsbreak: lazy-load attributes cleaned from body', function() {
    $p = new MMGRF_Profile_NewsBreak();
    $body = '<p>text body</p><img src="https://a.com/i.jpg" srcset="a 1x" loading="lazy" data-x="1">';
    $xml = $p->render_item( $p->prepare_item( mk_item( [ 'content' => $body ] ) ), profile_opts() );
    expect_not_contains( $xml, 'srcset' );
    expect_not_contains( $xml, 'loading=' );
    expect_not_contains( $xml, 'data-x' );
} );

t( 'newsbreak: nb namespace declared; nb:scripts emitted only when configured', function() {
    $p = new MMGRF_Profile_NewsBreak();
    expect_eq( $p->get_namespaces()['nb'], 'https://www.newsbreak.com/' );
    expect_eq( $p->get_channel_extras( profile_opts() ), '' );
    $extras = $p->get_channel_extras( profile_opts( [ 'nb_scripts' => '<script src="https://sb.scorecardresearch.com/x.js"></script>' ] ) );
    expect_contains( $extras, '<nb:scripts><![CDATA[' );
} );

// ── Aigeon (legacy parity) ───────────────────────────────────────

t( 'aigeon: item shape matches v2 — utm link, score category, wfw and slash retained', function() {
    $p = new MMGRF_Profile_Aigeon();
    $item = $p->prepare_item( mk_item( [ 'link' => 'https://example-brand.com/story-slug/?utm_source=feed&utm_medium=email' ] ) );
    $xml = $p->render_item( $item, profile_opts() );
    expect_contains( $xml, '<link>https://example-brand.com/story-slug/?utm_source=feed&amp;utm_medium=email</link>' );
    expect_contains( $xml, '<category domain="score">1.5</category>' );
    expect_contains( $xml, '<wfw:commentRss>' );
    expect_contains( $xml, '<slash:comments>0</slash:comments>' );
    expect_contains( $xml, '<category domain="tag"><![CDATA[nfl]]></category>' );
    expect_contains( $xml, '<category><![CDATA[News & Views]]></category>', 'D1 fix applied to legacy shape' );
} );

t( 'aigeon: no content gate — short imageless post still passes', function() {
    $p = new MMGRF_Profile_Aigeon();
    $item = $p->prepare_item( mk_item( [ 'image' => null, 'content' => '<p>tiny</p>' ] ) );
    expect_true( $p->validate_item( $item )['pass'] );
    $xml = $p->render_item( $item, profile_opts() );
    expect_not_contains( $xml, '<media:content' );
} );

// ── Skip log ─────────────────────────────────────────────────────

t( 'skip log: records entries, caps at 200, newest first', function() {
    for ( $i = 1; $i <= 205; $i++ ) {
        mmgrf_skip_log_add( 'yahoo', $i, "Post $i", 'body_word_count' );
    }
    $log = mmgrf_skip_log_get();
    expect_eq( count( $log ), 200 );
    expect_eq( $log[0]['post_id'], 205, 'newest first' );
    expect_eq( $log[0]['network'], 'yahoo' );
} );
