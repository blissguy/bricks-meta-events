<?php
/**
 * The "Meta tracking" panel on the Bricks form element.
 *
 * Meta's standard event list is closed, so this panel maps intent to an event
 * rather than asking for an event name. Bricks controls are declared per
 * element type and rendered client-side, so nothing here can react to the
 * form being edited — an info block can only describe the site. That is why
 * the site-level warning below matters: it is the one piece of state the
 * panel genuinely can show, and it is the one that silently ruins the data.
 *
 * @package BricksMetaEvents
 */

namespace BricksMetaEvents;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the tracking controls on the Bricks form element.
 */
class Element_Controls {

	private const GROUP = 'bmeTracking';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_filter( 'bricks/elements/form/control_groups', array( self::class, 'add_group' ) );
		add_filter( 'bricks/elements/form/controls', array( self::class, 'add_controls' ) );
	}

	/**
	 * Add the panel section.
	 *
	 * @param array $groups Existing control groups.
	 *
	 * @return array
	 */
	public static function add_group( $groups ) {
		if ( ! is_array( $groups ) ) {
			return $groups;
		}

		$groups[ self::GROUP ] = array(
			'tab'   => 'content',
			'title' => esc_html__( 'Meta tracking', 'bricks-meta-events' ),
		);

		return $groups;
	}

	/**
	 * Add the controls.
	 *
	 * @param array $controls Existing controls.
	 *
	 * @return array
	 */
	public static function add_controls( $controls ) {
		if ( ! is_array( $controls ) ) {
			return $controls;
		}

		$enabled = array( 'bmeEnabled', '=', true );

		$controls['bmeSiteStatus'] = array(
			'tab'     => 'content',
			'group'   => self::GROUP,
			'type'    => 'info',
			'content' => self::site_status(),
		);

		$controls['bmeEnabled'] = array(
			'tab'   => 'content',
			'group' => self::GROUP,
			'label' => esc_html__( 'Track this form', 'bricks-meta-events' ),
			'type'  => 'checkbox',
		);

		$controls['bmeIntent'] = array(
			'tab'         => 'content',
			'group'       => self::GROUP,
			'label'       => esc_html__( 'What does submitting this form mean?', 'bricks-meta-events' ),
			'type'        => 'select',
			'options'     => Event_Map::options(),
			'default'     => Event_Map::DEFAULT_INTENT,
			'clearable'   => false,
			'placeholder' => esc_html__( 'Select an outcome', 'bricks-meta-events' ),
			'description' => self::intent_reference(),
			'required'    => $enabled,
		);

		$controls['bmeCustomName'] = array(
			'tab'            => 'content',
			'group'          => self::GROUP,
			'label'          => esc_html__( 'Custom event name', 'bricks-meta-events' ),
			'type'           => 'text',
			'hasDynamicData' => false,
			'placeholder'    => 'MyCustomEvent',
			'info'           => esc_html__( 'Custom events cannot be used for most Meta optimisation goals until they have built up volume. Prefer a standard event unless you know you need this.', 'bricks-meta-events' ),
			'required'       => array( $enabled, array( 'bmeIntent', '=', Event_Map::CUSTOM_INTENT ) ),
		);

		$controls['bmeLabel'] = array(
			'tab'            => 'content',
			'group'          => self::GROUP,
			'label'          => esc_html__( 'Name in Events Manager', 'bricks-meta-events' ),
			'type'           => 'text',
			'hasDynamicData' => false,
			'placeholder'    => esc_html__( 'Auto', 'bricks-meta-events' ),
			'required'       => $enabled,
		);

		$controls['bmeSendMode'] = array(
			'tab'         => 'content',
			'group'       => self::GROUP,
			'label'       => esc_html__( 'Send via', 'bricks-meta-events' ),
			'type'        => 'select',
			'options'     => array(
				Form_Tracker::MODE_AUTO    => esc_html__( 'Automatic', 'bricks-meta-events' ),
				Form_Tracker::MODE_BOTH    => esc_html__( 'Conversions API and browser pixel', 'bricks-meta-events' ),
				Form_Tracker::MODE_SERVER  => esc_html__( 'Conversions API only', 'bricks-meta-events' ),
				Form_Tracker::MODE_BROWSER => esc_html__( 'Browser pixel only', 'bricks-meta-events' ),
			),
			'default'     => Form_Tracker::MODE_AUTO,
			'clearable'   => false,
			'description' => esc_html__( 'Automatic sends both, and deduplicates them so one conversion is counted. It drops to the Conversions API alone when this form redirects. The page would tear down before the browser event finished, and the browser event carries nothing the server event does not. It drops to the browser alone when the Conversions API is unavailable.', 'bricks-meta-events' ),
			'required'    => $enabled,
		);

		$controls['bmeFieldsSeparator'] = array(
			'tab'      => 'content',
			'group'    => self::GROUP,
			'label'    => esc_html__( 'Identity matching', 'bricks-meta-events' ),
			'type'     => 'separator',
			'required' => $enabled,
		);

		$controls['bmeFieldsInfo'] = array(
			'tab'      => 'content',
			'group'    => self::GROUP,
			'type'     => 'info',
			'content'  => esc_html__( 'Email and phone are detected automatically from your field types, and a logged-in visitor is matched by account. Only override when detection gets it wrong. Paste the field\'s ID copied from each field in your form.', 'bricks-meta-events' ),
			'required' => $enabled,
		);

		foreach ( self::override_controls() as $key => $label ) {
			$controls[ $key ] = array(
				'tab'            => 'content',
				'group'          => self::GROUP,
				'label'          => $label,
				'type'           => 'text',
				'hasDynamicData' => false,
				'placeholder'    => esc_html__( 'Auto-detect', 'bricks-meta-events' ),
				'required'       => $enabled,
			);
		}

		return $controls;
	}

	/**
	 * The three identity override slots.
	 *
	 * @return array<string, string>
	 */
	private static function override_controls(): array {
		return array(
			'bmeEmailField' => esc_html__( 'Email field', 'bricks-meta-events' ),
			'bmePhoneField' => esc_html__( 'Phone field', 'bricks-meta-events' ),
			'bmeNameField'  => esc_html__( 'Name field', 'bricks-meta-events' ),
		);
	}

	/**
	 * A compact reminder of which Meta event each option produces.
	 *
	 * Keeps the mapping visible to anyone who wants it without making the
	 * newcomer read an event taxonomy to fill in one dropdown.
	 */
	private static function intent_reference(): string {
		$pairs = array();

		foreach ( Event_Map::events() as $intent => $event ) {
			$pairs[] = $event;
		}

		return sprintf(
			/* translators: %s: comma-separated list of Meta standard event names. */
			esc_html__( 'Sends one of Meta\'s standard events: %s.', 'bricks-meta-events' ),
			implode( ', ', $pairs )
		);
	}

	/**
	 * Site-level Meta status, rendered into the panel.
	 *
	 * Collapses to a single line when healthy. When advanced matching is off
	 * every hashed identifier is stripped before sending, which is invisible
	 * everywhere else — totals still look correct.
	 */
	private static function site_status(): string {
		if ( ! Host_Adapter::probes()['host_active']['ok'] ) {
			return esc_html__( 'Meta pixel for WordPress is not active. Nothing will be tracked.', 'bricks-meta-events' );
		}

		$pixel = Host_Adapter::pixel_id();

		if ( '' === $pixel ) {
			return esc_html__( 'No Meta pixel is configured yet. Add one under Settings → Meta.', 'bricks-meta-events' );
		}

		$settings = Host_Adapter::aam_settings();
		$matching = is_object( $settings )
			&& method_exists( $settings, 'getEnableAutomaticMatching' )
			&& $settings->getEnableAutomaticMatching();

		if ( ! $matching ) {
			return sprintf(
				/* translators: %s: pixel ID. */
				esc_html__( 'Pixel %s is connected, but advanced matching is OFF, so email and phone are stripped from every event before it is sent. Conversions will be recorded and will not be attributable. See Settings → Bricks Meta Events.', 'bricks-meta-events' ),
				$pixel
			);
		}

		return sprintf(
			/* translators: %s: pixel ID. */
			esc_html__( 'Pixel %s connected, advanced matching on.', 'bricks-meta-events' ),
			$pixel
		);
	}
}
