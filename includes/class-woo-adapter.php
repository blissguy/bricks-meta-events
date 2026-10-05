<?php
/**
 * Compatibility layer over "Meta for WooCommerce" internals.
 *
 * The counterpart to Host_Adapter, for sites where the pixel comes from Meta
 * for WooCommerce rather than Meta pixel for WordPress. Everything touched
 * here is private API of another plugin, addressed by string name and probed
 * before use, so an update to it can only switch sending off, never fatal.
 *
 * Meta for WooCommerce sends straight to Meta and waits for the answer, so
 * there is no background route, no loopback and nothing to confirm later:
 * every send ends with Meta's own reply or an exception. It also hashes
 * whatever customer details it is given, with no advanced matching switch
 * that can strip them first.
 *
 * @package BricksMetaEvents
 */

namespace BricksMetaEvents;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps Meta for WooCommerce's internals behind probed, fail-safe accessors.
 */
class Woo_Adapter {

	public const CLS_EVENT       = 'WooCommerce\\Facebook\\Events\\Event';
	public const CLS_API         = 'WooCommerce\\Facebook\\API';
	public const CLS_SIGNALS     = 'WooCommerce\\Facebook\\Events\\FacebookSignalsState';
	public const CLS_INTEGRATION = 'WC_Facebookcommerce_Integration';

	/**
	 * Identity keys from Field_Mapper, mapped to the keys Meta expects.
	 *
	 * Meta for WooCommerce normalises and hashes these itself, and drops any
	 * value that fails its own validation rather than sending it.
	 */
	private const USER_DATA_KEYS = array(
		'email'       => 'em',
		'phone'       => 'ph',
		'first_name'  => 'fn',
		'last_name'   => 'ln',
		'external_id' => 'external_id',
	);

	/**
	 * Readable names for the hashed keys, for the log and the test result.
	 */
	private const MATCHED_LABELS = array(
		'em'          => 'email',
		'ph'          => 'phone',
		'fn'          => 'first name',
		'ln'          => 'last name',
		'external_id' => 'account id',
	);

	/**
	 * Memoized probe results.
	 *
	 * @var array<string, array{label: string, ok: bool, detail: string}>|null
	 */
	private static ?array $probes = null;

	/**
	 * Whether Meta for WooCommerce is loaded at all.
	 */
	public static function is_active(): bool {
		return function_exists( 'facebook_for_woocommerce' );
	}

	/**
	 * Run (and memoize) every structural probe.
	 *
	 * @return array<string, array{label: string, ok: bool, detail: string}>
	 */
	public static function probes(): array {
		if ( null !== self::$probes ) {
			return self::$probes;
		}

		self::$probes = array(
			'host_active'   => self::probe(
				__( 'Host plugin loaded', 'bricks-meta-events' ),
				self::is_active(),
				'facebook_for_woocommerce()'
			),
			'event'         => self::probe(
				__( 'Event builder available', 'bricks-meta-events' ),
				method_exists( self::CLS_EVENT, 'get_id' ) && method_exists( self::CLS_EVENT, 'get_user_data' ),
				self::CLS_EVENT
			),
			'transport'     => self::probe(
				__( 'Conversions API transport available', 'bricks-meta-events' ),
				method_exists( self::CLS_API, 'send_pixel_events' ),
				self::CLS_API . '::send_pixel_events()'
			),
			'pixel_lookup'  => self::probe(
				__( 'Pixel lookup available', 'bricks-meta-events' ),
				method_exists( self::CLS_INTEGRATION, 'get_facebook_pixel_id' ),
				self::CLS_INTEGRATION . '::get_facebook_pixel_id()'
			),
			'consent_state' => self::probe(
				__( 'Cookie consent state readable', 'bricks-meta-events' ),
				method_exists( self::CLS_SIGNALS, 'is_held' ),
				self::CLS_SIGNALS . '::is_held()'
			),
		);

		return self::$probes;
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
	 * The pixel ID Meta for WooCommerce is rendering, if any.
	 *
	 * Empty when its own pixel has been filtered off, because then it prints
	 * no pixel and there is nothing in the browser to send through.
	 */
	public static function pixel_id(): string {
		if ( ! self::is_active() || ! self::probes()['pixel_lookup']['ok'] ) {
			return '';
		}

		if ( ! apply_filters( 'facebook_for_woocommerce_integration_pixel_enabled', true ) ) {
			return '';
		}

		try {
			$integration = facebook_for_woocommerce()->get_integration();

			return is_object( $integration ) ? (string) $integration->get_facebook_pixel_id() : '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Whether the site is connected to Meta with an access token.
	 */
	public static function is_connected(): bool {
		if ( ! self::is_active() ) {
			return false;
		}

		try {
			$connection = facebook_for_woocommerce()->get_connection_handler();

			return is_object( $connection )
				&& $connection->is_connected()
				&& '' !== (string) $connection->get_access_token();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Whether Meta for WooCommerce has seen its token rejected.
	 *
	 * It stops sending entirely until the site is reconnected, and so does
	 * this plugin: a send that is certain to fail only adds noise.
	 */
	public static function connection_invalid(): bool {
		return false !== get_transient( 'wc_facebook_connection_invalid' );
	}

	/**
	 * Whether events are being held back until cookie consent is given.
	 */
	public static function is_held(): bool {
		if ( ! self::probes()['consent_state']['ok'] ) {
			return false;
		}

		return (bool) call_user_func( array( self::CLS_SIGNALS, 'is_held' ) );
	}

	/**
	 * Whether a Conversions API send could succeed right now.
	 */
	public static function can_send_server_events(): bool {
		foreach ( array( 'host_active', 'event', 'transport', 'pixel_lookup' ) as $key ) {
			if ( empty( self::probes()[ $key ]['ok'] ) ) {
				return false;
			}
		}

		return self::is_connected() && '' !== self::pixel_id() && ! self::connection_invalid();
	}

	/**
	 * Meta for WooCommerce's version string.
	 */
	public static function version(): string {
		if ( defined( 'WC_Facebookcommerce::PLUGIN_VERSION' ) ) {
			return (string) constant( 'WC_Facebookcommerce::PLUGIN_VERSION' );
		}

		return '';
	}

	/* ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------ */

	/**
	 * Convert Field_Mapper identity into Meta's user data keys.
	 *
	 * @param array<string, string> $identity Identity from Field_Mapper.
	 *
	 * @return array<string, string>
	 */
	public static function user_data( array $identity ): array {
		$user_data = array();

		foreach ( self::USER_DATA_KEYS as $ours => $theirs ) {
			if ( isset( $identity[ $ours ] ) && '' !== trim( (string) $identity[ $ours ] ) ) {
				$user_data[ $theirs ] = trim( (string) $identity[ $ours ] );
			}
		}

		return $user_data;
	}

	/**
	 * Send one event to Meta and wait for the answer.
	 *
	 * @param array{event_name: string, event_id: string, event_source_url: string, custom_data: array, user_data: array} $data Event data.
	 * @param string $test_code Events Manager test code, or empty.
	 *
	 * @return array{outcome: string, response: array|null, matched: array<int, string>}
	 */
	public static function send( array $data, string $test_code ): array {
		$result = array(
			'outcome'  => 'rejected',
			'response' => null,
			'matched'  => array(),
		);

		// Meta for WooCommerce holds its own events in this state, and so do
		// we. The browser half still goes to its signal queue, which sends
		// the pair once consent is given.
		if ( self::is_held() ) {
			$result['outcome'] = 'held';

			return $result;
		}

		$event_class = self::CLS_EVENT;
		$event       = new $event_class( $data );

		$result['matched'] = self::matched( $event );

		$add_test_code = static function ( $request_data ) use ( $test_code ) {
			if ( is_array( $request_data ) ) {
				$request_data['test_event_code'] = $test_code;
			}

			return $request_data;
		};

		if ( '' !== $test_code ) {
			add_filter( 'wc_facebook_api_pixel_event_request_data', $add_test_code );
		}

		// A site can make Meta for WooCommerce fire and forget. That is the
		// right call for page views, but here it would throw away the only
		// confirmation there is, on a request that is already waiting on a
		// form submission.
		add_filter( 'wc_facebook_pixel_events_non_blocking', '__return_false', PHP_INT_MAX );

		try {
			$response = facebook_for_woocommerce()->get_api()->send_pixel_events( self::pixel_id(), array( $event ) );
			$decoded  = is_object( $response ) && method_exists( $response, 'to_string' )
				? json_decode( (string) $response->to_string(), true )
				: null;

			$result['response'] = is_array( $decoded ) ? $decoded : null;
			$result['outcome']  = is_array( $decoded ) && ! empty( $decoded['events_received'] ) ? 'accepted' : 'rejected';
		} catch ( \Throwable $e ) {
			$result['response'] = array( 'error' => $e->getMessage() );
		} finally {
			remove_filter( 'wc_facebook_pixel_events_non_blocking', '__return_false', PHP_INT_MAX );
			remove_filter( 'wc_facebook_api_pixel_event_request_data', $add_test_code );
		}

		return $result;
	}

	/**
	 * Which customer details survived validation onto the event.
	 *
	 * @param object $event Meta for WooCommerce Event.
	 *
	 * @return array<int, string>
	 */
	private static function matched( $event ): array {
		$user_data = method_exists( $event, 'get_user_data' ) ? (array) $event->get_user_data() : array();
		$matched   = array();

		foreach ( self::MATCHED_LABELS as $key => $label ) {
			if ( ! empty( $user_data[ $key ] ) ) {
				$matched[] = $label;
			}
		}

		return $matched;
	}

	/**
	 * Build a single probe result row.
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
