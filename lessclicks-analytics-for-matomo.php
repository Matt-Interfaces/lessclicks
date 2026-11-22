<?php
/**
 * Plugin Name: Lessclicks Analytics for Matomo - Dashboard Widget
 * Plugin URI: https://matt-interfaces.ch/lessclicks
 * Description: View Matomo Analytics data directly in your WordPress dashboard. Simple integration, data-driven decisions.
 * Version: 0.9.3
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Christopher Matt
 * Author URI: https://www.matt-interfaces.ch
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lessclicks-analytics-for-matomo
 * Domain Path: /languages
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants
define( 'LESSCLICKS_VERSION', '0.9.3' );
define( 'LESSCLICKS_PLUGIN_FILE', __FILE__ );
define( 'LESSCLICKS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LESSCLICKS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'LESSCLICKS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Load main plugin class
 */
require_once LESSCLICKS_PLUGIN_PATH . 'includes/class-lessclicks.php';

/**
 * Initialize plugin
 */
function lessclicks_init() {
	return Lessclicks::get_instance();
}

/**
 * Run plugin
 */
add_action( 'plugins_loaded', 'lessclicks_init' );
