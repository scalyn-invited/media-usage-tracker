<?php

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Plugin Name:       Media Usage Tracker
 * Plugin URI:        https://example.com/media-usage-tracker
 * Description:       Identifies where media files are used across your WordPress site, detects unused media, and provides cleanup tools.
 * Version:           1.2.0
 * Author:            YajAce
 * Author URI:        https://example.com
 * License:           GPL-2.0+
 * Text Domain:       media-usage-tracker
 * Domain Path:       /languages
 * Requires at least: 6.8
 * Requires PHP:      8.1
 */
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}
define( 'MUT_VERSION', '1.2.0' );
define( 'MUT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MUT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MUT_PLUGIN_FILE', __FILE__ );
// Autoloader - register early
require_once MUT_PLUGIN_DIR . 'includes/class-autoloader.php';
// Ensure critical classes are loaded for activation and admin_menu
require_once MUT_PLUGIN_DIR . 'includes/class-media-usage-tracker.php';
require_once MUT_PLUGIN_DIR . 'includes/class-activator.php';
require_once MUT_PLUGIN_DIR . 'includes/class-deactivator.php';
require_once MUT_PLUGIN_DIR . 'includes/class-usage-details.php';

// Load the Plugin Update Checker (GitHub-based updates)
require_once MUT_PLUGIN_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';

$mut_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/scalyn-invited/media-usage-tracker/',
	MUT_PLUGIN_FILE,
	'media-usage-tracker'
);

$mut_update_checker->getVcsApi()->enableReleaseAssets();

// Register activation/deactivation hooks (must be early)
\MediaUsageTracker\Core\Plugin::register_hooks();
// Keep the database schema current after plugin updates (no reactivation
// needed). Cheap no-op once the stored DB version matches MUT_VERSION.
add_action( 'plugins_loaded', array( '\MediaUsageTracker\Core\Activator', 'maybe_upgrade' ), 5 );
// Initialize the plugin
function run_media_usage_tracker() {
    $plugin = new \MediaUsageTracker\Core\Plugin();
    $plugin->run();
}
run_media_usage_tracker();