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
	 * The screen's tabs, in order. The first is the default.
	 *
	 * @return array<string, string>
	 */
	private static function tabs(): array {
		return array(
			'status'      => __( 'Status', 'bricks-meta-events' ),
			'conversions' => __( 'Conversions', 'bricks-meta-events' ),
			'settings'    => __( 'Settings', 'bricks-meta-events' ),
			'testing'     => __( 'Testing', 'bricks-meta-events' ),
		);
	}

	/**
	 * The tab being viewed.
	 */
	private static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return isset( self::tabs()[ $tab ] ) ? $tab : (string) array_key_first( self::tabs() );
	}

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'bricks-meta-events' ) );
		}

		$notice = self::handle_actions();

		$tab = self::current_tab();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Bricks Meta Events', 'bricks-meta-events' ) . '</h1>';

		if ( '' !== Host::current() ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: host plugin name. */
					__( 'Pixel, connection and matching settings come from %s.', 'bricks-meta-events' ),
					Host::name()
				)
			) . '</p>';
		}

		echo '<nav class="nav-tab-wrapper" style="margin-bottom:1em">';

		foreach ( self::tabs() as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s"%s>%s</a>',
				esc_url( add_query_arg( 'tab', $slug, admin_url( 'options-general.php?page=bricks-meta-events' ) ) ),
				$slug === $tab ? ' nav-tab-active' : '',
				$slug === $tab ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';

		if ( '' !== $notice ) {
			echo wp_kses_post( $notice );
		}

		// Test mode quietly diverts real conversions, so it is announced on
		// every tab rather than only where it is switched on.
		if ( '' !== Form_Tracker::test_event_code() && 'testing' !== $tab ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'Test mode is on. Form conversions go to Test Events, not your real figures.', 'bricks-meta-events' )
			);
		}

		// Forms post back to the current URL, tab included, so every action
		// returns to the tab it was taken on.
		switch ( $tab ) {
			case 'conversions':
				self::render_last_event();
				self::render_log();
				break;

			case 'settings':
				self::render_settings();
				break;

			case 'testing':
				self::render_test_mode();
				self::render_test_event();
				break;

			default:
				self::render_checks();
				self::render_loopback();
		}

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

		if ( 'save_settings' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() ran above.
			Settings::save( wp_unslash( $_POST ) );

			return '<div class="notice notice-success"><p>'
				. esc_html__( 'Saved.', 'bricks-meta-events' ) . '</p></div>';
		}

		if ( 'save_test_code' === $action ) {
			$code = isset( $_POST['bme_test_code'] )
				? sanitize_text_field( wp_unslash( $_POST['bme_test_code'] ) )
				: '';
			$on   = ! empty( $_POST['bme_test_mode'] );

			if ( '' === $code ) {
				delete_option( Plugin::OPTION_TEST_CODE );
			} else {
				update_option( Plugin::OPTION_TEST_CODE, $code, false );
			}

			// Test mode without a code would send nothing to Test Events and
			// everything to the real figures, which is the opposite of what
			// ticking the box asked for.
			if ( $on && '' === $code ) {
				update_option( Plugin::OPTION_TEST_MODE, '0', false );

				return '<div class="notice notice-error"><p>'
					. esc_html__( 'Add a test code to turn on test mode. Test mode is still off.', 'bricks-meta-events' )
					. '</p></div>';
			}

			update_option( Plugin::OPTION_TEST_MODE, $on ? '1' : '0', false );

			if ( ! $on ) {
				return '<div class="notice notice-success"><p>'
					. esc_html__( 'Test mode is off. Form conversions count towards your real figures again.', 'bricks-meta-events' )
					. '</p></div>';
			}

			return '<div class="notice notice-warning"><p>'
				. esc_html__( 'Test mode is on. Form conversions go to Test Events, not your real figures.', 'bricks-meta-events' )
				. '</p></div>';
		}

		if ( 'clear_log' === $action ) {
			Event_Log::clear();

			return '<div class="notice notice-success"><p>'
				. esc_html__( 'Cleared.', 'bricks-meta-events' ) . '</p></div>';
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
	 * Render the site-wide settings.
	 *
	 * Everything here can be overridden on an individual form. It exists so a
	 * site with thirty forms does not need the same decision thirty times.
	 */
	private static function render_settings(): void {
		$settings = Settings::all();

		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="bme_action" value="save_settings">';
		echo '<table class="form-table" role="presentation"><tbody>';

		// Default outcome for newly tracked forms.
		echo '<tr><th scope="row"><label for="bme-default-intent">'
			. esc_html__( 'Default form event', 'bricks-meta-events' ) . '</label></th><td>';
		echo '<select name="default_intent" id="bme-default-intent">';

		foreach ( Event_Map::options() as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $value, Settings::default_intent(), false ),
				esc_html( $label )
			);
		}

		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Newly tracked forms start on this. You can change it on any form.', 'bricks-meta-events' ) . '</p>';
		echo '</td></tr>';

		// Currency used when a form has a value but no currency.
		printf(
			'<tr><th scope="row"><label for="bme-currency">%s</label></th><td>'
			. '<input type="text" name="currency" id="bme-currency" class="small-text" value="%s" placeholder="%s">'
			. '<p class="description">%s</p></td></tr>',
			esc_html__( 'Default currency', 'bricks-meta-events' ),
			esc_attr( (string) $settings['currency'] ),
			esc_attr( Settings::default_currency() ),
			esc_html__( 'Used when a form has a value but no currency. Leave blank to use your store currency.', 'bricks-meta-events' )
		);

		// Roles our own setting can actually affect.
		$selectable = self::selectable_roles();

		echo '<tr><th scope="row">' . esc_html__( 'Excluded roles', 'bricks-meta-events' ) . '</th><td>';

		if ( empty( $selectable ) ) {
			echo '<p class="description">' . esc_html__( 'Every role on this site is already excluded as staff.', 'bricks-meta-events' ) . '</p>';
		} else {
			foreach ( $selectable as $slug => $name ) {
				printf(
					'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="excluded_roles[]" value="%s"%s> %s</label>',
					esc_attr( $slug ),
					checked( in_array( $slug, Settings::excluded_roles(), true ), true, false ),
					esc_html( $name )
				);
			}

			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: who counts as staff, e.g. "can manage WooCommerce". */
					__( 'Signed-in visitors with these roles aren\'t tracked, for example existing customers. Anyone who %s is always excluded.', 'bricks-meta-events' ),
					Host::staff_rule()
				)
			) . '</p>';
		}

		echo '</td></tr>';

		// The blanket privacy switch. Styled as a warning because switching it
		// on quietly makes every ad result look worse.
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="never_send_details" value="1"%s> %s</label>'
			. '<p class="description"><span class="dashicons dashicons-warning" style="color:#dba617;font-size:16px;width:16px;height:16px;vertical-align:text-bottom" aria-hidden="true"></span> %s</p></td></tr>',
			esc_html__( 'Customer details', 'bricks-meta-events' ),
			checked( Settings::never_send_details(), true, false ),
			esc_html__( 'Never send email, phone, name or account', 'bricks-meta-events' ),
			esc_html__( 'Meta still counts each conversion but can\'t tell who it was, so ad results look worse. Turn on only if you have to.', 'bricks-meta-events' )
		);

		// Clicks, unlike form submissions, cannot be verified.
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="track_links" value="1"%s> %s</label>'
			. '<p class="description">%s</p></td></tr>',
			esc_html__( 'Phone and email links', 'bricks-meta-events' ),
			checked( Settings::track_links(), true, false ),
			esc_html__( 'Count a click as Contact', 'bricks-meta-events' ),
			esc_html__( 'Clicks are less reliable than form submissions and carry no customer details.', 'bricks-meta-events' )
		);

		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'bricks-meta-events' ), 'primary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Roles this plugin's exclusion setting can actually change.
	 *
	 * Staff are already skipped, by Meta pixel for WordPress itself or by our
	 * own rule on Meta for WooCommerce, so offering a checkbox for them would
	 * be a control that does nothing.
	 *
	 * @return array<string, string>
	 */
	private static function selectable_roles(): array {
		$roles = array();

		foreach ( wp_roles()->roles as $slug => $role ) {
			$caps = isset( $role['capabilities'] ) ? (array) $role['capabilities'] : array();

			if ( Host::role_is_staff( $caps ) ) {
				continue;
			}

			$roles[ $slug ] = ! empty( $role['name'] ) ? $role['name'] : $slug;
		}

		return $roles;
	}

	/**
	 * Render the check table.
	 */
	private static function render_checks(): void {
		echo '<table class="widefat striped"><tbody>';

		foreach ( Diagnostics::run() as $check ) {
			// The pixel ID is something people copy and compare against Events
			// Manager, so it gets the code face rather than prose.
			$message = 'pixel_id' === $check['id'] && Diagnostics::OK === $check['status']
				? '<code>' . esc_html( $check['message'] ) . '</code>'
				: esc_html( $check['message'] );

			printf(
				'<tr><td style="width:1%%;padding-right:0">%s</td><td style="width:22%%"><strong>%s</strong></td><td>%s%s%s</td></tr>',
				wp_kses_post( self::status_icon( $check['status'] ) ),
				esc_html( $check['label'] ),
				'' !== $check['summary'] ? '<strong>' . esc_html( $check['summary'] ) . '</strong> ' : '',
				$message, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
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
		echo '<h2>' . esc_html__( 'Last conversion', 'bricks-meta-events' ) . '</h2>';

		$last = Event_Log::latest();

		if ( ! is_array( $last ) || empty( $last['event'] ) ) {
			echo '<p>' . esc_html__( 'Nothing yet. Submit a tracked form while signed out and it shows up here.', 'bricks-meta-events' ) . '</p>';

			return;
		}

		[ $status, $outcome_label ] = self::resolve_outcome( $last );

		$rows = array(
			__( 'Event', 'bricks-meta-events' )    => $last['event'],
			__( 'Name in Events Manager', 'bricks-meta-events' ) => $last['label'] ?? '',
			__( 'When', 'bricks-meta-events' )     => sprintf(
				/* translators: %s: human-readable time difference. */
				__( '%s ago', 'bricks-meta-events' ),
				human_time_diff( (int) $last['time'] )
			),
			__( 'Event ID', 'bricks-meta-events' ) => $last['event_id'],
			__( 'Page', 'bricks-meta-events' ) => $last['source'],
			__( 'Delivery', 'bricks-meta-events' ) => 'inline' === ( $last['mode'] ?? '' )
				? __( 'While the form was submitting', 'bricks-meta-events' )
				: __( 'In the background', 'bricks-meta-events' ),
			__( 'Customer details sent', 'bricks-meta-events' ) => ! empty( $last['matched'] )
				? implode( ', ', (array) $last['matched'] )
				: __( 'None, so Meta can\'t tell who this was.', 'bricks-meta-events' ),
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

		// On success the result row above already says everything useful. The
		// raw reply is only worth showing when it explains a refusal.
		if ( 'rejected' === ( $last['outcome'] ?? '' ) && ! empty( $last['response'] ) ) {
			echo '<p>' . esc_html__( 'What Meta sent back:', 'bricks-meta-events' ) . '</p>';
			printf(
				'<pre style="white-space:pre-wrap;max-height:14em;overflow:auto">%s</pre>',
				esc_html( wp_json_encode( $last['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) )
			);
		}
	}

	/**
	 * Turn a stored conversion into a status and a plain answer.
	 *
	 * Background sending happens in a separate request, so the conversion is
	 * handed over and then goes quiet. A conversion that was handed over a
	 * while ago and never went out is not an unknown, it is a failure, and
	 * saying so is the whole point of this panel.
	 *
	 * The two OK results deliberately end on "confirmed" and "not confirmed".
	 * Only the inline route reads Meta's own reply, so only it can claim
	 * receipt; the background route can report that the send was attempted
	 * and nothing more. An earlier wording called both confirmed, which made
	 * the weaker one look like the stronger. Do not reinstate it.
	 *
	 * @param array $last Stored conversion record.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function resolve_outcome( array $last ): array {
		$outcome = $last['outcome'] ?? 'handed_off';

		if ( 'accepted' === $outcome ) {
			return array( Diagnostics::OK, __( 'Received by Meta, confirmed', 'bricks-meta-events' ) );
		}

		if ( 'rejected' === $outcome ) {
			return array( Diagnostics::ERROR, __( 'Rejected by Meta', 'bricks-meta-events' ) );
		}

		if ( 'held' === $outcome ) {
			return array( Diagnostics::WARNING, __( 'Waiting for cookie consent, not sent', 'bricks-meta-events' ) );
		}

		if ( ! empty( $last['delivered_at'] ) ) {
			return array( Diagnostics::OK, __( 'Sent in the background, not confirmed by Meta', 'bricks-meta-events' ) );
		}

		// Long enough that a working background send would have reported in.
		if ( time() - (int) ( $last['time'] ?? 0 ) > 2 * MINUTE_IN_SECONDS ) {
			return array(
				Diagnostics::ERROR,
				__( 'Handed over for background sending but it never went out, so Meta did not receive it. Background sending is being blocked on this site.', 'bricks-meta-events' ),
			);
		}

		return array( Diagnostics::INFO, __( 'Just handed over for background sending. Reload in a minute to see whether it went out.', 'bricks-meta-events' ) );
	}

	/**
	 * Render the recent conversions table.
	 *
	 * The most recent one is shown in full above; this is the history behind
	 * it, which is what makes a pattern visible rather than a snapshot.
	 */
	private static function render_log(): void {
		$log = Event_Log::all();

		if ( empty( $log ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Earlier conversions', 'bricks-meta-events' ) . '</h2>';

		if ( count( $log ) < 2 ) {
			echo '<p>' . esc_html__( 'Nothing earlier yet. The last 50 conversions are listed here.', 'bricks-meta-events' ) . '</p>';

			return;
		}
		echo '<table class="widefat striped"><thead><tr>';

		foreach (
			array(
				__( 'When', 'bricks-meta-events' ),
				__( 'Event', 'bricks-meta-events' ),
				__( 'Name in Events Manager', 'bricks-meta-events' ),
				__( 'Result', 'bricks-meta-events' ),
				__( 'Customer details sent', 'bricks-meta-events' ),
			) as $heading
		) {
			printf( '<th>%s</th>', esc_html( $heading ) );
		}

		echo '</tr></thead><tbody>';

		foreach ( array_slice( $log, 1 ) as $entry ) {
			[ $status, $label ] = self::resolve_outcome( $entry );

			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s %s</td><td>%s</td></tr>',
				esc_html(
					sprintf(
						/* translators: %s: human-readable time difference. */
						__( '%s ago', 'bricks-meta-events' ),
						human_time_diff( (int) ( $entry['time'] ?? 0 ) )
					)
				),
				esc_html( (string) ( $entry['event'] ?? '' ) ),
				esc_html( (string) ( $entry['label'] ?? '' ) ),
				wp_kses_post( self::status_icon( $status ) ),
				esc_html( $label ),
				esc_html(
					! empty( $entry['matched'] )
						? implode( ', ', (array) $entry['matched'] )
						: __( 'none', 'bricks-meta-events' )
				)
			);
		}

		echo '</tbody></table>';
		self::form( 'clear_log', __( 'Clear this list', 'bricks-meta-events' ) );
	}

	/**
	 * Render the test mode block.
	 *
	 * Without this, a real form submission always counts towards the live
	 * figures, so there is no way to check a setup without dirtying the data
	 * you report on.
	 */
	private static function render_test_mode(): void {
		echo '<h2>' . esc_html__( 'Test mode', 'bricks-meta-events' ) . '</h2>';

		// Linked straight to this pixel's Test Events tab, which is both where
		// the code is copied from and where the results show up.
		$place = esc_html__( 'Events Manager, Test Events', 'bricks-meta-events' );
		$url   = Host::events_manager_url( true );

		if ( '' !== $url ) {
			$place = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span></a>',
				esc_url( $url ),
				$place,
				esc_html__( '(opens in a new tab)', 'bricks-meta-events' )
			);
		}

		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="bme_action" value="save_test_code">';
		echo '<table class="form-table" role="presentation"><tbody>';

		// The switch. The code below stays saved when it is off.
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="bme_test_mode" value="1"%s> %s</label>'
			. '<p class="description">%s</p></td></tr>',
			esc_html__( 'Test mode', 'bricks-meta-events' ),
			checked( Form_Tracker::test_mode_on(), true, false ),
			esc_html__( 'Send form conversions to Test Events instead of your real figures', 'bricks-meta-events' ),
			esc_html__( 'While it\'s on, your browser console also shows what each button and link sends.', 'bricks-meta-events' )
		);

		printf(
			'<tr><th scope="row"><label for="bme-test-code">%s</label></th><td>'
			. '<input type="text" name="bme_test_code" id="bme-test-code" class="regular-text" value="%s" placeholder="%s">'
			. '<p class="description">%s</p></td></tr>',
			esc_html__( 'Test code', 'bricks-meta-events' ),
			esc_attr( Form_Tracker::saved_test_code() ),
			esc_attr__( 'TEST12345', 'bricks-meta-events' ),
			sprintf(
				/* translators: %s: "Events Manager, Test Events", linked. */
				esc_html__( 'Copy it from %s.', 'bricks-meta-events' ),
				$place // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			)
		);

		echo '</tbody></table>';
		submit_button( __( 'Save', 'bricks-meta-events' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Render the loopback probe block.
	 */
	private static function render_loopback(): void {
		// Meta for WooCommerce sends straight to Meta, never through the
		// site's own address, so there is nothing here to block.
		if ( Host::PIXEL !== Host::current() ) {
			return;
		}

		$result = Diagnostics::loopback();

		echo '<h2>' . esc_html__( 'Background sending', 'bricks-meta-events' ) . '</h2>';
		echo '<p>' . esc_html__( 'Conversions are normally sent by your site calling its own address. Security plugins sometimes block this, and conversions then disappear without warning.', 'bricks-meta-events' ) . '</p>';

		printf(
			'<p>%s %s</p>',
			wp_kses_post( self::status_icon( $result['status'] ) ),
			esc_html( $result['message'] )
		);

		if ( Diagnostics::ERROR === $result['status'] ) {
			printf(
				'<p><em>%s</em></p>',
				esc_html__( 'Conversions are being sent while the form submits instead, so nothing is lost. Background sending resumes on its own once this passes.', 'bricks-meta-events' )
			);
		}

		self::form( 'loopback', __( 'Check again', 'bricks-meta-events' ) );
	}

	/**
	 * Render the synchronous test-event block.
	 */
	private static function render_test_event(): void {
		echo '<h2>' . esc_html__( 'Send a test event', 'bricks-meta-events' ) . '</h2>';
		echo '<p>' . esc_html__( 'Sends a Lead to Meta now and shows Meta\'s reply, including which customer details got through. Add a test code so it lands in Test Events, not your real figures.', 'bricks-meta-events' ) . '</p>';

		if ( ! Host::can_send_server_events() ) {
			echo '<p><em>' . esc_html__( 'Not available until the checks under Status pass.', 'bricks-meta-events' ) . '</em></p>';

			return;
		}

		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="bme_action" value="test_event">';
		// Starts on the test mode code, so a test event lands in the same
		// Test Events session as the form conversions being checked.
		printf(
			'<input type="text" name="bme_test_event_code" class="regular-text" value="%s" placeholder="%s"> ',
			esc_attr( Form_Tracker::saved_test_code() ),
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
		if ( Host::is_woo() ) {
			return self::send_woo_test_event( $test_event_code );
		}

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
				esc_html__( 'The test could not run:', 'bricks-meta-events' ),
				esc_html( $e->getMessage() )
			);
		}

		// send() returns null when the host plugin is holding events for consent.
		if ( null === $response ) {
			return sprintf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Meta pixel for WordPress held this back instead of sending it, because cookie consent has not been given. Nothing reached Meta.', 'bricks-meta-events' )
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
	 * The same test, sent through Meta for WooCommerce.
	 *
	 * Goes through the exact sender a real submission uses, so a pass here
	 * means a form will get through too.
	 *
	 * @param string $test_event_code Optional Events Manager test code.
	 *
	 * @return string HTML notice.
	 */
	private static function send_woo_test_event( string $test_event_code ): string {
		if ( ! Host::can_send_server_events() ) {
			return sprintf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Meta for WooCommerce is not ready to send. See the checks above.', 'bricks-meta-events' )
			);
		}

		$result = Woo_Adapter::send(
			array(
				'event_name'       => 'Lead',
				'event_id'         => wp_generate_uuid4(),
				'event_source_url' => home_url( '/' ),
				'custom_data'      => array(
					'content_name'            => 'Bricks Meta Events diagnostic',
					'fb_integration_tracking' => Plugin::INTEGRATION_NAME,
				),
				'user_data'        => Woo_Adapter::user_data(
					array(
						'email'      => 'test@example.com',
						'first_name' => 'Test',
						'last_name'  => 'Visitor',
					)
				),
			),
			$test_event_code
		);

		if ( 'held' === $result['outcome'] ) {
			return sprintf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Meta for WooCommerce held this back instead of sending it, because cookie consent has not been given. Nothing reached Meta.', 'bricks-meta-events' )
			);
		}

		$success = 'accepted' === $result['outcome'];
		$matched = empty( $result['matched'] )
			? __( 'No customer details got through. Meta received this with nothing to identify the person by.', 'bricks-meta-events' )
			: sprintf(
				/* translators: %s: comma-separated field names. */
				__( 'Customer details sent, encrypted: %s.', 'bricks-meta-events' ),
				implode( ', ', $result['matched'] )
			);

		return sprintf(
			'<div class="notice notice-%s"><p><strong>%s</strong></p><p>%s</p><pre style="white-space:pre-wrap;max-height:20em;overflow:auto">%s</pre></div>',
			$success ? 'success' : 'error',
			esc_html(
				$success
					? __( 'Meta accepted the test event.', 'bricks-meta-events' )
					: __( 'Meta rejected the test event.', 'bricks-meta-events' )
			),
			esc_html( $matched ),
			esc_html( wp_json_encode( $result['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) )
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
			return __( 'No customer details got through. Meta received this with nothing to identify the person by. See the customer matching check above.', 'bricks-meta-events' );
		}

		return sprintf(
			/* translators: %s: comma-separated field names. */
			__( 'Customer details sent, encrypted: %s.', 'bricks-meta-events' ),
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
