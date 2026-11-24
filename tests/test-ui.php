<?php
// 3.7.0 — admin screens and the editor box.
//
// Governing rule for everything here: a writer never has to do anything for
// an article to be syndicated. The UI informs; it never adds a step.

function ui_render( callable $fn ) {
    ob_start();
    $fn();
    return ob_get_clean();
}

/** Every form field (name => submitted value) inside the form whose action is $action, as a browser would post it. */
function ui_form_fields( $html, $action ) {
    $doc = new DOMDocument();
    libxml_use_internal_errors( true );
    $doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
    libxml_clear_errors();
    $xp   = new DOMXPath( $doc );
    $data = [];
    foreach ( $xp->query( '//form[contains(@action,"' . $action . '")]//*[self::input or self::select or self::textarea][@name]' ) as $el ) {
        $name = $el->getAttribute( 'name' );
        $tag  = strtolower( $el->tagName );
        if ( $tag === 'input' ) {
            $type = strtolower( $el->getAttribute( 'type' ) );
            if ( $type === 'submit' || ( $type === 'checkbox' && ! $el->hasAttribute( 'checked' ) ) ) {
                continue;
            }
            $value = $el->getAttribute( 'value' );
        } elseif ( $tag === 'select' ) {
            $sel   = $xp->query( './/option[@selected]', $el )->item( 0 ) ?: $xp->query( './/option', $el )->item( 0 );
            $value = $sel ? $sel->getAttribute( 'value' ) : '';
        } else {
            $value = $el->textContent;
        }
        parse_str( $name . '=' . rawurlencode( $value ), $piece );
        $data = array_replace_recursive( $data, $piece );
    }
    return $data;
}

// ── Nothing a writer must do ─────────────────────────────────────

t( 'ui: an article with no Syndication input at all is sent to every network', function() {
    $id = fixture_post(); // never touched the box: no meta of ours exists
    expect_eq( $GLOBALS['mmgrf_test']['meta'][ $id ] ?? [], [] );
    foreach ( [ 'yahoo', 'msn', 'newsbreak' ] as $network ) {
        $xml = mmgrf_render_feed( mmgrf_get_profile( $network ), mmgrf_network_options( $network ), [] );
        expect_eq( substr_count( $xml, '<item>' ), 1, $network );
    }
} );

t( 'ui: saving the Syndication box untouched excludes nothing', function() {
    $id = fixture_post();
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ], 'msn' => [ 'enabled' => 1 ], 'newsbreak' => [ 'enabled' => 1 ] ] );
    $html   = ui_render( fn() => mmgrf_syndication_meta_box( get_post( $id ) ) );
    $fields = ui_form_fields( '<form action="post.php">' . $html . '</form>', 'post.php' );
    mmgrf_save_syndication_meta( $id, $fields );
    foreach ( MMGRF_NETWORKS as $network ) {
        expect_eq( get_post_meta( $id, '_mmgrf_exclude_' . $network, true ), '', "$network not excluded" );
    }
} );

t( 'ui: the editor box says it is automatic and hides the opt-out behind a closed disclosure', function() {
    $id = r5_ready_post( '<p>' . r5_words( 160 ) . '</p>', [ 'yahoo', 'msn', 'newsbreak' ] );
    $html = ui_render( fn() => mmgrf_syndication_meta_box( get_post( $id ) ) );
    expect_contains( $html, 'Nothing to do here.' );
    expect_match( $html, '#<details class="mmgrf-syn-options">#', 'options closed by default' );
    expect_not_contains( strtolower( $html ), 'required if', 'nothing reads as a required step' );
    expect_eq( substr_count( $html, 'class="is-ok"' ), 3, 'one green line per network' );

    update_post_meta( $id, '_mmgrf_exclude_msn', '1' );
    $html = ui_render( fn() => mmgrf_syndication_meta_box( get_post( $id ) ) );
    expect_match( $html, '#<details class="mmgrf-syn-options" open>#', 'an exclusion in force is never hidden' );
    expect_contains( $html, 'class="is-excluded"' );

    foreach ( MMGRF_NETWORKS as $network ) {
        update_post_meta( $id, '_mmgrf_exclude_' . $network, '1' );
    }
    $html = ui_render( fn() => mmgrf_syndication_meta_box( get_post( $id ) ) );
    expect_contains( $html, 'This article is being kept out of syndication (see Options).', 'and a fully kept-out article says so plainly' );
} );

t( 'ui: a brand-new draft is told it will be sent automatically', function() {
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    $id   = mmgrf_test_add_post( [ 'post_status' => 'auto-draft' ] );
    $html = ui_render( fn() => mmgrf_syndication_meta_box( get_post( $id ) ) );
    expect_contains( $html, 'Sent automatically to Yahoo when you publish. Nothing to do here.' );
} );

// ── Per-network verdicts ─────────────────────────────────────────

t( 'ui: verdicts say, network by network, what will happen and why', function() {
    $id = r5_ready_post( '<p>' . r5_words( 90 ) . '</p>', [ 'yahoo', 'msn', 'newsbreak' ] );
    $v  = mmgrf_syndication_check( $id )['verdicts'];
    expect_eq( $v['yahoo']['state'], 'blocked' );
    expect_eq( $v['yahoo']['reason'], 'Article under 150 words' );
    expect_eq( $v['msn']['state'], 'ok', 'MSN has no word floor' );
    expect_eq( $v['newsbreak']['state'], 'ok' );

    get_post( $id )->post_title = 'Too Short';
    expect_eq( mmgrf_syndication_check( $id )['verdicts']['msn']['state'], 'blocked' );

    update_post_meta( $id, '_mmgrf_exclude_newsbreak', '1' );
    expect_eq( mmgrf_syndication_check( $id )['verdicts']['newsbreak']['state'], 'excluded' );
} );

t( 'ui: a missing featured image blocks MSN, and NewsBreak only when the body has no image either', function() {
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ], 'msn' => [ 'enabled' => 1 ], 'newsbreak' => [ 'enabled' => 1 ] ] );
    $bare = mmgrf_test_add_post( [ 'post_title' => 'A Headline Long Enough To Clear Every Network Floor', 'post_content' => '<p>' . r5_words( 160 ) . '</p>' ] );
    $v    = mmgrf_syndication_check( $bare )['verdicts'];
    expect_eq( [ $v['yahoo']['state'], $v['msn']['state'], $v['newsbreak']['state'] ], [ 'note', 'blocked', 'blocked' ] );

    $inline = mmgrf_test_add_post( [ 'post_title' => 'A Headline Long Enough To Clear Every Network Floor', 'post_content' => '<p>' . r5_words( 160 ) . '</p><img src="https://example-brand.com/up/x.jpg">' ] );
    expect_eq( mmgrf_syndication_check( $inline )['verdicts']['newsbreak']['state'], 'ok', 'NewsBreak falls back to the body image' );
} );

t( 'ui: a slideshow post shows it goes out as a slideshow on Yahoo/MSN and as an article on NewsBreak', function() {
    $id = slideshow_post( 5 );
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ], 'msn' => [ 'enabled' => 1 ], 'newsbreak' => [ 'enabled' => 1 ] ] );
    $r = mmgrf_syndication_check( $id );
    expect_eq( $r['verdicts']['yahoo'], [ 'state' => 'ok', 'reason' => 'as a slideshow' ] );
    expect_eq( $r['verdicts']['newsbreak']['state'], 'ok' );
    expect_eq( $r['issues'], [] );
} );

t( 'ui: an over-long MSN headline is no longer something the writer is asked to fix', function() {
    $id = r5_ready_post( '<p>' . r5_words( 160 ) . '</p>', [ 'msn' ] );
    get_post( $id )->post_title = trim( str_repeat( 'Long headline words ', 10 ) );
    $r = mmgrf_syndication_check( $id );
    expect_eq( $r['issues'], [] );
    expect_eq( $r['verdicts']['msn']['state'], 'ok' );
} );

// ── Log: every code has plain language ───────────────────────────

t( 'ui: every log code the plugin can emit has a plain-language label and next step', function() {
    $catalog = mmgrf_code_catalog();
    $emitted = [];
    $dir     = dirname( __DIR__ ) . '/scoreline-feeds';
    foreach ( array_merge( glob( "$dir/includes/*.php" ), glob( "$dir/profiles/*.php" ) ) as $file ) {
        if ( basename( $file ) === 'health.php' ) {
            continue; // site-health problem codes are not log codes
        }
        $src = file_get_contents( $file );
        preg_match_all( "/'code'\\s*=>\\s*'([a-z_]+)'/", $src, $a );
        preg_match_all( "/->fail\\(\\s*'([a-z_]+)'/", $src, $b );
        preg_match_all( "/mmgrf_skip_log_add\\([^;]*?,\\s*'([a-z]+_[a-z_]+)'\\s*[,)]/s", $src, $c );
        $emitted = array_merge( $emitted, $a[1], $b[1], $c[1] );
    }
    $emitted = array_unique( $emitted );
    expect_true( count( $emitted ) > 35, 'the scan found the codes (' . count( $emitted ) . ')' );
    $missing = array_values( array_diff( $emitted, array_keys( $catalog ) ) );
    expect_eq( $missing, [], 'codes with no entry in mmgrf_code_catalog()' );
    foreach ( $catalog as $code => $info ) {
        expect_true( is_array( $info ) && count( $info ) === 2 && $info[0] !== '' && $info[1] !== '', $code );
    }
} );

t( 'ui: the log table speaks plain language, shows what to do, and never links a settings entry to a post', function() {
    $id = fixture_post( [ 'post_title' => 'An Article That Was Too Short For Yahoo' ] );
    mmgrf_skip_log_add( 'yahoo', $id, 'An Article That Was Too Short For Yahoo', 'body_below_word_floor', '87 words post-sanitization (floor 150)' );
    mmgrf_skip_log_add( 'msn-slideshows', $id, 'An Article That Was Too Short For Yahoo', 'slide_below_minimum', '"One": 900x600', 'warn' );
    mmgrf_skip_log_add( 'aigeon:nascar', 0, '(feed settings)', 'filter_term_missing', 'include tags "nascar" matches no existing term' );
    $html = ui_render( fn() => mmgrf_render_skip_log_table( mmgrf_skip_log_get() ) );
    expect_contains( $html, 'Article under 150 words' );
    expect_contains( $html, 'Yahoo only takes articles of 150 words or more.' );
    expect_contains( $html, '<span class="mmgrf-pill is-skip">Not sent</span>' );
    expect_contains( $html, '<span class="mmgrf-pill is-warn">Sent, with a note</span>' );
    expect_contains( $html, 'MSN slideshows' );
    expect_contains( $html, 'Email feed (nascar)' );
    expect_eq( substr_count( $html, 'post.php?post=' ), 2, 'the settings-level row has no post link' );
    expect_not_contains( $html, 'post.php?post=0' );
} );

// ── Settings page ────────────────────────────────────────────────

t( 'ui: saving the settings page unchanged changes nothing (every stored setting has a field)', function() {
    update_option( 'permalink_structure', '/%postname%/' );
    $options = array_merge( mmgrf_get_options(), [
        'feed_title' => 'Golfer Insight', 'feed_description' => 'Golf & more', 'post_count' => 40, 'image_size' => 'medium_large',
        'categories' => 'news', 'exclude_cats' => 'promo', 'tags' => 'pga', 'exclude_tags' => 'hidden',
        'exclude_networks_cats' => 'sponsored', 'exclude_networks_tags' => 'partner', 'affiliate_domain_action' => 'unwrap',
        'affiliate_path_action' => 'strip_paragraph', 'slideshow_tag' => 'gallery', 'video_tag' => 'clip', 'settle_seconds' => 120,
        'enhance_default_feeds' => 0, 'utm_source' => 'nl', 'utm_medium' => 'mail',
    ] );
    $networks = [
        'newsbreak' => [ 'enabled' => true, 'slug' => 'nb', 'post_count' => 20, 'image_size' => 'full', 'utm_enabled' => true, 'nb_scripts' => '<script src="https://t.example/a.js?x=1&amp;y=2"></script>' ],
        'msn'       => [ 'enabled' => false, 'slug' => 'msn', 'post_count' => 0, 'image_size' => '', 'utm_enabled' => false, 'nb_scripts' => '' ],
        'yahoo'     => [ 'enabled' => true, 'slug' => 'yahoo', 'post_count' => 0, 'image_size' => '', 'utm_enabled' => false, 'nb_scripts' => '' ],
    ];
    update_option( 'mmgrf_options', $options );
    update_option( 'mmgrf_networks', $networks );

    $posted = ui_form_fields( ui_render( 'mmgrf_settings_page' ), 'options.php' );
    $saved  = mmgrf_sanitize_options( $posted['mmgrf_options'] ?? [] );
    ksort( $saved );
    ksort( $options );
    expect_eq( $saved, $options, 'mmgrf_options round-trips' );
    expect_eq( mmgrf_sanitize_networks( $posted['mmgrf_networks'] ?? [] ), $networks, 'mmgrf_networks round-trips' );
    expect_eq( $GLOBALS['mmgrf_test']['settings_errors'], [], 'and nothing is rejected' );
} );

t( 'ui: the settings page leads with the networks, their feed URLs and whether each is being served', function() {
    update_option( 'permalink_structure', '/%postname%/' );
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    fixture_post();
    mmgrf_get_feed_output( 'yahoo' ); // a partner polled it
    $html = ui_render( 'mmgrf_settings_page' );
    expect_true( strpos( $html, 'data-panel="networks"' ) < strpos( $html, 'data-panel="email"' ), 'networks before the legacy email feed' );
    expect_contains( $html, 'Writers do not need to do anything.' );
    expect_contains( $html, 'data-copy="https://example-brand.com/feed/yahoo/"' );
    expect_contains( $html, 'data-copy="https://example-brand.com/feed/yahoo-slideshows/"' );
    expect_match( $html, '#1 item · last served \d+ seconds ago#' );
    expect_contains( $html, 'Not requested in the last 24 hours', 'the never-polled typed feeds say so' );
    expect_contains( $html, 'Switch on and save to start serving this feed.', 'disabled networks show no URL to hand out' );
    expect_not_contains( $html, 'data-copy="https://example-brand.com/feed/msn/"' );
} );

t( 'ui: actions are menus, not typed keywords; a mistyped category slug is called out on the page', function() {
    update_option( 'mmgrf_options', [ 'exclude_networks_cats' => 'sponsored, sponsered' ] );
    $GLOBALS['mmgrf_test']['terms']['category']['sponsored'] = (object) [ 'term_id' => 990, 'name' => 'Sponsored &amp; Partner', 'slug' => 'sponsored' ];
    $html = ui_render( 'mmgrf_settings_page' );
    expect_match( $html, '#<select id="mmgrf_options_affiliate_domain_action" name="mmgrf_options\[affiliate_domain_action\]">#' );
    expect_contains( $html, '<option value="skip_item" selected>Do not send the article to Yahoo</option>' );
    expect_contains( $html, 'Matches: Sponsored &amp; Partner' );
    expect_contains( $html, 'No category with the slug <code>sponsered</code> exists on this site' );
} );

t( 'ui: the number of articles not sent links through to the log, counted per article', function() {
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ] ] );
    mmgrf_skip_log_add( 'yahoo', 11, 'A', 'body_below_word_floor', '1' );
    mmgrf_skip_log_add( 'yahoo', 11, 'A', 'description_below_word_floor', '1' );
    mmgrf_skip_log_add( 'yahoo', 12, 'B', 'body_below_word_floor', '2' );
    mmgrf_skip_log_add( 'yahoo', 13, 'C', 'inline_image_rewritten', 'x', 'warn' );
    expect_eq( mmgrf_ui_withheld_counts(), [ 'yahoo' => 2 ] );
    expect_contains( ui_render( 'mmgrf_settings_page' ), 'data-network="yahoo">2 not sent this week</a>' );
} );

// ── Posts list ───────────────────────────────────────────────────

t( 'ui: the Posts list shows sent / not sent / kept out from stored state alone', function() {
    update_option( 'mmgrf_networks', [ 'yahoo' => [ 'enabled' => 1 ], 'msn' => [ 'enabled' => 1 ] ] );
    expect_true( isset( mmgrf_posts_column( [ 'title' => 'Title' ] )['mmgrf_syndication'] ) );

    $sent = fixture_post();
    expect_contains( mmgrf_posts_column_html( $sent ), 'is-sent' );

    $short = fixture_post();
    mmgrf_skip_log_add( 'yahoo', $short, 'x', 'body_below_word_floor', '80 words' );
    $html = mmgrf_posts_column_html( $short );
    expect_contains( $html, 'Yahoo: article under 150 words' );
    expect_not_contains( $html, 'MSN', 'only the network that did not take it is named' );

    $out = fixture_post();
    update_post_meta( $out, '_mmgrf_exclude_yahoo', '1' );
    update_post_meta( $out, '_mmgrf_exclude_msn', '1' );
    expect_contains( mmgrf_posts_column_html( $out ), 'Kept out' );

    $draft = mmgrf_test_add_post( [ 'post_status' => 'draft' ] );
    expect_contains( mmgrf_posts_column_html( $draft ), 'is-none' );

    update_option( 'mmgrf_networks', [] );
    expect_false( isset( mmgrf_posts_column( [ 'title' => 'Title' ] )['mmgrf_syndication'] ), 'no column when no network is on' );
} );

t( 'ui: wording on client sites is neutral (no internal company or partner names in writer-facing text)', function() {
    $id   = r5_ready_post( '<p>' . r5_words( 160 ) . '</p>', [ 'yahoo', 'msn', 'newsbreak' ] );
    $text = strip_tags( ui_render( fn() => mmgrf_syndication_meta_box( get_post( $id ) ) ) );
    foreach ( [ 'MMG', 'Aigeon', 'Austin' ] as $internal ) {
        expect_not_contains( $text, $internal );
    }
    $catalog = json_encode( mmgrf_code_catalog() );
    expect_not_contains( $catalog, 'MMG' );
    expect_not_contains( $catalog, 'Aigeon' );
} );
