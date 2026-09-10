<?php
/**
 * Plugin Name:       Bricks Meta Events
 * Description:       Sends Bricks form submissions and button clicks to Meta as conversions. No setup of its own: it uses your existing pixel settings.
 * Version:           0.8.4
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  official-facebook-pixel
 * Author:            Mixbus Marketing
 * Author URI:        https://mixbusmarketing.com/
 * License:           GPL-2.0-or-later
 * Text Domain:       bricks-meta-events
 * Update URI:        false
 *
 * @package BricksMetaEvents
 */

namespace BricksMetaEvents;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.8.4';
const FILE    = __FILE__;
const DIR     = __DIR__;

/**
 * Minimal PSR-4-ish autoloader for this plugin's own classes.
 *
 * Maps BricksMetaEvents\Foo_Bar to includes/class-foo-bar.php.
 */
spl_autoload_register(
	static function ( $class ) {
		if ( ! str_starts_with( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( __NAMESPACE__ ) + 1 );
		$file     = DIR . '/includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Boot the plugin once all plugins are loaded.
 *
 * The host plugin is a hard dependency. WordPress 6.5+ enforces the
 * "Requires Plugins" header at activation, but a host plugin that is
 * deactivated afterwards still has to be survived without a fatal.
 */
add_action(
	'plugins_loaded',
	static function () {
		Plugin::instance()->boot();
	},
	// Late, so the host plugin has registered its classes and options.
	20
);

register_activation_hook( FILE, array( __NAMESPACE__ . '\\Plugin', 'on_activate' ) );
register_deactivation_hook( FILE, array( __NAMESPACE__ . '\\Plugin', 'on_deactivate' ) );
