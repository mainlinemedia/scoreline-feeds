<?php
/**
 * Integration seed — run INSIDE a real WordPress with:
 *   wp eval-file tests/integration/seed.php
 *
 * Builds exactly the content shapes that the stub suite cannot model
 * faithfully, using only real core APIs. Writes the IDs it created to the
 * option "mmgrf_it" for tests/integration/assert.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$it = [];

// A theme that constrains "large" in the editor, like tagDiv's does.
$GLOBALS['content_width'] = 696;

/** A real JPEG of the given size, registered through the media library. */
function mmgrf_it_image( $name, $w, $h ) {
    $im = imagecreatetruecolor( $w, $h );
    imagefilledrectangle( $im, 0, 0, $w, $h, imagecolorallocate( $im, 40, 90, 160 ) );
    imagefilledellipse( $im, (int) ( $w / 2 ), (int) ( $h / 2 ), (int) ( $w / 3 ), (int) ( $h / 3 ), imagecolorallocate( $im, 240, 200, 60 ) );
    $tmp = wp_tempnam( $name );
    imagejpeg( $im, $tmp, 82 );
    imagedestroy( $im );
    $id = media_handle_sideload( [ 'name' => $name . '.jpg', 'tmp_name' => $tmp ], 0, $name );
    if ( is_wp_error( $id ) ) {
        fwrite( STDERR, 'image failed: ' . $id->get_error_message() . "\n" );
        exit( 1 );
    }
    return (int) $id;
}

$words = function( $n, $w = 'word' ) {
    return implode( ' ', array_fill( 0, $n, $w ) );
};
$hour_ago = gmdate( 'Y-m-d H:i:s', time() - 3600 );
$post     = function( $args ) use ( $hour_ago ) {
    $id = wp_insert_post( array_merge( [
        'post_status'   => 'publish',
        'post_type'     => 'post',
        'post_author'   => 1,
        'post_date_gmt' => $hour_ago,
        'post_date'     => get_date_from_gmt( $hour_ago ),
    ], $args ), true );
    if ( is_wp_error( $id ) ) {
        fwrite( STDERR, 'post failed: ' . $id->get_error_message() . "\n" );
        exit( 1 );
    }
    return (int) $id;
};

// ── Names WordPress stores entity-encoded ────────────────────────
wp_update_user( [ 'ID' => 1, 'display_name' => 'Staff & Wire Reports' ] );
$cat_news      = wp_insert_term( 'News & Views', 'category' );
$cat_sponsored = wp_insert_term( 'Sponsored', 'category', [ 'slug' => 'sponsored' ] );
$cat_child     = wp_insert_term( 'Betting Partners', 'category', [ 'parent' => $cat_sponsored['term_id'] ] );

// ── Media: a big upload (WordPress stores it as "-scaled") ───────
$it['big']   = mmgrf_it_image( 'wire-photo', 4000, 2667 );
$it['other'] = mmgrf_it_image( 'second-photo', 3000, 2000 );
$it['big_url'] = wp_get_attachment_url( $it['big'] );

// ── 1. Ordinary article: featured = big photo, the SAME photo inline at
//       "large" below some text, plus a second photo twice. ──────────────
// What the block editor writes: the "large" rendition URL plus a
// wp-image-{id} class. One copy deliberately has NO class, so the URL-only
// lookup of a "-scaled" original is exercised too.
$large = function( $id, $with_class = true ) {
    $src = wp_get_attachment_image_src( $id, 'large' );
    return '<img src="' . esc_url( $src[0] ) . '" alt=""' . ( $with_class ? ' class="wp-image-' . (int) $id . '"' : '' ) . '/>';
};
$inline_big   = $large( $it['big'] );
$inline_other = $large( $it['other'], false );
$it['article'] = $post( [
    'post_title'   => 'Chiefs Rally Late To Beat The Raiders In Overtime Thriller',
    'post_content' => '<p>' . $words( 120 ) . '</p>'
        . '<figure class="wp-block-image size-large">' . $inline_other . '<figcaption>Second photo caption</figcaption></figure>'
        . '<p>' . $words( 60 ) . '</p>'
        . '<figure class="wp-block-image size-large">' . $inline_big . '</figure>'
        . '<div class="wp-block-group"><div>A grouped sentence ends here.</div><div>The next one starts here.</div></div>'
        . '<figure class="wp-block-image size-large">' . $inline_other . '</figure>'
        . '<p>Closing words.</p></div><p>Paragraph after a stray closing div.</p>',
    'post_category' => [ $cat_news['term_id'] ],
    'tags_input'    => [ 'Q&A', 'nfl' ],
] );
set_post_thumbnail( $it['article'], $it['big'] );

// ── 2. Sticky post in a CHILD of the policy-excluded category ────
$it['sticky'] = $post( [
    'post_title'    => 'Sticky Sponsored Post That Must Never Reach A Network',
    'post_content'  => '<p>' . $words( 200, 'sponsored' ) . '</p>',
    'post_category' => [ $cat_child['term_id'] ],
    'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 40 * 86400 ),
    'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() - 40 * 86400 ) ),
] );
set_post_thumbnail( $it['sticky'], $it['other'] );
stick_post( $it['sticky'] );

// ── 3. Paginated post ────────────────────────────────────────────
$it['paged'] = $post( [
    'post_title'   => 'A Paginated Feature Story Split Across Three Pages',
    'post_content' => '<p>PAGEONE ' . $words( 80 ) . '</p><!--nextpage--><p>PAGETWO ' . $words( 80 ) . '</p><!--nextpage--><p>PAGETHREE ' . $words( 80 ) . '</p>',
    'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
    'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() - 7200 ) ),
] );
set_post_thumbnail( $it['paged'], $it['other'] );

// ── 4. Password-protected post ───────────────────────────────────
$it['locked'] = $post( [
    'post_title'    => 'A Password Protected Members Only Post Headline',
    'post_content'  => '<p>SECRETBODY ' . $words( 200 ) . '</p>',
    'post_password' => 'hunter2',
] );
set_post_thumbnail( $it['locked'], $it['other'] );

// ── 5. Tagged post for the tag feed ──────────────────────────────
$it['tagged'] = $post( [
    'post_title'   => 'The Tagged Nascar Story Headline Goes Right Here',
    'post_content' => '<p>NASCARBODY ' . $words( 200 ) . '</p>',
    'tags_input'   => [ 'nascar' ],
    'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 10800 ),
    'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() - 10800 ) ),
] );
set_post_thumbnail( $it['tagged'], $it['other'] );

// ── 6. A slideshow ───────────────────────────────────────────────
$slides = '<p>Intro paragraph with plenty of words to serve as the description.</p>';
$it['slide_imgs'] = [];
foreach ( [ 'Tiger Woods', 'Rory McIlroy', 'Scottie Scheffler', 'Jon Rahm' ] as $i => $name ) {
    $sid = mmgrf_it_image( 'slide-' . $i, 2600, 1734 );
    $it['slide_imgs'][] = $sid;
    $slides .= '<div class="wp-block-group"><h2 class="wp-block-heading">' . $name . '</h2>'
        . '<figure class="wp-block-image size-large">' . $large( $sid ) . '<figcaption>Photo credit line</figcaption></figure>'
        . '<p>Why ' . $name . ' is on this list.</p></div>';
}
$it['slideshow'] = $post( [
    'post_title'   => 'The Four Greatest Golfers Of The Modern Era Ranked',
    'post_content' => $slides,
    'tags_input'   => [ 'slideshow' ],
    'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 14400 ),
    'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() - 14400 ) ),
] );
set_post_thumbnail( $it['slideshow'], $it['slide_imgs'][0] ); // the usual habit: cover = slide 1

// ── 7. A post published seconds ago (inside the settle window) ───
$it['fresh'] = wp_insert_post( [
    'post_status'  => 'publish',
    'post_type'    => 'post',
    'post_author'  => 1,
    'post_title'   => 'A Post Published Only Seconds Ago Must Wait',
    'post_content' => '<p>FRESHBODY ' . $words( 200 ) . '</p>',
] );
set_post_thumbnail( $it['fresh'], $it['other'] );

// ── Plugin configuration ─────────────────────────────────────────
update_option( 'mmgrf_options', [
    'exclude_networks_cats' => 'sponsored',
    'settle_seconds'        => 60,
] );
update_option( 'mmgrf_networks', [
    'newsbreak' => [ 'enabled' => true, 'slug' => 'newsbreak' ],
    'msn'       => [ 'enabled' => true, 'slug' => 'msn' ],
    'yahoo'     => [ 'enabled' => true, 'slug' => 'yahoo' ],
] );
update_option( 'mmgrf_tag_feeds', [ [ 'slug' => 'nascar', 'title' => "Dale's NASCAR Feed", 'tags' => 'nascar', 'post_count' => 10, 'utm_source' => 'feed', 'utm_medium' => 'email', 'image_size' => 'large' ] ] );

update_option( 'mmgrf_it', $it );
echo 'seeded: ' . wp_json_encode( $it ) . "\n";
