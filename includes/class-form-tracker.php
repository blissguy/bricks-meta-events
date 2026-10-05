<?php
/**
 * Sends conversion events when a Bricks form submits successfully.
 *
 * Hooks bricks/form/response rather than bricks/form/submit: submit fires
 * before validation, spam checks and the nonce check, and fires a second time
 * when Bricks regenerates an expired nonce and resubmits. Only the response
 * filter corresponds to a real conversion.
 *
 * Both the Conversions API event and its browser counterpart are built from
 * one Event object so they share an event_id, which is what lets Meta
 * deduplicate the pair instead of counting two conversions.
 *
 * @package BricksMetaEvents
 */

namespace BricksMetaEvents;

defined( 'ABSPATH' ) || exit;

/**
 * Form conversion tracking, server side and browser side.
 */
class Form_Tracker {

	public const MODE_AUTO    = 'auto';
	public const MODE_BOTH    = 'both';
	public const MODE_SERVER  = 'server';
	public const MODE_BROWSER = 'browser';

	/**
	 * Events built during this request, keyed by form element ID.
	 *
	 * @var array<string, object>
	 */
	private static array $sent = array();

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_filter( 'bricks/form/response', array( self::class, 'on_response' ), 10, 2 );
		add_action( 'bricks/form/custom_action', array( self::class, 'ensure_response_filter_runs' ), 99 );
		add_filter( 'before_conversions_api_event_sent', array( self::class, 'note_delivery' ), 5 );
	}

	/**
	 * Note that a conversion actually reached the point of being sent.
	 *
	 * Background delivery happens in a second, separate request that this one
	 * cannot see the result of, and the Meta plugin offers no hook for after a
	 * send. This filter, though, runs inside that second request. If it never
	 * fires for a conversion we handed over, the second request never happened
	 * and the conversion was lost, which is the difference between "we do not
	 * know" and "it is broken".
	 *
	 * @param array $events Events about to be sent.
	 *
	 * @return array Unchanged.
	 */
	public static function note_delivery( $events ) {
		if ( ! is_array( $events ) ) {
			return $events;
		}

		foreach ( $events as $event ) {
			if ( is_object( $event ) && method_exists( $event, 'getEventId' ) ) {
				Event_Log::confirm( (string) $event->getEventId() );
			}
		}

		return $events;
	}

	/**
	 * Entry point for the Bricks response filter.
	 *
	 * @param array  $response Bricks AJAX response.
	 * @param object $form     Bricks form object.
	 *
	 * @return array
	 */
	public static function on_response( $response, $form ) {
		if ( ! is_array( $response ) || ! is_object( $form ) ) {
			return $response;
		}

		try {
			return self::handle( $response, $form );
		} catch ( \Throwable $e ) {
			// A tracking failure must never break the form for the visitor.
			self::log( 'Exception while tracking: ' . $e->getMessage() );

			return $response;
		}
	}

	/**
	 * Give a Custom-action-only form a result so it reaches our filter.
	 *
	 * Bricks short-circuits with an early wp_send_json_success() when no action
	 * has recorded a result, which skips bricks/form/response entirely. The
	 * Custom action is the only built-in that records nothing. A bare success
	 * result is inert: finish() only overwrites the message when one is set,
	 * and only sets refreshPage when that key is present.
	 *
	 * Runs inside ob_start()/ob_end_clean(), so it must not produce output.
	 *
	 * @param object $form Bricks form object.
	 */
	public static function ensure_response_filter_runs( $form ): void {
		if ( ! is_object( $form ) || ! method_exists( $form, 'set_result' ) ) {
			return;
		}

		if ( ! self::is_tracked( (array) $form->get_settings() ) ) {
			return;
		}

		$form->set_result( array( 'type' => 'success' ) );
	}

	/**
	 * The event built for a given form during this request, if any.
	 *
	 * @return object|null
	 */
	public static function sent_event( string $form_id ) {
		return self::$sent[ $form_id ] ?? null;
	}

	/* ---------------------------------------------------------------------
	 * Core
	 * ------------------------------------------------------------------ */

	/**
	 * Gate, build, dispatch, and attach the browser payload.
	 *
	 * @param array  $response Bricks AJAX response.
	 * @param object $form     Bricks form object.
	 */
	private static function handle( array $response, $form ): array {
		if ( 'success' !== ( $response['type'] ?? '' ) ) {
			return $response;
		}

		$settings = (array) $form->get_settings();

		if ( ! self::is_tracked( $settings ) ) {
			return $response;
		}

		// The host plugin discards these on its own side, or on Meta for
		// WooCommerce we do. Deciding once, here, keeps the browser echo
		// consistent with the server rather than letting one fire without the
		// other.
		$staff_test = false;

		if ( Host::is_internal_user() ) {
			if ( ! Host::staff_can_test() ) {
				return $response;
			}

			$staff_test = true;
		}

		if ( Settings::current_user_excluded() ) {
			return $response;
		}

		$event_name = Event_Map::resolve( $settings );

		if ( '' === $event_name ) {
			self::log( 'Form ' . $form->get_id() . ' is set to a custom event but has no event name.' );

			return $response;
		}

		$mapper   = new Field_Mapper( $form );
		$identity = $mapper->identity();
		$label    = Label_Resolver::resolve( $form );
		$mode     = $staff_test ? self::MODE_SERVER : self::resolve_mode( $settings, $response );

		foreach ( $mapper->notes() as $note ) {
			self::log( 'Form ' . $form->get_id() . ': ' . $note );
		}

		$custom = self::custom_data( $settings, $label );

		if ( Host::is_woo() ) {
			$event_id = wp_generate_uuid4();

			if ( self::MODE_BROWSER !== $mode && Host::capi_available() ) {
				self::send_via_woo( $event_name, $event_id, $identity, $custom, $form );
			}
		} else {
			$event    = self::build_event( $event_name, array_merge( $identity, $custom ), $form );
			$event_id = is_object( $event ) && method_exists( $event, 'getEventId' )
				? (string) $event->getEventId()
				: wp_generate_uuid4();

			if ( null !== $event && self::MODE_BROWSER !== $mode ) {
				self::dispatch( $event );
				self::$sent[ (string) $form->get_id() ] = $event;
			}
		}

		if ( self::MODE_SERVER !== $mode ) {
			$response['bme_event'] = self::browser_payload( $event_id, $event_name, $custom );
		}

		return $response;
	}

	/**
	 * Send the server half through Meta for WooCommerce.
	 *
	 * It waits for Meta's reply, so unlike the Meta pixel for WordPress route
	 * there is nothing handed off to confirm later: the log gets the answer.
	 *
	 * @param string $event_name Meta event name.
	 * @param string $event_id   Shared with the browser half.
	 * @param array  $identity   Identity from Field_Mapper.
	 * @param array  $custom     Custom data shared with the browser half.
	 * @param object $form       Bricks form object.
	 */
	private static function send_via_woo( string $event_name, string $event_id, array $identity, array $custom, $form ): void {
		$source = self::referrer();

		if ( '' === $source ) {
			self::log( 'No usable referrer on form ' . $form->get_id() . '; event source URL fell back to the site root.' );
			$source = home_url( '/' );
		}

		$custom_data = array_merge(
			$custom,
			array(
				// A stable machine key alongside the human label.
				'bricks_form_id'          => self::base_element_id( (string) $form->get_id() ),
				'fb_integration_tracking' => Plugin::INTEGRATION_NAME,
			)
		);

		$result = Woo_Adapter::send(
			array(
				'event_name'       => $event_name,
				'event_id'         => $event_id,
				'event_source_url' => $source,
				'custom_data'      => $custom_data,
				'user_data'        => Woo_Adapter::user_data( $identity ),
			),
			self::test_event_code()
		);

		Event_Log::record(
			array(
				'time'     => time(),
				'event'    => $event_name,
				'label'    => (string) ( $custom['content_name'] ?? '' ),
				'event_id' => $event_id,
				'source'   => $source,
				'matched'  => $result['matched'],
				'mode'     => 'inline',
				'outcome'  => $result['outcome'],
				'response' => $result['response'],
			)
		);
	}

	/**
	 * Decide where this conversion should be sent.
	 *
	 * On `auto`, two things drive the decision. A form that redirects or
	 * refreshes tears the page down immediately, so the browser event would
	 * usually be cancelled mid-flight — and because the browser pixel cannot
	 * carry advanced matching at all, it holds nothing the server event does
	 * not already have. Sending server-only there loses nothing and removes
	 * the race entirely. Conversely, when the Conversions API is unavailable
	 * the browser is the only route left.
	 *
	 * @param array $settings Bricks element settings.
	 * @param array $response Bricks AJAX response, already carrying redirect keys.
	 */
	private static function resolve_mode( array $settings, array $response ): string {
		$mode = $settings['bmeSendMode'] ?? self::MODE_AUTO;

		if ( in_array( $mode, array( self::MODE_BOTH, self::MODE_SERVER, self::MODE_BROWSER ), true ) ) {
			return $mode;
		}

		if ( ! empty( $response['redirectTo'] ) || ! empty( $response['refreshPage'] ) ) {
			return self::MODE_SERVER;
		}

		if ( ! Host::capi_available() ) {
			return self::MODE_BROWSER;
		}

		return self::MODE_BOTH;
	}

	/**
	 * The non-identity values that describe this conversion.
	 *
	 * These keys are the ones the host plugin's factory actually passes on.
	 * Anything else it computes and then discards, so extra properties have to
	 * be set on the event directly.
	 *
	 * @param array  $settings Bricks element settings.
	 * @param string $label    Resolved content_name.
	 *
	 * @return array<string, mixed>
	 */
	private static function custom_data( array $settings, string $label ): array {
		$custom = array( 'content_name' => $label );

		$value = isset( $settings['bmeValue'] ) ? (float) $settings['bmeValue'] : 0.0;

		// A zero value is dropped by the factory's own empty() guard, so there
		// is nothing to gain by passing it through.
		if ( $value > 0 ) {
			$currency = trim( (string) ( $settings['bmeCurrency'] ?? '' ) );

			$custom['value']    = $value;
			$custom['currency'] = '' !== $currency ? strtoupper( $currency ) : Element_Controls::default_currency();
		}

		return $custom;
	}

	/**
	 * Build the Event, or null when the host plugin cannot be used.
	 *
	 * @param string $event_name Meta event name.
	 * @param array  $payload    Identity plus custom data for the factory.
	 * @param object $form       Bricks form object.
	 *
	 * @return object|null
	 */
	private static function build_event( string $event_name, array $payload, $form ) {
		if ( ! Host_Adapter::can_send_server_events() ) {
			self::log( 'Host plugin compatibility probes failing; browser-only fallback.' );

			return null;
		}

		$event = call_user_func(
			array( Host_Adapter::CLS_EVENT_FACTORY, 'safe_create_event' ),
			$event_name,
			static fn() => $payload,
			array(),
			Plugin::INTEGRATION_NAME,
			false
		);

		if ( ! is_object( $event ) ) {
			self::log( 'Event factory returned nothing for form ' . $form->get_id() . '.' );

			return null;
		}

		self::decorate( $event, $form );

		return $event;
	}

	/**
	 * The payload the browser needs to fire the matching pixel event.
	 *
	 * The event name and method come from the server so the client can never
	 * disagree with what was sent server-side: divergent names would produce
	 * two conversions rather than one deduplicated pair.
	 *
	 * @param string $event_id   Shared with the server event.
	 * @param string $event_name Meta event name.
	 * @param array  $custom     Custom data shared with the server event.
	 *
	 * @return array<string, mixed>
	 */
	private static function browser_payload( string $event_id, string $event_name, array $custom ): array {
		return array(
			'name'     => $event_name,
			'event_id' => $event_id,
			// trackCustom is required for anything outside Meta's standard
			// list; hardcoding 'track' would silently drop custom events.
			'method'   => Event_Map::is_standard( $event_name ) ? 'track' : 'trackCustom',
			'custom'   => array_filter(
				array_merge(
					$custom,
					array( 'fb_integration_tracking' => Plugin::INTEGRATION_NAME )
				)
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Delivery
	 * ------------------------------------------------------------------ */

	/**
	 * Hand the event to the host plugin for delivery.
	 *
	 * Normally that means track(), which queues a non-blocking loopback
	 * request to admin-ajax.php and returns immediately. When the loopback is
	 * blocked — a firewall, or an environment that cannot reach itself — that
	 * request never completes and the event is lost with no error anywhere. In
	 * that case send synchronously instead: it costs the visitor one round
	 * trip to Meta, which is a great deal better than silently discarding
	 * every conversion.
	 *
	 * @param object $event Host plugin Event object.
	 */
	private static function dispatch( $event ): void {
		if ( self::should_send_synchronously() ) {
			$test_code = self::test_event_code();
			$result    = call_user_func(
				array( Host_Adapter::CLS_SERVER_EVENT, 'send' ),
				array( $event ),
				'' !== $test_code ? $test_code : null
			);

			self::record( $event, 'inline', $result );

			return;
		}

		call_user_func( array( Host_Adapter::CLS_SERVER_EVENT, 'get_instance' ) )->track( $event );

		// The background path is fire-and-forget, so "handed off" is the
		// strongest claim that can honestly be made about it.
		self::record( $event, 'background', null );
	}

	/**
	 * Whether to bypass the background loopback and send inline.
	 *
	 * Reads only the cached loopback verdict — probing here would add a ten
	 * second timeout to a form submission. With nothing cached, assume the
	 * normal asynchronous path.
	 */
	private static function should_send_synchronously(): bool {
		if ( ! method_exists( Host_Adapter::CLS_SERVER_EVENT, 'send' ) ) {
			return false;
		}

		// A test code only reaches Meta if this plugin makes the call itself.
		// The background route sends without one, so anything sent that way
		// would land in live data and never show in Test Events.
		if ( '' !== self::test_event_code() ) {
			return true;
		}

		$broken = Diagnostics::ERROR === Diagnostics::cached_loopback_status()
			|| self::background_sending_is_broken();

		/**
		 * Filters whether Conversions API events are sent inline.
		 *
		 * @param bool $synchronous True to send during the request.
		 */
		return (bool) apply_filters( 'bme_send_synchronously', $broken );
	}

	/**
	 * Whether background sending has been proven not to work here.
	 *
	 * A conversion handed over for background sending that never reported in
	 * was lost. Rather than lose one on every submission, the finding is
	 * remembered and sending switches to inline until the background check
	 * passes again, which clears it.
	 */
	private static function background_sending_is_broken(): bool {
		if ( get_option( Plugin::OPTION_BACKGROUND_BROKEN ) ) {
			return true;
		}

		$last = Event_Log::latest();

		if ( ! is_array( $last ) || 'handed_off' !== ( $last['outcome'] ?? '' ) ) {
			return false;
		}

		if ( ! empty( $last['delivered_at'] ) ) {
			return false;
		}

		if ( time() - (int) ( $last['time'] ?? 0 ) <= 2 * MINUTE_IN_SECONDS ) {
			return false;
		}

		update_option( Plugin::OPTION_BACKGROUND_BROKEN, time(), false );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Observability
	 * ------------------------------------------------------------------ */

	/**
	 * Remember what was last sent, so the health screen can show reality.
	 *
	 * Sending is otherwise entirely invisible: nothing is written anywhere,
	 * and a conversion that never arrives looks exactly like one that did.
	 *
	 * @param object     $event  Host plugin Event object.
	 * @param string     $mode   'inline' or 'background'.
	 * @param array|null $result Graph API result, when there is one.
	 */
	private static function record( $event, string $mode, $result ): void {
		$user_data = method_exists( $event, 'getUserData' ) ? $event->getUserData() : null;
		$matched   = array();

		if ( is_object( $user_data ) ) {
			$fields = array(
				'Email'      => 'email',
				'Phone'      => 'phone',
				'FirstName'  => 'first name',
				'LastName'   => 'last name',
				'ExternalId' => 'account id',
			);

			foreach ( $fields as $getter => $label ) {
				$method = 'get' . $getter;

				if ( method_exists( $user_data, $method ) && ! empty( $user_data->$method() ) ) {
					$matched[] = $label;
				}
			}
		}

		$outcome = 'handed_off';

		if ( is_array( $result ) ) {
			$outcome = ! empty( $result['success'] ) ? 'accepted' : 'rejected';
		} elseif ( 'inline' === $mode ) {
			// send() returns null when the host plugin is holding for consent.
			$outcome = 'held';
		}

		Event_Log::record(
			array(
				'time'     => time(),
				'event'    => method_exists( $event, 'getEventName' ) ? $event->getEventName() : '',
				'label'    => self::content_name( $event ),
				'event_id' => method_exists( $event, 'getEventId' ) ? $event->getEventId() : '',
				'source'   => method_exists( $event, 'getEventSourceUrl' ) ? $event->getEventSourceUrl() : '',
				'matched'  => $matched,
				'mode'     => $mode,
				'outcome'  => $outcome,
				'response' => is_array( $result ) ? $result : null,
			)
		);
	}

	/**
	 * The Events Manager test code, while test mode is on.
	 *
	 * Empty when test mode is off, even if a code is saved. Everything sent
	 * while this returns a code goes to Test Events instead of the real
	 * figures, so every caller that decides where a conversion goes reads
	 * this, never the saved code directly.
	 */
	public static function test_event_code(): string {
		return self::test_mode_on() ? self::saved_test_code() : '';
	}

	/**
	 * The saved test code, whether or not test mode is on.
	 *
	 * Kept when test mode is switched off, so turning it back on does not
	 * mean another trip to Events Manager to copy the same code.
	 */
	public static function saved_test_code(): string {
		return trim( (string) get_option( Plugin::OPTION_TEST_CODE, '' ) );
	}

	/**
	 * Whether test mode is switched on and has a code to send with.
	 *
	 * Before 0.9.0 there was no switch: a saved code was test mode. A site
	 * upgrading with a code saved and no switch stored yet therefore stays in
	 * test mode until someone turns it off, rather than silently starting to
	 * count its test submissions as real.
	 */
	public static function test_mode_on(): bool {
		$switch = get_option( Plugin::OPTION_TEST_MODE, null );

		if ( null === $switch ) {
			return '' !== self::saved_test_code();
		}

		return '1' === (string) $switch && '' !== self::saved_test_code();
	}

	/**
	 * The resolved label as it will appear in Events Manager.
	 *
	 * @param object $event Host plugin Event object.
	 */
	private static function content_name( $event ): string {
		if ( ! method_exists( $event, 'getCustomData' ) ) {
			return '';
		}

		$custom = $event->getCustomData();

		if ( ! is_object( $custom ) || ! method_exists( $custom, 'getContentName' ) ) {
			return '';
		}

		return (string) $custom->getContentName();
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Add the things safe_create_event() cannot carry for us.
	 *
	 * Its custom-data handling is a fixed allowlist — currency, value,
	 * contents, content_ids, content_type, num_items, content_name and
	 * content_category. Any other key returned from the callback is computed
	 * and then discarded, so extra properties have to be set directly.
	 *
	 * @param object $event Host plugin Event object.
	 * @param object $form  Bricks form object.
	 */
	private static function decorate( $event, $form ): void {
		// A stable machine key alongside the human label, which will get
		// renamed sooner or later.
		if ( method_exists( $event, 'getCustomData' ) ) {
			$custom = $event->getCustomData();

			if ( is_object( $custom ) && method_exists( $custom, 'addCustomProperty' ) ) {
				$custom->addCustomProperty( 'bricks_form_id', self::base_element_id( (string) $form->get_id() ) );
			}
		}

		// Left alone, the source URL is admin-ajax.php — genuinely the URL
		// being requested, and useless to Meta for attribution. Bricks posts
		// the real page URL alongside the form data.
		if ( ! method_exists( $event, 'setEventSourceUrl' ) ) {
			return;
		}

		$referrer = self::referrer();

		if ( '' === $referrer ) {
			self::log( 'No usable referrer on form ' . $form->get_id() . '; event source URL fell back to the site root.' );
			$referrer = home_url( '/' );
		}

		$event->setEventSourceUrl( $referrer );
	}

	/**
	 * The submitting page's URL, if it belongs to this site.
	 *
	 * @return string Empty when absent or off-site.
	 */
	private static function referrer(): string {
		if ( empty( $_POST['referrer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Bricks verifies the form nonce before this filter runs.
			return '';
		}

		$referrer = esc_url_raw( wp_unslash( $_POST['referrer'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$host     = wp_parse_url( $referrer, PHP_URL_HOST );
		$expected = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! $host || ! $expected || 0 !== strcasecmp( $host, $expected ) ) {
			return '';
		}

		return $referrer;
	}

	/**
	 * Strip the component-instance suffix from an element ID.
	 *
	 * Inside a component, get_id() returns {elementId}-{instanceId}. The base
	 * ID is what identifies the form across all of its instances.
	 */
	private static function base_element_id( string $id ): string {
		$dash = strpos( $id, '-' );

		return false === $dash ? $id : substr( $id, 0, $dash );
	}

	/**
	 * Whether tracking is switched on for this form.
	 *
	 * @param array $settings Bricks element settings.
	 */
	private static function is_tracked( array $settings ): bool {
		return ! empty( $settings['bmeEnabled'] );
	}

	/**
	 * Record a note for support and, when debugging, the error log.
	 */
	private static function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Bricks Meta Events] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
