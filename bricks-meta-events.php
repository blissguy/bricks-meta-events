<?php
/**
 * Plugin Name:       Bricks Meta Events
 * Description:       Sends Bricks form submissions and button clicks to Meta as conversions. No setup of its own: it uses your existing pixel settings.
 * Version:           0.9.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
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

const VERSION = '0.9.1';
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
 * It needs Meta pixel for WordPress or Meta for WooCommerce. WordPress's
 * "Requires Plugins" header can only demand every plugin it lists, not one of
 * them, so the dependency is checked at runtime instead: with neither active,
 * nothing fatals and the admin notice says what is missing.
 */
add_action(
	'plugins_loaded',
	static function () {
		Plugin::instance()->boot();
	},
	// Late, so the host plugins have registered their classes and options.
	20
);

register_activation_hook( FILE, array( __NAMESPACE__ . '\\Plugin', 'on_activate' ) );
register_deactivation_hook( FILE, array( __NAMESPACE__ . '\\Plugin', 'on_deactivate' ) );
