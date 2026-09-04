<?php
/**
 * Plugin Name: Pornalizer Connect
 * Plugin URI:  https://pornalizer.app
 * Description: Embed and sync videos from Pornalizer — live grid, lightbox player, AJAX search, WP cron sync.
 * Version:     1.0.0
 * Author:      Pornalizer
 * License:     GPL-2.0-or-later
 * Text Domain: pornalizer-connect
 */

defined( 'ABSPATH' ) || exit;

define( 'PZ_VERSION',    '1.0.0' );
define( 'PZ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PZ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PZ_API_BASE',   'https://pornalizer.app/api' );

require_once PZ_PLUGIN_DIR . 'includes/Api.php';
require_once PZ_PLUGIN_DIR . 'includes/PostType.php';
require_once PZ_PLUGIN_DIR . 'includes/Sync.php';
require_once PZ_PLUGIN_DIR . 'includes/Shortcodes.php';
require_once PZ_PLUGIN_DIR . 'includes/Admin.php';

/**
 * Bootstrap on plugins_loaded so all hooks run after core is ready.
 */
add_action( 'plugins_loaded', function () {
    Pornalizer\PostType::register();
    Pornalizer\Shortcodes::register();
    Pornalizer\Admin::register();
    Pornalizer\Sync::register_cron();
} );

register_activation_hook( __FILE__, function () {
    Pornalizer\Sync::on_activate();
    // Start drip cron if enabled
    if ( get_option( 'pz_drip_mode' ) === '1' && ! wp_next_scheduled( 'pz_drip_cron' ) ) {
        wp_schedule_event( time(), 'hourly', 'pz_drip_cron' );
    }
} );

register_deactivation_hook( __FILE__, function () {
    Pornalizer\Sync::on_deactivate();
    wp_clear_scheduled_hook( 'pz_drip_cron' );
} );
