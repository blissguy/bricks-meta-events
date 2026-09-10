<?php
/**
 * The diagnostics admin screen.
 *
 * @package BricksMetaEvents
 */

namespace BricksMetaEvents;

defined( 'ABSPATH' ) || exit;

/**
 * Renders health checks and runs the two on-demand probes.
 */
class Health_Screen {

	private const NONCE_ACTION = 'bme_health_action';

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'bricks-meta-events' ) );
		}

		$notice = self::handle_actions();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Bricks Meta Events', 'bricks-meta-events' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'This plugin has no settings of its own. Pixel ID, access token and advanced matching all come from Meta pixel for WordPress.', 'bricks-meta-events' ) . '</p>';

		if ( '' !== $notice ) {
			echo wp_kses_post( $notice );
		}

		self::render_checks();
		self::render_last_event();
		self::render_loopback();
		self::render_test_event();

		echo '</div>';
	}

	/**
	 * Handle the two POST actions on this screen.
	 *
	 * @return string HTML notice to render, or an empty string.
	 */
	private static function handle_actions(): string {
		if ( ! isset( $_POST['bme_action'] ) ) {
			return '';
		}

		check_admin_referer( self::NONCE_ACTION );

		$action = sanitize_key( wp_unslash( $_POST['bme_action'] ) );

		if ( 'loopback' === $action ) {
			$result = Diagnostics::loopback( true );

			return sprintf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				Diagnostics::OK === $result['status'] ? 'success' : 'error',
				esc_html( $result['message'] )
			);
		}

		if ( 'test_event' === $action ) {
			$code = isset( $_POST['bme_test_event_code'] )
				? sanitize_text_field( wp_unslash( $_POST['bme_test_event_code'] ) )
				: '';

			return self::send_test_event( $code );
		}

		return '';
	}

	/**
	 * Render the check table.
	 */
	private static function render_checks(): void {
		echo '<h2>' . esc_html__( 'Diagnostics', 'bricks-meta-events' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';

		foreach ( Diagnostics::run() as $check ) {
			printf(
				'<tr><td style="width:1%%;padding-right:0">%s</td><td style="width:22%%"><strong>%s</strong></td><td>%s%s</td></tr>',
				wp_kses_post( self::status_icon( $check['status'] ) ),
				esc_html( $check['label'] ),
				esc_html( $check['message'] ),
				'' !== $check['fix']
					? '<br><em style="color:#646970">' . esc_html( $check['fix'] ) . '</em>'
					: ''
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Show the most recent conversion this plugin sent.
	 *
	 * The single most useful thing after "is it configured": did a real
	 * submission actually produce an event, and which identifiers survived
	 * advanced matching.
	 */
	private static function render_last_event(): void {
		echo '<h2>' . esc_html__( 'Last conversion sent', 'bricks-meta-events' ) . '</h2>';

		$last = get_option( Plugin::OPTION_LAST_EVENT );

		if ( ! is_array( $last ) || empty( $last['event'] ) ) {
			echo '<p>' . esc_html__( 'Nothing yet. Submit a tracked Bricks form while logged out to see it here.', 'bricks-meta-events' ) . '</p>';

			return;
		}

		$outcomes = array(
			'accepted'   => array( Diagnostics::OK, __( 'Accepted by Meta', 'bricks-meta-events' ) ),
			'rejected'   => array( Diagnostics::ERROR, __( 'Rejected by Meta', 'bricks-meta-events' ) ),
			'held'       => array( Diagnostics::WARNING, __( 'Held pending consent, not sent', 'bricks-meta-events' ) ),
			'handed_off' => array( Diagnostics::INFO, __( 'Handed to background delivery, outcome unknown', 'bricks-meta-events' ) ),
		);

		[ $status, $outcome_label ] = $outcomes[ $last['outcome'] ?? 'handed_off' ] ?? $outcomes['handed_off'];

		$rows = array(
			__( 'Event', 'bricks-meta-events' )    => $last['event'],
			__( 'Name in Events Manager', 'bricks-meta-events' ) => $last['label'] ?? '',
			__( 'Outcome', 'bricks-meta-events' )  => $outcome_label,
			__( 'When', 'bricks-meta-events' )     => sprintf(
				/* translators: %s: human-readable time difference. */
				__( '%s ago', 'bricks-meta-events' ),
				human_time_diff( (int) $last['time'] )
			),
			__( 'Event ID', 'bricks-meta-events' ) => $last['event_id'],
			__( 'Source URL', 'bricks-meta-events' ) => $last['source'],
			__( 'Delivery', 'bricks-meta-events' ) => 'inline' === ( $last['mode'] ?? '' )
				? __( 'Inline (loopback unavailable)', 'bricks-meta-events' )
				: __( 'Background', 'bricks-meta-events' ),
			__( 'Identity sent', 'bricks-meta-events' ) => ! empty( $last['matched'] )
				? implode( ', ', (array) $last['matched'] )
				: __( 'None. Every identifier was stripped, so Meta cannot attribute this conversion.', 'bricks-meta-events' ),
		);

		printf( '<p>%s <strong>%s</strong></p>', wp_kses_post( self::status_icon( $status ) ), esc_html( $outcome_label ) );
		echo '<table class="widefat striped"><tbody>';

		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><td style="width:22%%"><strong>%s</strong></td><td>%s</td></tr>',
				esc_html( $label ),
				esc_html( (string) $value )
			);
		}

		echo '</tbody></table>';

		if ( ! empty( $last['response'] ) ) {
			printf(
				'<pre style="white-space:pre-wrap;max-height:14em;overflow:auto">%s</pre>',
				esc_html( wp_json_encode( $last['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) )
			);
		}
	}

	/**
	 * Render the loopback probe block.
	 */
	private static function render_loopback(): void {
		$result = Diagnostics::loopback();

		echo '<h2>' . esc_html__( 'Loopback delivery', 'bricks-meta-events' ) . '</h2>';
		echo '<p>' . esc_html__( 'Conversions API events are delivered through a non-blocking request from WordPress to its own admin-ajax.php. Security plugins routinely block this, and when they do the events disappear with no error.', 'bricks-meta-events' ) . '</p>';

		printf(
			'<p>%s %s</p>',
			wp_kses_post( self::status_icon( $result['status'] ) ),
			esc_html( $result['message'] )
		);

		if ( Diagnostics::ERROR === $result['status'] ) {
			printf(
				'<p><em>%s</em></p>',
				esc_html__( 'Because of this, conversion events are being sent inline during form submission rather than in the background. Nothing is lost, but submitting a form costs one extra round trip to Meta. Fixing the loopback restores background delivery automatically.', 'bricks-meta-events' )
			);
		}

		self::form( 'loopback', __( 'Re-test loopback', 'bricks-meta-events' ) );
	}

	/**
	 * Render the synchronous test-event block.
	 */
	private static function render_test_event(): void {
		echo '<h2>' . esc_html__( 'Send a test event', 'bricks-meta-events' ) . '</h2>';
		echo '<p>' . esc_html__( 'Sends a Lead event to the Conversions API synchronously and prints the raw response from Meta. Unlike normal delivery this bypasses the background loopback, so it verifies your access token and shows exactly which identity fields survived advanced matching.', 'bricks-meta-events' ) . '</p>';
		echo '<p>' . esc_html__( 'Paste a test event code from Events Manager → Test Events to have it appear there rather than in your live data.', 'bricks-meta-events' ) . '</p>';

		if ( ! Host_Adapter::can_send_server_events() ) {
			echo '<p><em>' . esc_html__( 'Unavailable: the host plugin compatibility check above is failing.', 'bricks-meta-events' ) . '</em></p>';

			return;
		}

		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="bme_action" value="test_event">';
		printf(
			'<input type="text" name="bme_test_event_code" class="regular-text" placeholder="%s"> ',
			esc_attr__( 'TEST12345 (optional)', 'bricks-meta-events' )
		);
		submit_button( __( 'Send test event', 'bricks-meta-events' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Build and synchronously send a test Lead event.
	 *
	 * Deliberately routed through the host plugin's own factory so the test
	 * exercises the same advanced-matching pipeline a real submission would.
	 *
	 * @param string $test_event_code Optional Events Manager test code.
	 *
	 * @return string HTML notice.
	 */
	private static function send_test_event( string $test_event_code ): string {
		try {
			$event = call_user_func(
				array( Host_Adapter::CLS_EVENT_FACTORY, 'safe_create_event' ),
				'Lead',
				static function () {
					return array(
						'email'        => 'test@example.com',
						'first_name'   => 'Test',
						'last_name'    => 'Visitor',
						'content_name' => 'Bricks Meta Events diagnostic',
					);
				},
				array(),
				'bricks-meta-events',
				false
			);

			$response = call_user_func(
				array( Host_Adapter::CLS_SERVER_EVENT, 'send' ),
				array( $event ),
				'' !== $test_event_code ? $test_event_code : null
			);
		} catch ( \Throwable $e ) {
			return sprintf(
				'<div class="notice notice-error"><p><strong>%s</strong></p><pre>%s</pre></div>',
				esc_html__( 'The test event threw an exception:', 'bricks-meta-events' ),
				esc_html( $e->getMessage() )
			);
		}

		// send() returns null when the host plugin is holding events for consent.
		if ( null === $response ) {
			return sprintf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'The host plugin queued this event instead of sending it, which means signals are currently held pending consent. Nothing was sent to Meta.', 'bricks-meta-events' )
			);
		}

		$matched = self::describe_matched_fields( $event );
		$success = is_array( $response ) && ! empty( $response['success'] );

		return sprintf(
			'<div class="notice notice-%s"><p><strong>%s</strong></p><p>%s</p><pre style="white-space:pre-wrap;max-height:20em;overflow:auto">%s</pre></div>',
			$success ? 'success' : 'error',
			esc_html(
				$success
					? __( 'Meta accepted the test event.', 'bricks-meta-events' )
					: __( 'Meta rejected the test event.', 'bricks-meta-events' )
			),
			esc_html( $matched ),
			esc_html( wp_json_encode( $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) )
		);
	}

	/**
	 * Report which hashed identity fields actually made it onto the event.
	 *
	 * This is the point of the test: advanced matching can strip every one of
	 * them, and the API response looks identical either way.
	 *
	 * @param object $event Host plugin Event object.
	 */
	private static function describe_matched_fields( $event ): string {
		if ( ! is_object( $event ) || ! method_exists( $event, 'getUserData' ) ) {
			return '';
		}

		$user_data = $event->getUserData();

		if ( ! is_object( $user_data ) ) {
			return '';
		}

		$present = array();

		foreach ( array( 'Email' => 'email', 'Phone' => 'phone', 'FirstName' => 'first name', 'LastName' => 'last name' ) as $getter => $label ) {
			$method = 'get' . $getter;

			if ( method_exists( $user_data, $method ) && ! empty( $user_data->$method() ) ) {
				$present[] = $label;
			}
		}

		if ( empty( $present ) ) {
			return __( 'No identity fields survived: every hashed identifier was stripped before sending. Meta received this conversion with nothing to attribute it to. See the advanced matching check above.', 'bricks-meta-events' );
		}

		return sprintf(
			/* translators: %s: comma-separated field names. */
			__( 'Identity fields sent (hashed): %s.', 'bricks-meta-events' ),
			implode( ', ', $present )
		);
	}

	/**
	 * Render a one-button POST form.
	 */
	private static function form( string $action, string $label ): void {
		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION );
		printf( '<input type="hidden" name="bme_action" value="%s">', esc_attr( $action ) );
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * A coloured status dot.
	 */
	private static function status_icon( string $status ): string {
		$colors = array(
			Diagnostics::OK      => '#00a32a',
			Diagnostics::WARNING => '#dba617',
			Diagnostics::ERROR   => '#d63638',
			Diagnostics::INFO    => '#787c82',
		);

		$color = $colors[ $status ] ?? $colors[ Diagnostics::INFO ];

		return '<span aria-hidden="true" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . esc_attr( $color ) . '"></span>'
			. '<span class="screen-reader-text">' . esc_html( $status ) . '</span>';
	}
}
