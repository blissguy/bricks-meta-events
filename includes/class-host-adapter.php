<?php
/**
 * Compatibility layer over "Meta pixel for WordPress" internals.
 *
 * Every class this file touches is private API of another plugin. It is
 * addressed by string FQCN and probed before use so a host-plugin update can
 * only degrade this plugin, never fatal it. Deliberately thin: real
 * verification lives in Diagnostics, which can actually talk to the Graph API.
 *
 * @package BricksMetaEvents
 */

namespace BricksMetaEvents;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the host plugin's internals behind probed, fail-safe accessors.
 */
class Host_Adapter {

	public const CLS_EVENT_FACTORY   = 'FacebookPixelPlugin\\Core\\ServerEventFactory';
	public const CLS_SERVER_EVENT    = 'FacebookPixelPlugin\\Core\\FacebookServerSideEvent';
	public const CLS_PLUGIN_UTILS    = 'FacebookPixelPlugin\\Core\\FacebookPluginUtils';
	public const CLS_OPTIONS         = 'FacebookPixelPlugin\\Core\\FacebookWordpressOptions';
	public const CLS_CIRCUIT_BREAKER = 'FacebookPixelPlugin\\Core\\FacebookCapiCircuitBreaker';
	public const CLS_AAM_FIELDS      = 'FacebookPixelPlugin\\Core\\AAMSettingsFields';

	/**
	 * Arity of ServerEventFactory::safe_create_event as of host plugin 5.2.2.
	 *
	 * An arity change is the most likely way this integration breaks, and it
	 * is silent: PHP would simply pass our $prefer_referrer into a different
	 * parameter. Probed rather than assumed.
	 */
	private const EXPECTED_SAFE_CREATE_ARITY = 5;

	/**
	 * Memoized probe results.
	 *
	 * @var array<string, array{label: string, ok: bool, detail: string}>|null
	 */
	private static ?array $probes = null;

	/**
	 * Run (and memoize) every structural probe against the host plugin.
	 *
	 * @return array<string, array{label: string, ok: bool, detail: string}>
	 */
	public static function probes(): array {
		if ( null !== self::$probes ) {
			return self::$probes;
		}

		$probes = array();

		$probes['host_active'] = self::probe(
			__( 'Host plugin loaded', 'bricks-meta-events' ),
			class_exists( self::CLS_OPTIONS ),
			self::CLS_OPTIONS
		);

		$probes['event_factory'] = self::probe(
			__( 'Event factory available', 'bricks-meta-events' ),
			method_exists( self::CLS_EVENT_FACTORY, 'safe_create_event' ),
			self::CLS_EVENT_FACTORY . '::safe_create_event()'
		);

		$arity = self::safe_create_event_arity();

		$probes['event_factory_arity'] = self::probe(
			__( 'Event factory signature unchanged', 'bricks-meta-events' ),
			self::EXPECTED_SAFE_CREATE_ARITY === $arity,
			null === $arity
				? __( 'Could not inspect the method.', 'bricks-meta-events' )
				: sprintf(
					/* translators: 1: expected parameter count, 2: actual parameter count. */
					__( 'Expected %1$d parameters, found %2$d.', 'bricks-meta-events' ),
					self::EXPECTED_SAFE_CREATE_ARITY,
					$arity
				)
		);

		$probes['server_event'] = self::probe(
			__( 'Conversions API transport available', 'bricks-meta-events' ),
			method_exists( self::CLS_SERVER_EVENT, 'get_instance' )
				&& method_exists( self::CLS_SERVER_EVENT, 'track' ),
			self::CLS_SERVER_EVENT . '::track()'
		);

		$probes['synchronous_send'] = self::probe(
			__( 'Synchronous test-event send available', 'bricks-meta-events' ),
			method_exists( self::CLS_SERVER_EVENT, 'send' ),
			self::CLS_SERVER_EVENT . '::send()'
		);

		$probes['internal_user'] = self::probe(
			__( 'Internal-user check available', 'bricks-meta-events' ),
			method_exists( self::CLS_PLUGIN_UTILS, 'is_internal_user' ),
			self::CLS_PLUGIN_UTILS . '::is_internal_user()'
		);

		$probes['aam_settings'] = self::probe(
			__( 'Advanced matching settings readable', 'bricks-meta-events' ),
			method_exists( self::CLS_OPTIONS, 'get_aam_settings' ),
			self::CLS_OPTIONS . '::get_aam_settings()'
		);

		$probes['active_pixel'] = self::probe(
			__( 'Connection-aware pixel lookup available', 'bricks-meta-events' ),
			method_exists( self::CLS_OPTIONS, 'get_active_pixel_id' ),
			method_exists( self::CLS_OPTIONS, 'get_active_pixel_id' )
				? self::CLS_OPTIONS . '::get_active_pixel_id()'
				: __( 'Falling back to the legacy accessor, which cannot see a Facebook Login for Business pixel.', 'bricks-meta-events' )
		);

		self::$probes = $probes;

		return self::$probes;
	}

	/**
	 * Whether every probe required to send a server event passed.
	 */
	public static function can_send_server_events(): bool {
		$required = array( 'host_active', 'event_factory', 'event_factory_arity', 'server_event' );

		foreach ( $required as $key ) {
			if ( empty( self::probes()[ $key ]['ok'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * List the probes that are currently failing.
	 *
	 * @return array<int, string> Human-readable probe labels.
	 */
	public static function failing_probes(): array {
		$failing = array();

		foreach ( self::probes() as $probe ) {
			if ( ! $probe['ok'] ) {
				$failing[] = $probe['label'];
			}
		}

		return $failing;
	}

	/* ---------------------------------------------------------------------
	 * Fail-safe accessors
	 * ------------------------------------------------------------------ */

	/**
	 * The pixel ID the host plugin is actually rendering, if any.
	 *
	 * Must be get_active_pixel_id(), not get_pixel_id(): a site connected via
	 * Facebook Login for Business stores its pixel under a completely separate
	 * option, and the legacy accessor returns an empty string for it. The host
	 * plugin's own injector branches the same way.
	 */
	public static function pixel_id(): string {
		foreach ( array( 'get_active_pixel_id', 'get_pixel_id' ) as $method ) {
			if ( method_exists( self::CLS_OPTIONS, $method ) ) {
				$id = (string) call_user_func( array( self::CLS_OPTIONS, $method ) );

				if ( '' !== $id ) {
					return $id;
				}
			}
		}

		return '';
	}

	/**
	 * Whether a Conversions API access token is present.
	 *
	 * Same active-vs-legacy split as the pixel ID. The token itself is never
	 * returned or displayed.
	 */
	public static function has_access_token(): bool {
		foreach ( array( 'get_active_access_token', 'get_access_token' ) as $method ) {
			if ( method_exists( self::CLS_OPTIONS, $method ) ) {
				if ( '' !== (string) call_user_func( array( self::CLS_OPTIONS, $method ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * How the site is connected: 'fbl4b', 'mbe', or '' when unknown.
	 *
	 * Worth surfacing because it determines which option the pixel and token
	 * live in, and which advanced-matching code path runs.
	 */
	public static function connection_type(): string {
		if ( ! method_exists( self::CLS_OPTIONS, 'get_connection_type' ) ) {
			return '';
		}

		return (string) call_user_func( array( self::CLS_OPTIONS, 'get_connection_type' ) );
	}

	/**
	 * Whether the site is connected via Facebook Business Extension.
	 *
	 * Relevant because a non-FBE site falls back to set_old_aam_settings(),
	 * which disables advanced matching unless a legacy use_pii flag exists.
	 */
	public static function is_fbe_installed(): bool {
		if ( ! method_exists( self::CLS_OPTIONS, 'get_is_fbe_installed' ) ) {
			return false;
		}

		return (bool) call_user_func( array( self::CLS_OPTIONS, 'get_is_fbe_installed' ) );
	}

	/**
	 * The host plugin's AdsPixelSettings object, or null.
	 *
	 * @return object|null
	 */
	public static function aam_settings() {
		if ( ! method_exists( self::CLS_OPTIONS, 'get_aam_settings' ) ) {
			return null;
		}

		$settings = call_user_func( array( self::CLS_OPTIONS, 'get_aam_settings' ) );

		return is_object( $settings ) ? $settings : null;
	}

	/**
	 * Whether the Conversions API circuit breaker is currently closed.
	 *
	 * Returns true when the breaker cannot be inspected: an unknown state
	 * should not read as "everything is broken".
	 */
	public static function circuit_breaker_ok(): bool {
		if ( ! method_exists( self::CLS_CIRCUIT_BREAKER, 'is_send_allowed' ) ) {
			return true;
		}

		return (bool) call_user_func( array( self::CLS_CIRCUIT_BREAKER, 'is_send_allowed' ) );
	}

	/**
	 * Whether the host plugin would discard events for the current user.
	 *
	 * This is current_user_can( 'edit_posts' ) || current_user_can( 'upload_files' )
	 * inside the host plugin, with no filter. Mirrored rather than reimplemented
	 * so the two can never disagree.
	 */
	public static function is_internal_user(): bool {
		if ( ! method_exists( self::CLS_PLUGIN_UTILS, 'is_internal_user' ) ) {
			// Match the host plugin's own logic if the method has moved.
			return current_user_can( 'edit_posts' ) || current_user_can( 'upload_files' );
		}

		return (bool) call_user_func( array( self::CLS_PLUGIN_UTILS, 'is_internal_user' ) );
	}

	/**
	 * Whether Meta's advanced-matching settings are present in the local cache.
	 *
	 * The host plugin fetches these from Meta and caches them in a transient.
	 * When the fetch fails the settings object is built disabled, which is
	 * indistinguishable from the user having turned matching off — except by
	 * looking for the cache entry.
	 */
	public static function aam_settings_cached(): bool {
		$key = 'facebook_pixel_aam_settings';

		if ( defined( 'FacebookPixelPlugin\\Core\\FacebookPluginConfig::AAM_SETTINGS_KEY' ) ) {
			$key = (string) constant( 'FacebookPixelPlugin\\Core\\FacebookPluginConfig::AAM_SETTINGS_KEY' );
		}

		return false !== get_transient( $key );
	}

	/**
	 * The host plugin's version string, if it exposes one.
	 */
	public static function host_version(): string {
		if ( ! defined( 'FacebookPixelPlugin\\Core\\FacebookPluginConfig::PLUGIN_VERSION' ) ) {
			return '';
		}

		return (string) constant( 'FacebookPixelPlugin\\Core\\FacebookPluginConfig::PLUGIN_VERSION' );
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Reflect the parameter count of safe_create_event().
	 *
	 * @return int|null Null when the method cannot be inspected.
	 */
	private static function safe_create_event_arity(): ?int {
		if ( ! method_exists( self::CLS_EVENT_FACTORY, 'safe_create_event' ) ) {
			return null;
		}

		try {
			$method = new \ReflectionMethod( self::CLS_EVENT_FACTORY, 'safe_create_event' );

			return $method->getNumberOfParameters();
		} catch ( \ReflectionException $e ) {
			return null;
		}
	}

	/**
	 * Build a single probe result row.
	 *
	 * @param string $label  Human-readable probe name.
	 * @param bool   $ok     Whether the probe passed.
	 * @param string $detail Supporting detail shown on the health screen.
	 *
	 * @return array{label: string, ok: bool, detail: string}
	 */
	private static function probe( string $label, bool $ok, string $detail ): array {
		return array(
			'label'  => $label,
			'ok'     => $ok,
			'detail' => $detail,
		);
	}
}
