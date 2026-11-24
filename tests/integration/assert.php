<?php
/**
 * Integration assertions — plain PHP CLI, talks to a running WordPress over
 * HTTP exactly as a partner's fetcher does.
 *
 *   php tests/integration/assert.php http://127.0.0.1:8080 /path/to/wordpress
 *
 * Seeded by tests/integration/seed.php. Every check here is something the
 * stub suite cannot prove, because it depends on what real WordPress core
 * returns or on when in the request core acts.
 */

[ , $site, $wp_root ] = $argv + [ null, 'http://127.0.0.1:8080', getcwd() ];
$site = rtrim( $site, '/' );

$failures = 0;
$checks   = 0;
function check( $cond, $label, $detail = '' ) {
    global $failures, $checks;
    $checks++;
    if ( $cond ) {
        echo "ok    $label\n";
        return true;
    }
    $failures++;
    echo "FAIL  $label" . ( $detail !== '' ? "\n      " . str_replace( "\n", "\n      ", substr( (string) $detail, 0, 1500 ) ) : '' ) . "\n";
    return false;
}

/** GET a URL. Returns ['status'=>int, 'headers'=>[lower-name=>value], 'body'=>string]. */
function fetch( $url, $headers = [] ) {
    $ctx  = stream_context_create( [ 'http' => [ 'method' => 'GET', 'header' => implode( "\r\n", $headers ), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60 ] ] );
    $body = @file_get_contents( $url, false, $ctx );
    $out  = [ 'status' => 0, 'headers' => [], 'body' => $body === false ? '' : $body ];
    foreach ( $http_response_header ?? [] as $line ) {
        if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $line, $m ) ) {
            $out['status'] = (int) $m[1];
        } elseif ( strpos( $line, ':' ) !== false ) {
            [ $k, $v ] = explode( ':', $line, 2 );
            $out['headers'][ strtolower( trim( $k ) ) ] = trim( $v );
        }
    }
    return $out;
}

function xml_errors( $xml ) {
    libxml_use_internal_errors( true );
    libxml_clear_errors();
    $doc = new DOMDocument();
    $doc->loadXML( $xml );
    $errs = array_map( fn( $e ) => trim( $e->message ) . ' (line ' . $e->line . ')', libxml_get_errors() );
    libxml_clear_errors();
    return $errs;
}

function wp( $args ) {
    global $wp_root;
    exec( 'wp --path=' . escapeshellarg( $wp_root ) . ' ' . $args . ' 2>&1', $out, $code );
    return [ $code, implode( "\n", $out ) ];
}

function items( $xml ) {
    preg_match_all( '#<item>.*?</item>#s', $xml, $m );
    return $m[0];
}

function item_with( $xml, $needle ) {
    foreach ( items( $xml ) as $item ) {
        if ( strpos( $item, $needle ) !== false ) {
            return $item;
        }
    }
    return '';
}

function image_urls( $item ) {
    preg_match_all( '/<img\b[^>]*?\ssrc="([^"]+)"|<media:(?:content|thumbnail)\b[^>]*?\surl="([^"]+)"/i', $item, $m, PREG_SET_ORDER );
    return array_map( fn( $x ) => html_entity_decode( $x[1] !== '' ? $x[1] : $x[2] ), $m );
}

[ , $json ] = wp( 'option get mmgrf_it --format=json' );
$it = json_decode( $json, true );
check( is_array( $it ) && ! empty( $it['article'] ), 'seed data is present', $json );

$feeds = [
    'yahoo'            => "$site/feed/yahoo/",
    'msn'              => "$site/feed/msn/",
    'newsbreak'        => "$site/feed/newsbreak/",
    'aigeon'           => "$site/?feed=raw-feed-raw-feed",
    'tag feed'         => "$site/?feed=nascar-raw-feed",
    'yahoo slideshows' => "$site/feed/yahoo-slideshows/",
    'msn slideshows'   => "$site/feed/msn-slideshows/",
    'yahoo videos'     => "$site/feed/yahoo-videos/",
    'default feed'     => "$site/feed/",
];

// ── Every route answers, on the first request, with a well-formed document ──
// (The pretty URLs resolving at all proves the rewrite self-heal: nothing
// here ever saved Settings → Permalinks after the networks were enabled.)
$xml = [];
foreach ( $feeds as $name => $url ) {
    $r = fetch( $url );
    check( $r['status'] === 200, "$name: HTTP 200", "status {$r['status']} for $url\n" . substr( $r['body'], 0, 400 ) );
    $errs = xml_errors( $r['body'] );
    check( $errs === [], "$name: well-formed XML", implode( "\n", $errs ) );
    check( strpos( $r['body'], '&amp;amp;' ) === false && strpos( $r['body'], '&amp;#' ) === false, "$name: nothing double-escaped" );
    if ( $name !== 'default feed' ) {
        check( strpos( $r['headers']['content-type'] ?? '', 'application/rss+xml' ) === 0, "$name: content type", $r['headers']['content-type'] ?? '(none)' );
        check( strpos( $r['body'], '<generator>Scoreline Feeds ' ) !== false, "$name: served by this plugin" );
    }
    $xml[ $name ] = $r['body'];
}

// ── Names WordPress stores entity-encoded arrive decoded exactly once ──
$sx = simplexml_load_string( $xml['yahoo'] );
check( (string) $sx->channel->title === "Bob's Sports & More", 'channel title decoded', (string) $sx->channel->title );
check( (string) $sx->channel->description === 'Scores & stories', 'channel description decoded', (string) $sx->channel->description );
check( strpos( $xml['yahoo'], '<category><![CDATA[News & Views]]></category>' ) !== false, 'category name decoded inside CDATA' );
check( strpos( $xml['yahoo'], '<dc:creator><![CDATA[Staff & Wire Reports]]></dc:creator>' ) !== false, 'author name decoded inside CDATA' );

// ── Withholding ──────────────────────────────────────────────────
foreach ( [ 'yahoo', 'msn', 'newsbreak' ] as $n ) {
    check( strpos( $xml[ $n ], 'Sticky Sponsored' ) === false, "$n: sticky post in a child of the excluded category is withheld" );
    check( strpos( $xml[ $n ], 'FRESHBODY' ) === false, "$n: a post published seconds ago is inside the settle window" );
}
foreach ( $xml as $name => $body ) {
    // WordPress's own /feed/ lists the protected post's title (without its
    // content) — that is core's behaviour, not a Scoreline feed.
    $absent = strpos( $body, 'SECRETBODY' ) === false && ( $name === 'default feed' || strpos( $body, 'Password Protected' ) === false );
    check( $absent, "$name: password-protected post never ships" );
}
check( strpos( $xml['aigeon'], 'FRESHBODY' ) !== false, 'aigeon: the legacy feed is not delayed' );
check( count( items( $xml['yahoo'] ) ) === 3, 'yahoo: exactly the three eligible articles', count( items( $xml['yahoo'] ) ) . ' items' );
check( count( items( $xml['msn'] ) ) === 3, 'msn: exactly the three eligible articles', count( items( $xml['msn'] ) ) . ' items' );
check( count( items( $xml['newsbreak'] ) ) === 4, 'newsbreak: three articles plus the slideshow post (NewsBreak has no gallery type; it is an article there)', count( items( $xml['newsbreak'] ) ) . ' items' );
check( strpos( $xml['yahoo'], 'Greatest Golfers' ) === false, 'yahoo: the slideshow post is not also an article' );

// ── Paginated post ───────────────────────────────────────────────
$paged = item_with( $xml['yahoo'], 'Paginated Feature' );
check( strpos( $paged, 'PAGETWO' ) !== false && strpos( $paged, 'PAGETHREE' ) !== false, 'yahoo: every page of a paginated post ships' );

// ── The article: images, structure, dates ────────────────────────
$article = item_with( $xml['yahoo'], 'Chiefs Rally' );
check( $article !== '', 'yahoo: the article is present' );
$urls = image_urls( $article );
check( count( $urls ) === count( array_unique( $urls ) ), 'yahoo: no image URL listed twice in the item', implode( "\n", $urls ) );
check( count( $urls ) === 2, 'yahoo: featured photo once, second photo once', implode( "\n", $urls ) );
check( isset( $urls[0] ) && $urls[0] === $it['big_url'], 'yahoo: lead figure is the full "-scaled" original', ( $urls[0] ?? '(none)' ) . ' vs ' . $it['big_url'] );
check( (bool) preg_match( '#<content:encoded><!\[CDATA\[<figure><img src="#', $article ), 'yahoo: lead figure is the first element of the body' );
check( isset( $urls[1] ) && strpos( $urls[1], 'second-photo-scaled' ) !== false, 'yahoo: inline 1024px rendition of a "-scaled" upload was resolved and upgraded', $urls[1] ?? '(none)' );
check( strpos( $article, 'ends here.The next' ) === false && strpos( $article, 'A grouped sentence ends here.' ) !== false, 'yahoo: unwrapped blocks do not fuse sentences' );
check( strpos( $article, 'Paragraph after a stray closing div.' ) !== false, 'yahoo: a stray closing tag does not truncate the article' );
[ , $gmt ] = wp( 'post get ' . (int) $it['article'] . ' --field=post_date_gmt' );
check( strpos( $article, '<pubDate>' . gmdate( 'D, d M Y H:i:s', strtotime( trim( $gmt ) . ' UTC' ) ) . ' +0000</pubDate>' ) !== false, 'yahoo: pubDate is the GMT publish time on a non-UTC site', trim( $gmt ) );

$nb_article = item_with( $xml['newsbreak'], 'Chiefs Rally' );
preg_match( '#<content:encoded>(.*?)</content:encoded>#s', $nb_article, $m );
$nb_imgs = image_urls( $m[1] ?? '' );
check( count( $nb_imgs ) === count( array_unique( array_map( fn( $u ) => preg_replace( '/-(?:\d+x\d+|scaled)(\.\w+)$/', '$1', $u ), $nb_imgs ) ) ), 'newsbreak: no photo appears twice in the body, at any rendition', implode( "\n", $nb_imgs ) );

// ── Tag feed ─────────────────────────────────────────────────────
check( count( items( $xml['tag feed'] ) ) === 1 && strpos( $xml['tag feed'], 'NASCARBODY' ) !== false, 'tag feed: only the tagged post' );
check( strpos( $xml['tag feed'], "<title>Dale&#039;s NASCAR Feed</title>" ) !== false, 'tag feed: channel title', substr( $xml['tag feed'], 0, 400 ) );

// ── Slideshow ────────────────────────────────────────────────────
foreach ( [ 'yahoo slideshows', 'msn slideshows' ] as $n ) {
    $show = items( $xml[ $n ] );
    check( count( $show ) === 1, "$n: one slideshow item", count( $show ) . " items\n" . substr( $xml[ $n ], 0, 600 ) );
    $one = $show[0] ?? '';
    check( substr_count( $one, '<media:content ' ) === 4, "$n: four slides (headings inside group blocks are found)", substr( $one, 0, 800 ) );
    $u = image_urls( $one );
    check( count( $u ) === count( array_unique( $u ) ), "$n: cover does not repeat a slide URL", implode( "\n", $u ) );
    check( strpos( $one, 'Photo credit line' ) === false, "$n: photo captions are not slide copy" );
    check( strpos( $one, 'Tiger Woods' ) !== false && strpos( $one, 'Jon Rahm' ) !== false, "$n: slide titles" );
}
check( count( items( $xml['yahoo videos'] ) ) === 0, 'yahoo videos: empty but valid when nothing is tagged' );

// ── Settle window: a partner that polled during the hold gets the post
//    afterwards, whichever validator it sends back. (Shortened to a few
//    seconds here; the mechanism is identical at 60.) ──────────────────
$held      = fetch( $feeds['yahoo'] );
$echo_lm   = $held['headers']['last-modified'] ?? '';
$fetched   = gmdate( 'D, d M Y H:i:s' ) . ' GMT';
check( strpos( $held['body'], 'FRESHBODY' ) === false && $echo_lm !== '', 'yahoo: still held on a second poll, with a Last-Modified' );
wp( "eval '\$o = get_option( \"mmgrf_options\" ); \$o[\"settle_seconds\"] = 3; update_option( \"mmgrf_options\", \$o ); mmgrf_touch_config();'" ); // a settings save
sleep( 5 );
$a = fetch( $feeds['yahoo'], [ 'If-Modified-Since: ' . $echo_lm ] );
check( $a['status'] === 200 && strpos( $a['body'], 'FRESHBODY' ) !== false, 'yahoo: released post reaches a client that echoes Last-Modified', "status {$a['status']}" );
$b = fetch( $feeds['yahoo'], [ 'If-Modified-Since: ' . $fetched ] );
check( $b['status'] === 200 && strpos( $b['body'], 'FRESHBODY' ) !== false, 'yahoo: …and a client that sends its own fetch time', "status {$b['status']}" );

wp( "eval '\$o = get_option( \"mmgrf_options\" ); \$o[\"settle_seconds\"] = 0; update_option( \"mmgrf_options\", \$o );'" );
$r = fetch( $feeds['yahoo'] );
check( strpos( $r['body'], 'FRESHBODY' ) !== false, 'yahoo: the released post stays in the feed' );

// ── Conditional GET. WordPress core answers these itself, before the
//    plugin's feed callback runs — so core must be seeing our timestamps. ──
$lm   = $r['headers']['last-modified'] ?? '';
$etag = $r['headers']['etag'] ?? '';
check( $lm !== '', 'yahoo: Last-Modified header present' );
$c = fetch( $feeds['yahoo'], [ 'If-Modified-Since: ' . $lm ] );
check( $c['status'] === 304 && $c['body'] === '', 'yahoo: unchanged feed answers 304 to If-Modified-Since', "status {$c['status']}" );
if ( $etag !== '' ) {
    $c = fetch( $feeds['yahoo'], [ 'If-None-Match: ' . $etag ] );
    check( $c['status'] === 304, 'yahoo: unchanged feed answers 304 to If-None-Match', "status {$c['status']}" );
}
sleep( 2 );
wp( "eval 'mmgrf_touch_config();'" ); // what a settings save does
$c = fetch( $feeds['yahoo'], [ 'If-Modified-Since: ' . $lm ] );
check( $c['status'] === 200, 'yahoo: a settings change defeats the stale 304', "status {$c['status']}" );
if ( $etag !== '' ) {
    $c = fetch( $feeds['yahoo'], [ 'If-None-Match: ' . $etag, 'If-Modified-Since: ' . $lm ] );
    check( $c['status'] === 200, 'yahoo: …for clients that send both validators too', "status {$c['status']}" );
}

// A retraction must reach a partner that already holds the newest stamp.
$lm = $c['headers']['last-modified'] ?? $lm;
sleep( 2 );
wp( 'post update ' . (int) $it['paged'] . ' --post_status=draft' );
$c = fetch( $feeds['yahoo'], [ 'If-Modified-Since: ' . $lm ] );
check( $c['status'] === 200 && strpos( $c['body'], 'Paginated Feature' ) === false, 'yahoo: unpublishing a post is delivered, not hidden behind a 304', "status {$c['status']}" );

// ── Nothing blew up quietly ──────────────────────────────────────
[ , $log ] = wp( 'option get mmgrf_skip_log --format=json' );
$entries   = json_decode( $log, true ) ?: [];
$errors    = array_filter( $entries, fn( $e ) => ( $e['code'] ?? '' ) === 'render_error' );
check( $errors === [], 'no item raised an exception during any render', json_encode( array_values( $errors ) ) );
$unresolved = array_filter( $entries, fn( $e ) => ( $e['code'] ?? '' ) === 'inline_image_unresolvable' );
check( $unresolved === [], 'every inline image resolved to its attachment', json_encode( array_values( $unresolved ) ) );

$debug = @file_get_contents( rtrim( $wp_root, '/' ) . '/wp-content/debug.log' );
$ours  = $debug ? array_filter( explode( "\n", $debug ), fn( $l ) => strpos( $l, 'scoreline-feeds' ) !== false ) : [];
check( $ours === [], 'no PHP warning, notice or deprecation from the plugin', implode( "\n", array_slice( $ours, 0, 10 ) ) );

echo "\n$checks checks, $failures failed\n";
exit( $failures ? 1 : 0 );
