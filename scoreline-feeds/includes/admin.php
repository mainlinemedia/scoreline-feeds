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
                <td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $e['post_id'] . '&action=edit' ) ); ?>"><?php echo esc_html( $e['post_title'] ); ?></a></td>
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
    wp_redirect( admin_url( 'options-general.php?page=scoreline-feeds#skip-log' ) );
    exit;
}

add_action( 'admin_init', 'mmgrf_register_settings' );
function mmgrf_register_settings() {
    register_setting( 'mmgrf_options_group', 'mmgrf_options', 'mmgrf_sanitize_options' );
    register_setting( 'mmgrf_options_group', 'mmgrf_networks', 'mmgrf_sanitize_networks' );

    add_settings_section( 'mmgrf_main',   'Main Feed Configuration (Aigeon legacy)', '__return_false', 'scoreline-feeds' );
    add_settings_section( 'mmgrf_filter', 'Content Filters', '__return_false', 'scoreline-feeds' );

    $fields_main = [
        'feed_slug'        => [ 'Feed Slug',        'Generates: /?feed={slug}-raw-feed — flush permalinks after changing.' ],
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
    ];
    foreach ( $fields_filter as $key => [ $label, $desc ] ) {
        add_settings_field( 'mmgrf_' . $key, $label, 'mmgrf_field_cb', 'scoreline-feeds', 'mmgrf_filter', [ 'key' => $key, 'desc' => $desc ] );
    }
}

function mmgrf_sanitize_options( $input ) {
    $clean = [];
    $clean['feed_slug']             = sanitize_title( $input['feed_slug'] ?? 'raw-feed' ) ?: 'raw-feed';
    $clean['feed_title']            = sanitize_text_field( $input['feed_title'] ?? get_bloginfo( 'name' ) );
    $clean['feed_description']      = sanitize_text_field( $input['feed_description'] ?? get_bloginfo( 'description' ) );
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
    $defaults = mmgrf_get_options();
    $clean['affiliate_domains']       = sanitize_text_field( $input['affiliate_domains'] ?? $defaults['affiliate_domains'] );
    $clean['affiliate_paths']         = sanitize_text_field( $input['affiliate_paths'] ?? $defaults['affiliate_paths'] );
    $clean['affiliate_domain_action'] = in_array( $input['affiliate_domain_action'] ?? '', [ 'skip_item', 'unwrap', 'strip_paragraph' ], true ) ? $input['affiliate_domain_action'] : 'skip_item';
    $clean['affiliate_path_action']   = in_array( $input['affiliate_path_action'] ?? '', [ 'skip_item', 'unwrap', 'strip_paragraph' ], true ) ? $input['affiliate_path_action'] : 'unwrap';
    $clean['slideshow_tag']           = sanitize_title( $input['slideshow_tag'] ?? 'slideshow' ) ?: 'slideshow';
    $clean['video_tag']               = sanitize_title( $input['video_tag'] ?? 'video' ) ?: 'video';
    $clean['enhance_default_feeds'] = empty( $input['enhance_default_feeds'] ) ? 0 : 1;
    set_transient( 'mmgrf_flush_rewrites', 1, 30 );
    mmgrf_touch_config();
    return $clean;
}

function mmgrf_sanitize_networks( $input ) {
    $clean = [];
    foreach ( MMGRF_NETWORKS as $key ) {
        $in = is_array( $input[ $key ] ?? null ) ? $input[ $key ] : [];
        $clean[ $key ] = [
            'enabled'     => ! empty( $in['enabled'] ),
            'slug'        => sanitize_title( $in['slug'] ?? '' ) ?: $key,
            'post_count'  => absint( $in['post_count'] ?? 0 ),
            'image_size'  => sanitize_key( $in['image_size'] ?? '' ),
            'utm_enabled' => ! empty( $in['utm_enabled'] ),
            // nb:scripts executes on NewsBreak's property — raw markup is the
            // point; gated by manage_options capability + Austin's sign-off.
            'nb_scripts'  => $key === 'newsbreak' ? trim( (string) ( $in['nb_scripts'] ?? '' ) ) : '',
        ];
    }
    set_transient( 'mmgrf_flush_rewrites', 1, 30 );
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

    $entry = [
        'slug'       => sanitize_title( $_POST['tf_slug'] ?? '' ),
        'title'      => sanitize_text_field( $_POST['tf_title'] ?? '' ),
        'tags'       => sanitize_text_field( $_POST['tf_tags'] ?? '' ),
        'post_count' => absint( $_POST['tf_post_count'] ?? 10 ),
        'utm_source' => sanitize_key( $_POST['tf_utm_source'] ?? 'feed' ),
        'utm_medium' => sanitize_key( $_POST['tf_utm_medium'] ?? 'email' ),
        'image_size' => sanitize_key( $_POST['tf_image_size'] ?? 'large' ),
    ];

    if ( empty( $entry['slug'] ) || empty( $entry['tags'] ) ) {
        wp_redirect( admin_url( 'options-general.php?page=scoreline-feeds&mmgrf_error=missing_fields#tag-feeds' ) );
        exit;
    }

    if ( $edit_index !== null && isset( $feeds[ $edit_index ] ) ) {
        $feeds[ $edit_index ] = $entry;
    } else {
        $feeds[] = $entry;
    }

    update_option( 'mmgrf_tag_feeds', $feeds );
    set_transient( 'mmgrf_flush_rewrites', 1, 30 );
    wp_redirect( admin_url( 'options-general.php?page=scoreline-feeds&mmgrf_saved=1#tag-feeds' ) );
    exit;
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
        set_transient( 'mmgrf_flush_rewrites', 1, 30 );
    }
    wp_redirect( admin_url( 'options-general.php?page=scoreline-feeds&mmgrf_deleted=1#tag-feeds' ) );
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
            <div class="notice notice-error is-dismissible"><p>⚠️ Slug and Tags are required.</p></div>
        <?php endif; ?>

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
                $qs_url     = mmgrf_network_feed_url( $net['slug'] );
                $pretty_url = home_url( '/feed/' . rawurlencode( $net['slug'] ) . '/' );
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
                            <p class="description">URLs: <code><?php echo esc_html( $qs_url ); ?></code> and <code><?php echo esc_html( $pretty_url ); ?></code><?php echo $key === 'msn' ? ' — give MSN the pretty form.' : ''; ?></p>
                            <?php if ( $key === 'yahoo' || $key === 'msn' ) : ?>
                            <p class="description">Typed feeds: slideshows <code><?php echo esc_html( home_url( '/feed/' . rawurlencode( $net['slug'] ) . '-slideshows/' ) ); ?></code> · videos <code><?php echo esc_html( home_url( '/feed/' . rawurlencode( $net['slug'] ) . '-videos/' ) ); ?></code> (register each as its content type with the partner).</p>
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
                            <textarea name="mmgrf_networks[newsbreak][nb_scripts]" rows="3" class="large-text code" placeholder="Empty = not emitted"><?php echo esc_html( $net['nb_scripts'] ); ?></textarea>
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
        <p style="color:#888;font-size:12px;">After adding or editing feeds, go to <strong>Settings → Permalinks</strong> and click Save once to register the new feed slugs.</p>
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

add_action( 'save_post', function( $post_id ) {
    if ( ! isset( $_POST['mmgrf_syndication_nonce'] ) || ! wp_verify_nonce( $_POST['mmgrf_syndication_nonce'], 'mmgrf_save_syndication' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    mmgrf_save_syndication_meta( $post_id, $_POST );
} );

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
    return $post;
}, 10, 2 );
