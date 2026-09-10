<?php
/**
 * Plugin bootstrap and wiring.
 *
 * @package BricksMetaEvents
 */

namespace BricksMetaEvents;

defined( 'ABSPATH' ) || exit;

/**
 * Wires up the plugin's admin surface and scheduled checks.
 */
class Plugin {

	public const CRON_HOOK       = 'bme_daily_health_check';
	public const OPTION_AAM_LAST   = 'bme_aam_last_status';
	public const OPTION_BACKGROUND_BROKEN = 'bme_background_broken';
	public const OPTION_EVENT_LOG         = 'bme_event_log';
	public const OPTION_TEST_CODE         = 'bme_test_event_code';
	public const OPTION_SETTINGS          = 'bme_settings';

	/**
	 * Identifies this plugin's events to the host plugin and to Meta.
	 *
	 * Must not be 'wp-cloudbridge-plugin': the host plugin string-matches that
	 * exact value to flag Open Bridge events and rewrites the partner agent.
	 */
	public const INTEGRATION_NAME = 'bricks-meta-events';

	private static ?Plugin $instance = null;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Run the checks a fresh install depends on.
	 *
	 * The background-sending check in particular has to happen before the
	 * first conversion, not after someone opens the health screen. Until it
	 * has run, the plugin assumes the background route works, which is the
	 * wrong assumption to make silently.
	 */
	public static function on_activate(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		Diagnostics::loopback( true );

		// Retired in 0.5.0, when the single last-conversion record became a log.
		delete_option( 'bme_last_event' );
	}

	/**
	 * Clear our scheduled check when the plugin is switched off.
	 */
	public static function on_deactivate(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		Element_Controls::register();
		Form_Tracker::register();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tracking' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_check' ) );
		add_action( 'admin_init', array( $this, 'maybe_schedule_cron' ) );
	}

	/**
	 * Load the browser side tracking.
	 *
	 * Enqueued site-wide rather than only on pages known to contain a form or
	 * a phone link: both turn up in popups, query loops and content loaded in
	 * later, and the script does nothing until something happens.
	 */
	public function enqueue_tracking(): void {
		if ( '' === Host_Adapter::pixel_id() ) {
			return;
		}

		/**
		 * Filters whether the browser tracking script is loaded.
		 *
		 * @param bool $enqueue True to load it.
		 */
		if ( ! apply_filters( 'bme_enqueue_tracking', true ) ) {
			return;
		}

		wp_enqueue_script(
			'bme-tracking',
			plugins_url( 'assets/js/tracking.js', FILE ),
			array(),
			VERSION,
			true
		);

		// Whether this visitor counts at all is decided here, once, so the
		// browser cannot disagree with the server about who is being tracked.
		// Written as JSON rather than through wp_localize_script, which turns
		// every boolean into "1" or an empty string.
		wp_add_inline_script(
			'bme-tracking',
			'window.bmeConfig = ' . wp_json_encode(
				array(
					'enabled'      => ! Host_Adapter::is_internal_user() && ! Settings::current_user_excluded(),
					'trackLinks'   => Settings::track_links(),
					'contactEvent' => 'Contact',
					'standard'     => Event_Map::standard_events(),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Add the health screen under Settings, next to the host plugin's own page.
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'Bricks Meta Events', 'bricks-meta-events' ),
			__( 'Bricks Meta Events', 'bricks-meta-events' ),
			'manage_options',
			'bricks-meta-events',
			array( Health_Screen::class, 'render' )
		);
	}

	/**
	 * Ensure the daily check is scheduled.
	 *
	 * Advanced matching settings are fetched from Meta and cached in a
	 * transient, so a site that is healthy today can silently degrade
	 * tomorrow with no local change. Activation-time checking is not enough.
	 */
	public function maybe_schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Record whether advanced matching is currently active.
	 */
	public function run_scheduled_check(): void {
		// Refresh the background-sending check too. Whether it works decides
		// how conversions are sent, and if it has never been checked the
		// plugin assumes the background route works and quietly loses every
		// conversion on a site where it does not.
		Diagnostics::loopback( true );

		$settings = Host_Adapter::aam_settings();

		$enabled = null !== $settings
			&& method_exists( $settings, 'getEnableAutomaticMatching' )
			&& $settings->getEnableAutomaticMatching();

		update_option(
			self::OPTION_AAM_LAST,
			array(
				'enabled'    => $enabled,
				'checked_at' => time(),
			),
			false
		);
	}

	/**
	 * Show a dismissible notice when the plugin is in a silently broken state.
	 *
	 * Private distribution means failing loudly is correct: the alternative is
	 * finding out from a client six months later.
	 */
	public function render_admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();

		// The health screen says all of this in more detail already.
		if ( $screen && 'settings_page_bricks-meta-events' === $screen->id ) {
			return;
		}

		$problems = array();

		foreach ( Diagnostics::run() as $check ) {
			if ( Diagnostics::ERROR === $check['status'] ) {
				$problems[] = $check['label'];
			}
		}

		if ( empty( $problems ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><a href="%s">%s</a></p></div>',
			esc_html__( 'Bricks Meta Events:', 'bricks-meta-events' ),
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of failing check names. */
					__( 'conversion tracking is not working properly: %s.', 'bricks-meta-events' ),
					implode( ', ', $problems )
				)
			),
			esc_url( admin_url( 'options-general.php?page=bricks-meta-events' ) ),
			esc_html__( 'See what is wrong', 'bricks-meta-events' )
		);
	}
}
