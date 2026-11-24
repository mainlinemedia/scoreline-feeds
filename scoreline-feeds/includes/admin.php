<?php
/**
 * Layer 4 — admin: menus, settings registration and validation, the tag-feed
 * and log handlers, saving the Syndication box, and the attachment
 * syndication-rights field. The screens themselves are rendered in
 * admin-ui.php; the Syndication box in syndication-check.php.
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
// SYNDICATION BOX — saving (the box is rendered in syndication-check.php)
// ─────────────────────────────────────────────────────────────────

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
        'html'  => '<label><input type="checkbox" name="attachments[' . $post->ID . '][mmgrf_no_syndication_rights]" value="1"' . ( $flagged ? ' checked' : '' ) . '> Not cleared for syndication (a licensed image we may not pass on). MSN will not be sent this image.</label>',
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
