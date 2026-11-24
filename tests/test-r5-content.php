<?php
// Audit round 5 (2026-11-22) — sanitizer, emitter and network profiles.
// Sanitizer tests run against the SHIPPED profile rulesets, never private
// copies (the old private copies had drifted from what production runs).

function r5_rules( $network ) {
    return mmgrf_get_profile( $network )->get_sanitizer_rules();
}

/** Sanitize + prune exactly as the network profile does. */
function r5_clean( $html, $network ) {
    $r = mmgrf_sanitize( $html, r5_rules( $network ) );
    $r['html'] = mmgrf_prune_empty_nodes( $r['html'] );
    return $r;
}

/** Register an image attachment with a full size and a 1024px rendition. */
function r5_image( $id, $name, $w = 1920, $h = 1080, $extra = [] ) {
    $base = 'https://example-brand.com/up/';
    mmgrf_test_add_attachment( $id, [
        'full'  => [ $base . $name . '.jpg', $w, $h ],
        'large' => [ $base . $name . '-1024x576.jpg', min( 1024, $w ), min( 576, $h ) ],
    ], 'image/jpeg', $extra );
    $GLOBALS['mmgrf_test']['url_to_attachment'][ $base . $name . '.jpg' ] = $id;
    return $base . $name . '.jpg';
}

function r5_words( $n, $word = 'word' ) {
    return implode( ' ', array_fill( 0, $n, $word ) );
}

/** Render one post through a network feed and return its <item> block ('' if withheld). */
function r5_item_xml( $network, $post_args, $thumb_id = 0 ) {
    $id = mmgrf_test_add_post( array_merge( [
        'post_title'        => 'A Headline Long Enough To Clear Every Network Floor',
        'post_date_gmt'     => gmdate( 'Y-m-d H:i:s', time() - 86400 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 86400 ),
    ], $post_args ) );
    if ( $thumb_id ) {
        $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = $thumb_id;
    }
    $xml = mmgrf_render_feed( mmgrf_get_profile( $network ), mmgrf_network_options( $network ), [] );
    expect_well_formed( $xml, $network );
    return preg_match( '#<item>.*?</item>#s', $xml, $m ) ? $m[0] : '';
}

// ── Truncation: content that used to be silently cut short ────────

t( 'r5: a stray closing </div> no longer truncates the article (all four networks)', function() {
    foreach ( [ 'yahoo', 'msn', 'newsbreak', 'aigeon' ] as $n ) {
        $out = r5_clean( '<p>First paragraph.</p></div><p>Second.</p><p>Third.</p>', $n )['html'];
        expect_contains( $out, 'Second.', $n );
        expect_contains( $out, 'Third.', $n );
        $out = r5_clean( '<p>Kept one</div> lost tail</p><p>Lost.</p>', $n )['html'];
        expect_contains( $out, 'lost tail', $n );
        expect_contains( $out, 'Lost.', $n );
    }
} );

t( 'r5: removing an <embed> does not remove the rest of the article', function() {
    $html = '<p>Intro paragraph.</p><embed src="https://x.test/doc.pdf" type="application/pdf"><p>Second paragraph.</p><h2>Heading</h2><p>Third paragraph.</p>';
    foreach ( [ 'msn', 'newsbreak', 'aigeon' ] as $n ) {
        $out = r5_clean( $html, $n )['html'];
        expect_eq( $out, '<p>Intro paragraph.</p><p>Second paragraph.</p><h2>Heading</h2><p>Third paragraph.</p>', $n );
    }
    $yahoo = r5_clean( $html, 'yahoo' )['html'];
    // (libxml versions differ on whether <embed> is serialized with a closing tag.)
    expect_match( $yahoo, '#^<p>Intro paragraph\.</p><embed [^>]*>(</embed>)?<p>Second paragraph\.</p><h2>Heading</h2><p>Third paragraph\.</p>$#', 'yahoo keeps the embed, with the article beside it — not inside it' );
    expect_contains( r5_clean( '<p>Intro with inline <embed src="http://x.test/a.swf"> and the rest of this sentence.</p>', 'msn' )['html'], 'and the rest of this sentence.' );
} );

t( 'r5: a pasted <meta charset> or one invalid byte does not garble the rest of the article', function() {
    $out = r5_clean( '<p>before café</p><meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1"><p>after café “q”</p>', 'yahoo' )['html'];
    expect_eq( $out, '<p>before café</p><p>after café “q”</p>' );
    $out = r5_clean( "<p>caf\xC3</p><p>naïve — “quoted”</p>", 'yahoo' )['html'];
    expect_contains( $out, '<p>naïve — “quoted”</p>', 'text after a truncated multibyte character survives intact' );
} );

t( 'r5: deeply nested markup does not cut the parse short', function() {
    $deep = str_repeat( '<span>', 300 ) . 'DEEPTEXT' . str_repeat( '</span>', 300 ) . '<p>after deep</p>';
    $out  = mmgrf_sanitize( $deep, r5_rules( 'yahoo' ) )['html'];
    expect_contains( $out, 'DEEPTEXT' );
    expect_contains( $out, 'after deep' );
} );

t( 'r5: processing instructions never reach the feed', function() {
    $out = r5_clean( '<p>a</p><?php echo "x"; ?><?xml:namespace prefix = o ns = "urn:schemas-microsoft-com:office:office" /><p>b</p>', 'yahoo' )['html'];
    expect_eq( $out, '<p>a</p><p>b</p>' );
} );

// ── Word fusing ──────────────────────────────────────────────────

t( 'r5: dropped block tags become paragraphs instead of fusing sentences', function() {
    $r = r5_clean( '<div>First sentence ends here.</div><div>Second sentence starts here.</div>', 'yahoo' );
    expect_eq( $r['html'], '<p>First sentence ends here.</p><p>Second sentence starts here.</p>' );
    expect_eq( $r['word_count'], 8, 'and the words are counted correctly for the body floor' );
    expect_eq( r5_clean( '<dl><dt>Term</dt><dd>Definition</dd></dl>', 'msn' )['html'], '<p>Term</p><p>Definition</p>' );
    expect_eq( r5_clean( '<div class="wp-block-group"><p>One.</p><p>Two.</p></div>', 'yahoo' )['html'], '<p>One.</p><p>Two.</p>', 'a pure wrapper is still just unwrapped' );
    expect_eq( r5_clean( '<table><caption>Standings</caption><tr><th>Team</th></tr></table>', 'yahoo' )['html'], '<p>Standings</p><table><tr><th>Team</th></tr></table>', 'caption text is hoisted, not left bare inside the table' );
} );

t( 'r5: a whitespace-only inline element keeps its space (Google Docs paste shape)', function() {
    $out = r5_clean( '<p><span>Patrick Mahomes</span><span> </span><span>threw for 300 yards</span> and <strong>two scores</strong><em> </em>on Sunday.<b>&nbsp;</b>Next.</p>', 'yahoo' )['html'];
    $text = strip_tags( $out );
    expect_eq( $text, 'Patrick Mahomes threw for 300 yards and two scores on Sunday. Next.' );
} );

// ── Pruning ──────────────────────────────────────────────────────

t( 'r5: pruning keeps boolean attributes and alt=""', function() {
    $out = r5_clean( '<iframe src="https://www.tiktok.com/embed/1" allowfullscreen></iframe><p>x<br></p><img src="https://example-brand.com/a.jpg" alt="">', 'yahoo' )['html'];
    expect_contains( $out, 'allowfullscreen' );
    expect_contains( $out, 'alt=""' );
    $nb = r5_clean( '<p><video controls muted playsinline src="https://example-brand.com/v.mp4"></video></p><p>x<br></p>', 'newsbreak' )['html'];
    expect_contains( $nb, '<video controls muted playsinline', 'a player keeps its controls, and its paragraph' );
} );

t( 'r5: a non-breaking-space paragraph is pruned on the real sanitize-then-prune path', function() {
    // The serializer emits raw U+00A0, not the &nbsp; entity the old fast
    // path looked for.
    $out = r5_clean( '<p>Healthy one.</p><p>&nbsp;</p><p>Healthy two.</p>', 'msn' )['html'];
    expect_eq( $out, '<p>Healthy one.</p><p>Healthy two.</p>' );
} );

t( 'r5: a link emptied by sanitizing is removed, not left as dead markup', function() {
    $out = r5_clean( '<p>a</p><a href="https://fb.test"><svg><title>Share on Facebook</title><path d="M0"/></svg></a><p>b</p>', 'yahoo' )['html'];
    expect_eq( $out, '<p>a</p><p>b</p>' );
} );

t( 'r5: removing a blocked embed takes its figure and caption with it', function() {
    $out = r5_clean( '<figure class="wp-block-embed"><div class="wp-block-embed__wrapper"><iframe src="https://www.youtube.com/embed/abc"></iframe></div><figcaption>Watch: the game-winning drive</figcaption></figure><p>after</p>', 'msn' )['html'];
    expect_eq( $out, '<p>after</p>', 'no caption pointing at a video that is not there' );
} );

// ── Leaks and executable markup ──────────────────────────────────

t( 'r5: strict feeds do not leak player fallback or share-button text', function() {
    $html = '<p>a</p><video controls src="https://site.test/v.mp4"><a href="https://site.test/v.mp4">https://site.test/v.mp4</a></video><audio src="https://site.test/x.mp3">Your browser does not support the audio element.</audio><p>b</p>';
    foreach ( [ 'yahoo', 'msn' ] as $n ) {
        expect_eq( r5_clean( $html, $n )['html'], '<p>a</p><p>b</p>', $n );
    }
} );

t( 'r5: scriptable URLs and inline documents are stripped in every mode', function() {
    $html = '<a href="javascript:alert(1)">x</a><a href=" java&#9;script:alert(1)">y</a><iframe src="https://ok.test/e" srcdoc="<script>1</script>"></iframe><base href="https://evil.test/"><link rel="stylesheet" href="https://evil.test/x.css"><svg><set attributeName="onmouseover" to="alert(1)"/><a xlink:href="javascript:alert(1)">s</a></svg><img src="javascript:alert(1)"><p onclick="x()">t</p>';
    foreach ( [ 'yahoo', 'msn', 'newsbreak', 'aigeon' ] as $n ) {
        $out = strtolower( r5_clean( $html, $n )['html'] );
        foreach ( [ 'javascript:', 'srcdoc', '<base', '<link', '<set', 'onclick', 'onmouseover' ] as $bad ) {
            expect_not_contains( $out, $bad, "$n: $bad" );
        }
    }
} );

t( 'r5: strict feeds carry only fetchable images; lazy-load placeholders are resolved or dropped', function() {
    $out = r5_clean( '<img src="data:image/gif;base64,R0lGOD" data-src="https://example-brand.com/real.jpg" class="lazy"><img src="data:image/gif;base64,R0lGOD"><p>t</p>', 'yahoo' )['html'];
    expect_eq( $out, '<img src="https://example-brand.com/real.jpg"><p>t</p>' );
} );

t( 'r5: iframe host rules match whole path segments and tolerate a padded src', function() {
    $out = r5_clean( '<iframe src="https://www.google.com/mapsevil/x"></iframe><iframe src="https://www.google.com/maps/embed?pb=1"></iframe><iframe src=" https://x.com/i/1"></iframe>', 'msn' )['html'];
    expect_not_contains( $out, 'mapsevil' );
    expect_contains( $out, 'google.com/maps/embed' );
    expect_contains( $out, 'src="https://x.com/i/1"' );
} );

t( 'r5: relative URLs are made absolute before any check reads them', function() {
    $out = r5_clean( '<iframe src="/local/embed.html"></iframe><img src="img/a.jpg"><a href="?p=2">q</a>', 'aigeon' )['html'];
    expect_contains( $out, 'src="https://example-brand.com/local/embed.html"', 'a rooted iframe is no longer deleted for having no host' );
    expect_contains( $out, 'src="https://example-brand.com/img/a.jpg"' );
    expect_contains( $out, 'href="https://example-brand.com/?p=2"' );
} );

t( 'r5: an odd attribute name on a renamed heading cannot throw', function() {
    $out = r5_clean( '<h1 .foo="1" xmlns="urn:x" class="t">Title</h1><p>x</p>', 'yahoo' )['html'];
    expect_eq( $out, '<h2>Title</h2><p>x</p>', 'and nothing rides through the rename past the attribute allowlist' );
} );

t( 'r5: msn demotes h6 to h5 rather than losing the heading', function() {
    expect_eq( r5_clean( '<h6>Small heading</h6><p>x</p>', 'msn' )['html'], '<h5>Small heading</h5><p>x</p>' );
} );

t( 'r5: a large stats table sanitizes and prunes in linear time', function() {
    $rows = '';
    for ( $i = 0; $i < 8000; $i++ ) {
        $rows .= "<tr><td>Player $i</td><td>$i</td><td><br></td></tr>";
    }
    $t   = microtime( true );
    $out = r5_clean( "<table>$rows</table>", 'msn' )['html'];
    mmgrf_guard_affiliate_links( $out . '<a href="https://x.test">x</a>', [ 'fanduel.com' ], [ 'promo' ] );
    expect_true( microtime( true ) - $t < 5, 'was 37 seconds for 8,000 rows before; now well under a second' );
    expect_contains( $out, 'Player 7999' );
} );

// ── Emitter: a single bad character must not sink the whole feed ──

t( 'r5: control characters and invalid UTF-8 in any field leave the feed well-formed', function() {
    $id = fixture_post( [
        'post_title'   => "Chiefs win\x0B in\x08 overtime thriller at Arrowhead",
        'post_excerpt' => "Caf\xE9 owner wins the &#12; raffle on Sunday night",
    ] );
    $GLOBALS['mmgrf_test']['authors'][1] = "Staff\x0C Writer";
    mmgrf_test_set_terms( $id, 'category', [ "NFL\x1F News" ] );
    foreach ( [ 'aigeon', 'newsbreak', 'msn', 'yahoo' ] as $network ) {
        $xml = mmgrf_render_feed( mmgrf_get_profile( $network ), mmgrf_network_options( $network ), [] );
        expect_well_formed( $xml, $network );
        expect_eq( substr_count( $xml, '<item>' ), 1, "$network: the post still ships" );
        expect_contains( $xml, 'Chiefs win in overtime thriller', $network );
    }
} );

t( 'r5: the escaper never returns an empty string for invalid UTF-8', function() {
    expect_eq( mmgrf_xml( "Caf\xE9 owner <wins>" ), 'Caf? owner &lt;wins&gt;' );
    expect_eq( mmgrf_cdata( "a\x0Bb]]>c" ), '<![CDATA[ab]]]]><![CDATA[>c]]>' );
    expect_eq( mmgrf_xml( 'plain & simple' ), 'plain &amp; simple' );
} );

// ── Yahoo: each photo exactly once ───────────────────────────────

t( 'r5 yahoo: featured photo also placed lower in the body ships once, as the lead', function() {
    $hero  = r5_image( 7001, 'hero' );
    $other = r5_image( 7002, 'other' );
    $item  = r5_item_xml( 'yahoo', [ 'post_content' =>
        '<p>' . r5_words( 200 ) . '</p>'
        . '<figure><img src="https://example-brand.com/up/other-1024x576.jpg" class="wp-image-7002"></figure>'
        . '<p>More words here.</p>'
        . '<figure><img src="https://example-brand.com/up/hero-1024x576.jpg" class="wp-image-7001"><figcaption>Body caption</figcaption></figure>',
    ], 7001 );
    expect_eq( item_image_urls( $item ), [ $hero, $other ], 'lead first, then the other photo — no URL twice' );
    expect_eq( substr_count( $item, 'Body caption' ), 1, 'the caption typed on the moved block travels with the photo' );
    expect_match( $item, '#<content:encoded><!\[CDATA\[<figure><img [^>]*/><figcaption>Body caption</figcaption></figure>#', 'on the lead figure' );
} );

t( 'r5 yahoo: featured photo that is the only body image, at the bottom, becomes the lead figure', function() {
    $hero = r5_image( 7011, 'hero-b' );
    $item = r5_item_xml( 'yahoo', [ 'post_content' =>
        '<p>' . r5_words( 200 ) . '</p><p><img src="https://example-brand.com/up/hero-b.jpg" class="wp-image-7011" alt=""></p>',
    ], 7011 );
    expect_eq( item_image_urls( $item ), [ $hero ] );
    expect_match( $item, '#<content:encoded><!\[CDATA\[<figure><img src="https://example-brand\.com/up/hero-b\.jpg" alt="#', 'lead figure is the first element of the body' );
} );

t( 'r5 yahoo: body that already leads with the featured photo is left alone', function() {
    $hero = r5_image( 7021, 'hero-c' );
    $item = r5_item_xml( 'yahoo', [ 'post_content' =>
        '<figure><img src="https://example-brand.com/up/hero-c-1024x576.jpg" class="wp-image-7021"><figcaption>Editor caption</figcaption></figure><p>' . r5_words( 200 ) . '</p>',
    ], 7021 );
    expect_eq( item_image_urls( $item ), [ $hero ], 'upgraded to the qualifying rendition, once' );
    expect_contains( $item, 'Editor caption', "the editor's own figure is kept" );
} );

t( 'r5 yahoo: the same photo inserted twice in the body ships once', function() {
    $hero  = r5_image( 7031, 'hero-d' );
    $other = r5_image( 7032, 'other-d' );
    $item  = r5_item_xml( 'yahoo', [ 'post_content' =>
        '<p>' . r5_words( 200 ) . '</p><img src="https://example-brand.com/up/other-d-1024x576.jpg" class="wp-image-7032">'
        . '<p>Middle.</p><img src="https://example-brand.com/up/other-d.jpg" class="wp-image-7032">',
    ], 7031 );
    expect_eq( item_image_urls( $item ), [ $hero, $other ] );
    expect_true( in_array( 'inline_image_duplicate', array_column( mmgrf_skip_log_get(), 'code' ), true ), 'and the editor is told' );
} );

t( 'r5 yahoo: CDN/Photon URLs with query strings are recognised as the same photo', function() {
    $GLOBALS['mmgrf_test']['upload_baseurl'] = 'https://example-brand.com/wp-content/uploads';
    $full = 'https://i0.wp.com/example-brand.com/wp-content/uploads/2026/11/hero.jpg?fit=1920%2C1080&ssl=1';
    mmgrf_test_add_attachment( 7041, [ 'full' => [ $full, 1920, 1080 ] ], 'image/jpeg', [ 'url' => 'https://example-brand.com/wp-content/uploads/2026/11/hero.jpg' ] );
    $GLOBALS['mmgrf_test']['url_to_attachment']['https://example-brand.com/wp-content/uploads/2026/11/hero.jpg'] = 7041;
    $item = r5_item_xml( 'yahoo', [ 'post_content' =>
        '<p><img src="https://i0.wp.com/example-brand.com/wp-content/uploads/2026/11/hero.jpg?resize=1024%2C576&#038;ssl=1"></p><p>' . r5_words( 200 ) . '</p>',
    ], 7041 );
    expect_eq( item_image_urls( $item ), [ $full ] );
} );

t( 'r5 yahoo: a rendition of a "-scaled" original resolves from its wp-image class alone', function() {
    // No URL lookup fixture at all: core's own lookup cannot match these.
    mmgrf_test_add_attachment( 7051, [
        'full'  => [ 'https://example-brand.com/up/wire-scaled.jpg', 2560, 1707 ],
        'large' => [ 'https://example-brand.com/up/wire-1024x683.jpg', 1024, 683 ],
    ] );
    $item = r5_item_xml( 'yahoo', [ 'post_content' =>
        '<figure><img src="https://example-brand.com/up/wire-1024x683.jpg" class="alignnone size-large wp-image-7051" width="1024" height="683"></figure><p>' . r5_words( 200 ) . '</p>',
    ], 7051 );
    expect_eq( item_image_urls( $item ), [ 'https://example-brand.com/up/wire-scaled.jpg' ], 'one photo, at the size that clears the floor' );
    expect_false( in_array( 'inline_image_unresolvable', array_column( mmgrf_skip_log_get(), 'code' ), true ) );
} );

t( 'r5: a wp-image class copied from another site is not trusted', function() {
    mmgrf_test_add_attachment( 7061, [ 'full' => [ 'https://example-brand.com/up/ours.jpg', 1920, 1080 ] ] );
    expect_eq( mmgrf_find_attachment( 'https://example-brand.com/up/stranger-1024x683.jpg', 7061 ), 0 );
    expect_eq( mmgrf_find_attachment( 'https://example-brand.com/up/ours-300x200.jpg', 7061 ), 7061 );
} );

t( 'r5 yahoo: the 150-word floor is judged on the body that actually ships', function() {
    mmgrf_test_add_attachment( 7071, [ 'full' => [ 'https://example-brand.com/up/small.jpg', 900, 600 ] ] );
    $GLOBALS['mmgrf_test']['url_to_attachment']['https://example-brand.com/up/small.jpg'] = 7071;
    $item = r5_item_xml( 'yahoo', [ 'post_content' =>
        '<p>' . r5_words( 140 ) . '</p><figure><img src="https://example-brand.com/up/small.jpg"><figcaption>' . r5_words( 15, 'caption' ) . '</figcaption></figure>',
    ] );
    expect_eq( $item, '', '140 words ship after the undersized figure is removed — below the floor' );
    $entry = array_values( array_filter( mmgrf_skip_log_get(), fn( $e ) => $e['code'] === 'body_below_word_floor' ) );
    expect_eq( count( $entry ), 1 );
    expect_contains( $entry[0]['detail'], '140 words' );
} );

// ── NewsBreak ────────────────────────────────────────────────────

t( 'r5 newsbreak: a body leading with a smaller rendition of the featured photo is not doubled', function() {
    r5_image( 7101, 'nb-hero' );
    $item = r5_item_xml( 'newsbreak', [ 'post_content' =>
        '<figure><img src="https://example-brand.com/up/nb-hero-1024x576.jpg" class="wp-image-7101"></figure><p>' . r5_words( 60 ) . '</p>',
    ], 7101 );
    preg_match( '#<content:encoded>(.*?)</content:encoded>#s', $item, $m );
    expect_eq( substr_count( $m[1], '<img' ), 1, 'one photo in the article body' );
} );

t( 'r5 newsbreak: an entity-encoded inline image URL is emitted escaped exactly once', function() {
    $item = r5_item_xml( 'newsbreak', [ 'post_content' =>
        '<p><img src="https://cdn.example.net/a.jpg?resize=1024%2C576&#038;ssl=1"></p><p>' . r5_words( 60 ) . '</p>',
    ] );
    expect_contains( $item, '<media:thumbnail url="https://cdn.example.net/a.jpg?resize=1024%2C576&amp;ssl=1"' );
    expect_not_contains( $item, '&amp;#038;' );
} );

// ── MSN ──────────────────────────────────────────────────────────

t( 'r5 msn: a rights-flagged image inserted in the body is withheld, not just the thumbnail', function() {
    r5_image( 7201, 'clean' );
    r5_image( 7202, 'getty' );
    update_post_meta( 7202, '_mmgrf_no_syndication_rights', '1' );
    $item = r5_item_xml( 'msn', [ 'post_content' =>
        '<p>' . r5_words( 60 ) . '</p><figure><img src="https://example-brand.com/up/getty-1024x576.jpg" class="wp-image-7202"><figcaption>Getty Images</figcaption></figure><p>End.</p>',
    ], 7201 );
    expect_not_contains( $item, 'getty' );
    expect_not_contains( $item, 'Getty Images', 'caption goes with it' );
    expect_contains( $item, 'clean.jpg', 'the rights-cleared thumbnail still ships' );
    expect_true( in_array( 'no_syndication_rights', array_column( mmgrf_skip_log_get(), 'code' ), true ) );
} );

t( 'r5 msn: the skip log names the real reason an image was rejected', function() {
    mmgrf_test_add_attachment( 7211, [ 'full' => [ 'https://example-brand.com/up/tiny.jpg', 600, 338 ] ] );
    $item = r5_item_xml( 'msn', [ 'post_content' => '<p>' . r5_words( 60 ) . '</p>' ], 7211 );
    expect_eq( $item, '' );
    $codes = array_column( mmgrf_skip_log_get(), 'code' );
    expect_true( in_array( 'image_below_minimum', $codes, true ), 'what the editor must fix' );
    expect_false( in_array( 'no_featured_image', $codes, true ), 'not the misleading symptom' );
} );

t( 'r5 msn: an over-long short title is never sent; a usable one is derived instead', function() {
    $p    = mmgrf_get_profile( 'msn' );
    $long = trim( str_repeat( 'Long headline words ', 10 ) ); // 199 chars
    $item = $p->prepare_item( mk_item( [ 'title' => $long, 'short_title' => str_repeat( 'x', 208 ), 'pub_ts' => time() - 3600, 'mod_ts' => time() - 3600 ] ), profile_opts() );
    expect_true( $p->validate_item( $item )['pass'] );
    $xml = $p->render_item( $item, profile_opts() );
    expect_not_contains( $xml, str_repeat( 'x', 60 ), 'the unusable 208-character value is not sent' );
    expect_contains( $xml, '<mi:shortTitle>Long headline words' );
    $ok = $p->prepare_item( mk_item( [ 'short_title' => str_repeat( 'x', 208 ), 'pub_ts' => time() - 3600, 'mod_ts' => time() - 3600 ] ), profile_opts() );
    expect_true( $p->validate_item( $ok )['pass'] );
    expect_not_contains( $p->render_item( $ok, profile_opts() ), 'mi:shortTitle', 'a normal headline needs none' );
} );

t( 'r5: no author-visible log detail carries an absolute server path', function() {
    $tmp = sys_get_temp_dir() . '/mmgrf-nodims-' . uniqid() . '.jpg';
    file_put_contents( $tmp, 'not an image' ); // exists on disk, but has no readable dimensions
    mmgrf_test_add_attachment( 7301, [ 'full' => [ 'https://example-brand.com/up/nodims.jpg', 0, 0 ] ], 'image/jpeg', [ 'file' => $tmp ] );
    foreach ( [ 'yahoo', 'msn', 'newsbreak' ] as $n ) {
        r5_item_xml( $n, [ 'post_content' => '<p>' . r5_words( 200 ) . '</p>' ], 7301 );
    }
    foreach ( mmgrf_skip_log_get() as $e ) {
        expect_not_contains( $e['detail'], dirname( $tmp ), $e['network'] . ' ' . $e['code'] );
    }
    @unlink( $tmp );
    expect_true( in_array( 'image_dimensions_unknown', array_column( mmgrf_skip_log_get(), 'code' ), true ) );
} );

t( 'r5: two external images that differ only by query string are different images', function() {
    $GLOBALS['mmgrf_test']['upload_baseurl'] = 'https://example-brand.com/wp-content/uploads';
    $pass = mmgrf_process_body_images( '<p>a</p><img src="https://charts.example.net/render?id=1"><p>b</p><img src="https://charts.example.net/render?id=2">', [ 'dedupe' => true ] );
    expect_eq( count( $pass['images'] ), 2 );
    // …while two renditions of one upload are still the same photo.
    expect_eq( mmgrf_image_identity( 'https://i0.wp.com/example-brand.com/wp-content/uploads/2026/11/a-1024x576.jpg?resize=1' ), mmgrf_image_identity( 'https://example-brand.com/wp-content/uploads/2026/11/a-scaled.jpg' ) );
} );
