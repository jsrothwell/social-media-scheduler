<?php
/**
 * Plugin Name:       Social Media Auto Post & Scheduler
 * Description:       Automatically posts your new WordPress content to social media platforms.
 * Version:           1.0.0
 * Author:            Jamieson Rothwell
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sm-scheduler
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// =============================================================================
// Settings Page
// =============================================================================

function sm_scheduler_add_admin_menu() {
    add_options_page(
        'Social Media Scheduler',
        'Social Scheduler',
        'manage_options',
        'sm_scheduler',
        'sm_scheduler_options_page_html'
    );
}
add_action( 'admin_menu', 'sm_scheduler_add_admin_menu' );

function sm_scheduler_settings_init() {
    register_setting( 'smSchedulerPage', 'sm_scheduler_settings' );

    add_settings_section(
        'sm_scheduler_api_section',
        'Connect Social Media Accounts',
        'sm_scheduler_settings_section_callback',
        'smSchedulerPage'
    );

    // Placeholder fields for social networks
    add_settings_field( 'sm_scheduler_twitter_api_key', 'Twitter API Key', 'sm_scheduler_field_render', 'smSchedulerPage', 'sm_scheduler_api_section', ['id' => 'twitter_api_key', 'label' => 'Enter your Twitter API Key'] );
    add_settings_field( 'sm_scheduler_twitter_api_secret', 'Twitter API Secret', 'sm_scheduler_field_render', 'smSchedulerPage', 'sm_scheduler_api_section', ['id' => 'twitter_api_secret', 'label' => 'Enter your Twitter API Secret'] );
    add_settings_field( 'sm_scheduler_facebook_app_id', 'Facebook App ID', 'sm_scheduler_field_render', 'smSchedulerPage', 'sm_scheduler_api_section', ['id' => 'facebook_app_id', 'label' => 'Enter your Facebook App ID'] );
    add_settings_field( 'sm_scheduler_linkedin_client_id', 'LinkedIn Client ID', 'sm_scheduler_field_render', 'smSchedulerPage', 'sm_scheduler_api_section', ['id' => 'linkedin_client_id', 'label' => 'Enter your LinkedIn Client ID'] );
}
add_action( 'admin_init', 'sm_scheduler_settings_init' );

function sm_scheduler_field_render($args) {
    $options = get_option( 'sm_scheduler_settings' );
    $value = isset($options[$args['id']]) ? esc_attr($options[$args['id']]) : '';
    ?>
    <input type='text' name='sm_scheduler_settings[<?php echo esc_attr($args['id']); ?>]' value='<?php echo $value; ?>' class="regular-text">
    <p class="description"><?php echo esc_html($args['label']); ?></p>
    <?php
}

function sm_scheduler_settings_section_callback() {
    echo '<p>Enter your API credentials below to connect your accounts. Full OAuth connection would be required for a production plugin.</p>';
}

function sm_scheduler_options_page_html() {
    ?>
    <div class="wrap">
        <h1>Social Media Auto Post & Scheduler</h1>
        <form action='options.php' method='post'>
            <?php
            settings_fields( 'smSchedulerPage' );
            do_settings_sections( 'smSchedulerPage' );
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// =============================================================================
// Post Metabox for individual post settings
// =============================================================================

function sm_scheduler_add_meta_box() {
    add_meta_box(
        'sm_scheduler_metabox',
        'Social Media Auto Post',
        'sm_scheduler_meta_box_html',
        ['post', 'page'], // Add to posts and pages
        'side',
        'high'
    );
}
add_action( 'add_meta_boxes', 'sm_scheduler_add_meta_box' );

function sm_scheduler_meta_box_html( $post ) {
    $value = get_post_meta( $post->ID, '_sm_scheduler_message', true );
    wp_nonce_field( 'sm_scheduler_save_meta_box_data', 'sm_scheduler_meta_box_nonce' );
    ?>
    <label for="sm_scheduler_message" class="components-base-control__label">Custom Message:</label>
    <textarea id="sm_scheduler_message" name="sm_scheduler_message" style="width:100%; height: 100px;" placeholder="Optional. If empty, the post title will be used."><?php echo esc_textarea( $value ); ?></textarea>
    <p class="description">Craft a custom message for social media. Use `{title}` and `{url}` as placeholders.</p>
    <hr>
    <label><input type="checkbox" name="sm_scheduler_disable_autopost" value="1" <?php checked( get_post_meta( $post->ID, '_sm_scheduler_disable_autopost', true ), '1' ); ?>> Disable auto post for this entry</label>
    <?php
}

function sm_scheduler_save_meta_box_data( $post_id ) {
    if ( ! isset( $_POST['sm_scheduler_meta_box_nonce'] ) || ! wp_verify_nonce( $_POST['sm_scheduler_meta_box_nonce'], 'sm_scheduler_save_meta_box_data' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    // Save custom message
    if ( isset( $_POST['sm_scheduler_message'] ) ) {
        update_post_meta( $post_id, '_sm_scheduler_message', sanitize_textarea_field( $_POST['sm_scheduler_message'] ) );
    }

    // Save disable setting
    if ( isset( $_POST['sm_scheduler_disable_autopost'] ) ) {
        update_post_meta( $post_id, '_sm_scheduler_disable_autopost', '1' );
    } else {
        delete_post_meta( $post_id, '_sm_scheduler_disable_autopost' );
    }
}
add_action( 'save_post', 'sm_scheduler_save_meta_box_data' );

// =============================================================================
// Auto Post Trigger
// =============================================================================

function sm_scheduler_on_publish( $new_status, $old_status, $post ) {
    // Check if this is a real post, is being published, and auto-post is not disabled
    if ( 'publish' !== $new_status || 'publish' === $old_status ) {
        return;
    }
    if ( ! in_array( $post->post_type, ['post', 'page'] ) ) {
        return;
    }
    if ( get_post_meta( $post->ID, '_sm_scheduler_disable_autopost', true ) ) {
        return;
    }

    // All checks passed, let's "share" the post
    sm_scheduler_share_post( $post->ID );
}
add_action( 'transition_post_status', 'sm_scheduler_on_publish', 10, 3 );


function sm_scheduler_share_post( $post_id ) {
    $post = get_post( $post_id );
    $options = get_option( 'sm_scheduler_settings' );
    $custom_message = get_post_meta( $post_id, '_sm_scheduler_message', true );
    $post_url = get_permalink( $post_id );

    // Prepare the message
    $message = ! empty( $custom_message ) ? $custom_message : $post->post_title;
    $message = str_replace( '{title}', $post->post_title, $message );
    $message = str_replace( '{url}', $post_url, $message );

    // --- THIS IS WHERE YOU WOULD ADD THE API CALLS ---

    // Example for Twitter (requires a library like Abraham's TwitterOAuth)
    if ( ! empty( $options['twitter_api_key'] ) ) {
        // ... code to post to Twitter using $message ...
        // error_log('Sharing to Twitter: ' . $message);
    }

    // Example for Facebook (requires Facebook SDK for PHP)
    if ( ! empty( $options['facebook_app_id'] ) ) {
        // ... code to post to Facebook using $message ...
        // error_log('Sharing to Facebook: ' . $message);
    }

    // Example for LinkedIn
    if ( ! empty( $options['linkedin_client_id'] ) ) {
         // ... code to post to LinkedIn using $message ...
         // error_log('Sharing to LinkedIn: ' . $message);
    }
}
