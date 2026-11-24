<?php
/**
 * Layer 4 — admin. Settings page (main feed + networks + tag feeds + skip
 * log), Syndication meta box (replaces the v2 Feed Score box, preserves
 * _mmgrf_score), attachment syndication-rights field.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────────────────────────
// MENU + SETTINGS REGISTRATION
// ─────────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'mmgrf_admin_menu' );
function mmgrf_admin_menu() {
    add_options_page( 'Scoreline Feeds', 'Scoreline Feeds', 'manage_options', 'scoreline-feeds', 'mmgrf_settings_page' );
    // Fix 6: writers need to see why their post was dropped — Tools page at
    // edit_posts, filtered to the viewer's own posts for non-admins.
    add_management_page( 'Syndication Log', 'Syndication Log', 'edit_posts', 'mmgrf-syndication-log', 'mmgrf_syndication_log_page' );
}

function mmgrf_syndication_log_page() {
    $entries = mmgrf_skip_log_visible_entries( get_current_user_id(), current_user_can( 'manage_options' ) );
    echo '<div class="wrap"><h1>Syndication Log</h1>';
    echo '<p>Articles or images withheld from syndication feeds in the last 7 days, and why.</p>';
    if ( $entries ) {
        mmgrf_render_skip_log_table( $entries );
    } else {
        echo '<p style="color:#888;font-style:italic;">Nothing withheld in the last 7 days.</p>';
    }
    echo '</div>';
}

function mmgrf_render_skip_log_table( $entries ) {
    ?>
    <table class="widefat striped" style="max-width:1100px;">
        <thead><tr><th>When (UTC)</th><th>Network</th><th>Level</th><th>Post</th><th>Code</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ( array_slice( $entries, 0, 100 ) as $e ) : ?>
            <tr>
                <td><?php echo esc_html( gmdate( 'Y-m-d H:i', $e['ts'] ) ); ?></td>
                <td><?php echo esc_html( $e['network'] ); ?></td>
                <td><?php echo esc_html( $e['level'] ?? 'skip' ); ?></td>
                <td><?php if ( (int) $e['post_id'] > 0 ) : ?><a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $e['post_id'] . '&action=edit' ) ); ?>"><?php echo esc_html( $e['post_title'] ); ?></a><?php else : ?><?php echo esc_html( $e['post_title'] ); ?><?php endif; ?></td>
                <td><code><?php echo esc_html( $e['code'] ?? ( $e['reason'] ?? '' ) ); ?></code></td>
                <td><?php echo esc_html( $e['detail'] ?? '' ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

add_action( 'admin_post_mmgrf_clear_skip_log', 'mmgrf_handle_clear_skip_log' );
function mmgrf_handle_clear_skip_log() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
    check_admin_referer( 'mmgrf_clear_skip_log' );
    mmgrf_skip_log_clear();
    wp_safe_redirect( admin_url( 'options-general.php?page=scoreline-feeds#skip-log' ) );
    exit;
}

add_action( 'admin_init', 'mmgrf_register_settings' );
function mmgrf_register_settings() {
    register_setting( 'mmgrf_options_group', 'mmgrf_options', 'mmgrf_sanitize_options' );
    register_setting( 'mmgrf_options_group', 'mmgrf_networks', 'mmgrf_sanitize_networks' );

    add_settings_section( 'mmgrf_main',   'Main Feed Configuration (Aigeon legacy)', '__return_false', 'scoreline-feeds' );
    add_settings_section( 'mmgrf_filter', 'Content Filters', '__return_false', 'scoreline-feeds' );

    $fields_main = [
        'feed_slug'        => [ 'Feed Slug',        'Generates: /?feed={slug}-raw-feed.' ],
        'feed_title'       => [ 'Feed Title',        'Channel <title>.' ],
        'feed_description' => [ 'Feed Description',  'Channel <description>.' ],
        'feed_link'        => [ 'Site Link',         'Canonical homepage URL.' ],
        'utm_source'       => [ 'UTM Source',        'Appended to article links (Aigeon feed; networks have their own toggle).' ],
        'utm_medium'       => [ 'UTM Medium',        'Appended to article links.' ],
        'post_count'       => [ 'Number of Posts',   'How many recent posts (default 25).' ],
        'image_size'       => [ 'Image Size',        'WP image size for media:content (large, medium, full, etc.).' ],
    ];
    foreach ( $fields_main as $key => [ $label, $desc ] ) {
        add_settings_field( 'mmgrf_' . $key, $label, 'mmgrf_field_cb', 'scoreline-feeds', 'mmgrf_main', [ 'key' => $key, 'desc' => $desc ] );
    }

    $fields_filter = [
        'categories'            => [ 'Include Categories',        'Comma-separated category slugs. Leave blank for all.' ],
        'exclude_cats'          => [ 'Exclude Categories',        'Comma-separated category slugs to exclude.' ],
        'tags'                  => [ 'Include Tags',              'Comma-separated tag slugs. Posts must have ANY of these tags.' ],
        'exclude_tags'          => [ 'Exclude Tags',              'Posts with ANY of these tags are excluded.' ],
        'exclude_networks_cats' => [ 'Network-Excluded Categories', 'Categories withheld from ALL syndication networks (sponsored, partner-content) — the legacy Aigeon feed is unaffected.' ],
        'exclude_networks_tags' => [ 'Network-Excluded Tags',     'Tags withheld from ALL syndication networks.' ],
        'affiliate_domains'       => [ 'Affiliate Domain Blocklist', 'Comma-separated operator domains (host/subdomain match on link hrefs). Default action skips the whole item from Yahoo.' ],
        'affiliate_paths'         => [ 'Affiliate Path Blocklist', 'Comma-separated substrings matched against full link URLs (never body text). Default action unwraps the link, keeping its text.' ],
        'affiliate_domain_action' => [ 'Domain Match Action', 'skip_item (default), unwrap, or strip_paragraph.' ],
        'affiliate_path_action'   => [ 'Path Match Action', 'unwrap (default), skip_item, or strip_paragraph.' ],
        'slideshow_tag'           => [ 'Slideshow Marker Tag', 'Posts with this tag ship via the {slug}-slideshows feeds and are withheld from article feeds. Change it here if this site already uses the tag editorially.' ],
        'video_tag'               => [ 'Video Marker Tag', 'Posts with this tag ship via the {slug}-videos feeds and are withheld from article feeds.' ],
        'settle_seconds'          => [ 'Publish Settle Window (seconds)', 'A newly published post is held back from the network feeds for this long, so a partner can never pick it up before its categories, featured image and Syndication box have finished saving (syndication cannot be undone). Default 60; 0 disables. The legacy Aigeon feed is not delayed.' ],
    ];
    foreach ( $fields_filter as $key => [ $label, $desc ] ) {
        add_settings_field( 'mmgrf_' . $key, $label, 'mmgrf_field_cb', 'scoreline-feeds', 'mmgrf_filter', [ 'key' => $key, 'desc' => $desc ] );
    }
}

/**
 * Is $slug safe to register as a feed route? Core feed names are off limits
 * (add_feed() REPLACES the core handler — the site's main feed would become
 * a network feed), and so is any slug another feed of this plugin already
 * uses (two handlers on one route emit two XML documents back to back).
 */
function mmgrf_feed_slug_conflict( $slug, $taken ) {
    if ( in_array( $slug, MMGRF_RESERVED_SLUGS, true ) ) {
        return 'is a built-in WordPress feed name';
    }
    if ( in_array( $slug, $taken, true ) ) {
        return 'is already used by another Scoreline feed';
    }
    return '';
}

function mmgrf_settings_error( $message ) {
    if ( function_exists( 'add_settings_error' ) ) {
        add_settings_error( 'mmgrf_options_group', 'mmgrf_slug_' . md5( $message ), $message, 'error' );
    }
}

function mmgrf_sanitize_options( $input ) {
    $input    = is_array( $input ) ? $input : [];
    $defaults = mmgrf_get_options();
    $clean    = [];

    $slug = sanitize_title( $input['feed_slug'] ?? 'raw-feed' ) ?: 'raw-feed';
    // The main feed is served at {slug}-raw-feed; tag feeds share that suffix.
    $tag_slugs = array_filter( array_column( mmgrf_get_tag_feeds(), 'slug' ) );
    if ( in_array( $slug, $tag_slugs, true ) ) {
        mmgrf_settings_error( 'Feed Slug "' . $slug . '" is already used by a tag feed — the previous slug was kept.' );
        $slug = $defaults['feed_slug'];
    }
    $clean['feed_slug']             = $slug;
    $clean['feed_title']            = sanitize_text_field( $input['feed_title'] ?? $defaults['feed_title'] );
    $clean['feed_description']      = sanitize_text_field( $input['feed_description'] ?? $defaults['feed_description'] );
    $clean['feed_link']             = esc_url_raw( $input['feed_link'] ?? home_url() );
    $clean['utm_source']            = sanitize_key( $input['utm_source'] ?? 'feed' );
    $clean['utm_medium']            = sanitize_key( $input['utm_medium'] ?? 'email' );
    $clean['post_count']            = absint( $input['post_count'] ?? 25 ) ?: 25;
    $clean['image_size']            = sanitize_key( $input['image_size'] ?? 'large' );
    $clean['categories']            = sanitize_text_field( $input['categories'] ?? '' );
    $clean['exclude_cats']          = sanitize_text_field( $input['exclude_cats'] ?? '' );
    $clean['tags']                  = sanitize_text_field( $input['tags'] ?? '' );
    $clean['exclude_tags']          = sanitize_text_field( $input['exclude_tags'] ?? '' );
    $clean['exclude_networks_cats'] = sanitize_text_field( $input['exclude_networks_cats'] ?? '' );
    $clean['exclude_networks_tags'] = sanitize_text_field( $input['exclude_networks_tags'] ?? '' );
    $clean['affiliate_domains']       = sanitize_text_field( $input['affiliate_domains'] ?? $defaults['affiliate_domains'] );
    $clean['affiliate_paths']         = sanitize_text_field( $input['affiliate_paths'] ?? $defaults['affiliate_paths'] );
    $clean['affiliate_domain_action'] = in_array( $input['affiliate_domain_action'] ?? '', [ 'skip_item', 'unwrap', 'strip_paragraph' ], true ) ? $input['affiliate_domain_action'] : 'skip_item';
    $clean['affiliate_path_action']   = in_array( $input['affiliate_path_action'] ?? '', [ 'skip_item', 'unwrap', 'strip_paragraph' ], true ) ? $input['affiliate_path_action'] : 'unwrap';
    $clean['slideshow_tag']           = sanitize_title( $input['slideshow_tag'] ?? 'slideshow' ) ?: 'slideshow';
    $clean['video_tag']               = sanitize_title( $input['video_tag'] ?? 'video' ) ?: 'video';
    if ( $clean['video_tag'] === $clean['slideshow_tag'] ) {
        mmgrf_settings_error( 'The slideshow and video marker tags must be different — the video tag was reset to "video".' );
        $clean['video_tag'] = $clean['slideshow_tag'] === 'video' ? 'video-clip' : 'video';
    }
    $clean['settle_seconds']        = max( 0, min( 900, absint( $input['settle_seconds'] ?? $defaults['settle_seconds'] ) ) );
    $clean['enhance_default_feeds'] = empty( $input['enhance_default_feeds'] ) ? 0 : 1;
    mmgrf_touch_config();
    return $clean;
}

function mmgrf_sanitize_networks( $input ) {
    $input   = is_array( $input ) ? $input : [];
    $current = mmgrf_get_network_config();
    $clean   = [];
    // Slugs already spoken for: the legacy feeds, then each network (and its
    // typed feeds) as it is accepted below.
    $taken   = [ mmgrf_get_options()['feed_slug'] . '-raw-feed' ];
    foreach ( mmgrf_get_tag_feeds() as $tf ) {
        if ( ! empty( $tf['slug'] ) ) {
            $taken[] = $tf['slug'] . '-raw-feed';
        }
    }
    foreach ( MMGRF_NETWORKS as $key ) {
        $in    = is_array( $input[ $key ] ?? null ) ? $input[ $key ] : [];
        $slug  = sanitize_title( $in['slug'] ?? '' ) ?: $key;
        $typed = in_array( $key, MMGRF_TYPED_NETWORKS, true ) ? [ $slug . '-slideshows', $slug . '-videos' ] : [];
        foreach ( array_merge( [ $slug ], $typed ) as $candidate ) {
            $why = mmgrf_feed_slug_conflict( $candidate, $taken );
            if ( $why !== '' ) {
                mmgrf_settings_error( ucfirst( $key ) . ' feed slug "' . $slug . '" was not saved: "' . $candidate . '" ' . $why . '. The previous slug was kept.' );
                $slug  = $current[ $key ]['slug'];
                $typed = in_array( $key, MMGRF_TYPED_NETWORKS, true ) ? [ $slug . '-slideshows', $slug . '-videos' ] : [];
                break;
            }
        }
        $taken = array_merge( $taken, [ $slug ], $typed );
        $clean[ $key ] = [
            'enabled'     => ! empty( $in['enabled'] ),
            'slug'        => $slug,
            'post_count'  => absint( $in['post_count'] ?? 0 ),
            'image_size'  => sanitize_key( $in['image_size'] ?? '' ),
            'utm_enabled' => ! empty( $in['utm_enabled'] ),
            // nb:scripts executes on NewsBreak's property — raw markup is the
            // point; gated by manage_options capability + Austin's sign-off.
            'nb_scripts'  => $key === 'newsbreak' ? trim( (string) ( $in['nb_scripts'] ?? '' ) ) : '',
        ];
    }
    mmgrf_touch_config();
    return $clean;
}

function mmgrf_field_cb( $args ) {
    $opts  = mmgrf_get_options();
    $key   = $args['key'];
    $value = esc_attr( $opts[ $key ] ?? '' );
    $desc  = esc_html( $args['desc'] );
    echo "<input type='text' name='mmgrf_options[{$key}]' value='{$value}' class='regular-text' />";
    echo "<p class='description'>{$desc}</p>";
}

// ─────────────────────────────────────────────────────────────────
// TAG FEED MANAGER (unchanged from v2.1.0)
// ─────────────────────────────────────────────────────────────────

add_action( 'admin_post_mmgrf_save_tag_feed', 'mmgrf_handle_save_tag_feed' );
function mmgrf_handle_save_tag_feed() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
    check_admin_referer( 'mmgrf_tag_feed_nonce' );

    $feeds      = mmgrf_get_tag_feeds();
    $edit_index = ( isset( $_POST['edit_index'] ) && $_POST['edit_index'] !== '' ) ? (int) $_POST['edit_index'] : null;

    // WordPress adds slashes to $_POST; without this "Dale's NASCAR Feed"
    // is stored (and emitted in the channel title) as "Dale\'s NASCAR Feed".
    $post  = wp_unslash( $_POST );
    $entry = mmgrf_clean_tag_feed( $post );

    if ( empty( $entry['slug'] ) || empty( $entry['tags'] ) ) {
        wp_safe_redirect( admin_url( 'options-general.php?page=scoreline-feeds&mmgrf_error=missing_fields#tag-feeds' ) );
        exit;
    }
    if ( mmgrf_tag_feed_slug_taken( $entry['slug'], $feeds, $edit_index ) ) {
        wp_safe_redirect( admin_url( 'options-general.php?page=scoreline-feeds&mmgrf_error=slug_taken#tag-feeds' ) );
        exit;
    }

    if ( $edit_index !== null && isset( $feeds[ $edit_index ] ) ) {
        $feeds[ $edit_index ] = $entry;
    } else {
        $feeds[] = $entry;
    }

    update_option( 'mmgrf_tag_feeds', $feeds );
    mmgrf_touch_config();
    wp_safe_redirect( admin_url( 'options-general.php?page=scoreline-feeds&mmgrf_saved=1#tag-feeds' ) );
    exit;
}

/** Sanitize one tag-feed form submission (already unslashed). */
function mmgrf_clean_tag_feed( $post ) {
    return [
        'slug'       => sanitize_title( $post['tf_slug'] ?? '' ),
        'title'      => sanitize_text_field( $post['tf_title'] ?? '' ),
        'tags'       => sanitize_text_field( $post['tf_tags'] ?? '' ),
        'post_count' => absint( $post['tf_post_count'] ?? 10 ),
        'utm_source' => sanitize_key( $post['tf_utm_source'] ?? 'feed' ),
        'utm_medium' => sanitize_key( $post['tf_utm_medium'] ?? 'email' ),
        'image_size' => sanitize_key( $post['tf_image_size'] ?? 'large' ),
    ];
}

/** Would a tag feed with this slug collide with the main feed or another tag feed? */
function mmgrf_tag_feed_slug_taken( $slug, $feeds, $edit_index = null ) {
    if ( $slug === mmgrf_get_options()['feed_slug'] ) {
        return true;
    }
    foreach ( $feeds as $i => $tf ) {
        if ( $i !== $edit_index && ( $tf['slug'] ?? '' ) === $slug ) {
            return true;
        }
    }
    // {slug}-raw-feed must not equal a network route either.
    foreach ( mmgrf_get_network_config() as $network => $net ) {
        $routes = [ $net['slug'] ];
        if ( in_array( $network, MMGRF_TYPED_NETWORKS, true ) ) {
            $routes[] = $net['slug'] . '-slideshows';
            $routes[] = $net['slug'] . '-videos';
        }
        if ( in_array( $slug . '-raw-feed', $routes, true ) ) {
            return true;
        }
    }
    return false;
}

add_action( 'admin_post_mmgrf_delete_tag_feed', 'mmgrf_handle_delete_tag_feed' );
function mmgrf_handle_delete_tag_feed() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
    check_admin_referer( 'mmgrf_delete_tag_feed' );

    $index = (int) ( $_GET['index'] ?? -1 );
    $feeds = mmgrf_get_tag_feeds();
    if ( isset( $feeds[ $index ] ) ) {
        array_splice( $feeds, $index, 1 );
        update_option( 'mmgrf_tag_feeds', $feeds );
        mmgrf_touch_config();
    }
    wp_safe_redirect( admin_url( 'options-general.php?page=scoreline-feeds&mmgrf_deleted=1#tag-feeds' ) );
    exit;
}

// ─────────────────────────────────────────────────────────────────
// SETTINGS PAGE
// ─────────────────────────────────────────────────────────────────

function mmgrf_settings_page() {
    $opts      = mmgrf_get_options();
    $networks  = mmgrf_get_network_config();
    $tag_feeds = mmgrf_get_tag_feeds();
    $main_url  = mmgrf_feed_url( $opts['feed_slug'] );

    $edit_index = ( isset( $_GET['edit_tag'] ) && $_GET['edit_tag'] !== '' ) ? (int) $_GET['edit_tag'] : null;
    $editing    = ( $edit_index !== null && isset( $tag_feeds[ $edit_index ] ) ) ? $tag_feeds[ $edit_index ] : null;

    $network_labels = [
        'newsbreak' => [ 'NewsBreak', 'Replaces the standalone "NewsBreak RSS Feed" plugin. GUID-stable, figure-prepended images, clean links.' ],
        'msn'       => [ 'MSN (Partner Hub)', 'Hand MSN the pretty-permalink URL. Titles must be 21–150 chars (or set a short title per post). Images require syndication rights.' ],
        'yahoo'     => [ 'Yahoo', 'Strict HTML allowlist, 150-word body floor, 1280×720 image floor. Items failing the gate appear in the skip log.' ],
    ];
    ?>
    <div class="wrap">
        <h1>Scoreline Feeds <span style="font-size:13px;color:#888;font-weight:normal;">v<?php echo esc_html( MMGRF_VERSION ); ?></span></h1>

        <?php if ( isset( $_GET['mmgrf_saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>✅ Tag feed saved.</p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['mmgrf_deleted'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>🗑 Tag feed deleted.</p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['mmgrf_error'] ) ) : ?>
            <div class="notice notice-error is-dismissible"><p>⚠️ <?php echo $_GET['mmgrf_error'] === 'slug_taken' ? 'That feed slug is already in use by another Scoreline feed — choose a different one.' : 'Slug and Tags are required.'; ?></p></div>
        <?php endif; ?>
        <?php foreach ( mmgrf_health_problems() as $problem ) : ?>
            <div class="notice notice-<?php echo $problem['level'] === 'error' ? 'error' : 'warning'; ?>"><p><strong>Scoreline Feeds:</strong> <?php echo esc_html( $problem['message'] ); ?></p></div>
        <?php endforeach; ?>

        <h2>Main Feed (Aigeon legacy)</h2>
        <p>Live URL: <strong><a href="<?php echo esc_url( $main_url ); ?>" target="_blank"><?php echo esc_html( $main_url ); ?></a></strong></p>

        <form method="post" action="options.php">
            <?php
            settings_fields( 'mmgrf_options_group' );
            do_settings_sections( 'scoreline-feeds' );
            ?>

            <h2>Syndication Networks</h2>
            <p>Each network gets a compliance-profiled feed. All default <strong>off</strong> — enable per brand after verifying output.</p>

            <?php foreach ( $network_labels as $key => [ $label, $note ] ) :
                $net = $networks[ $key ];
                // Same helper as the feed's atom:link — the URL shown here is
                // always the one the partner should be given (Fix 8).
                $feed_url   = mmgrf_network_feed_url( $net['slug'] );
            ?>
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px 20px;margin-bottom:14px;max-width:900px;">
                <h3 style="margin-top:0;"><?php echo esc_html( $label ); ?></h3>
                <p class="description"><?php echo esc_html( $note ); ?></p>
                <table class="form-table" style="margin-top:0;">
                    <tr>
                        <th>Enabled</th>
                        <td><label><input type="checkbox" name="mmgrf_networks[<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( $net['enabled'] ); ?>> Serve this feed</label></td>
                    </tr>
                    <tr>
                        <th>Feed Slug</th>
                        <td>
                            <input type="text" name="mmgrf_networks[<?php echo esc_attr( $key ); ?>][slug]" value="<?php echo esc_attr( $net['slug'] ); ?>" class="regular-text">
                            <p class="description">Feed URL to give the partner: <code><?php echo esc_html( $feed_url ); ?></code></p>
                            <?php if ( in_array( $key, MMGRF_TYPED_NETWORKS, true ) ) : ?>
                            <p class="description">Typed feeds: slideshows <code><?php echo esc_html( mmgrf_network_feed_url( $net['slug'] . '-slideshows' ) ); ?></code> · videos <code><?php echo esc_html( mmgrf_network_feed_url( $net['slug'] . '-videos' ) ); ?></code> (register each as its content type with the partner).</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Post Count</th>
                        <td><input type="number" min="0" max="100" name="mmgrf_networks[<?php echo esc_attr( $key ); ?>][post_count]" value="<?php echo esc_attr( $net['post_count'] ?: '' ); ?>" style="width:90px;" placeholder="default">
                        <p class="description">Blank = profile default (NewsBreak 50 · MSN 30 · Yahoo 50). Ceilings: MSN 30, Yahoo 100.</p></td>
                    </tr>
                    <tr>
                        <th>UTM Parameters</th>
                        <td><label><input type="checkbox" name="mmgrf_networks[<?php echo esc_attr( $key ); ?>][utm_enabled]" value="1" <?php checked( $net['utm_enabled'] ); ?>> Append UTM parameters to links</label>
                        <p class="description">Default off — networks treat &lt;link&gt; as the canonical source pointer; URL variation harms source attribution.</p></td>
                    </tr>
                    <?php if ( $key === 'newsbreak' ) : ?>
                    <tr>
                        <th>nb:scripts</th>
                        <td>
                            <textarea name="mmgrf_networks[newsbreak][nb_scripts]" rows="3" class="large-text code" placeholder="Empty = not emitted"><?php echo esc_textarea( $net['nb_scripts'] ); ?></textarea>
                            <p class="description">⚠️ Third-party tracking markup NewsBreak executes sandboxed on their property under MMG's name. Leave empty unless explicitly signed off.</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            <?php endforeach; ?>

            <h2>Default WordPress Feeds</h2>
            <label>
                <input type="checkbox" name="mmgrf_options[enhance_default_feeds]" value="1" <?php checked( ! empty( $opts['enhance_default_feeds'] ) ); ?>>
                Enhance default <code>/feed/</code> output (media tags + figure-prepended featured image)
            </label>
            <p class="description">Preserves the behavior of the retired NewsBreak RSS Feed plugin. Leave on unless you know no consumer depends on it.</p>

            <?php submit_button( 'Save Settings' ); ?>
        </form>

        <hr id="skip-log">
        <h2>Skip Log</h2>
        <p>Items withheld by a network's validation gate (both MSN and Yahoo reject silently on their side — this is the only place the answer to "why isn't this article on the network" lives).</p>
        <?php $log = mmgrf_skip_log_get(); ?>
        <?php if ( $log ) : ?>
            <?php mmgrf_render_skip_log_table( $log ); ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
                <input type="hidden" name="action" value="mmgrf_clear_skip_log">
                <?php wp_nonce_field( 'mmgrf_clear_skip_log' ); ?>
                <?php submit_button( 'Clear Log', 'delete small', 'submit', false ); ?>
            </form>
        <?php else : ?>
            <p style="color:#888;font-style:italic;">No skips recorded in the last 7 days.</p>
        <?php endif; ?>

        <hr id="tag-feeds">
        <h2>Tag Feed Manager</h2>
        <p>Separate feed URLs filtered by WordPress tags — for splitting Aigeon sends by topic or vertical.</p>

        <?php if ( ! empty( $tag_feeds ) ) : ?>
        <table class="widefat striped" style="margin-bottom:20px;max-width:1100px;">
            <thead><tr><th>Feed URL</th><th>Title</th><th>Tags</th><th>Posts</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ( $tag_feeds as $i => $tf ) :
                $tf_url = mmgrf_feed_url( $tf['slug'] );
            ?>
                <tr>
                    <td><a href="<?php echo esc_url( $tf_url ); ?>" target="_blank"><?php echo esc_html( $tf_url ); ?></a></td>
                    <td><?php echo esc_html( $tf['title'] ); ?></td>
                    <td><code><?php echo esc_html( $tf['tags'] ); ?></code></td>
                    <td><?php echo esc_html( $tf['post_count'] ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( admin_url( 'options-general.php?page=scoreline-feeds&edit_tag=' . $i . '#tag-feeds' ) ); ?>">Edit</a>
                        &nbsp;|&nbsp;
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mmgrf_delete_tag_feed&index=' . $i ), 'mmgrf_delete_tag_feed' ) ); ?>"
                           onclick="return confirm('Delete this tag feed?');" style="color:red;">Delete</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
            <p style="color:#888;font-style:italic;">No tag feeds created yet.</p>
        <?php endif; ?>

        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;max-width:700px;border-radius:4px;">
            <h3><?php echo $editing ? '✏️ Edit Tag Feed' : '➕ Add New Tag Feed'; ?></h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="mmgrf_save_tag_feed">
                <?php wp_nonce_field( 'mmgrf_tag_feed_nonce' ); ?>
                <?php if ( $edit_index !== null ) : ?>
                    <input type="hidden" name="edit_index" value="<?php echo (int) $edit_index; ?>">
                <?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th><label for="tf_slug">Feed Slug <span style="color:red">*</span></label></th>
                        <td><input type="text" id="tf_slug" name="tf_slug" class="regular-text" value="<?php echo esc_attr( $editing['slug'] ?? '' ); ?>" placeholder="e.g. nfl, politics, nascar">
                            <p class="description">Generates: <code>/?feed={slug}-raw-feed</code> — unique across all feeds.</p></td>
                    </tr>
                    <tr>
                        <th><label for="tf_title">Feed Title</label></th>
                        <td><input type="text" id="tf_title" name="tf_title" class="regular-text" value="<?php echo esc_attr( $editing['title'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="tf_tags">Tags <span style="color:red">*</span></label></th>
                        <td><input type="text" id="tf_tags" name="tf_tags" class="regular-text" value="<?php echo esc_attr( $editing['tags'] ?? '' ); ?>" placeholder="e.g. nascar, cup-series">
                            <p class="description">Comma-separated tag <strong>slugs</strong>; posts need at least one.</p></td>
                    </tr>
                    <tr>
                        <th><label for="tf_post_count">Number of Posts</label></th>
                        <td><input type="number" id="tf_post_count" name="tf_post_count" min="1" max="200" value="<?php echo esc_attr( $editing['post_count'] ?? 10 ); ?>" style="width:80px;"></td>
                    </tr>
                    <tr>
                        <th><label for="tf_utm_source">UTM Source</label></th>
                        <td><input type="text" id="tf_utm_source" name="tf_utm_source" class="regular-text" value="<?php echo esc_attr( $editing['utm_source'] ?? 'feed' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="tf_utm_medium">UTM Medium</label></th>
                        <td><input type="text" id="tf_utm_medium" name="tf_utm_medium" class="regular-text" value="<?php echo esc_attr( $editing['utm_medium'] ?? 'email' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="tf_image_size">Image Size</label></th>
                        <td><input type="text" id="tf_image_size" name="tf_image_size" class="regular-text" value="<?php echo esc_attr( $editing['image_size'] ?? 'large' ); ?>" placeholder="large"></td>
                    </tr>
                </table>
                <?php submit_button( $editing ? 'Update Tag Feed' : 'Create Tag Feed', 'primary', 'submit', false ); ?>
                <?php if ( $editing ) : ?>
                    &nbsp;<a href="<?php echo esc_url( admin_url( 'options-general.php?page=scoreline-feeds#tag-feeds' ) ); ?>" class="button">Cancel</a>
                <?php endif; ?>
            </form>
        </div>

        <hr>
        <p style="color:#888;font-size:12px;">New and renamed feed URLs register themselves automatically on the next page load — no Permalinks re-save needed.</p>
    </div>
    <?php
}

// ─────────────────────────────────────────────────────────────────
// SYNDICATION META BOX (replaces the v2 Feed Score box)
// ─────────────────────────────────────────────────────────────────

add_action( 'add_meta_boxes', function() {
    add_meta_box( 'mmgrf_syndication', 'Syndication', 'mmgrf_syndication_meta_box', 'post', 'side', 'default' );
} );

function mmgrf_syndication_meta_box( $post ) {
    $score       = get_post_meta( $post->ID, '_mmgrf_score', true );
    $short_title = get_post_meta( $post->ID, '_mmgrf_short_title', true );
    wp_nonce_field( 'mmgrf_save_syndication', 'mmgrf_syndication_nonce' );

    echo '<p><label>Feed score (<code>&lt;category domain="score"&gt;</code>):</label>';
    echo '<input type="number" step="0.01" name="mmgrf_score" value="' . esc_attr( $score !== '' ? $score : '0' ) . '" style="width:100%;margin-top:4px;"></p>';

    echo '<p><label>MSN short title (≤54 chars ideal; required if headline &gt;150):</label>';
    echo '<input type="text" name="mmgrf_short_title" value="' . esc_attr( $short_title ) . '" maxlength="150" style="width:100%;margin-top:4px;"></p>';

    $sponsored = true;
    echo '<p style="margin-bottom:4px;"><strong>Exclude from networks:</strong></p>';
    foreach ( MMGRF_NETWORKS as $network ) {
        $checked = get_post_meta( $post->ID, '_mmgrf_exclude_' . $network, true ) === '1';
        if ( ! $checked ) {
            $sponsored = false;
        }
        echo '<label style="display:block;"><input type="checkbox" name="mmgrf_exclude_' . esc_attr( $network ) . '" value="1"' . ( $checked ? ' checked' : '' ) . '> ' . esc_html( ucfirst( $network ) ) . '</label>';
    }
    echo '<label style="display:block;margin-top:6px;border-top:1px solid #eee;padding-top:6px;"><input type="checkbox" name="mmgrf_sponsored" value="1"' . ( $sponsored ? ' checked' : '' ) . '> <strong>Sponsored content</strong> (exclude from all networks)</label>';
}

add_action( 'save_post', 'mmgrf_on_save_post' );
function mmgrf_on_save_post( $post_id ) {
    if ( ! isset( $_POST['mmgrf_syndication_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mmgrf_syndication_nonce'] ) ), 'mmgrf_save_syndication' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! mmgrf_is_edited_post( $post_id, $_POST ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    mmgrf_save_syndication_meta( $post_id, wp_unslash( $_POST ) );
}

/**
 * Is $post_id the post this form submission is editing?
 *
 * save_post also fires for the autosave REVISION that "Preview Changes"
 * creates (meta writes on a revision land on the live parent — so unticking
 * "Sponsored content" and clicking Preview used to clear the exclusion on
 * the published post), and for any other post a plugin inserts or updates
 * during the same request (translation sync, duplicators), which would
 * inherit this post's flags.
 */
function mmgrf_is_edited_post( $post_id, $form ) {
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return false;
    }
    if ( (int) ( $form['post_ID'] ?? 0 ) !== (int) $post_id ) {
        return false;
    }
    $post = get_post( $post_id );
    return $post && in_array( $post->post_type, mmgrf_syndication_post_types(), true );
}

/** Persist the Syndication box fields. $data is the raw form payload. */
function mmgrf_save_syndication_meta( $post_id, $data ) {
    if ( isset( $data['mmgrf_score'] ) ) {
        update_post_meta( $post_id, '_mmgrf_score', sanitize_text_field( $data['mmgrf_score'] ) );
    }
    update_post_meta( $post_id, '_mmgrf_short_title', sanitize_text_field( $data['mmgrf_short_title'] ?? '' ) );

    $sponsored = ! empty( $data['mmgrf_sponsored'] );
    foreach ( MMGRF_NETWORKS as $network ) {
        $exclude = $sponsored || ! empty( $data[ 'mmgrf_exclude_' . $network ] );
        if ( $exclude ) {
            update_post_meta( $post_id, '_mmgrf_exclude_' . $network, '1' );
        } else {
            delete_post_meta( $post_id, '_mmgrf_exclude_' . $network );
        }
    }
}

// ─────────────────────────────────────────────────────────────────
// ATTACHMENT SYNDICATION-RIGHTS FIELD (MSN HasSyndicationRights)
// ─────────────────────────────────────────────────────────────────

add_filter( 'attachment_fields_to_edit', function( $fields, $post ) {
    $flagged = get_post_meta( $post->ID, '_mmgrf_no_syndication_rights', true );
    $fields['mmgrf_no_syndication_rights'] = [
        'label' => 'Syndication rights',
        'input' => 'html',
        'html'  => '<label><input type="checkbox" name="attachments[' . $post->ID . '][mmgrf_no_syndication_rights]" value="1"' . ( $flagged ? ' checked' : '' ) . '> MMG does NOT hold syndication rights (Getty, licensed) — image withheld from MSN</label>',
    ];
    return $fields;
}, 10, 2 );

add_filter( 'attachment_fields_to_save', function( $post, $attachment ) {
    if ( ! empty( $attachment['mmgrf_no_syndication_rights'] ) ) {
        update_post_meta( $post['ID'], '_mmgrf_no_syndication_rights', '1' );
    } else {
        delete_post_meta( $post['ID'], '_mmgrf_no_syndication_rights' );
    }
    mmgrf_touch_config(); // a takedown must not wait behind a 304
    return $post;
}, 10, 2 );
