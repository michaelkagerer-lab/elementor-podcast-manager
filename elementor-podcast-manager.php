<?php
/**
 * Plugin Name:       Elementor Podcast Manager
 * Description:       Manage and display a podcast on Elementor websites. Episodes, RSS feed, custom player, and Elementor widgets — no external podcast platform required.
 * Version:           1.3.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Internal
 * Text Domain:       elementor-podcast-manager
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package EPM
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'EPM_VERSION', '1.3.0' );
define( 'EPM_FILE', __FILE__ );
define( 'EPM_PATH', plugin_dir_path( __FILE__ ) );
define( 'EPM_URL', plugin_dir_url( __FILE__ ) );
define( 'EPM_SLUG', 'elementor-podcast-manager' );
define( 'EPM_TEXT_DOMAIN', 'elementor-podcast-manager' );

// Global helpers (epm_esc_xml, epm()).
require_once EPM_PATH . 'includes/helpers.php';

// PSR-4 style autoloader for the EPM namespace.
spl_autoload_register(
	function ( $class ) {
		$prefix = 'EPM\\';

		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		// EPM\Elementor\Widgets\PodcastPlayerWidget -> includes/Elementor/Widgets/PodcastPlayerWidget.php
		$file = EPM_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Returns the main plugin instance.
 *
 * @return EPM\Plugin
 */
function epm() {
	return EPM\Plugin::instance();
}

// Boot the plugin.
add_action(
	'plugins_loaded',
	function () {
		epm()->init();
	}
);

// Activation: register CPT + feed endpoint, then flush rewrites.
register_activation_hook(
	__FILE__,
	function () {
		// Classes are available via autoloader at activation time.
		// The feed rule must be registered before the post type so that
		// /podcast/feed/ wins over the post type's archive-feed rule.
		EPM\Feed::register_endpoint();
		EPM\EpisodePostType::register();
		flush_rewrite_rules();
		update_option( 'epm_version', EPM_VERSION );
		// Open the setup assistant on the next admin page load (once).
		add_option( 'epm_activation_redirect', 1, '', false );
	}
);

// Deactivation: flush rewrites so /podcast/feed/ stops resolving, and stop
// the host sync / background import schedules.
register_deactivation_hook(
	__FILE__,
	function () {
		EPM\Hosting::unschedule_all();
		flush_rewrite_rules();
	}
);
