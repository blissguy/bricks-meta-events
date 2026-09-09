<?php
/**
 * Sends a Conversions API event when a Bricks form submits successfully.
 *
 * Hooks bricks/form/response rather than bricks/form/submit: submit fires
 * before validation, spam checks and the nonce check, and fires a second time
 * when Bricks regenerates an expired nonce and resubmits. Only the response
 * filter corresponds to a real conversion.
 *
 * @package BricksMetaEvents
 */

namespace BricksMetaEvents;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side form conversion tracking.
 */
class Form_Tracker {

	/**
	 * Events built during this request, keyed by form element ID.
	 *
	 * Kept so the browser echo can reuse the same event_id rather than
	 * minting its own, which would defeat deduplication.
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
	}

	/**
	 * Build and send the server event, then hand the response back untouched.
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
			self::track( $response, $form );
		} catch ( \Throwable $e ) {
			// A tracking failure must never break the form for the visitor.
			self::log( 'Exception while tracking: ' . $e->getMessage() );
		}

		return $response;
	}

	/**
	 * Give a Custom-action-only form a result so it reaches our filter.
	 *
	 * Bricks short-circuits with an early wp_send_json_success() when no
	 * action has recorded a result, which skips bricks/form/response entirely.
	 * The Custom action is the only built-in that records nothing, so a form
	 * whose sole action is Custom would never be tracked. A bare success
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
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Gate, build and dispatch.
	 *
	 * @param array  $response Bricks AJAX response.
	 * @param object $form     Bricks form object.
	 */
	private static function track( array $response, $form ): void {
		if ( 'success' !== ( $response['type'] ?? '' ) ) {
			return;
		}

		$settings = (array) $form->get_settings();

		if ( ! self::is_tracked( $settings ) ) {
			return;
		}

		// The host plugin drops these events on its own side anyway. Deciding
		// here, once, keeps the browser echo consistent with the server.
		if ( Host_Adapter::is_internal_user() ) {
			return;
		}

		if ( ! Host_Adapter::can_send_server_events() ) {
			self::log( 'Host plugin compatibility probes failing; no server event sent.' );

			return;
		}

		$event_name = Event_Map::resolve( $settings );

		if ( '' === $event_name ) {
			self::log( 'Form ' . $form->get_id() . ' is set to a custom event but has no event name.' );

			return;
		}

		$mapper   = new Field_Mapper( $form );
		$identity = $mapper->identity();
		$label    = Label_Resolver::resolve( $form );

		foreach ( $mapper->notes() as $note ) {
			self::log( 'Form ' . $form->get_id() . ': ' . $note );
		}

		$event = call_user_func(
			array( Host_Adapter::CLS_EVENT_FACTORY, 'safe_create_event' ),
			$event_name,
			static fn() => array_merge( $identity, array( 'content_name' => $label ) ),
			array(),
			Plugin::INTEGRATION_NAME,
			false
		);

		if ( ! is_object( $event ) ) {
			self::log( 'Event factory returned nothing for form ' . $form->get_id() . '.' );

			return;
		}

		self::decorate( $event, $form );
		self::dispatch( $event );

		self::$sent[ (string) $form->get_id() ] = $event;
	}

	/**
	 * Hand the event to the host plugin for delivery.
	 *
	 * Normally that means track(), which queues a non-blocking loopback
	 * request to admin-ajax.php and returns immediately. When the loopback is
	 * blocked — a firewall, or a local environment that cannot reach itself —
	 * that request never completes and the event is lost with no error
	 * anywhere. In that case send synchronously instead: it costs the visitor
	 * one round trip to Meta on submit, which is a great deal better than
	 * silently discarding every conversion.
	 *
	 * @param object $event Host plugin Event object.
	 */
	private static function dispatch( $event ): void {
		if ( self::should_send_synchronously() ) {
			$result = call_user_func( array( Host_Adapter::CLS_SERVER_EVENT, 'send' ), array( $event ) );

			self::record( $event, 'inline', $result );

			return;
		}

		call_user_func( array( Host_Adapter::CLS_SERVER_EVENT, 'get_instance' ) )->track( $event );

		// The background path is fire-and-forget, so "handed off" is the
		// strongest claim that can honestly be made about it.
		self::record( $event, 'background', null );
	}

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
			foreach ( array( 'Email' => 'email', 'Phone' => 'phone', 'FirstName' => 'first name', 'LastName' => 'last name', 'ExternalId' => 'account id' ) as $getter => $label ) {
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

		update_option(
			Plugin::OPTION_LAST_EVENT,
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
			),
			false
		);
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

		$broken = Diagnostics::ERROR === Diagnostics::cached_loopback_status();

		/**
		 * Filters whether Conversions API events are sent inline.
		 *
		 * @param bool $synchronous True to send during the request.
		 */
		return (bool) apply_filters( 'bme_send_synchronously', $broken );
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

	/**
	 * Add the things safe_create_event() cannot carry for us.
	 *
	 * Its custom-data handling is a fixed allowlist — currency, value,
	 * contents, content_ids, content_type, num_items, content_name and
	 * content_category. Any other key we return from the callback is computed
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
		// the real page URL alongside the form data, so prefer that. When it
		// is missing or points off-site, fall back to the site root rather
		// than leaving an endpoint URL (or, in non-web contexts, a malformed
		// one) on the event.
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
	 * Record a note for the health screen and, when debugging, the error log.
	 */
	private static function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Bricks Meta Events] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
