<?php
/**
 * Render every admin screen of the plugin inside a real WordPress, as an
 * administrator, and print the HTML. Run with `wp eval-file`. Any warning,
 * notice or fatal from the plugin lands in debug.log / a non-zero exit and
 * fails the integration job.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}
require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user( 1 );

$it      = get_option( 'mmgrf_it' );
$article = get_post( (int) $it['article'] );

mmgrf_settings_page();
mmgrf_syndication_log_page();
mmgrf_syndication_meta_box( $article );
mmgrf_syndication_meta_box( get_post( (int) $it['slideshow'] ) );
echo mmgrf_posts_column_html( $article->ID );
echo mmgrf_posts_column_html( (int) $it['fresh'] );
echo wp_json_encode( mmgrf_syndication_check( $article->ID )['verdicts'] );
