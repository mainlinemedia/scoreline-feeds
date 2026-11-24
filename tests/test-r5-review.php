<?php
// Audit round 5 — findings of the independent review of the 3.6.0 diff
// itself (regressions the hardening would otherwise have introduced).

// ── 1. NewsBreak: a lead photo below the intro must not vanish ────

t( 'r5-review newsbreak: featured photo placed after an intro is moved to the top, never dropped', function() {
    r5_image( 7901, 'nb-lead' );
    $item = r5_item_xml( 'newsbreak', [ 'post_content' =>
        '<p>Intro paragraph.</p><figure class="wp-block-image size-full"><img src="https://example-brand.com/up/nb-lead.jpg" class="wp-image-7901"/><figcaption>Photo: Jane Doe / Imagn Images</figcaption></figure><p>' . r5_words( 60 ) . '</p>',
    ], 7901 );
    preg_match( '#<content:encoded>(.*?)</content:encoded>#s', $item, $m );
    expect_eq( substr_count( $m[1], '<img' ), 1, 'the photo is in the body exactly once' );
    expect_match( $m[1], '#^<!\[CDATA\[<figure><img src="https://example-brand\.com/up/nb-lead\.jpg"#', 'as the lead figure' );
} );

t( 'r5-review newsbreak: with no featured image, the first inline photo is kept in the body', function() {
    $item = r5_item_xml( 'newsbreak', [ 'post_content' =>
        '<p>Intro paragraph.</p><figure class="wp-block-image"><img src="https://cdn.other.com/a/b/photo-one.jpg"/><figcaption>Credit: Getty Images</figcaption></figure><p>' . r5_words( 60 ) . '</p>',
    ] );
    preg_match( '#<content:encoded>(.*?)</content:encoded>#s', $item, $m );
    expect_eq( substr_count( $m[1], 'photo-one.jpg' ), 1 );
    expect_contains( $m[1], 'Credit: Getty Images', 'with its credit' );
} );

// ── 2. A moved lead keeps the caption and alt typed in the editor ──

t( 'r5-review: a lead photo moved to the top keeps its in-body caption and alt', function() {
    r5_image( 7911, 'cap-lead' ); // attachment itself has no caption or alt
    $body = '<p>Intro paragraph.</p><figure class="wp-block-image size-large"><img src="https://example-brand.com/up/cap-lead-1024x576.jpg" alt="Mahomes scrambles" class="wp-image-7911"/><figcaption>Photo: Jane Doe / Imagn Images</figcaption></figure><p>' . r5_words( 200 ) . '</p>';
    foreach ( [ 'yahoo', 'newsbreak' ] as $n ) {
        $item = r5_item_xml( $n, [ 'post_content' => $body ], 7911 );
        preg_match( '#<content:encoded>(.*?)</content:encoded>#s', $item, $m );
        expect_contains( $m[1], '<figcaption>Photo: Jane Doe / Imagn Images</figcaption>', "$n keeps the credit line" );
        expect_contains( $m[1], 'alt="Mahomes scrambles"', "$n keeps the alt text" );
        expect_eq( substr_count( $m[1], '<img' ), 1, $n );
        mmgrf_test_reset();
        r5_image( 7911, 'cap-lead' );
    }
} );

// ── 3. NewsBreak keeps withholding slideshow/video posts (as 3.5.3 did) ──

t( 'r5-review: updating does not start shipping slideshow- or video-tagged posts to NewsBreak', function() {
    $s = fixture_post();
    mmgrf_test_set_terms( $s, 'post_tag', [ 'slideshow' ] );
    $v = fixture_post();
    mmgrf_test_set_terms( $v, 'post_tag', [ 'video' ] );
    expect_eq( r5_ids( 'newsbreak' ), [] );
    expect_eq( count( r5_ids( 'aigeon' ) ), 2, 'the legacy feed never excluded them' );
} );

// ── 4. Another site's upload is never mistaken for one of ours ────

t( 'r5-review: a foreign URL that merely contains the uploads path is not matched to a local attachment', function() {
    $GLOBALS['mmgrf_test']['upload_baseurl'] = 'https://example-brand.com/wp-content/uploads';
    $GLOBALS['mmgrf_test']['url_to_attachment']['https://example-brand.com/wp-content/uploads/2026/11/photo.jpg'] = 7921;
    expect_eq( mmgrf_find_attachment( 'https://sister-site.com/wp-content/uploads/2026/11/photo.jpg' ), 0 );
    expect_eq( mmgrf_find_attachment( 'https://example-brand.com/wp-content/uploads/2026/11/photo-300x200.jpg' ), 7921, 'our own host' );
    expect_eq( mmgrf_find_attachment( 'https://www.example-brand.com/wp-content/uploads/2026/11/photo.jpg' ), 7921, 'www form of our host' );
    expect_eq( mmgrf_find_attachment( 'https://cdn.example-brand.com/wp-content/uploads/2026/11/photo.jpg' ), 7921, 'a subdomain of ours' );
    expect_eq( mmgrf_find_attachment( 'https://i2.wp.com/example-brand.com/wp-content/uploads/2026/11/photo.jpg?w=800' ), 7921, 'Photon serving our site' );
    expect_eq( mmgrf_find_attachment( 'https://i2.wp.com/sister-site.com/wp-content/uploads/2026/11/photo.jpg' ), 0, 'Photon serving someone else' );
    add_filter( 'mmgrf_media_hosts', fn( $hosts ) => array_merge( $hosts, [ 'd111abc.cloudfront.net' ] ) );
    expect_eq( mmgrf_find_attachment( 'https://d111abc.cloudfront.net/wp-content/uploads/2026/11/photo.jpg?v=2' ), 7921, 'a declared CDN host' );
    expect_true( mmgrf_image_identity( 'https://sister-site.com/wp-content/uploads/2026/11/photo.jpg' ) !== mmgrf_image_identity( 'https://example-brand.com/wp-content/uploads/2026/11/photo.jpg' ), 'and is not "the same photo" either' );
} );

// ── 5. The lead is first even when another photo precedes it ──────

t( 'r5-review yahoo: the lead figure is first even when another photo sits above it with no text between', function() {
    $hero  = r5_image( 7931, 'first-lead' );
    $other = r5_image( 7932, 'first-other' );
    $item  = r5_item_xml( 'yahoo', [ 'post_content' =>
        '<figure><img src="https://example-brand.com/up/first-other-1024x576.jpg" class="wp-image-7932"/></figure>'
        . '<figure><img src="https://example-brand.com/up/first-lead-1024x576.jpg" class="wp-image-7931"/></figure><p>' . r5_words( 200 ) . '</p>',
    ], 7931 );
    expect_eq( item_image_urls( $item ), [ $hero, $other ] );
} );

// ── 6. Dropped blocks inside headings and inline tags ─────────────

t( 'r5-review: a dropped block inside a heading, link or span is unwrapped, not turned into a nested paragraph', function() {
    expect_eq( r5_clean( '<h2><div>Heading in div</div></h2><p>x</p>', 'yahoo' )['html'], '<h2>Heading in div</h2><p>x</p>' );
    expect_eq( r5_clean( '<p>See <a href="https://x.test/"><div>the link</div></a> now</p>', 'yahoo' )['html'], '<p>See <a href="https://x.test/">the link</a> now</p>' );
    $span = r5_clean( '<p>Before <span><div>Inner</div></span> after.</p>', 'yahoo' )['html'];
    expect_not_contains( $span, '<span><p>' );
    expect_contains( $span, 'Inner' );
} );

// ── 7. Settle window vs. both styles of conditional request ───────

t( 'r5-review: after the settle window a held post is delivered whichever validator the partner sends', function() {
    $T = time() - 30; // published 30s ago: still held
    fixture_post( [ 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $T ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', $T ) ] );
    $posts_ts = strtotime( get_lastpostmodified( 'GMT' ) . ' UTC' );

    // What a poll during the hold is told…
    $during = mmgrf_effective_lastmod( $posts_ts, $T + 30 );
    expect_eq( $during, $T + 30, 'while anything is settling, Last-Modified is simply "now"' );
    // …and what the feed reports once the post is out.
    $after = mmgrf_effective_lastmod( $posts_ts, $T + 300 );
    expect_eq( $after, $T + 60 );

    // Partner A echoes the Last-Modified it was given.
    expect_false( mmgrf_feed_not_modified( gmdate( 'D, d M Y H:i:s', $during ) . ' GMT', $after ), 'echoing client gets the post' );
    // Partner B sends the time of its own last fetch (any moment in the hold).
    foreach ( [ $T + 1, $T + 30, $T + 59 ] as $fetched_at ) {
        expect_false( mmgrf_feed_not_modified( gmdate( 'D, d M Y H:i:s', $fetched_at ) . ' GMT', $after ), "fetch-time client (T+" . ( $fetched_at - $T ) . ') gets the post' );
    }
    // And once it has the post, an unchanged feed is a 304 again.
    expect_true( mmgrf_feed_not_modified( gmdate( 'D, d M Y H:i:s', $after ) . ' GMT', mmgrf_effective_lastmod( $posts_ts, $T + 900 ) ) );
} );

t( 'r5-review: with the settle window off, Last-Modified is the plain last change', function() {
    update_option( 'mmgrf_options', [ 'settle_seconds' => 0 ] );
    $id = fixture_post();
    expect_eq( mmgrf_feed_lastmod(), strtotime( get_post( $id )->post_modified_gmt . ' UTC' ) );
} );

// ── 8. The cache works during the settle window ───────────────────

t( 'r5-review: the feed cache hits during the settle window (one render per change, not one per second)', function() {
    fixture_post();
    mmgrf_on_media_change(); // "now": opens a settle window
    $sig = mmgrf_cache_signature();
    expect_eq( mmgrf_cache_signature( time() + 20 ), $sig, 'stable while the window is open' );
    expect_true( mmgrf_cache_signature( time() + 61 ) !== $sig, 'and refreshed once, when it closes' );

    $calls = 0;
    $fn    = function() use ( &$calls ) { $calls++; return '<rss>x</rss>'; };
    mmgrf_cached_render( 'unit|settle', $fn );
    mmgrf_cached_render( 'unit|settle', $fn );
    mmgrf_cached_render( 'unit|settle', $fn );
    expect_eq( $calls, 1 );
} );

// ── 9. Slideshow text is never silently dropped ───────────────────

t( 'r5-review slideshow: a list item or quote with its own text AND nested blocks keeps both', function() {
    slides_attachment( 7941, 'https://example-brand.com/up/nest1' );
    $parsed = mmgrf_parse_slideshow( '<h2>Nested</h2><img src="https://example-brand.com/up/nest1.jpg"><ul><li>Parent point text<ul><li>child a</li></ul></li></ul><blockquote>Loose quote text<p>inner para</p></blockquote><p>Plain <strong>bold</strong> text.</p>' );
    expect_eq( $parsed['slides'][0]['text'], 'Parent point text child a Loose quote text inner para Plain bold text.' );
} );

t( 'r5-review slideshow: a heading with no text is reported, not silently skipped', function() {
    slides_attachment( 7951, 'https://example-brand.com/up/notitle' );
    $parsed = mmgrf_parse_slideshow( '<h2><img src="https://example-brand.com/up/notitle.jpg"></h2><p>Text under an image-only heading.</p>' );
    expect_eq( count( $parsed['slides'] ), 0 );
    expect_true( in_array( 'slide_missing_title', array_column( $parsed['events'], 'code' ), true ) );
} );
