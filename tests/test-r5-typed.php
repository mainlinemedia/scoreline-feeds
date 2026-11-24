<?php
// Audit round 5 (2026-11-22) — slideshow and video feeds. These modules had
// never been exercised against a partner; fixtures here use the markup
// WordPress actually produces (block figures, sized renditions, wrappers,
// shortcode output), not the bare tags the first tests used.

function r5_slideshow_xml( $network = 'yahoo' ) {
    update_option( 'mmgrf_networks', [ $network => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_slideshow_feed( $network, mmgrf_slideshow_options( $network ) );
    expect_well_formed( $xml, "$network slideshows" );
    return $xml;
}

function r5_video_xml( $network = 'yahoo' ) {
    update_option( 'mmgrf_networks', [ $network => [ 'enabled' => 1 ] ] );
    $xml = mmgrf_render_video_feed( $network, mmgrf_video_options( $network ) );
    expect_well_formed( $xml, "$network videos" );
    return $xml;
}

function r5_log_codes( $network ) {
    return array_values( array_column( array_filter( mmgrf_skip_log_get(), fn( $e ) => $e['network'] === $network ), 'code' ) );
}

/** A block-editor slide section: heading, sized figure with caption, paragraph. */
function r5_block_slide( $i, $w = 1920, $h = 1080 ) {
    slides_attachment( 7600 + $i, "https://example-brand.com/up/blk$i", $w, $h );
    return "<h2 class=\"wp-block-heading\">Golfer Number $i</h2>"
        . "<figure class=\"wp-block-image size-large\"><img src=\"https://example-brand.com/up/blk$i-1024x683.jpg\" alt=\"\" class=\"wp-image-" . ( 7600 + $i ) . "\"/><figcaption class=\"wp-element-caption\">Photo: Jane Doe/Getty Images</figcaption></figure>"
        . "<p>Career notes for golfer $i in a sentence.</p>";
}

function r5_block_slideshow( $body, $over = [] ) {
    $id = mmgrf_test_add_post( array_merge( [
        'post_title'        => 'The Five Greatest Golfers Of The Modern Era',
        'post_content'      => $body,
        'post_date_gmt'     => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
    ], $over ) );
    slides_attachment( 7699, 'https://example-brand.com/up/blkcover', 2560, 1440 );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 7699;
    mmgrf_test_set_terms( $id, 'post_tag', [ 'Slideshow' ] );
    return $id;
}

// ── Withholding rules (the critical finding) ─────────────────────

t( 'r5 typed feeds: "Sponsored content" / per-network exclusion is honoured', function() {
    $s = slideshow_post( 5 );
    $v = video_post( [ 'post_title' => 'A Second Tagged Video Post Headline' ] );
    foreach ( [ 'yahoo', 'msn' ] as $n ) {
        update_post_meta( $s, '_mmgrf_exclude_' . $n, '1' );
        update_post_meta( $v, '_mmgrf_exclude_' . $n, '1' );
    }
    foreach ( [ 'yahoo', 'msn' ] as $n ) {
        expect_eq( substr_count( r5_slideshow_xml( $n ), '<item>' ), 0, "$n slideshows" );
        expect_eq( substr_count( r5_video_xml( $n ), '<item>' ), 0, "$n videos" );
    }
} );

t( 'r5 typed feeds: the policy tag and category exclusions are honoured', function() {
    $s = slideshow_post( 5 );
    mmgrf_test_set_terms( $s, 'post_tag', [ 'sponsored' ] );
    $v = video_post();
    mmgrf_test_set_terms( $v, 'category', [ 'Partner Content' ] );
    update_option( 'mmgrf_options', [ 'exclude_networks_tags' => 'sponsored', 'exclude_networks_cats' => 'partner-content' ] );
    expect_eq( substr_count( r5_slideshow_xml(), '<item>' ), 0 );
    expect_eq( substr_count( r5_video_xml(), '<item>' ), 0 );
} );

t( 'r5 typed feeds: password-protected and just-published posts are withheld', function() {
    slideshow_post( 5, [ 'post_password' => 'x' ] );
    expect_eq( substr_count( r5_slideshow_xml(), '<item>' ), 0 );
    mmgrf_test_reset();
    video_post( [ 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ] );
    expect_eq( substr_count( r5_video_xml(), '<item>' ), 0, 'inside the settle window' );
} );

t( 'r5 typed feeds: a site with no marker tag gets an empty feed, not term id 1', function() {
    fixture_post();
    $xml = r5_slideshow_xml();
    expect_eq( substr_count( $xml, '<item>' ), 0 );
    expect_eq( $GLOBALS['mmgrf_test']['last_query_args'], null, 'no query is even run' );
} );

t( 'r5 typed feeds: a post tagged both slideshow and video ships only as a slideshow', function() {
    $id = slideshow_post( 5 );
    mmgrf_test_set_terms( $id, 'post_tag', [ 'Video' ] );
    expect_eq( substr_count( r5_slideshow_xml(), '<item>' ), 1 );
    expect_eq( substr_count( r5_video_xml(), '<item>' ), 0 );
} );

t( 'r5 typed feeds: one post that throws costs that item, not the feed', function() {
    slideshow_post( 5, [ 'post_title' => 'The Exploding Slideshow Post Headline', 'post_content' => '<p>BOOM</p>' ] );
    add_filter( 'the_content', function( $c ) {
        if ( strpos( $c, 'BOOM' ) !== false ) {
            throw new RuntimeException( 'gallery shortcode blew up' );
        }
        return $c;
    } );
    $xml = r5_slideshow_xml();
    expect_contains( $xml, '</channel>' );
    expect_true( in_array( 'render_error', r5_log_codes( 'yahoo-slideshows' ), true ) );
} );

// ── Slideshow parsing against real editor markup ─────────────────

t( 'r5 slideshow: block-editor markup with sized renditions and captions parses into clean slides', function() {
    $body = '<p>Intro paragraph with plenty of words for a description.</p>';
    for ( $i = 1; $i <= 4; $i++ ) {
        $body .= r5_block_slide( $i );
    }
    r5_block_slideshow( $body );
    $xml = r5_slideshow_xml();
    expect_eq( substr_count( $xml, '<media:content ' ), 4 );
    expect_contains( $xml, '<media:content url="https://example-brand.com/up/blk1.jpg"', 'full-size rendition, not the 1024px insert' );
    expect_contains( $xml, '<media:description>Career notes for golfer 1 in a sentence.</media:description>' );
    expect_not_contains( $xml, 'Getty Images Career notes', 'the photo caption is not slide copy' );
} );

t( 'r5 slideshow: scripts, styles, comments and plugin chrome never reach a description', function() {
    $ad   = '<!-- A generated by theme --><script>var td_screen_width = window.innerWidth; googletag.cmd.push(function(){});</script><style>.x{color:red}</style><!-- end A -->';
    $body = '<p>Intro paragraph with plenty of words for a description.</p>' . $ad;
    for ( $i = 1; $i <= 4; $i++ ) {
        $body .= r5_block_slide( $i ) . ( $i === 2 ? $ad : '' );
    }
    r5_block_slideshow( $body );
    $xml = r5_slideshow_xml();
    foreach ( [ 'td_screen_width', 'googletag', 'generated by theme', 'color:red' ] as $leak ) {
        expect_not_contains( $xml, $leak );
    }
} );

t( 'r5 slideshow: the integrator content hooks fire for slideshow posts too', function() {
    slideshow_post( 5 );
    $seen = [];
    add_action( 'mmgrf_pre_content', function( $id, $net ) use ( &$seen ) { $seen[] = "pre:$net"; } );
    add_action( 'mmgrf_post_content', function( $id, $net ) use ( &$seen ) { $seen[] = "post:$net"; } );
    r5_slideshow_xml();
    expect_eq( $seen, [ 'pre:yahoo', 'post:yahoo' ] );
} );

t( 'r5 slideshow: headings inside group/column wrappers are still slides', function() {
    $body = '<p>Intro paragraph with plenty of words for a description.</p>';
    for ( $i = 1; $i <= 4; $i++ ) {
        $slide = r5_block_slide( $i );
        $body .= $i % 2 ? '<div class="wp-block-group"><div class="wp-block-group__inner-container">' . $slide . '</div></div>' : $slide;
    }
    r5_block_slideshow( $body );
    $parsed = mmgrf_parse_slideshow( get_post( array_key_last( $GLOBALS['mmgrf_test']['posts'] ) )->post_content );
    expect_eq( array_column( $parsed['slides'], 'title' ), [ 'Golfer Number 1', 'Golfer Number 2', 'Golfer Number 3', 'Golfer Number 4' ] );
    expect_eq( $parsed['slides'][1]['text'], 'Career notes for golfer 2 in a sentence.', 'no text bleeding in from the wrapped neighbour' );
} );

t( 'r5 slideshow: a paginated listicle ships every page of slides', function() {
    $body = '<p>Intro paragraph with plenty of words for a description.</p>';
    for ( $i = 1; $i <= 4; $i++ ) {
        $body .= r5_block_slide( $i ) . ( $i === 2 ? '<!--nextpage-->' : '' );
    }
    r5_block_slideshow( $body );
    expect_eq( substr_count( r5_slideshow_xml(), '<media:content ' ), 4 );
} );

t( 'r5 slideshow: photo-above-heading layout pairs each title with its own photo', function() {
    $names = [ 'tiger-woods' => 'Tiger Woods', 'rory-mcilroy' => 'Rory McIlroy', 'scottie-scheffler' => 'Scottie Scheffler', 'jon-rahm' => 'Jon Rahm' ];
    $body  = '';
    $i     = 0;
    foreach ( $names as $slug => $name ) {
        slides_attachment( 7700 + ( ++$i ), "https://example-brand.com/up/$slug" );
        $body .= "<figure><img src=\"https://example-brand.com/up/$slug.jpg\"></figure><h2>$name</h2><p>Why $name is on this list of greats.</p>";
    }
    $parsed = mmgrf_parse_slideshow( $body );
    expect_eq( $parsed['layout'], 'image-first' );
    foreach ( $parsed['slides'] as $slide ) {
        $slug = strtolower( str_replace( ' ', '-', $slide['title'] ) );
        expect_contains( $slide['image']['url'], $slug, $slide['title'] . ' must not get the next golfer\'s photo' );
    }
    expect_eq( count( $parsed['slides'] ), 4 );
} );

t( 'r5 slideshow: a cover photo plus an image-less closing section is still heading-first', function() {
    slides_attachment( 7750, 'https://example-brand.com/up/intro-cover' );
    $body = '<figure><img src="https://example-brand.com/up/intro-cover.jpg"></figure><p>Intro paragraph with plenty of words.</p>';
    for ( $i = 1; $i <= 4; $i++ ) {
        $body .= r5_block_slide( $i );
    }
    $body  .= '<h2>Final Thoughts</h2><p>That is the list for this year.</p>';
    $parsed = mmgrf_parse_slideshow( $body );
    expect_eq( $parsed['layout'], 'heading-first' );
    expect_eq( count( $parsed['slides'] ), 4 );
    expect_contains( $parsed['slides'][0]['image']['url'], 'blk1' );
} );

t( 'r5 slideshow: when the layout cannot be determined the post is withheld, never guessed', function() {
    $body = '';
    for ( $i = 1; $i <= 4; $i++ ) {
        slides_attachment( 7800 + $i, "https://example-brand.com/up/amb$i" );
        $body .= "<img src=\"https://example-brand.com/up/amb$i.jpg\"><h2>Name $i</h2>"; // no text anywhere: no evidence
    }
    r5_block_slideshow( $body );
    expect_eq( substr_count( r5_slideshow_xml(), '<item>' ), 0 );
    expect_true( in_array( 'slideshow_layout_ambiguous', r5_log_codes( 'yahoo-slideshows' ), true ) );
} );

t( 'r5 slideshow: an emoji or hotlinked image ahead of the real photo does not win the slide', function() {
    slides_attachment( 7850, 'https://example-brand.com/up/real' );
    $parsed = mmgrf_parse_slideshow( '<h2>Title One</h2><p>Text <img class="emoji" src="https://s.w.org/images/core/emoji/15/svg/26f3.svg" alt="⛳"></p><figure><img src="https://example-brand.com/up/real.jpg"></figure>' );
    expect_eq( $parsed['slides'][0]['image']['url'], 'https://example-brand.com/up/real.jpg' );
} );

t( 'r5 slideshow: line breaks and table cells do not fuse words', function() {
    slides_attachment( 7860, 'https://example-brand.com/up/t1' );
    $parsed = mmgrf_parse_slideshow( '<h2>Stats</h2><img src="https://example-brand.com/up/t1.jpg"><p>5.<br>Tiger Woods</p><table><tr><td>Wins</td><td>82</td></tr></table>' );
    expect_eq( $parsed['slides'][0]['text'], '5. Tiger Woods Wins 82' );
} );

// ── Slideshow output rules ───────────────────────────────────────

t( 'r5 slideshow: featured image that is also slide 1 does not list that URL twice', function() {
    $id = slideshow_post( 4 );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 7001; // the usual habit
    $GLOBALS['mmgrf_test']['attachments'][7001]['sizes']['2048x2048'] = [ 'https://example-brand.com/up/slide1-2048x1365.jpg', 2048, 1365 ];
    $GLOBALS['mmgrf_test']['attachments'][7001]['sizes']['full']      = [ 'https://example-brand.com/up/slide1.jpg', 4000, 2667 ];
    $xml  = r5_slideshow_xml();
    $urls = item_image_urls( $xml );
    expect_eq( count( $urls ), count( array_unique( $urls ) ), 'every image URL in the item is unique' );
    expect_contains( $xml, '<media:thumbnail url="https://example-brand.com/up/slide1-2048x1365.jpg"' );
} );

t( 'r5 slideshow: the same photo used on two slides is listed once', function() {
    $id   = slideshow_post( 5 );
    $post = get_post( $id );
    $post->post_content = str_replace( 'slide4.jpg', 'slide1-1024x683.jpg', $post->post_content );
    $xml  = r5_slideshow_xml();
    expect_eq( substr_count( $xml, 'url="https://example-brand.com/up/slide1.jpg"' ), 1 );
    expect_true( in_array( 'slide_duplicate_image', r5_log_codes( 'yahoo-slideshows' ), true ) );
} );

t( 'r5 slideshow: yahoo withholds WebP/AVIF slides (JPEG, PNG and GIF only)', function() {
    slideshow_post( 5 );
    $GLOBALS['mmgrf_test']['attachments'][7002]['mime'] = 'image/webp';
    $xml = r5_slideshow_xml();
    expect_not_contains( $xml, 'slide2.jpg' );
    expect_eq( substr_count( $xml, '<media:content ' ), 4 );
    expect_true( in_array( 'image_format_unsupported', r5_log_codes( 'yahoo-slideshows' ), true ) );
} );

t( 'r5 slideshow: yahoo word floors — title more than one word, description more than three', function() {
    slideshow_post( 5, [ 'post_title' => 'Bags' ] );
    expect_eq( substr_count( r5_slideshow_xml(), '<item>' ), 0 );
    expect_true( in_array( 'title_empty', r5_log_codes( 'yahoo-slideshows' ), true ) );
    mmgrf_test_reset();
    $id = slideshow_post( 5 );
    get_post( $id )->post_content = str_replace( 'Intro paragraph with enough words to serve as the description.', 'Three word intro', get_post( $id )->post_content );
    expect_eq( substr_count( r5_slideshow_xml(), '<item>' ), 0 );
    $entry = array_values( array_filter( mmgrf_skip_log_get(), fn( $e ) => $e['code'] === 'description_below_word_floor' ) );
    expect_contains( $entry[0]['detail'], 'intro paragraph', 'the log says how to fix it' );
} );

t( 'r5 slideshow: a post that opens straight on a heading uses the first slide text as its description', function() {
    $id = slideshow_post( 5 );
    get_post( $id )->post_content = str_replace( '<p>Intro paragraph with enough words to serve as the description.</p>', '', get_post( $id )->post_content );
    $xml = r5_slideshow_xml();
    expect_contains( $xml, '<description><![CDATA[Detail text for slide 1 with several words.]]></description>' );
} );

t( 'r5 slideshow: msn keywords are decoded and never include the routing tag', function() {
    $id = slideshow_post( 5 );
    $GLOBALS['mmgrf_test']['terms']['post_tag']['qa'] = (object) [ 'term_id' => 881, 'name' => 'Q&amp;A, Live', 'slug' => 'qa' ];
    $GLOBALS['mmgrf_test']['post_terms'][ $id ]['post_tag'][] = 'qa';
    $xml = r5_slideshow_xml( 'msn' );
    expect_contains( $xml, '<media:keywords>Q&amp;A Live</media:keywords>' );
    expect_not_contains( $xml, 'Slideshow</media:keywords>' );
} );

// ── Video ────────────────────────────────────────────────────────

t( 'r5 video: a file hosted on another site is never syndicated', function() {
    video_post( [ 'post_content' => '<p>Intro words for the clip here.</p><figure class="wp-block-video"><video controls src="https://cdn.other-publisher.com/media/viral-clip.mp4"></video></figure>' ], false );
    $xml = r5_video_xml();
    expect_eq( substr_count( $xml, '<item>' ), 0 );
    expect_true( in_array( 'video_not_self_hosted', r5_log_codes( 'yahoo-videos' ), true ) );
} );

t( 'r5 video: a relative file URL ships absolute', function() {
    video_post( [ 'post_content' => '<p>Intro words for the clip here.</p><video controls src="/up/clip.mp4"></video>' ] );
    expect_contains( r5_video_xml(), '<media:content url="https://example-brand.com/up/clip.mp4"' );
} );

t( 'r5 video: classic [video] shortcode output resolves despite its cache-busting query', function() {
    video_post( [ 'post_content' => '<p>Intro words for the clip here.</p><div style="width: 640px;" class="wp-video"><video class="wp-video-shortcode" id="video-1-1" width="640" height="360" preload="metadata" controls="controls"><source type="video/mp4" src="https://example-brand.com/up/clip.mp4?_=1" /><a href="https://example-brand.com/up/clip.mp4">https://example-brand.com/up/clip.mp4</a></video></div>' ] );
    $xml = r5_video_xml();
    expect_contains( $xml, '<media:content url="https://example-brand.com/up/clip.mp4" type="video/mp4" medium="video" duration="185" width="1920" height="1080"', 'stable URL, metadata intact' );
    expect_not_contains( $xml, '?_=1' );
    expect_not_contains( $xml, 'clip.mp4</description>', 'the fallback link text is not the description' );
} );

t( 'r5 video: an audio player above the video, or a webm source before the mp4, does not block the item', function() {
    video_post( [ 'post_content' => '<audio controls><source src="https://example-brand.com/up/pod.mp3" type="audio/mpeg"></audio><p>Intro words for the clip here.</p><video controls><source src="https://example-brand.com/up/clip.webm" type="video/webm"><source src="https://example-brand.com/up/clip.mp4" type="video/mp4"></video>' ] );
    $xml = r5_video_xml();
    expect_eq( substr_count( $xml, '<item>' ), 1 );
    expect_contains( $xml, 'url="https://example-brand.com/up/clip.mp4"' );
} );

t( 'r5 video: audio-only posts are still rejected, with the type named', function() {
    video_post( [ 'post_content' => '<p>Intro.</p><video controls src="https://example-brand.com/up/pod.mp3"></video>' ], false );
    expect_eq( substr_count( r5_video_xml(), '<item>' ), 0 );
    $entry = array_values( array_filter( mmgrf_skip_log_get(), fn( $e ) => $e['code'] === 'video_format_unsupported' ) );
    expect_contains( $entry[0]['detail'], 'audio/mpeg' );
} );

t( 'r5 video: msn declares the canonical http MRSS namespace; yahoo keeps its documented https one', function() {
    video_post();
    expect_contains( r5_video_xml( 'msn' ), 'xmlns:media="http://search.yahoo.com/mrss/"' );
    expect_contains( r5_video_xml( 'yahoo' ), 'xmlns:media="https://search.yahoo.com/mrss/"' );
} );

t( 'r5 video: yahoo withholds a clip below its minimum size; msn withholds one with no duration', function() {
    video_post();
    $GLOBALS['mmgrf_test']['attachments'][7500]['meta']['width']  = 640;
    $GLOBALS['mmgrf_test']['attachments'][7500]['meta']['height'] = 360;
    expect_eq( substr_count( r5_video_xml( 'yahoo' ), '<item>' ), 0 );
    expect_true( in_array( 'video_below_minimum', r5_log_codes( 'yahoo-videos' ), true ) );

    $GLOBALS['mmgrf_test']['attachments'][7500]['meta']['length'] = 0;
    expect_eq( substr_count( r5_video_xml( 'msn' ), '<item>' ), 0 );
    expect_true( in_array( 'video_duration_unknown', r5_log_codes( 'msn-videos' ), true ) );
} );

t( 'r5 video: vertical and square clips at the published sizes pass', function() {
    expect_true( mmgrf_video_meets_yahoo_minimum( 1280, 720 ) );
    expect_true( mmgrf_video_meets_yahoo_minimum( 720, 1280 ) );
    expect_true( mmgrf_video_meets_yahoo_minimum( 1080, 1080 ) );
    expect_false( mmgrf_video_meets_yahoo_minimum( 640, 360 ) );
    expect_false( mmgrf_video_meets_yahoo_minimum( 720, 720 ) );
} );

t( 'r5 video: "shipped without duration" is only logged for items that actually shipped', function() {
    video_post( [], true, false ); // no thumbnail -> withheld
    $GLOBALS['mmgrf_test']['attachments'][7500]['meta']['length'] = 0;
    r5_video_xml( 'yahoo' );
    $codes = r5_log_codes( 'yahoo-videos' );
    expect_true( in_array( 'no_featured_image', $codes, true ) );
    expect_false( in_array( 'video_duration_unknown', $codes, true ) );
} );

t( 'r5 video: msn never uses a rights-flagged image as the thumbnail', function() {
    video_post();
    update_post_meta( 7499, '_mmgrf_no_syndication_rights', '1' );
    expect_eq( substr_count( r5_video_xml( 'msn' ), '<item>' ), 0 );
    expect_eq( substr_count( r5_video_xml( 'yahoo' ), '<item>' ), 1, 'yahoo has no rights concept' );
} );

t( 'r5 video: an aligned video block caption stays out of the description', function() {
    video_post( [ 'post_content' => '<p>Intro paragraph describing this exclusive interview in several words.</p><figure class="wp-block-video aligncenter"><video controls src="https://example-brand.com/up/clip.mp4"></video><figcaption class="wp-element-caption">Courtesy of the team</figcaption></figure>' ] );
    $xml = r5_video_xml();
    expect_contains( $xml, '<description><![CDATA[Intro paragraph describing this exclusive interview in several words.]]></description>' );
} );

t( 'r5 video: keywords are capped at ten, decoded, and exclude the routing tag', function() {
    $id = video_post();
    mmgrf_test_set_terms( $id, 'post_tag', [ 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven' ] );
    preg_match( '#<media:keywords>(.*?)</media:keywords>#', r5_video_xml(), $m );
    $terms = array_map( 'trim', explode( ',', $m[1] ) );
    expect_eq( count( $terms ), 10 );
    expect_false( in_array( 'Video', $terms, true ) );
} );
