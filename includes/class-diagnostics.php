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
					__( 'Meta pixel for WordPress is not loaded', 'bricks-meta-events' ),
					__( 'This plugin has no pixel ID, no access token and no way to send anything.', 'bricks-meta-events' ),
					__( 'Activate "Meta pixel for WordPress". Nothing else here will work until you do.', 'bricks-meta-events' )
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
		$label = __( 'Advanced matching (identity data)', 'bricks-meta-events' );

		// The host plugin abandons set_aam_settings() early when no pixel ID
		// is present, so matching would always read as off here. Reporting
		// that as its own error would be a second alarm for one cause.
		if ( '' === Host_Adapter::pixel_id() ) {
			return self::row(
				'aam',
				self::INFO,
				$label,
				__( 'Cannot be determined until a pixel ID is configured.', 'bricks-meta-events' ),
				''
			);
		}

		$settings = Host_Adapter::aam_settings();

		if ( null === $settings || ! method_exists( $settings, 'getEnableAutomaticMatching' ) ) {
			return self::row(
				'aam',
				self::ERROR,
				$label,
				__( 'Meta pixel for WordPress has no advanced matching settings loaded, so every email, phone number and name will be stripped from your conversion events before they are sent.', 'bricks-meta-events' ),
				__( 'Open Settings → Meta and reconnect. If the site is not connected through Facebook Business Extension, these settings are fetched from Meta and cached, so a failed fetch leaves them empty.', 'bricks-meta-events' )
			);
		}

		if ( ! $settings->getEnableAutomaticMatching() ) {
			$message = __( 'Advanced matching is OFF. Conversion events will still reach Meta and your totals will look correct, but every hashed identifier is discarded first, so Meta cannot attribute conversions to the people who saw your ads. Match quality will be near zero.', 'bricks-meta-events' );

			// A failed fetch and a deliberate opt-out produce an identical
			// disabled settings object. The absence of the cache entry is the
			// only thing that tells them apart, and the fixes are different.
			if ( ! Host_Adapter::aam_settings_cached() ) {
				return self::row(
					'aam',
					self::ERROR,
					$label,
					$message . ' ' . __( 'Meta pixel for WordPress has also never successfully retrieved these settings from Meta, so this may be a connectivity problem rather than a deliberate setting.', 'bricks-meta-events' ),
					__( 'Check that this site can reach graph.facebook.com, then reload. If it can, enable automatic advanced matching for this pixel in Meta Events Manager.', 'bricks-meta-events' )
				);
			}

			return self::row(
				'aam',
				self::ERROR,
				$label,
				$message,
				__( 'Enable automatic advanced matching for this pixel in Meta Events Manager, then reload this page.', 'bricks-meta-events' )
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
					__( 'Advanced matching is on, but these identifiers are excluded and will be dropped: %s.', 'bricks-meta-events' ),
					implode( ', ', array_map( array( self::class, 'field_name' ), $missing ) )
				),
				__( 'Enable the missing fields in your Meta Events Manager pixel settings if you want them used for attribution.', 'bricks-meta-events' )
			);
		}

		return self::row(
			'aam',
			self::OK,
			$label,
			sprintf(
				/* translators: %d: number of enabled matching fields. */
				__( 'On, with %d identifier fields enabled including email and phone.', 'bricks-meta-events' ),
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
				__( 'Your account is not treated as internal, so your test submissions will be tracked.', 'bricks-meta-events' ),
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
				__( 'You are logged in as: %s. Meta pixel for WordPress discards events from anyone who can edit posts or upload files, so nothing you submit yourself will reach Meta.', 'bricks-meta-events' ),
				$roles
			),
			__( 'Test in a private window while logged out, or as a Subscriber. This is enforced inside the host plugin and cannot be filtered.', 'bricks-meta-events' )
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
				__( 'Roles excluded from tracking', 'bricks-meta-events' ),
				sprintf(
					/* translators: %s: comma-separated role names. */
					__( 'These roles can edit posts or upload files, so their conversions are discarded: %s.', 'bricks-meta-events' ),
					implode( ', ', $hit )
				),
				empty( $unexpected )
					? ''
					: sprintf(
						/* translators: %s: comma-separated role names. */
						__( 'Check that these are staff-only: %s. If any is a customer or member role, every conversion from those users is being thrown away.', 'bricks-meta-events' ),
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
					__( 'WordPress Address is %1$s but Site Address is %2$s. Conversions API events are POSTed to the WordPress Address, so they are being sent to %1$s rather than to this site.', 'bricks-meta-events' ),
					$siteurl,
					$home
				),
				__( 'Expected on a local or staging copy of a live site — server-side tracking cannot be tested in that situation. On a production site, correct WordPress Address under Settings → General.', 'bricks-meta-events' )
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
				__( 'No pixel ID is configured, so the host plugin renders no pixel and this plugin has nothing to attach events to.', 'bricks-meta-events' ),
				__( 'Add your pixel ID under Settings → Meta.', 'bricks-meta-events' )
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
		$label = __( 'Conversions API access token', 'bricks-meta-events' );

		if ( ! Host_Adapter::has_access_token() ) {
			return self::row(
				'access_token',
				self::WARNING,
				$label,
				__( 'No access token found. Server-side events cannot be sent, so tracking falls back to the browser pixel alone and will be lost to ad blockers and to form redirects.', 'bricks-meta-events' ),
				__( 'Add a Conversions API access token under Settings → Meta.', 'bricks-meta-events' )
			);
		}

		return self::row( 'access_token', self::OK, $label, __( 'Present.', 'bricks-meta-events' ), '' );
	}

	/**
	 * Has the host plugin tripped its own circuit breaker?
	 */
	private static function check_circuit_breaker(): array {
		$label = __( 'Conversions API circuit breaker', 'bricks-meta-events' );

		if ( Host_Adapter::circuit_breaker_ok() ) {
			return self::row( 'circuit_breaker', self::OK, $label, __( 'Closed — sends are allowed.', 'bricks-meta-events' ), '' );
		}

		return self::row(
			'circuit_breaker',
			self::ERROR,
			$label,
			__( 'Tripped. The host plugin has stopped sending server events after repeated failures, and will keep refusing until it resets.', 'bricks-meta-events' ),
			__( 'Use "Send test event" below to see the underlying Graph API error.', 'bricks-meta-events' )
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
					__( 'Host plugin compatibility', 'bricks-meta-events' ),
					__( 'All internal interfaces this plugin depends on are present and unchanged.', 'bricks-meta-events' ),
					''
				),
			);
		}

		return array(
			self::row(
				'probes',
				self::ERROR,
				__( 'Host plugin compatibility', 'bricks-meta-events' ),
				sprintf(
					/* translators: %s: comma-separated probe names. */
					__( 'Meta pixel for WordPress has changed in a way this plugin does not recognise. Failing checks: %s.', 'bricks-meta-events' ),
					implode( '; ', $failing )
				),
				__( 'Server-side events are disabled until this is resolved. Roll the host plugin back to a known-good version, or update this plugin.', 'bricks-meta-events' )
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
					__( 'Could not reach %1$s — %2$s. Conversions API events are delivered through this endpoint, so they are silently failing.', 'bricks-meta-events' ),
					$target,
					$response->get_error_message()
				),
			);
		} else {
			$result = array(
				'status'  => self::OK,
				'message' => sprintf(
					/* translators: 1: target URL, 2: HTTP status code. */
					__( 'Reached %1$s (HTTP %2$d). Conversions API delivery is not being blocked at the network layer.', 'bricks-meta-events' ),
					$target,
					wp_remote_retrieve_response_code( $response )
				),
			);
		}

		set_transient( self::LOOPBACK_TRANSIENT, $result, HOUR_IN_SECONDS );

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
