<?php
/**
 * Which Meta plugin this site's pixel comes from.
 *
 * Either "Meta pixel for WordPress" or "Meta for WooCommerce" will do. Both
 * print the pixel, hold events until cookie consent, and offer a browser
 * sender with the same signature, so only the server half and the rules about
 * who is skipped differ between them.
 *
 * When both are active, Meta for WooCommerce wins. It is the one sending the
 * shop's purchases, and Meta pixel for WordPress steps back from WooCommerce
 * events when it sees it. It does not step back from printing its own pixel
 * and PageView, though, so running both counts every page view twice. The
 * health screen says so; this class only has to pick one.
 *
 * @package BricksMetaEvents
 */

namespace BricksMetaEvents;

defined( 'ABSPATH' ) || exit;

/**
 * Picks the host plugin and answers the questions that depend on it.
 */
class Host {

	public const PIXEL = 'pixel';
	public const WOO   = 'woo';

	/**
	 * Memoized choice.
	 */
	private static ?string $current = null;

	/**
	 * The host plugin in use: self::WOO, self::PIXEL, or '' for neither.
	 */
	public static function current(): string {
		if ( null !== self::$current ) {
			return self::$current;
		}

		$woo   = Woo_Adapter::is_active();
		$pixel = self::pixel_plugin_active();

		// Meta for WooCommerce wins only once it actually has a pixel. Until
		// it is connected it prints nothing, and an installed but unconnected
		// copy should not take over from a working Meta pixel for WordPress.
		if ( $woo && ( '' !== Woo_Adapter::pixel_id() || ! $pixel ) ) {
			$host = self::WOO;
		} elseif ( $pixel ) {
			$host = self::PIXEL;
		} else {
			$host = '';
		}

		/**
		 * Filters which Meta plugin is used when more than one is active.
		 *
		 * @param string $host 'woo', 'pixel' or ''. Ignored when it names a plugin that isn't active.
		 */
		$chosen = (string) apply_filters( 'bme_host', $host );

		// A filter can only choose between plugins that are actually running.
		// Naming one that isn't falls back to the normal choice.
		$available = array_keys( array_filter( array( self::WOO => $woo, self::PIXEL => $pixel ) ) );

		self::$current = in_array( $chosen, $available, true ) ? $chosen : $host;

		return self::$current;
	}

	/**
	 * Whether Meta for WooCommerce is the host.
	 */
	public static function is_woo(): bool {
		return self::WOO === self::current();
	}

	/**
	 * Whether Meta pixel for WordPress is loaded.
	 */
	public static function pixel_plugin_active(): bool {
		return class_exists( Host_Adapter::CLS_OPTIONS );
	}

	/**
	 * Whether both Meta plugins are switched on.
	 */
	public static function both_active(): bool {
		return Woo_Adapter::is_active() && self::pixel_plugin_active();
	}

	/**
	 * The host plugin's name, as it appears on the Plugins screen.
	 *
	 * @param string|null $host Defaults to the current host.
	 */
	public static function name( ?string $host = null ): string {
		$host = $host ?? self::current();

		if ( self::WOO === $host ) {
			return __( 'Meta for WooCommerce', 'bricks-meta-events' );
		}

		if ( self::PIXEL === $host ) {
			return __( 'Meta pixel for WordPress', 'bricks-meta-events' );
		}

		return '';
	}

	/**
	 * Where the host plugin's own settings live, for fix-it text.
	 */
	public static function settings_location(): string {
		return self::is_woo()
			? __( 'Marketing, Facebook', 'bricks-meta-events' )
			: __( 'Settings, Meta', 'bricks-meta-events' );
	}

	/**
	 * The pixel the host plugin is printing.
	 */
	public static function pixel_id(): string {
		if ( self::is_woo() ) {
			return Woo_Adapter::pixel_id();
		}

		return self::PIXEL === self::current() ? Host_Adapter::pixel_id() : '';
	}

	/**
	 * This site's pixel in Meta Events Manager, or '' without a pixel.
	 *
	 * The same address Meta pixel for WordPress links to from its own
	 * settings page. Events Manager opens whichever business owns the pixel,
	 * so no business ID is needed.
	 *
	 * @param bool $test Open the Test Events tab instead of the overview.
	 */
	public static function events_manager_url( bool $test = false ): string {
		$pixel_id = preg_replace( '/\D/', '', self::pixel_id() );

		if ( '' === $pixel_id ) {
			return '';
		}

		return 'https://business.facebook.com/events_manager2/list/pixel/' . $pixel_id . ( $test ? '/test_events' : '' );
	}

	/**
	 * Whether the host plugin's server-side internals are usable.
	 */
	public static function can_send_server_events(): bool {
		if ( self::is_woo() ) {
			return Woo_Adapter::can_send_server_events();
		}

		return self::PIXEL === self::current() && Host_Adapter::can_send_server_events();
	}

	/**
	 * Whether a Conversions API send could actually succeed right now.
	 */
	public static function capi_available(): bool {
		if ( self::is_woo() ) {
			return Woo_Adapter::can_send_server_events();
		}

		return Host_Adapter::can_send_server_events()
			&& Host_Adapter::has_access_token()
			&& Host_Adapter::circuit_breaker_ok();
	}

	/**
	 * Whether the signed-in visitor counts as staff, and so is not tracked.
	 *
	 * Meta pixel for WordPress decides this itself and discards their events
	 * whatever we do. Meta for WooCommerce only skips shop managers on
	 * purchases, so on that host the same rule is applied here.
	 */
	public static function is_internal_user(): bool {
		if ( self::is_woo() ) {
			return current_user_can( 'manage_woocommerce' );
		}

		return Host_Adapter::is_internal_user();
	}

	/**
	 * Whether staff form submissions should be sent while testing.
	 *
	 * Only possible on Meta for WooCommerce, which does not discard them.
	 * With test mode on they go to Test Events from the server alone:
	 * the browser half could not carry the code and would land in the real
	 * figures.
	 */
	public static function staff_can_test(): bool {
		return self::is_woo() && '' !== Form_Tracker::test_event_code();
	}

	/**
	 * Whether a role with these capabilities counts as staff.
	 *
	 * @param array<string, bool> $caps Role capabilities.
	 */
	public static function role_is_staff( array $caps ): bool {
		if ( self::is_woo() ) {
			return ! empty( $caps['manage_woocommerce'] );
		}

		return ! empty( $caps['edit_posts'] ) || ! empty( $caps['upload_files'] );
	}

	/**
	 * Who counts as staff, in words: "can manage WooCommerce".
	 */
	public static function staff_rule(): string {
		return self::is_woo()
			? __( 'can manage WooCommerce', 'bricks-meta-events' )
			: __( 'can edit posts or upload files', 'bricks-meta-events' );
	}

	/**
	 * Why a staff visitor's events are not sent, and who decides that.
	 */
	public static function staff_note(): string {
		return self::is_woo()
			? __( 'This plugin ignores anyone who can manage WooCommerce, the same as Meta for WooCommerce does for purchases.', 'bricks-meta-events' )
			: __( 'Meta pixel for WordPress ignores anyone who can edit posts or upload files.', 'bricks-meta-events' );
	}

	/**
	 * The global the host plugin's browser sender lives on.
	 *
	 * Same signature on both: trackEvent( name, params, userData, method, eventId ).
	 */
	public static function browser_sender(): string {
		return self::is_woo() ? 'FacebookSignals' : 'FacebookSignal';
	}

	/**
	 * The host plugin's version string.
	 */
	public static function version(): string {
		return self::is_woo() ? Woo_Adapter::version() : Host_Adapter::host_version();
	}
}
