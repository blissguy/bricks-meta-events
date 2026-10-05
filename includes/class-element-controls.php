<?php
/**
 * The "Meta tracking" panel on the Bricks form element.
 *
 * Meta's event list is fixed, so this panel asks what the form means and
 * derives the event, rather than asking anyone to type an event name. The
 * event each option produces is shown in brackets beside it, the same
 * convention Bricks uses for its own dynamic data tags.
 *
 * Bricks controls are declared per element type and rendered client side, so
 * nothing here can react to the form being edited. An info block can only
 * describe the site, which is why the connection warning below matters: it is
 * the one piece of state the panel can show, and it is the one that quietly
 * ruins the data.
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
	 * Elements whose clicks can be tracked.
	 *
	 * Both are needed rather than just links: a link styled as a button and a
	 * real button look identical to a visitor, and a Bricks button with no
	 * link renders as a button element, so neither can be inferred from the
	 * other.
	 */
	public const CLICK_ELEMENTS = array( 'button', 'text-link' );

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_filter( 'bricks/elements/form/control_groups', array( self::class, 'add_group' ) );
		add_filter( 'bricks/elements/form/controls', array( self::class, 'add_controls' ) );

		foreach ( self::CLICK_ELEMENTS as $element ) {
			add_filter( "bricks/elements/{$element}/control_groups", array( self::class, 'add_group' ) );
			add_filter( "bricks/elements/{$element}/controls", array( self::class, 'add_click_controls' ) );
		}
	}

	/**
	 * Add the controls for a clickable element.
	 *
	 * Deliberately a shorter panel than a form's. There is no customer to
	 * match on a click and nothing for the server to verify, so the settings
	 * that only make sense with a submission are not offered.
	 *
	 * @param array $controls Existing controls.
	 *
	 * @return array
	 */
	public static function add_click_controls( $controls ) {
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
			'label' => esc_html__( 'Track clicks', 'bricks-meta-events' ),
			'type'  => 'checkbox',
		);

		$controls['bmeClickInfo'] = array(
			'tab'      => 'content',
			'group'    => self::GROUP,
			'type'     => 'info',
			'content'  => esc_html__( 'A click isn\'t a confirmed enquiry and carries no customer details. Use it for things like a phone number or booking link, not in place of a form.', 'bricks-meta-events' ),
			'required' => $enabled,
		);

		$controls['bmeIntent'] = array(
			'tab'         => 'content',
			'group'       => self::GROUP,
			'label'       => esc_html__( 'Event', 'bricks-meta-events' ),
			'type'        => 'select',
			'options'     => Event_Map::click_options(),
			'default'     => Event_Map::DEFAULT_CLICK_INTENT,
			// Bricks shows "Default" until an option is picked; show the one
			// that will actually be sent instead.
			'placeholder' => Event_Map::click_options()[ Event_Map::DEFAULT_CLICK_INTENT ],
			'clearable'   => false,
			'description' => esc_html__( 'The name in brackets is what Events Manager shows.', 'bricks-meta-events' ),
			'required'    => $enabled,
		);

		$controls['bmeCustomName'] = array(
			'tab'            => 'content',
			'group'          => self::GROUP,
			'label'          => esc_html__( 'Event name', 'bricks-meta-events' ),
			'type'           => 'text',
			'hasDynamicData' => false,
			'placeholder'    => 'BrochureOpened',
			'info'           => esc_html__( 'Most ad goals can\'t use your own event until it has built up lots of activity. Pick a standard event unless you\'re sure.', 'bricks-meta-events' ),
			'required'       => array( $enabled, array( 'bmeIntent', '=', Event_Map::CUSTOM_INTENT ) ),
		);

		$controls['bmeLabel'] = array(
			'tab'            => 'content',
			'group'          => self::GROUP,
			'label'          => esc_html__( 'Name in Events Manager', 'bricks-meta-events' ),
			'type'           => 'text',
			'hasDynamicData' => false,
			'placeholder'    => esc_html__( 'Automatic', 'bricks-meta-events' ),
			'description'    => esc_html__( 'Leave blank to use the button or link text.', 'bricks-meta-events' ),
			'required'       => $enabled,
		);

		$controls['bmeValue'] = array(
			'tab'            => 'content',
			'group'          => self::GROUP,
			'label'          => esc_html__( 'Value', 'bricks-meta-events' ),
			'type'           => 'number',
			'min'            => 0,
			'hasDynamicData' => false,
			'description'    => esc_html__( 'What one click is worth to you. Leave blank if you\'re not sure: a wrong figure is worse than none.', 'bricks-meta-events' ),
			'required'       => $enabled,
		);

		$controls['bmeCurrency'] = array(
			'tab'            => 'content',
			'group'          => self::GROUP,
			'label'          => esc_html__( 'Currency', 'bricks-meta-events' ),
			'type'           => 'text',
			'hasDynamicData' => false,
			'placeholder'    => Settings::default_currency(),
			'required'       => array( $enabled, array( 'bmeValue', '!=', '' ) ),
		);

		return $controls;
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
			'label'       => esc_html__( 'Event', 'bricks-meta-events' ),
			'type'        => 'select',
			'options'     => Event_Map::options(),
			'default'     => Settings::default_intent(),
			// Bricks shows "Default" until an option is picked; show the one
			// that will actually be sent instead.
			'placeholder' => Event_Map::options()[ Settings::default_intent() ],
			'clearable'   => false,
			'description' => esc_html__( 'The name in brackets is what Events Manager shows.', 'bricks-meta-events' ),
			'required'    => $enabled,
		);

		$controls['bmeCustomName'] = array(
			'tab'            => 'content',
			'group'          => self::GROUP,
			'label'          => esc_html__( 'Event name', 'bricks-meta-events' ),
			'type'           => 'text',
			'hasDynamicData' => false,
			'placeholder'    => 'BrochureRequest',
			'info'           => esc_html__( 'Most ad goals can\'t use your own event until it has built up lots of activity. Pick a standard event unless you\'re sure.', 'bricks-meta-events' ),
			'required'       => array( $enabled, array( 'bmeIntent', '=', Event_Map::CUSTOM_INTENT ) ),
		);

		$controls['bmeLabel'] = array(
			'tab'            => 'content',
			'group'          => self::GROUP,
			'label'          => esc_html__( 'Name in Events Manager', 'bricks-meta-events' ),
			'type'           => 'text',
			'hasDynamicData' => false,
			'placeholder'    => esc_html__( 'Automatic', 'bricks-meta-events' ),
			'description'    => esc_html__( 'Leave blank to name it after the form automatically.', 'bricks-meta-events' ),
			'required'       => $enabled,
		);

		$controls['bmeValue'] = array(
			'tab'            => 'content',
			'group'          => self::GROUP,
			'label'          => esc_html__( 'Value', 'bricks-meta-events' ),
			'type'           => 'number',
			'min'            => 0,
			'hasDynamicData' => false,
			'description'    => esc_html__( 'What one submission is worth to you, so Meta can work out what your ads earn. Leave blank if you\'re not sure: a wrong figure is worse than none.', 'bricks-meta-events' ),
			'required'       => $enabled,
		);

		$controls['bmeCurrency'] = array(
			'tab'            => 'content',
			'group'          => self::GROUP,
			'label'          => esc_html__( 'Currency', 'bricks-meta-events' ),
			'type'           => 'text',
			'hasDynamicData' => false,
			'placeholder'    => self::default_currency(),
			'required'       => array( $enabled, array( 'bmeValue', '!=', '' ) ),
		);

		$controls['bmeSendMode'] = array(
			'tab'         => 'content',
			'group'       => self::GROUP,
			'label'       => esc_html__( 'Sending', 'bricks-meta-events' ),
			'type'        => 'select',
			'options'     => array(
				Form_Tracker::MODE_AUTO    => esc_html__( 'Automatic', 'bricks-meta-events' ),
				Form_Tracker::MODE_BOTH    => esc_html__( 'From your site and the visitor\'s browser', 'bricks-meta-events' ),
				Form_Tracker::MODE_SERVER  => esc_html__( 'From your site only', 'bricks-meta-events' ),
				Form_Tracker::MODE_BROWSER => esc_html__( 'From the visitor\'s browser only', 'bricks-meta-events' ),
			),
			'default'     => Form_Tracker::MODE_AUTO,
			// Without this Bricks shows "Default", while the description
			// tells people to leave it on Automatic.
			'placeholder' => esc_html__( 'Automatic', 'bricks-meta-events' ),
			'clearable'   => false,
			'description' => esc_html__( 'Leave on Automatic: it handles forms that redirect, and never counts one enquiry twice. Change it only when testing.', 'bricks-meta-events' ),
			'required'    => $enabled,
		);

		$controls['bmeFieldsSeparator'] = array(
			'tab'      => 'content',
			'group'    => self::GROUP,
			'label'    => esc_html__( 'Customer details', 'bricks-meta-events' ),
			'type'     => 'separator',
			'required' => $enabled,
		);

		$controls['bmeFieldsInfo'] = array(
			'tab'      => 'content',
			'group'    => self::GROUP,
			'type'     => 'info',
			'content'  => esc_html__( 'Email, phone and name are found automatically. Fill these in only if the wrong field is picked up, using the ID shown on each form field.', 'bricks-meta-events' ),
			'required' => $enabled,
		);

		foreach ( self::override_controls() as $key => $label ) {
			$controls[ $key ] = array(
				'tab'            => 'content',
				'group'          => self::GROUP,
				'label'          => $label,
				'type'           => 'text',
				'hasDynamicData' => false,
				'placeholder'    => esc_html__( 'Automatic', 'bricks-meta-events' ),
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
	 * The currency to suggest, preferring the store's own setting.
	 */
	public static function default_currency(): string {
		return Settings::default_currency();
	}

	/**
	 * Meta connection status, rendered into the panel.
	 *
	 * Collapses to a single line when all is well. When matching is switched
	 * off every identifier is removed before sending, which is invisible
	 * everywhere else because the totals still look right.
	 */
	private static function site_status(): string {
		if ( '' === Host::current() ) {
			return esc_html__( 'Nothing can be tracked until Meta for WooCommerce or Meta pixel for WordPress is switched on.', 'bricks-meta-events' );
		}

		$pixel = Host::pixel_id();

		if ( '' === $pixel ) {
			return sprintf(
				/* translators: %s: admin menu location. */
				esc_html__( 'No Meta pixel is set up yet. Add one under %s.', 'bricks-meta-events' ),
				esc_html( Host::settings_location() )
			);
		}

		// Meta for WooCommerce has no matching switch to strip details, so
		// the only thing worth saying is whether the server half can send.
		if ( Host::is_woo() ) {
			if ( ! Host::capi_available() ) {
				return sprintf(
					/* translators: %s: Meta pixel ID. */
					esc_html__( 'Pixel %s isn\'t connected to Meta, so forms are sent from the browser only. See Settings, Bricks Meta Events.', 'bricks-meta-events' ),
					$pixel
				);
			}

			return sprintf(
				/* translators: %s: Meta pixel ID. */
				esc_html__( 'Pixel %s connected through Meta for WooCommerce.', 'bricks-meta-events' ),
				$pixel
			);
		}

		$settings = Host_Adapter::aam_settings();
		$matching = is_object( $settings )
			&& method_exists( $settings, 'getEnableAutomaticMatching' )
			&& $settings->getEnableAutomaticMatching();

		if ( ! $matching ) {
			return sprintf(
				/* translators: %s: Meta pixel ID. */
				esc_html__( 'Pixel %s connected, but advanced matching is off, so Meta can\'t tell who sent each conversion. See Settings, Bricks Meta Events.', 'bricks-meta-events' ),
				$pixel
			);
		}

		return sprintf(
			/* translators: %s: Meta pixel ID. */
			esc_html__( 'Pixel %s connected through Meta pixel for WordPress.', 'bricks-meta-events' ),
			$pixel
		);
	}
}
