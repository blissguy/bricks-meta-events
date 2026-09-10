<?php
/**
 * Health checks for the host plugin's silent failure modes.
 *
 * The host plugin degrades quietly in several ways that look identical to
 * working correctly from the outside: events arrive, counts look right, and
 * match quality is zero. Everything here exists to make one of those states
 * visible before it costs someone six months of ad spend.
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
	 * @return array<int, array{id: string, status: string, label: string, message: string, fix: string}>
	 */
	public static function run(): array {
		if ( ! Host_Adapter::probes()['host_active']['ok'] ) {
			return array(
				self::row(
					'host_active',
					self::ERROR,
					__( 'Meta pixel for WordPress is switched off', 'bricks-meta-events' ),
					__( 'Without it there is no pixel, and no way to send anything to Meta.', 'bricks-meta-events' ),
					__( 'Switch on Meta pixel for WordPress. Nothing here works until you do.', 'bricks-meta-events' )
				),
			);
		}

		// Ordered by causality: a missing pixel ID makes several checks below
		// fail as a consequence, and leading with the consequence is how
		// health screens train people to ignore them.
		return array_merge(
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
	 * The headline check: is advanced matching actually on?
	 *
	 * AAMFieldsExtractor::get_normalized_user_data() returns an empty array
	 * whenever automatic matching is disabled, so every hashed email, phone
	 * and name is stripped before the Conversions API request is built. On a
	 * site configured by pasting a pixel ID and token — rather than connecting
	 * through Facebook Business Extension — this is the default state.
	 */
	private static function check_advanced_matching(): array {
		$label = __( 'Customer matching', 'bricks-meta-events' );

		// The host plugin abandons set_aam_settings() early when no pixel ID
		// is present, so matching would always read as off here. Reporting
		// that as its own error would be a second alarm for one cause.
		if ( '' === Host_Adapter::pixel_id() ) {
			return self::row(
				'aam',
				self::INFO,
				$label,
				__( 'Cannot be checked until a pixel is set up.', 'bricks-meta-events' ),
				''
			);
		}

		$settings = Host_Adapter::aam_settings();

		if ( null === $settings || ! method_exists( $settings, 'getEnableAutomaticMatching' ) ) {
			return self::row(
				'aam',
				self::ERROR,
				$label,
				__( 'Meta pixel for WordPress has not loaded your matching settings, so email, phone and name are removed from everything you send.', 'bricks-meta-events' ),
				__( 'Open Settings, Meta and reconnect. These settings come from Meta and are stored for a while, so a failed connection leaves them empty.', 'bricks-meta-events' )
			);
		}

		if ( ! $settings->getEnableAutomaticMatching() ) {
			$message = __( 'Customer matching is switched off. Your enquiries still reach Meta and the totals look right, but email, phone and name are removed first, so Meta cannot tell who made them. Your ad results will look far worse than they are.', 'bricks-meta-events' );

			// A failed fetch and a deliberate opt-out produce an identical
			// disabled settings object. The absence of the cache entry is the
			// only thing that tells them apart, and the fixes are different.
			if ( ! Host_Adapter::aam_settings_cached() ) {
				return self::row(
					'aam',
					self::ERROR,
					$label,
					$message . ' ' . __( 'Meta pixel for WordPress has also never managed to fetch these settings, so this may be a connection problem rather than a choice.', 'bricks-meta-events' ),
					__( 'Check this site can reach Meta, then reload. If it can, switch on automatic advanced matching for this pixel in Meta Events Manager.', 'bricks-meta-events' )
				);
			}

			return self::row(
				'aam',
				self::ERROR,
				$label,
				$message,
				__( 'Switch on automatic advanced matching for this pixel in Meta Events Manager, then reload this page.', 'bricks-meta-events' )
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
				sprintf(
					/* translators: %s: comma-separated list of field names. */
					__( 'Matching is on, but these are being left out and will not be sent: %s.', 'bricks-meta-events' ),
					implode( ', ', array_map( array( self::class, 'field_name' ), $missing ) )
				),
				__( 'Switch the missing ones on in your Meta Events Manager pixel settings.', 'bricks-meta-events' )
			);
		}

		return self::row(
			'aam',
			self::OK,
			$label,
			sprintf(
				/* translators: %d: number of enabled matching fields. */
				__( 'On, matching on %d details including email and phone.', 'bricks-meta-events' ),
				count( $enabled )
			),
			''
		);
	}

	/**
	 * Warn the person reading the screen that their own events are discarded.
	 *
	 * Almost everyone testing this plugin will be an administrator, will see
	 * nothing in Events Manager, and will conclude it is broken.
	 */
	private static function check_current_user(): array {
		$label = __( 'Your own events', 'bricks-meta-events' );

		if ( ! Host_Adapter::is_internal_user() ) {
			return self::row(
				'current_user',
				self::OK,
				$label,
				__( 'Your account is not excluded, so your own test will be tracked.', 'bricks-meta-events' ),
				''
			);
		}

		$user  = wp_get_current_user();
		$roles = ! empty( $user->roles ) ? implode( ', ', $user->roles ) : __( 'unknown', 'bricks-meta-events' );

		return self::row(
			'current_user',
			self::WARNING,
			$label,
			sprintf(
				/* translators: %s: comma-separated role names. */
				__( 'You are signed in as %s. Meta pixel for WordPress throws away anything sent by people who can edit posts or upload files, so your own test will not reach Meta.', 'bricks-meta-events' ),
				$roles
			),
			__( 'Test in a private window while signed out, or as a Subscriber. This is built into Meta pixel for WordPress and cannot be changed.', 'bricks-meta-events' )
		);
	}

	/**
	 * Flag any role that would have its conversions silently discarded.
	 *
	 * On a membership site this is the difference between tracking paying
	 * customers and tracking nobody.
	 */
	private static function check_roles(): array {
		$roles = wp_roles()->roles;
		$hit   = array();

		foreach ( $roles as $slug => $role ) {
			$caps = isset( $role['capabilities'] ) ? (array) $role['capabilities'] : array();

			if ( ! empty( $caps['edit_posts'] ) || ! empty( $caps['upload_files'] ) ) {
				$hit[] = ! empty( $role['name'] ) ? $role['name'] : $slug;
			}
		}

		if ( empty( $hit ) ) {
			return array();
		}

		// Roles that are expected to be staff are not worth alarming about.
		$expected  = array( 'Administrator', 'Editor', 'Author', 'Contributor' );
		$unexpected = array_diff( $hit, $expected );

		return array(
			self::row(
				'roles',
				empty( $unexpected ) ? self::INFO : self::WARNING,
				__( 'People who will not be tracked', 'bricks-meta-events' ),
				sprintf(
					/* translators: %s: comma-separated role names. */
					__( 'Anyone with these roles can edit posts or upload files, so nothing they do is tracked: %s.', 'bricks-meta-events' ),
					implode( ', ', $hit )
				),
				empty( $unexpected )
					? ''
					: sprintf(
						/* translators: %s: comma-separated role names. */
						__( 'Check these are staff only: %s. If any of them is a customer or member role, everything those people do is being thrown away.', 'bricks-meta-events' ),
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
	 * @return array<int, array{id: string, status: string, label: string, message: string, fix: string}>
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
				__( 'Site address mismatch', 'bricks-meta-events' ),
				sprintf(
					/* translators: 1: WordPress address host, 2: site address host. */
					__( 'WordPress Address is %1$s but Site Address is %2$s. Conversions are sent to the WordPress Address, so they are going to %1$s instead of this site.', 'bricks-meta-events' ),
					$siteurl,
					$home
				),
				__( 'Normal on a local or staging copy of a live site, where this cannot be tested. On a live site, correct WordPress Address under Settings, General.', 'bricks-meta-events' )
			),
		);
	}

	/**
	 * Is a pixel ID configured at all?
	 */
	private static function check_pixel_id(): array {
		$pixel_id = Host_Adapter::pixel_id();

		if ( '' === $pixel_id ) {
			return self::row(
				'pixel_id',
				self::ERROR,
				__( 'Pixel ID', 'bricks-meta-events' ),
				__( 'No pixel is set up, so nothing can be tracked.', 'bricks-meta-events' ),
				__( 'Add your pixel under Settings, Meta.', 'bricks-meta-events' )
			);
		}

		return self::row(
			'pixel_id',
			self::OK,
			__( 'Pixel ID', 'bricks-meta-events' ),
			$pixel_id,
			''
		);
	}

	/**
	 * Is a Conversions API token present?
	 *
	 * Without one, server events cannot be sent and the plugin falls back to
	 * browser-only tracking — which still works, but loses the ad-blocker and
	 * redirect resilience that is most of the point.
	 */
	private static function check_access_token(): array {
		$label = __( 'Sending from your site', 'bricks-meta-events' );

		if ( ! Host_Adapter::has_access_token() ) {
			return self::row(
				'access_token',
				self::WARNING,
				$label,
				__( 'Not set up. Everything will be sent from the visitor\'s browser instead, where ad blockers and page redirects can lose it.', 'bricks-meta-events' ),
				__( 'Connect the Conversions API under Settings, Meta.', 'bricks-meta-events' )
			);
		}

		return self::row( 'access_token', self::OK, $label, __( 'Set up.', 'bricks-meta-events' ), '' );
	}

	/**
	 * Has the host plugin tripped its own circuit breaker?
	 */
	private static function check_circuit_breaker(): array {
		$label = __( 'Sending paused', 'bricks-meta-events' );

		if ( Host_Adapter::circuit_breaker_ok() ) {
			return self::row( 'circuit_breaker', self::OK, $label, __( 'No, sending is working normally.', 'bricks-meta-events' ), '' );
		}

		return self::row(
			'circuit_breaker',
			self::ERROR,
			$label,
			__( 'Yes. Meta pixel for WordPress stopped sending after repeated failures and will not try again until it resets.', 'bricks-meta-events' ),
			__( 'Use Send a test event below to see what Meta is reporting.', 'bricks-meta-events' )
		);
	}

	/**
	 * Surface any structural probe failure against the host plugin.
	 */
	private static function check_probes(): array {
		$failing = Host_Adapter::failing_probes();

		if ( empty( $failing ) ) {
			return array(
				self::row(
					'probes',
					self::OK,
					__( 'Meta plugin compatibility', 'bricks-meta-events' ),
					__( 'Everything this plugin relies on is present and unchanged.', 'bricks-meta-events' ),
					''
				),
			);
		}

		return array(
			self::row(
				'probes',
				self::ERROR,
				__( 'Meta plugin compatibility', 'bricks-meta-events' ),
				sprintf(
					/* translators: %s: comma-separated probe names. */
					__( 'Meta pixel for WordPress has changed in a way this plugin does not recognise: %s.', 'bricks-meta-events' ),
					implode( '; ', $failing )
				),
				__( 'Sending from your site is switched off until this is sorted. Roll Meta pixel for WordPress back to a version that worked, or update this plugin.', 'bricks-meta-events' )
			),
		);
	}

	/**
	 * Informational version row, for support.
	 */
	private static function check_versions(): array {
		$host       = Host_Adapter::host_version();
		$bricks     = defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : __( 'not detected', 'bricks-meta-events' );
		$connection = Host_Adapter::connection_type();

		$labels = array(
			'fbl4b' => __( 'Facebook Login for Business', 'bricks-meta-events' ),
			'mbe'   => __( 'Facebook Business Extension', 'bricks-meta-events' ),
		);

		return self::row(
			'versions',
			self::INFO,
			__( 'Versions', 'bricks-meta-events' ),
			sprintf(
				/* translators: 1: this plugin's version, 2: host plugin version, 3: Bricks version, 4: connection type. */
				__( 'Bricks Meta Events %1$s · Meta pixel for WordPress %2$s · Bricks %3$s · Connected via %4$s', 'bricks-meta-events' ),
				VERSION,
				'' !== $host ? $host : __( 'unknown', 'bricks-meta-events' ),
				$bricks,
				$labels[ $connection ] ?? ( '' !== $connection ? $connection : __( 'manual configuration', 'bricks-meta-events' ) )
			),
			''
		);
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
					__( 'Could not reach %1$s: %2$s. Conversions are normally sent through this address in the background, so they would be lost.', 'bricks-meta-events' ),
					$target,
					$response->get_error_message()
				),
			);
		} else {
			$result = array(
				'status'  => self::OK,
				'message' => sprintf(
					/* translators: %s: target URL. */
					__( 'Reached %s. Background sending is not being blocked.', 'bricks-meta-events' ),
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
	 * @return array{id: string, status: string, label: string, message: string, fix: string}
	 */
	private static function row( string $id, string $status, string $label, string $message, string $fix ): array {
		return array(
			'id'      => $id,
			'status'  => $status,
			'label'   => $label,
			'message' => $message,
			'fix'     => $fix,
		);
	}
}
