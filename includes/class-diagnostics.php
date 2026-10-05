<?php
/**
 * Health checks for the host plugin's silent failure modes.
 *
 * The host plugin degrades quietly in several ways that look identical to
 * working correctly from the outside: events arrive, counts look right, and
 * match quality is zero. Everything here exists to make one of those states
 * visible before it costs someone six months of ad spend.
 *
 * Each row leads with a short status ("Connected.", "Off.") so the screen can
 * be scanned, then at most one sentence of context and one of fix.
 *
 * @package BricksMetaEvents
 */

namespace BricksMetaEvents;

defined( 'ABSPATH' ) || exit;

/**
 * Produces the check rows rendered by Health_Screen.
 */
class Diagnostics {

	public const OK      = 'ok';
	public const WARNING = 'warning';
	public const ERROR   = 'error';
	public const INFO    = 'info';

	private const LOOPBACK_TRANSIENT = 'bme_loopback_probe';

	/**
	 * Run every check that is cheap enough for a page load.
	 *
	 * The loopback probe makes an HTTP request and is excluded; call
	 * loopback() explicitly.
	 *
	 * @return array<int, array{id: string, status: string, label: string, summary: string, message: string, fix: string}>
	 */
	public static function run(): array {
		if ( '' === Host::current() ) {
			return array(
				self::row(
					'host_active',
					self::ERROR,
					__( 'Meta plugin', 'bricks-meta-events' ),
					__( 'None.', 'bricks-meta-events' ),
					__( 'Nothing can be sent to Meta without Meta for WooCommerce or Meta pixel for WordPress.', 'bricks-meta-events' ),
					__( 'Switch one of them on.', 'bricks-meta-events' )
				),
			);
		}

		if ( Host::is_woo() ) {
			return array_merge(
				self::check_host(),
				array(
					self::check_pixel_id(),
					self::check_woo_connection(),
					self::check_woo_matching(),
					self::check_current_user(),
				),
				self::check_roles(),
				self::check_probes(),
				array( self::check_versions() )
			);
		}

		// Ordered by causality: a missing pixel ID makes several checks below
		// fail as a consequence, and leading with the consequence is how
		// health screens train people to ignore them.
		return array_merge(
			self::check_host(),
			array(
				self::check_pixel_id(),
				self::check_access_token(),
				self::check_advanced_matching(),
				self::check_current_user(),
				self::check_circuit_breaker(),
			),
			self::check_site_url(),
			self::check_roles(),
			self::check_probes(),
			array( self::check_versions() )
		);
	}

	/**
	 * Which Meta plugin is being used, and whether both are switched on.
	 *
	 * Meta pixel for WordPress steps back from WooCommerce events when Meta
	 * for WooCommerce is active, but still prints its own pixel and PageView,
	 * so with both on every page view is counted twice.
	 *
	 * @return array<int, array{id: string, status: string, label: string, summary: string, message: string, fix: string}>
	 */
	private static function check_host(): array {
		$label = __( 'Meta plugin', 'bricks-meta-events' );

		if ( ! Host::both_active() ) {
			return array( self::row( 'host', self::INFO, $label, '', Host::name(), '' ) );
		}

		return array(
			self::row(
				'host',
				self::WARNING,
				$label,
				Host::name() . '.',
				Host::is_woo()
					? __( 'Meta pixel for WordPress is also on, so page views are counted twice.', 'bricks-meta-events' )
					: __( 'Meta for WooCommerce is also on, so page views are counted twice.', 'bricks-meta-events' ),
				Host::is_woo()
					? __( 'Switch off Meta pixel for WordPress.', 'bricks-meta-events' )
					: __( 'Switch off the one you aren\'t using.', 'bricks-meta-events' )
			),
		);
	}

	/**
	 * Can Meta for WooCommerce send from the server?
	 */
	private static function check_woo_connection(): array {
		$label = __( 'Connection', 'bricks-meta-events' );

		if ( ! Woo_Adapter::is_connected() ) {
			return self::row(
				'woo_connection',
				self::WARNING,
				$label,
				__( 'Not connected.', 'bricks-meta-events' ),
				__( 'Conversions are sent from the visitor\'s browser only, where ad blockers can lose them.', 'bricks-meta-events' ),
				__( 'Connect under Marketing, Facebook.', 'bricks-meta-events' )
			);
		}

		if ( Woo_Adapter::connection_invalid() ) {
			return self::row(
				'woo_connection',
				self::ERROR,
				$label,
				__( 'Rejected by Meta.', 'bricks-meta-events' ),
				__( 'Nothing is being sent from your site until you reconnect.', 'bricks-meta-events' ),
				__( 'Reconnect under Marketing, Facebook.', 'bricks-meta-events' )
			);
		}

		return self::row(
			'woo_connection',
			self::OK,
			$label,
			__( 'Connected.', 'bricks-meta-events' ),
			__( 'Conversions go straight to Meta, and Meta\'s reply is logged under Conversions.', 'bricks-meta-events' ),
			''
		);
	}

	/**
	 * Advanced matching on Meta for WooCommerce.
	 *
	 * Nothing to go wrong here, which is worth saying: on Meta pixel for
	 * WordPress this is the check that most often fails.
	 */
	private static function check_woo_matching(): array {
		return self::row(
			'aam',
			self::OK,
			__( 'Advanced matching', 'bricks-meta-events' ),
			__( 'On.', 'bricks-meta-events' ),
			__( 'Email, phone and name are sent encrypted whenever a form collects them.', 'bricks-meta-events' ),
			''
		);
	}

	/**
	 * The headline check: is advanced matching actually on?
	 *
	 * AAMFieldsExtractor::get_normalized_user_data() returns an empty array
	 * whenever automatic matching is disabled, so every hashed email, phone
	 * and name is stripped before the Conversions API request is built. On a
	 * site configured by pasting a pixel ID and token — rather than connecting
	 * through Facebook Business Extension — this is the default state.
	 */
	private static function check_advanced_matching(): array {
		$label = __( 'Advanced matching', 'bricks-meta-events' );

		// The host plugin abandons set_aam_settings() early when no pixel ID
		// is present, so matching would always read as off here. Reporting
		// that as its own error would be a second alarm for one cause.
		if ( '' === Host_Adapter::pixel_id() ) {
			return self::row(
				'aam',
				self::INFO,
				$label,
				__( 'Not checked.', 'bricks-meta-events' ),
				__( 'Set up a pixel first.', 'bricks-meta-events' ),
				''
			);
		}

		$settings = Host_Adapter::aam_settings();

		if ( null === $settings || ! method_exists( $settings, 'getEnableAutomaticMatching' ) ) {
			return self::row(
				'aam',
				self::ERROR,
				$label,
				__( 'Not loaded.', 'bricks-meta-events' ),
				__( 'Email, phone and name are removed from everything you send.', 'bricks-meta-events' ),
				__( 'Reconnect under Settings, Meta.', 'bricks-meta-events' )
			);
		}

		if ( ! $settings->getEnableAutomaticMatching() ) {
			// A failed fetch and a deliberate opt-out produce an identical
			// disabled settings object. The absence of the cache entry is the
			// only thing that tells them apart, and the fixes are different.
			$never_fetched = ! Host_Adapter::aam_settings_cached();

			return self::row(
				'aam',
				self::ERROR,
				$label,
				__( 'Off.', 'bricks-meta-events' ),
				$never_fetched
					? __( 'Email, phone and name are removed before sending, and these settings have never been fetched from Meta, so it may be a connection problem.', 'bricks-meta-events' )
					: __( 'Conversions still count, but email, phone and name are removed first, so Meta can\'t tell who sent them.', 'bricks-meta-events' ),
				$never_fetched
					? __( 'Check this site can reach Meta, then turn on automatic advanced matching in Meta Events Manager.', 'bricks-meta-events' )
					: __( 'Turn on automatic advanced matching for this pixel in Meta Events Manager.', 'bricks-meta-events' )
			);
		}

		$enabled = method_exists( $settings, 'getEnabledAutomaticMatchingFields' )
			? (array) $settings->getEnabledAutomaticMatchingFields()
			: array();

		$missing = array_diff( array( 'em', 'ph' ), $enabled );

		if ( ! empty( $missing ) ) {
			return self::row(
				'aam',
				self::WARNING,
				$label,
				__( 'Partly on.', 'bricks-meta-events' ),
				sprintf(
					/* translators: %s: comma-separated list of field names. */
					__( 'Not sent: %s.', 'bricks-meta-events' ),
					implode( ', ', array_map( array( self::class, 'field_name' ), $missing ) )
				),
				__( 'Turn them on in your pixel settings in Meta Events Manager.', 'bricks-meta-events' )
			);
		}

		return self::row(
			'aam',
			self::OK,
			$label,
			__( 'On.', 'bricks-meta-events' ),
			sprintf(
				/* translators: %d: number of enabled matching fields. */
				__( 'Matching on %d details, including email and phone.', 'bricks-meta-events' ),
				count( $enabled )
			),
			''
		);
	}

	/**
	 * Tell the person reading the screen whether their own events count.
	 *
	 * Almost everyone testing this plugin will be an administrator, will see
	 * nothing in Events Manager, and will conclude it is broken.
	 */
	private static function check_current_user(): array {
		$label = __( 'Your account', 'bricks-meta-events' );

		if ( ! Host::is_internal_user() ) {
			return self::row( 'current_user', self::OK, $label, __( 'Tracked.', 'bricks-meta-events' ), '', '' );
		}

		if ( Host::staff_can_test() ) {
			return self::row(
				'current_user',
				self::OK,
				$label,
				__( 'Sent to Test Events.', 'bricks-meta-events' ),
				__( 'Test mode is on, so your form submissions go to Test Events. Your button clicks aren\'t tracked.', 'bricks-meta-events' ),
				''
			);
		}

		if ( Host::is_woo() ) {
			return self::row(
				'current_user',
				self::WARNING,
				$label,
				__( 'Not tracked.', 'bricks-meta-events' ),
				__( 'Anyone who can manage WooCommerce is skipped.', 'bricks-meta-events' ),
				__( 'Turn on test mode, or test in a private window.', 'bricks-meta-events' )
			);
		}

		return self::row(
			'current_user',
			self::WARNING,
			$label,
			__( 'Not tracked.', 'bricks-meta-events' ),
			__( 'Meta pixel for WordPress ignores anyone who can edit posts or upload files, and this can\'t be changed.', 'bricks-meta-events' ),
			__( 'Test in a private window while signed out.', 'bricks-meta-events' )
		);
	}

	/**
	 * List the roles that count as staff, and flag any that look like customers.
	 *
	 * On a membership site this is the difference between tracking paying
	 * customers and tracking nobody.
	 *
	 * @return array<int, array{id: string, status: string, label: string, summary: string, message: string, fix: string}>
	 */
	private static function check_roles(): array {
		$hit = array();

		foreach ( wp_roles()->roles as $slug => $role ) {
			$caps = isset( $role['capabilities'] ) ? (array) $role['capabilities'] : array();

			if ( Host::role_is_staff( $caps ) ) {
				$hit[] = ! empty( $role['name'] ) ? $role['name'] : $slug;
			}
		}

		if ( empty( $hit ) ) {
			return array();
		}

		// Roles that are expected to be staff are not worth alarming about.
		$expected   = Host::is_woo()
			? array( 'Administrator', 'Shop manager' )
			: array( 'Administrator', 'Editor', 'Author', 'Contributor' );
		$unexpected = array_diff( $hit, $expected );

		return array(
			self::row(
				'roles',
				empty( $unexpected ) ? self::INFO : self::WARNING,
				__( 'Excluded roles', 'bricks-meta-events' ),
				'',
				sprintf(
					/* translators: 1: comma-separated role names, 2: who counts as staff, e.g. "can manage WooCommerce". */
					__( '%1$s. Anyone who %2$s is skipped.', 'bricks-meta-events' ),
					implode( ', ', $hit ),
					Host::staff_rule()
				),
				empty( $unexpected )
					? ''
					: sprintf(
						/* translators: %s: comma-separated role names. */
						__( 'Check these are staff only: %s. If one is a customer or member role, those people aren\'t being tracked.', 'bricks-meta-events' ),
						implode( ', ', $unexpected )
					)
			),
		);
	}

	/**
	 * Detect a site-address / WordPress-address mismatch.
	 *
	 * The host plugin delivers Conversions API events by POSTing to
	 * admin_url( 'admin-ajax.php' ), which is built from siteurl. When siteurl
	 * points somewhere other than the site people actually visit — the usual
	 * cause being a local or staging copy of a live site — those events are
	 * fired at the other server and vanish. The visible symptom is a loopback
	 * timeout, which reads like a firewall problem and is not one.
	 *
	 * @return array<int, array{id: string, status: string, label: string, summary: string, message: string, fix: string}>
	 */
	private static function check_site_url(): array {
		$siteurl = wp_parse_url( get_option( 'siteurl' ), PHP_URL_HOST );
		$home    = wp_parse_url( get_option( 'home' ), PHP_URL_HOST );

		if ( ! $siteurl || ! $home || strtolower( $siteurl ) === strtolower( $home ) ) {
			return array();
		}

		return array(
			self::row(
				'site_url',
				self::ERROR,
				__( 'Site address', 'bricks-meta-events' ),
				__( 'Mismatch.', 'bricks-meta-events' ),
				sprintf(
					/* translators: 1: WordPress address host, 2: site address host. */
					__( 'WordPress Address is %1$s but Site Address is %2$s, so conversions are sent to %1$s.', 'bricks-meta-events' ),
					$siteurl,
					$home
				),
				__( 'Normal on a local or staging copy. On a live site, correct WordPress Address under Settings, General.', 'bricks-meta-events' )
			),
		);
	}

	/**
	 * Is a pixel ID configured at all?
	 */
	private static function check_pixel_id(): array {
		$pixel_id = Host::pixel_id();

		if ( '' === $pixel_id ) {
			return self::row(
				'pixel_id',
				self::ERROR,
				__( 'Pixel ID', 'bricks-meta-events' ),
				__( 'Missing.', 'bricks-meta-events' ),
				__( 'Nothing can be tracked.', 'bricks-meta-events' ),
				sprintf(
					/* translators: %s: admin menu location. */
					__( 'Add your pixel under %s.', 'bricks-meta-events' ),
					Host::settings_location()
				)
			);
		}

		return self::row( 'pixel_id', self::OK, __( 'Pixel ID', 'bricks-meta-events' ), '', $pixel_id, '' );
	}

	/**
	 * Is a Conversions API token present?
	 *
	 * Without one, server events cannot be sent and the plugin falls back to
	 * browser-only tracking — which still works, but loses the ad-blocker and
	 * redirect resilience that is most of the point.
	 */
	private static function check_access_token(): array {
		$label = __( 'Connection', 'bricks-meta-events' );

		if ( ! Host_Adapter::has_access_token() ) {
			return self::row(
				'access_token',
				self::WARNING,
				$label,
				__( 'Not connected.', 'bricks-meta-events' ),
				__( 'Conversions are sent from the visitor\'s browser only, where ad blockers can lose them.', 'bricks-meta-events' ),
				__( 'Connect the Conversions API under Settings, Meta.', 'bricks-meta-events' )
			);
		}

		return self::row( 'access_token', self::OK, $label, __( 'Connected.', 'bricks-meta-events' ), '', '' );
	}

	/**
	 * Has the host plugin tripped its own circuit breaker?
	 */
	private static function check_circuit_breaker(): array {
		$label = __( 'Sending', 'bricks-meta-events' );

		if ( Host_Adapter::circuit_breaker_ok() ) {
			return self::row( 'circuit_breaker', self::OK, $label, __( 'Working.', 'bricks-meta-events' ), '', '' );
		}

		return self::row(
			'circuit_breaker',
			self::ERROR,
			$label,
			__( 'Paused.', 'bricks-meta-events' ),
			__( 'Meta pixel for WordPress stopped after repeated failures and waits until it resets.', 'bricks-meta-events' ),
			__( 'Send a test event under Testing to see Meta\'s reply.', 'bricks-meta-events' )
		);
	}

	/**
	 * Surface any structural probe failure against the host plugin.
	 *
	 * The probes check that the parts of the host plugin this one calls are
	 * still there and shaped as expected. They cannot tell whether anything
	 * is up to date, so the OK wording must not claim that.
	 */
	private static function check_probes(): array {
		$failing = Host::is_woo() ? Woo_Adapter::failing_probes() : Host_Adapter::failing_probes();
		$label   = __( 'Compatibility', 'bricks-meta-events' );

		if ( empty( $failing ) ) {
			return array(
				self::row(
					'probes',
					self::OK,
					$label,
					__( 'OK.', 'bricks-meta-events' ),
					sprintf(
						/* translators: %s: host plugin name. */
						__( 'Everything this plugin uses in %s is there.', 'bricks-meta-events' ),
						Host::name()
					),
					''
				),
			);
		}

		return array(
			self::row(
				'probes',
				self::ERROR,
				$label,
				__( 'Changed.', 'bricks-meta-events' ),
				sprintf(
					/* translators: 1: host plugin name, 2: list of missing parts. */
					__( '%1$s has changed and these parts are missing: %2$s. Sending from your site is off.', 'bricks-meta-events' ),
					Host::name(),
					implode( '; ', $failing )
				),
				sprintf(
					/* translators: %s: host plugin name. */
					__( 'Roll %s back to a version that worked, or update this plugin.', 'bricks-meta-events' ),
					Host::name()
				)
			),
		);
	}

	/**
	 * Informational version row, for support.
	 */
	private static function check_versions(): array {
		$host   = Host::version();
		$bricks = defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : __( 'not detected', 'bricks-meta-events' );
		$parts  = array(
			'Bricks Meta Events ' . VERSION,
			Host::name() . ' ' . ( '' !== $host ? $host : __( 'unknown', 'bricks-meta-events' ) ),
			'Bricks ' . $bricks,
		);

		if ( Host::PIXEL === Host::current() ) {
			$connection = Host_Adapter::connection_type();
			$labels     = array(
				'fbl4b' => __( 'Facebook Login for Business', 'bricks-meta-events' ),
				'mbe'   => __( 'Facebook Business Extension', 'bricks-meta-events' ),
			);

			$parts[] = sprintf(
				/* translators: %s: how Meta pixel for WordPress is connected. */
				__( 'Connected via %s', 'bricks-meta-events' ),
				$labels[ $connection ] ?? ( '' !== $connection ? $connection : __( 'manual setup', 'bricks-meta-events' ) )
			);
		}

		return self::row( 'versions', self::INFO, __( 'Versions', 'bricks-meta-events' ), '', implode( ' · ', $parts ), '' );
	}

	/**
	 * Probe whether WordPress can reach its own admin-ajax endpoint.
	 *
	 * The host plugin delivers Conversions API events through a non-blocking
	 * loopback request on shutdown. If a firewall blocks that, every server
	 * event vanishes with no error anywhere.
	 *
	 * @param bool $force Bypass the cached result.
	 *
	 * @return array{status: string, message: string}
	 */
	public static function loopback( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::LOOPBACK_TRANSIENT );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		// Probe exactly what the host plugin's async task will POST to, so a
		// misconfigured site URL shows up as the wrong hostname rather than as
		// a mysterious timeout.
		$target = admin_url( 'admin-ajax.php' );

		$response = wp_remote_post(
			$target,
			array(
				'timeout'   => 10,
				'blocking'  => true,
				'sslverify' => false,
				'body'      => array( 'action' => 'bme_loopback_probe' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result = array(
				'status'  => self::ERROR,
				'message' => sprintf(
					/* translators: 1: target URL, 2: error message. */
					__( 'Failed. Couldn\'t reach %1$s: %2$s', 'bricks-meta-events' ),
					$target,
					$response->get_error_message()
				),
			);
		} else {
			$result = array(
				'status'  => self::OK,
				'message' => sprintf(
					/* translators: %s: target URL. */
					__( 'Working. Reached %s.', 'bricks-meta-events' ),
					$target
				),
			);
		}

		set_transient( self::LOOPBACK_TRANSIENT, $result, HOUR_IN_SECONDS );

		// A passing check clears the sticky "background sending is broken"
		// finding, so a site that gets fixed goes back to background sending
		// on its own rather than staying on the slower inline route forever.
		if ( self::OK === $result['status'] ) {
			delete_option( Plugin::OPTION_BACKGROUND_BROKEN );
		}

		return $result;
	}

	/**
	 * The cached loopback verdict, without running a probe.
	 *
	 * Used on the form-submission path, where a ten-second timeout would be
	 * unacceptable. Returns an empty string when nothing has been cached yet,
	 * which callers should read as "assume the normal path".
	 */
	public static function cached_loopback_status(): string {
		$cached = get_transient( self::LOOPBACK_TRANSIENT );

		return is_array( $cached ) && isset( $cached['status'] ) ? (string) $cached['status'] : '';
	}

	/**
	 * Map an AAM field key to something a human can read.
	 */
	private static function field_name( string $key ): string {
		$names = array(
			'em' => __( 'email', 'bricks-meta-events' ),
			'ph' => __( 'phone', 'bricks-meta-events' ),
			'fn' => __( 'first name', 'bricks-meta-events' ),
			'ln' => __( 'last name', 'bricks-meta-events' ),
		);

		return $names[ $key ] ?? $key;
	}

	/**
	 * Build a single check row.
	 *
	 * @param string $id      Stable row key.
	 * @param string $status  One of the status constants.
	 * @param string $label   Row name.
	 * @param string $summary Short status shown first in bold, e.g. "Connected.". May be empty.
	 * @param string $message At most one sentence of context.
	 * @param string $fix     What to do about it, or empty.
	 *
	 * @return array{id: string, status: string, label: string, summary: string, message: string, fix: string}
	 */
	private static function row( string $id, string $status, string $label, string $summary, string $message, string $fix ): array {
		return array(
			'id'      => $id,
			'status'  => $status,
			'label'   => $label,
			'summary' => $summary,
			'message' => $message,
			'fix'     => $fix,
		);
	}
}
