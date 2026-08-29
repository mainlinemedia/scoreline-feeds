<?php
// Sanitizer engine: DOMDocument-based, allowlist-driven, per-profile rules.

function yahoo_rules() {
    return [
        'allowed_tags' => [ 'a','b','blockquote','br','em','h1','h2','h3','h4','h5','h6','i','img','li','ol','ul','p','strong','iframe','embed','sub','sup','pre','figure','figcaption','section','span','table','tr','th','td' ],
        'allowed_attributes' => [ 'a' => [ 'href' ], 'img' => [ 'src', 'alt', 'width', 'height' ], 'iframe' => [ 'src', 'width', 'height', 'allowfullscreen' ] ],
        'strip_style_attr' => true,
        'iframe_hosts' => null,
        'base_url' => 'https://example-brand.com',
    ];
}

function msn_rules() {
    return [
        'allowed_tags' => [ 'b','i','em','strong','sub','sup','small','h1','h2','h3','h4','h5','a','img','table','thead','tbody','tfoot','tr','td','th','col','caption','colgroup','ul','ol','li','p','div','span','br','blockquote','iframe' ],
        'allowed_attributes' => [ 'a' => [ 'href' ], 'img' => [ 'src', 'alt', 'width', 'height' ], 'iframe' => [ 'src', 'width', 'height', 'allowfullscreen' ] ],
        'strip_style_attr' => true,
        'iframe_hosts' => [ 'x.com', 'twitter.com', 'facebook.com', 'instagram.com', 'pinterest.com', 'spotify.com', 'infogram.com', 'google.com', 'giphy.com', 'flourish.studio', 'flo.uri.sh', 'reddit.com', 'tiktok.com' ],
        'href_schemes' => [ 'https', 'http', 'mailto' ],
        'base_url' => 'https://example-brand.com',
    ];
}

function permissive_rules() {
    return [
        'allowed_tags' => null,
        'strip_attr_patterns' => [ 'srcset', 'sizes', 'loading', 'decoding', 'fetchpriority', 'data-*' ],
        'base_url' => 'https://example-brand.com',
    ];
}

t( 'yahoo: style attributes stripped globally, text intact', function() {
    $r = mmgrf_sanitize( '<p style="color:red">Hello <span style="font-weight:700">world</span></p>', yahoo_rules() );
    expect_not_contains( $r['html'], 'style=' );
    expect_contains( $r['html'], 'Hello' );
    expect_contains( $r['html'], 'world' );
} );

t( 'yahoo: script deleted with subtree, div unwrapped keeping children', function() {
    $r = mmgrf_sanitize( '<div class="wrap"><p>Kept text</p><script>evil()</script></div>', yahoo_rules() );
    expect_not_contains( $r['html'], 'evil' );
    expect_not_contains( $r['html'], '<script' );
    expect_not_contains( $r['html'], '<div' );
    expect_contains( $r['html'], '<p>Kept text</p>' );
} );

t( 'yahoo: disallowed attributes dropped, allowed kept', function() {
    $r = mmgrf_sanitize( '<p><a href="https://a.com" onclick="x()" class="btn">link</a></p>', yahoo_rules() );
    expect_contains( $r['html'], 'href="https://a.com"' );
    expect_not_contains( $r['html'], 'onclick' );
    expect_not_contains( $r['html'], 'class=' );
} );

t( 'msn: youtube iframe deleted, tiktok iframe kept', function() {
    $html = '<p>Before</p><iframe src="https://www.youtube.com/embed/abc"></iframe><iframe src="https://www.tiktok.com/embed/v2/123"></iframe>';
    $r = mmgrf_sanitize( $html, array_merge( msn_rules(), [ 'blocked_iframe_hosts' => [ 'youtube.com', 'youtube-nocookie.com', 'youtu.be' ] ] ) );
    expect_not_contains( $r['html'], 'youtube.com' );
    expect_contains( $r['html'], 'tiktok.com' );
} );

t( 'msn: iframe from unlisted host deleted', function() {
    $r = mmgrf_sanitize( '<iframe src="https://random-widget.io/embed/9"></iframe><p>Text</p>', msn_rules() );
    expect_not_contains( $r['html'], 'random-widget.io' );
    expect_contains( $r['html'], '<p>Text</p>' );
} );

t( 'msn: h6 unwrapped (h1-h5 only), anchor with javascript: href unwrapped', function() {
    $r = mmgrf_sanitize( '<h6>Sub heading</h6><p><a href="javascript:alert(1)">click</a></p>', msn_rules() );
    expect_not_contains( $r['html'], '<h6' );
    expect_contains( $r['html'], 'Sub heading' );
    expect_not_contains( $r['html'], 'javascript:' );
    expect_contains( $r['html'], 'click' );
} );

t( 'permissive: lazy-load and data attributes stripped, structure preserved', function() {
    $html = '<div class="block"><img src="https://a.com/i.jpg" srcset="a 1x, b 2x" sizes="(max-width:600px)" loading="lazy" decoding="async" fetchpriority="high" data-id="7"><p style="margin:0">Text</p></div>';
    $r = mmgrf_sanitize( $html, permissive_rules() );
    foreach ( [ 'srcset', 'sizes=', 'loading=', 'decoding=', 'fetchpriority', 'data-id' ] as $gone ) {
        expect_not_contains( $r['html'], $gone );
    }
    expect_contains( $r['html'], '<div class="block">', 'permissive keeps divs and classes' );
    expect_contains( $r['html'], 'style="margin:0"', 'permissive keeps inline style' );
    expect_contains( $r['html'], 'src="https://a.com/i.jpg"' );
} );

t( 'permissive: script still deleted even in permissive mode', function() {
    $r = mmgrf_sanitize( '<p>ok</p><script>bad()</script><noscript>nojs</noscript>', permissive_rules() );
    expect_not_contains( $r['html'], 'bad()' );
    expect_not_contains( $r['html'], 'nojs' );
} );

t( 'relative img src and a href are absolutized against base_url', function() {
    $r = mmgrf_sanitize( '<p><a href="/story/x">rel</a> <img src="/wp-content/i.jpg"></p>', yahoo_rules() );
    expect_contains( $r['html'], 'href="https://example-brand.com/story/x"' );
    expect_contains( $r['html'], 'src="https://example-brand.com/wp-content/i.jpg"' );
} );

t( 'word_count counts text words after tag stripping', function() {
    $r = mmgrf_sanitize( '<p>one two three</p><div>four <b>five</b></div><script>not counted here</script>', permissive_rules() );
    expect_eq( $r['word_count'], 5 );
} );

t( 'utf-8 content survives: em dash, curly quotes, accents', function() {
    $r = mmgrf_sanitize( '<p>Résumé — “quoted”ناس</p>', permissive_rules() );
    expect_contains( $r['html'], 'Résumé — “quoted”ناس' );
} );

t( 'body_has_image and first_image_src reported', function() {
    $r = mmgrf_sanitize( '<p>text</p><img src="https://a.com/lead.jpg"><img src="https://a.com/second.jpg">', permissive_rules() );
    expect_true( $r['body_has_image'] );
    expect_eq( $r['first_image_src'], 'https://a.com/lead.jpg' );
    $r2 = mmgrf_sanitize( '<p>no images</p>', permissive_rules() );
    expect_false( $r2['body_has_image'] );
} );

t( 'has_embeds flags iframe presence', function() {
    $r = mmgrf_sanitize( '<iframe src="https://www.instagram.com/p/x/embed"></iframe>', msn_rules() );
    expect_true( $r['has_embeds'] );
    $r2 = mmgrf_sanitize( '<p>plain</p>', msn_rules() );
    expect_false( $r2['has_embeds'] );
} );

t( 'empty and whitespace-only input yields empty result without warnings', function() {
    $r = mmgrf_sanitize( '', yahoo_rules() );
    expect_eq( $r['html'], '' );
    expect_eq( $r['word_count'], 0 );
} );
