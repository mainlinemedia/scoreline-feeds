<?php
/**
 * Admin screens: the settings page, the activity log, and the Posts-list
 * column. Rendering only — saving and validation live in admin.php.
 *
 * Layout principle: what an operator does most (switch a network on, copy
 * its feed URL, see that it is being served, find out why an article was
 * not sent) is on the first screen; rarely-touched plumbing is one click
 * down. Every control is a real choice (a switch, a menu) wherever the set
 * of valid answers is known, so a typo cannot silently disable a rule.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────────────────────────
// ASSETS
// ─────────────────────────────────────────────────────────────────

add_action( 'admin_enqueue_scripts', 'mmgrf_admin_assets' );
function mmgrf_admin_assets( $hook ) {
    $ours = [ 'settings_page_scoreline-feeds', 'tools_page_mmgrf-syndication-log', 'edit.php' ];
    if ( ! in_array( $hook, $ours, true ) ) {
        return;
    }
    $base = plugin_dir_url( MMGRF_DIR . '/scoreline-feeds.php' ) . 'assets/';
    wp_enqueue_style( 'mmgrf-admin', $base . 'admin.css', [ 'dashicons' ], MMGRF_VERSION );
    if ( $hook !== 'edit.php' ) {
        wp_enqueue_script( 'mmgrf-admin', $base . 'admin.js', [], MMGRF_VERSION, true );
    }
}

// ─────────────────────────────────────────────────────────────────
// SMALL RENDER HELPERS (all output escaped here)
// ─────────────────────────────────────────────────────────────────

function mmgrf_ui_row( $label, $control, $help = '', $for = '' ) {
    echo '<tr><th scope="row">' . ( $for !== '' ? '<label for="' . esc_attr( $for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label ) ) . '</th><td>';
    echo $control; // phpcs:ignore — built by the helpers below, already escaped
    if ( $help !== '' ) {
        echo '<p class="description">' . wp_kses( $help, [ 'code' => [], 'strong' => [], 'em' => [] ] ) . '</p>';
    }
    echo '</td></tr>';
}

function mmgrf_ui_input( $name, $value, $args = [] ) {
    $id   = $args['id'] ?? sanitize_key( str_replace( [ '[', ']' ], [ '_', '' ], $name ) );
    $type = $args['type'] ?? 'text';
    $html = '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"'
        . ' class="' . esc_attr( $args['class'] ?? ( $type === 'number' ? 'small-text' : 'regular-text' ) ) . '"';
    foreach ( [ 'min', 'max', 'step', 'placeholder' ] as $attr ) {
        if ( isset( $args[ $attr ] ) ) {
            $html .= ' ' . $attr . '="' . esc_attr( (string) $args[ $attr ] ) . '"';
        }
    }
    return $html . '>' . ( isset( $args['suffix'] ) ? ' <span class="mmgrf-suffix">' . esc_html( $args['suffix'] ) . '</span>' : '' );
}

function mmgrf_ui_select( $name, $value, $choices, $id = '' ) {
    $id   = $id !== '' ? $id : sanitize_key( str_replace( [ '[', ']' ], [ '_', '' ], $name ) );
    $html = '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
    foreach ( $choices as $key => $label ) {
        $html .= '<option value="' . esc_attr( (string) $key ) . '"' . ( (string) $key === (string) $value ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
    }
    return $html . '</select>';
}

function mmgrf_ui_checkbox( $name, $checked, $label ) {
    return '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . ( $checked ? ' checked' : '' ) . '> ' . esc_html( $label ) . '</label>';
}

/**
 * Feedback under a comma-separated list of category/tag slugs: the names it
 * matched, and — loudly — any slug that matches nothing. A mistyped slug is
 * the classic way a rule silently stops applying.
 */
function mmgrf_ui_term_note( $slugs, $taxonomy ) {
    if ( trim( (string) $slugs ) === '' ) {
        return '';
    }
    $noun  = $taxonomy === 'category' ? 'category' : 'tag';
    $found = [];
    $html  = '';
    foreach ( explode( ',', (string) $slugs ) as $slug ) {
        $slug = trim( $slug );
        if ( $slug === '' ) {
            continue;
        }
        $term = get_term_by( 'slug', $slug, $taxonomy );
        if ( $term ) {
            $found[] = mmgrf_text_field( $term->name );
        } else {
            $html .= '<p class="mmgrf-field-error"><span class="dashicons dashicons-warning" aria-hidden="true"></span> No ' . esc_html( $noun ) . ' with the slug <code>' . esc_html( $slug ) . '</code> exists on this site — it matches nothing.</p>';
        }
    }
    if ( $found ) {
        $html = '<p class="mmgrf-field-ok"><span class="dashicons dashicons-yes" aria-hidden="true"></span> Matches: ' . esc_html( implode( ', ', $found ) ) . '</p>' . $html;
    }
    return $html;
}

/** "3 minutes ago" for a unix time. */
function mmgrf_ui_ago( $ts ) {
    return human_time_diff( (int) $ts, time() ) . ' ago';
}

/** A feed URL with Copy and Open controls. */
function mmgrf_ui_feed_url( $url ) {
    return '<code class="mmgrf-url">' . esc_html( $url ) . '</code> '
        . '<button type="button" class="button button-small mmgrf-copy" data-copy="' . esc_attr( $url ) . '">Copy</button> '
        . '<a class="mmgrf-open" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Open<span class="screen-reader-text"> feed in a new tab</span></a>';
}

/** One line on what a feed last served. */
function mmgrf_ui_feed_status_text( $cache_id ) {
    $status = mmgrf_feed_status( $cache_id );
    if ( ! $status ) {
        return '<span class="mmgrf-status is-idle">Not requested in the last 24 hours</span>';
    }
    $n = (int) $status['items'];
    return '<span class="mmgrf-status is-live">' . esc_html( $n . ( $n === 1 ? ' item' : ' items' ) . ' · last served ' . mmgrf_ui_ago( $status['built'] ) ) . '</span>';
}

/** Distinct posts withheld from each log network in the retained log. */
function mmgrf_ui_withheld_counts() {
    $posts = [];
    foreach ( mmgrf_skip_log_get() as $e ) {
        if ( ( $e['level'] ?? 'skip' ) === 'skip' && (int) ( $e['post_id'] ?? 0 ) > 0 ) {
            $posts[ $e['network'] ][ (int) $e['post_id'] ] = true;
        }
    }
    return array_map( 'count', $posts );
}

// ─────────────────────────────────────────────────────────────────
// ACTIVITY LOG
// ─────────────────────────────────────────────────────────────────

function mmgrf_syndication_log_page() {
    $entries = mmgrf_skip_log_visible_entries( get_current_user_id(), current_user_can( 'manage_options' ) );
    echo '<div class="wrap mmgrf-wrap"><h1>Syndication Log</h1>';
    echo '<p>Articles are sent to our syndication partners automatically. This is the list of what was <strong>not</strong> sent, or was sent with something changed, in the last 7 days — and what, if anything, would fix it.</p>';
    mmgrf_render_skip_log_table( $entries );
    echo '</div>';
}

function mmgrf_render_skip_log_table( $entries ) {
    if ( ! $entries ) {
        echo '<p class="mmgrf-empty">Nothing to report in the last 7 days — everything eligible was sent.</p>';
        return;
    }
    $networks = array_values( array_unique( array_column( $entries, 'network' ) ) );
    sort( $networks );
    ?>
    <div class="mmgrf-log-filters">
        <label class="screen-reader-text" for="mmgrf-log-network">Filter by network</label>
        <select id="mmgrf-log-network">
            <option value="">All networks</option>
            <?php foreach ( $networks as $n ) : ?>
                <option value="<?php echo esc_attr( $n ); ?>"><?php echo esc_html( mmgrf_network_label( $n ) ); ?></option>
            <?php endforeach; ?>
        </select>
        <label class="screen-reader-text" for="mmgrf-log-level">Filter by outcome</label>
        <select id="mmgrf-log-level">
            <option value="">Every outcome</option>
            <option value="skip">Not sent</option>
            <option value="warn">Sent, with a note</option>
        </select>
        <label class="screen-reader-text" for="mmgrf-log-search">Search the log</label>
        <input type="search" id="mmgrf-log-search" placeholder="Search articles…">
        <span class="mmgrf-log-count" aria-live="polite"></span>
    </div>
    <table class="widefat striped mmgrf-log">
        <thead><tr><th>When</th><th>Network</th><th>Outcome</th><th>Article</th><th>What happened</th><th>What to do</th></tr></thead>
        <tbody>
        <?php foreach ( array_slice( $entries, 0, MMGRF_SKIP_LOG_CAP ) as $e ) :
            $code  = (string) ( $e['code'] ?? ( $e['reason'] ?? '' ) );
            $level = ( $e['level'] ?? 'skip' ) === 'warn' ? 'warn' : 'skip';
            [ $label, $fix ] = mmgrf_code_info( $code );
            ?>
            <tr data-network="<?php echo esc_attr( $e['network'] ); ?>" data-level="<?php echo esc_attr( $level ); ?>">
                <td class="mmgrf-log-when"><?php echo esc_html( wp_date( 'M j, g:i a', (int) $e['ts'] ) ); ?></td>
                <td><?php echo esc_html( mmgrf_network_label( $e['network'] ) ); ?></td>
                <td><span class="mmgrf-pill is-<?php echo esc_attr( $level ); ?>"><?php echo $level === 'skip' ? 'Not sent' : 'Sent, with a note'; ?></span></td>
                <td class="mmgrf-log-post"><?php if ( (int) $e['post_id'] > 0 ) : ?><a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $e['post_id'] . '&action=edit' ) ); ?>"><?php echo esc_html( $e['post_title'] ); ?></a><?php else : ?><?php echo esc_html( $e['post_title'] ); ?><?php endif; ?></td>
                <td><strong><?php echo esc_html( $label ); ?></strong><?php if ( ( $e['detail'] ?? '' ) !== '' ) : ?><br><span class="mmgrf-log-detail"><?php echo esc_html( $e['detail'] ); ?></span><?php endif; ?><br><code class="mmgrf-log-code"><?php echo esc_html( $code ); ?></code></td>
                <td><?php echo esc_html( $fix ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

// ─────────────────────────────────────────────────────────────────
// SETTINGS PAGE
// ─────────────────────────────────────────────────────────────────

function mmgrf_settings_page() {
    $opts      = mmgrf_get_options();
    $networks  = mmgrf_get_network_config();
    $tag_feeds = mmgrf_get_tag_feeds();
    $withheld  = mmgrf_ui_withheld_counts();

    $edit_index = ( isset( $_GET['edit_tag'] ) && $_GET['edit_tag'] !== '' ) ? (int) $_GET['edit_tag'] : null;
    $editing    = ( $edit_index !== null && isset( $tag_feeds[ $edit_index ] ) ) ? $tag_feeds[ $edit_index ] : null;

    $network_notes = [
        'newsbreak' => 'Full articles with a lead image. Also takes slideshow and video posts as ordinary articles.',
        'msn'       => 'Headlines of 21–150 characters and an image of at least 640×360 we hold syndication rights to.',
        'yahoo'     => 'Articles of 150 words or more. Images at least 1280×720. No betting or affiliate links.',
    ];
    $actions = [
        'skip_item'       => 'Do not send the article to Yahoo',
        'unwrap'          => 'Send the article, with the link removed',
        'strip_paragraph' => 'Send the article, with that paragraph removed',
    ];
    $sizes = [ 'full' => 'Full size' ];
    foreach ( get_intermediate_image_sizes() as $size ) {
        $sizes[ $size ] = ucfirst( str_replace( [ '_', '-' ], ' ', $size ) );
    }
    if ( ! isset( $sizes[ $opts['image_size'] ] ) ) {
        $sizes[ $opts['image_size'] ] = $opts['image_size'];
    }
    ?>
    <div class="wrap mmgrf-wrap">
        <h1>Scoreline Feeds <span class="mmgrf-version">v<?php echo esc_html( MMGRF_VERSION ); ?></span></h1>
        <p class="mmgrf-intro">Published articles are sent to each switched-on network automatically. Writers do not need to do anything.</p>

        <?php if ( isset( $_GET['mmgrf_saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>Tag feed saved.</p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['mmgrf_deleted'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>Tag feed deleted.</p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['mmgrf_error'] ) ) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo $_GET['mmgrf_error'] === 'slug_taken' ? 'That feed slug is already in use by another Scoreline feed — choose a different one.' : 'A tag feed needs both a slug and at least one tag.'; ?></p></div>
        <?php endif; ?>
        <?php foreach ( mmgrf_health_problems() as $problem ) : ?>
            <div class="notice notice-<?php echo $problem['level'] === 'error' ? 'error' : 'warning'; ?>"><p><strong>Scoreline Feeds:</strong> <?php echo esc_html( $problem['message'] ); ?></p></div>
        <?php endforeach; ?>

        <nav class="nav-tab-wrapper mmgrf-tabs" aria-label="Scoreline Feeds sections">
            <a href="#networks" class="nav-tab nav-tab-active" data-tab="networks">Networks</a>
            <a href="#rules" class="nav-tab" data-tab="rules">Content rules</a>
            <a href="#email" class="nav-tab" data-tab="email">Email feed</a>
            <a href="#tag-feeds" class="nav-tab" data-tab="tag-feeds">Tag feeds</a>
            <a href="#log" class="nav-tab" data-tab="log">Activity log</a>
        </nav>

        <form method="post" action="options.php" class="mmgrf-form">
            <?php settings_fields( 'mmgrf_options_group' ); ?>

            <?php /* ─────────────── Networks ─────────────── */ ?>
            <section class="mmgrf-panel" id="mmgrf-panel-networks" data-panel="networks">
                <h2 class="screen-reader-text">Networks</h2>
                <?php foreach ( MMGRF_NETWORKS as $key ) :
                    $net   = $networks[ $key ];
                    $on    = ! empty( $net['enabled'] );
                    $name  = "mmgrf_networks[{$key}]";
                    $typed = in_array( $key, MMGRF_TYPED_NETWORKS, true );
                    $held  = (int) ( $withheld[ $key ] ?? 0 );
                    ?>
                    <div class="mmgrf-card <?php echo $on ? 'is-on' : 'is-off'; ?>">
                        <div class="mmgrf-card-head">
                            <label class="mmgrf-switch">
                                <input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $on ); ?>>
                                <span class="mmgrf-switch-name"><?php echo esc_html( mmgrf_network_label( $key ) ); ?></span>
                            </label>
                            <span class="mmgrf-badge <?php echo $on ? 'is-on' : 'is-off'; ?>"><?php echo $on ? 'On' : 'Off'; ?></span>
                        </div>
                        <p class="description"><?php echo esc_html( $network_notes[ $key ] ); ?></p>

                        <?php if ( $on ) : ?>
                        <table class="mmgrf-feeds">
                            <tr>
                                <th scope="row">Articles</th>
                                <td><?php echo mmgrf_ui_feed_url( mmgrf_network_feed_url( $net['slug'] ) ); // phpcs:ignore ?></td>
                                <td><?php echo mmgrf_ui_feed_status_text( 'article|' . $key . '|' . $net['slug'] ); // phpcs:ignore ?>
                                    <?php if ( $held ) : ?> · <a href="#log" class="mmgrf-goto-log" data-network="<?php echo esc_attr( $key ); ?>"><?php echo (int) $held; ?> not sent this week</a><?php endif; ?></td>
                            </tr>
                            <?php if ( $typed ) : ?>
                            <tr>
                                <th scope="row">Slideshows</th>
                                <td><?php echo mmgrf_ui_feed_url( mmgrf_network_feed_url( $net['slug'] . '-slideshows' ) ); // phpcs:ignore ?></td>
                                <td><?php echo mmgrf_ui_feed_status_text( 'slideshow|' . $key . '|' . $net['slug'] . '-slideshows' ); // phpcs:ignore ?></td>
                            </tr>
                            <tr>
                                <th scope="row">Videos</th>
                                <td><?php echo mmgrf_ui_feed_url( mmgrf_network_feed_url( $net['slug'] . '-videos' ) ); // phpcs:ignore ?></td>
                                <td><?php echo mmgrf_ui_feed_status_text( 'video|' . $key . '|' . $net['slug'] . '-videos' ); // phpcs:ignore ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                        <?php if ( $typed ) : ?>
                            <p class="description">Give the partner each URL as its own feed: articles, slideshows and videos are registered separately.</p>
                        <?php endif; ?>
                        <?php else : ?>
                            <p class="mmgrf-off-note">Switch on and save to start serving this feed.</p>
                        <?php endif; ?>

                        <details class="mmgrf-advanced">
                            <summary>Advanced</summary>
                            <table class="form-table" role="presentation">
                                <?php
                                mmgrf_ui_row( 'Feed address', mmgrf_ui_input( $name . '[slug]', $net['slug'] ), 'The last part of the feed URL. <strong>Changing this changes the URL the partner polls</strong> — only do it before the feed is registered.', sanitize_key( "mmgrf_networks_{$key}_slug" ) );
                                mmgrf_ui_row( 'Articles in the feed', mmgrf_ui_input( $name . '[post_count]', $net['post_count'] ?: '', [ 'type' => 'number', 'min' => 0, 'max' => 100, 'placeholder' => 'auto' ] ), 'Blank uses the recommended number for this network (NewsBreak 50, MSN 30, Yahoo 50).', sanitize_key( "mmgrf_networks_{$key}_post_count" ) );
                                mmgrf_ui_row( 'Link tracking', mmgrf_ui_checkbox( $name . '[utm_enabled]', ! empty( $net['utm_enabled'] ), 'Add UTM parameters to article links' ), 'Leave off: networks treat the link as the article\'s identity, and tracking parameters weaken attribution.' );
                                if ( $key === 'newsbreak' ) {
                                    mmgrf_ui_row( 'nb:scripts', '<textarea id="mmgrf_nb_scripts" name="mmgrf_networks[newsbreak][nb_scripts]" rows="3" class="large-text code" placeholder="Empty = not sent">' . esc_textarea( $net['nb_scripts'] ) . '</textarea>', 'Third-party tracking markup that NewsBreak runs on its own pages under our name. <strong>Leave empty unless explicitly signed off.</strong>', 'mmgrf_nb_scripts' );
                                }
                                ?>
                            </table>
                            <input type="hidden" name="<?php echo esc_attr( $name ); ?>[image_size]" value="<?php echo esc_attr( $net['image_size'] ); ?>">
                        </details>
                    </div>
                <?php endforeach; ?>
            </section>

            <?php /* ─────────────── Content rules ─────────────── */ ?>
            <section class="mmgrf-panel" id="mmgrf-panel-rules" data-panel="rules">
                <h2>Never send to the networks</h2>
                <p class="mmgrf-section-note">Articles in these categories or with these tags stay out of Yahoo, MSN and NewsBreak — for sponsored and partner content. The email feed is not affected. A single article can also be kept out from its own Syndication box.</p>
                <table class="form-table" role="presentation">
                    <?php
                    mmgrf_ui_row( 'Categories', mmgrf_ui_input( 'mmgrf_options[exclude_networks_cats]', $opts['exclude_networks_cats'], [ 'placeholder' => 'sponsored, partner-content' ] ) . mmgrf_ui_term_note( $opts['exclude_networks_cats'], 'category' ), 'Category slugs, separated by commas. Sub-categories are included.', 'mmgrf_options_exclude_networks_cats' );
                    mmgrf_ui_row( 'Tags', mmgrf_ui_input( 'mmgrf_options[exclude_networks_tags]', $opts['exclude_networks_tags'], [ 'placeholder' => 'sponsored' ] ) . mmgrf_ui_term_note( $opts['exclude_networks_tags'], 'post_tag' ), 'Tag slugs, separated by commas.', 'mmgrf_options_exclude_networks_tags' );
                    ?>
                </table>

                <h2>Timing</h2>
                <table class="form-table" role="presentation">
                    <?php mmgrf_ui_row( 'Wait after publishing', mmgrf_ui_input( 'mmgrf_options[settle_seconds]', (int) $opts['settle_seconds'], [ 'type' => 'number', 'min' => 0, 'max' => 900, 'suffix' => 'seconds' ] ), 'A new article is held back this long before the networks can pick it up, so its categories, image and sponsored flag are always saved first (an article cannot be recalled once a network has it). 60 is recommended; 0 switches the wait off.', 'mmgrf_options_settle_seconds' ); ?>
                </table>

                <h2>Betting and affiliate links <span class="mmgrf-h2-note">Yahoo</span></h2>
                <p class="mmgrf-section-note">Yahoo prohibits affiliate and commerce links. Only link addresses are checked — never the words in the article.</p>
                <table class="form-table" role="presentation">
                    <?php
                    mmgrf_ui_row( 'Operator sites', mmgrf_ui_input( 'mmgrf_options[affiliate_domains]', $opts['affiliate_domains'], [ 'class' => 'large-text' ] ), 'Domains, separated by commas. A link to any of these (or a sub-domain) counts.', 'mmgrf_options_affiliate_domains' );
                    mmgrf_ui_row( 'When an article links to one', mmgrf_ui_select( 'mmgrf_options[affiliate_domain_action]', $opts['affiliate_domain_action'], $actions ), '', 'mmgrf_options_affiliate_domain_action' );
                    mmgrf_ui_row( 'Promotional link patterns', mmgrf_ui_input( 'mmgrf_options[affiliate_paths]', $opts['affiliate_paths'], [ 'class' => 'large-text' ] ), 'Pieces of a link address that mark it as promotional, separated by commas.', 'mmgrf_options_affiliate_paths' );
                    mmgrf_ui_row( 'When a link matches a pattern', mmgrf_ui_select( 'mmgrf_options[affiliate_path_action]', $opts['affiliate_path_action'], $actions ), '', 'mmgrf_options_affiliate_path_action' );
                    ?>
                </table>

                <h2>Slideshows and videos</h2>
                <p class="mmgrf-section-note">An article carrying one of these tags is sent to Yahoo and MSN through the slideshow or video feed instead of the article feed.</p>
                <table class="form-table" role="presentation">
                    <?php
                    mmgrf_ui_row( 'Slideshow tag', mmgrf_ui_input( 'mmgrf_options[slideshow_tag]', $opts['slideshow_tag'] ), 'Each heading (H2) followed by a photo becomes one slide.', 'mmgrf_options_slideshow_tag' );
                    mmgrf_ui_row( 'Video tag', mmgrf_ui_input( 'mmgrf_options[video_tag]', $opts['video_tag'] ), 'The article must contain a video uploaded to this site.', 'mmgrf_options_video_tag' );
                    ?>
                </table>

                <h2>WordPress's own feed</h2>
                <table class="form-table" role="presentation">
                    <?php mmgrf_ui_row( 'Site feed', mmgrf_ui_checkbox( 'mmgrf_options[enhance_default_feeds]', ! empty( $opts['enhance_default_feeds'] ), 'Add the featured image to the standard /feed/' ), 'Keeps the behaviour of the retired NewsBreak RSS Feed plugin. Leave on unless you know nothing reads <code>/feed/</code>.' ); ?>
                </table>
            </section>

            <?php /* ─────────────── Email feed ─────────────── */ ?>
            <section class="mmgrf-panel" id="mmgrf-panel-email" data-panel="email">
                <h2>Email feed</h2>
                <p class="mmgrf-section-note">The feed our email partner reads to build newsletters. It is always on and separate from the networks.</p>
                <table class="mmgrf-feeds mmgrf-feeds-solo">
                    <tr><th scope="row">Feed</th><td><?php echo mmgrf_ui_feed_url( mmgrf_feed_url( $opts['feed_slug'] ) ); // phpcs:ignore ?></td></tr>
                </table>
                <table class="form-table" role="presentation">
                    <?php
                    mmgrf_ui_row( 'Feed address', mmgrf_ui_input( 'mmgrf_options[feed_slug]', $opts['feed_slug'] ), '<strong>Changing this changes the URL the email partner reads.</strong>', 'mmgrf_options_feed_slug' );
                    mmgrf_ui_row( 'Title', mmgrf_ui_input( 'mmgrf_options[feed_title]', $opts['feed_title'] ), 'Shown as the name of every Scoreline feed.', 'mmgrf_options_feed_title' );
                    mmgrf_ui_row( 'Description', mmgrf_ui_input( 'mmgrf_options[feed_description]', $opts['feed_description'], [ 'class' => 'large-text' ] ), 'Blank uses the site tagline.', 'mmgrf_options_feed_description' );
                    mmgrf_ui_row( 'Site link', mmgrf_ui_input( 'mmgrf_options[feed_link]', $opts['feed_link'], [ 'type' => 'url' ] ), '', 'mmgrf_options_feed_link' );
                    mmgrf_ui_row( 'Articles in the feed', mmgrf_ui_input( 'mmgrf_options[post_count]', (int) $opts['post_count'], [ 'type' => 'number', 'min' => 1, 'max' => 100 ] ), '', 'mmgrf_options_post_count' );
                    mmgrf_ui_row( 'Image size', mmgrf_ui_select( 'mmgrf_options[image_size]', $opts['image_size'], $sizes ), '', 'mmgrf_options_image_size' );
                    mmgrf_ui_row( 'Link tracking', mmgrf_ui_input( 'mmgrf_options[utm_source]', $opts['utm_source'], [ 'class' => 'small-text mmgrf-utm', 'id' => 'mmgrf_options_utm_source' ] ) . ' <span class="mmgrf-suffix">source</span> &nbsp; ' . mmgrf_ui_input( 'mmgrf_options[utm_medium]', $opts['utm_medium'], [ 'class' => 'small-text mmgrf-utm', 'id' => 'mmgrf_options_utm_medium' ] ) . ' <span class="mmgrf-suffix">medium</span>', 'Added to article links in the email feed as <code>utm_source</code> and <code>utm_medium</code>.', 'mmgrf_options_utm_source' );
                    ?>
                </table>
                <h2>Which articles</h2>
                <p class="mmgrf-section-note">Leave everything blank to include all published articles.</p>
                <table class="form-table" role="presentation">
                    <?php
                    mmgrf_ui_row( 'Only these categories', mmgrf_ui_input( 'mmgrf_options[categories]', $opts['categories'] ) . mmgrf_ui_term_note( $opts['categories'], 'category' ), 'Category slugs, separated by commas.', 'mmgrf_options_categories' );
                    mmgrf_ui_row( 'Leave out these categories', mmgrf_ui_input( 'mmgrf_options[exclude_cats]', $opts['exclude_cats'] ) . mmgrf_ui_term_note( $opts['exclude_cats'], 'category' ), '', 'mmgrf_options_exclude_cats' );
                    mmgrf_ui_row( 'Only these tags', mmgrf_ui_input( 'mmgrf_options[tags]', $opts['tags'] ) . mmgrf_ui_term_note( $opts['tags'], 'post_tag' ), 'Tag slugs, separated by commas. An article needs at least one.', 'mmgrf_options_tags' );
                    mmgrf_ui_row( 'Leave out these tags', mmgrf_ui_input( 'mmgrf_options[exclude_tags]', $opts['exclude_tags'] ) . mmgrf_ui_term_note( $opts['exclude_tags'], 'post_tag' ), '', 'mmgrf_options_exclude_tags' );
                    ?>
                </table>
            </section>

            <div class="mmgrf-save" data-save-for="networks rules email">
                <?php submit_button( 'Save changes', 'primary', 'submit', false ); ?>
            </div>
        </form>

        <?php /* ─────────────── Tag feeds ─────────────── */ ?>
        <section class="mmgrf-panel" id="mmgrf-panel-tag-feeds" data-panel="tag-feeds">
            <h2>Tag feeds</h2>
            <p class="mmgrf-section-note">Extra email feeds limited to particular tags — for sending separate newsletters by topic.</p>

            <?php if ( $tag_feeds ) : ?>
            <table class="widefat striped mmgrf-tagfeeds">
                <thead><tr><th>Feed</th><th>Title</th><th>Tags</th><th>Articles</th><th><span class="screen-reader-text">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ( $tag_feeds as $i => $tf ) : ?>
                    <tr>
                        <td><?php echo mmgrf_ui_feed_url( mmgrf_feed_url( $tf['slug'] ) ); // phpcs:ignore ?></td>
                        <td><?php echo esc_html( $tf['title'] ?? '' ); ?></td>
                        <td><code><?php echo esc_html( $tf['tags'] ?? '' ); ?></code><?php echo mmgrf_ui_term_note( $tf['tags'] ?? '', 'post_tag' ); // phpcs:ignore ?></td>
                        <td><?php echo esc_html( (string) ( $tf['post_count'] ?? '' ) ); ?></td>
                        <td class="mmgrf-row-actions">
                            <a href="<?php echo esc_url( admin_url( 'options-general.php?page=scoreline-feeds&edit_tag=' . $i . '#tag-feeds' ) ); ?>">Edit</a>
                            <a class="mmgrf-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mmgrf_delete_tag_feed&index=' . $i ), 'mmgrf_delete_tag_feed' ) ); ?>" data-confirm="Delete this tag feed? Anything reading its URL will stop receiving articles.">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
                <p class="mmgrf-empty">No tag feeds yet.</p>
            <?php endif; ?>

            <div class="mmgrf-card">
                <h3><?php echo $editing ? 'Edit tag feed' : 'Add a tag feed'; ?></h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="mmgrf_save_tag_feed">
                    <?php wp_nonce_field( 'mmgrf_tag_feed_nonce' ); ?>
                    <?php if ( $edit_index !== null ) : ?>
                        <input type="hidden" name="edit_index" value="<?php echo (int) $edit_index; ?>">
                    <?php endif; ?>
                    <table class="form-table" role="presentation">
                        <?php
                        mmgrf_ui_row( 'Feed address', mmgrf_ui_input( 'tf_slug', $editing['slug'] ?? '', [ 'placeholder' => 'nascar' ] ), 'Becomes <code>?feed={address}-raw-feed</code>. Required.', 'tf_slug' );
                        mmgrf_ui_row( 'Tags', mmgrf_ui_input( 'tf_tags', $editing['tags'] ?? '', [ 'placeholder' => 'nascar, cup-series' ] ), 'Tag slugs, separated by commas. An article needs at least one. Required.', 'tf_tags' );
                        mmgrf_ui_row( 'Title', mmgrf_ui_input( 'tf_title', $editing['title'] ?? '' ), 'Blank uses the email feed\'s title.', 'tf_title' );
                        mmgrf_ui_row( 'Articles in the feed', mmgrf_ui_input( 'tf_post_count', $editing['post_count'] ?? 10, [ 'type' => 'number', 'min' => 1, 'max' => 200 ] ), '', 'tf_post_count' );
                        mmgrf_ui_row( 'Image size', mmgrf_ui_select( 'tf_image_size', $editing['image_size'] ?? 'large', $sizes ), '', 'tf_image_size' );
                        mmgrf_ui_row( 'Link tracking', mmgrf_ui_input( 'tf_utm_source', $editing['utm_source'] ?? 'feed', [ 'class' => 'small-text mmgrf-utm' ] ) . ' <span class="mmgrf-suffix">source</span> &nbsp; ' . mmgrf_ui_input( 'tf_utm_medium', $editing['utm_medium'] ?? 'email', [ 'class' => 'small-text mmgrf-utm' ] ) . ' <span class="mmgrf-suffix">medium</span>', '', 'tf_utm_source' );
                        ?>
                    </table>
                    <p class="submit">
                        <?php submit_button( $editing ? 'Save tag feed' : 'Add tag feed', 'primary', 'submit', false ); ?>
                        <?php if ( $editing ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'options-general.php?page=scoreline-feeds#tag-feeds' ) ); ?>" class="button">Cancel</a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>
        </section>

        <?php /* ─────────────── Activity log ─────────────── */ ?>
        <section class="mmgrf-panel" id="mmgrf-panel-log" data-panel="log">
            <h2>Activity log</h2>
            <p class="mmgrf-section-note">Partners never tell us when they reject an article — this is the only record of what was not sent, or was sent with something changed, in the last 7 days. Writers see their own articles under <strong>Tools → Syndication Log</strong>.</p>
            <?php $log = mmgrf_skip_log_get(); ?>
            <?php mmgrf_render_skip_log_table( $log ); ?>
            <?php if ( $log ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mmgrf-log-clear">
                <input type="hidden" name="action" value="mmgrf_clear_skip_log">
                <?php wp_nonce_field( 'mmgrf_clear_skip_log' ); ?>
                <?php submit_button( 'Clear the log', 'delete small', 'submit', false ); ?>
                <span class="description">Anything still wrong is logged again the next time a partner reads the feed.</span>
            </form>
            <?php endif; ?>
        </section>
    </div>
    <?php
}

// ─────────────────────────────────────────────────────────────────
// POSTS LIST: one glance at what was and was not sent
// ─────────────────────────────────────────────────────────────────

add_filter( 'manage_post_posts_columns', 'mmgrf_posts_column' );
function mmgrf_posts_column( $columns ) {
    if ( ! mmgrf_syndication_gating()['enabled'] ) {
        return $columns;
    }
    $columns['mmgrf_syndication'] = 'Syndication';
    return $columns;
}

add_action( 'manage_post_posts_custom_column', 'mmgrf_posts_column_cell', 10, 2 );
function mmgrf_posts_column_cell( $column, $post_id ) {
    if ( $column === 'mmgrf_syndication' ) {
        echo mmgrf_posts_column_html( $post_id ); // phpcs:ignore — escaped in the builder
    }
}

/**
 * What the Posts list shows for one article. Reads only stored state (the
 * exclusion flags and the activity log) — no feed checks are run per row.
 */
function mmgrf_posts_column_html( $post_id ) {
    $networks = mmgrf_syndication_gating()['networks'];
    if ( get_post_status( $post_id ) !== 'publish' ) {
        return '<span class="mmgrf-col is-none" title="Sent automatically once published">—</span>';
    }
    $log_by_post = [];
    foreach ( mmgrf_skip_log_get() as $e ) {
        if ( ( $e['level'] ?? 'skip' ) === 'skip' && (int) ( $e['post_id'] ?? 0 ) === (int) $post_id ) {
            $base = preg_replace( '/-(slideshows|videos)$/', '', (string) $e['network'] );
            $log_by_post[ (int) $post_id ][ $base ] = (string) $e['code'];
        }
    }
    $excluded = [];
    $withheld = [];
    foreach ( $networks as $net ) {
        if ( get_post_meta( $post_id, '_mmgrf_exclude_' . $net, true ) === '1' ) {
            $excluded[] = $net;
        } elseif ( isset( $log_by_post[ (int) $post_id ][ $net ] ) ) {
            $withheld[ $net ] = $log_by_post[ (int) $post_id ][ $net ];
        }
    }
    if ( count( $excluded ) === count( $networks ) ) {
        return '<span class="mmgrf-col is-excluded" title="Kept out of every network from the article\'s Syndication box">Kept out</span>';
    }
    $parts = [];
    foreach ( $withheld as $net => $code ) {
        $parts[] = '<span class="mmgrf-col is-withheld" title="' . esc_attr( mmgrf_code_info( $code )[1] ) . '">' . esc_html( mmgrf_network_label( $net ) . ': ' . lcfirst( mmgrf_code_info( $code )[0] ) ) . '</span>';
    }
    foreach ( $excluded as $net ) {
        $parts[] = '<span class="mmgrf-col is-excluded">' . esc_html( mmgrf_network_label( $net ) . ': kept out' ) . '</span>';
    }
    if ( ! $parts ) {
        return '<span class="mmgrf-col is-sent" title="Sent automatically to ' . esc_attr( mmgrf_syndication_network_names( $networks ) ) . '"><span class="dashicons dashicons-yes" aria-hidden="true"></span><span class="screen-reader-text">Sent</span></span>';
    }
    return implode( '<br>', $parts );
}
