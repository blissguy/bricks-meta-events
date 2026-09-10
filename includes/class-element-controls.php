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
			'label' => esc_html__( 'Track clicks on this', 'bricks-meta-events' ),
			'type'  => 'checkbox',
		);

		$controls['bmeClickInfo'] = array(
			'tab'      => 'content',
			'group'    => self::GROUP,
			'type'     => 'info',
			'content'  => esc_html__( 'A click is not a confirmed enquiry the way a sent form is. Nothing checks that anything came of it, and no customer details go with it, so use this for things like a phone number or a booking link rather than in place of a form.', 'bricks-meta-events' ),
			'required' => $enabled,
		);

		$controls['bmeIntent'] = array(
			'tab'         => 'content',
			'group'       => self::GROUP,
			'label'       => esc_html__( 'What does clicking this mean?', 'bricks-meta-events' ),
			'type'        => 'select',
			'options'     => Event_Map::click_options(),
			'default'     => Event_Map::DEFAULT_CLICK_INTENT,
			'clearable'   => false,
			'description' => esc_html__( 'The name in brackets is what you will see in Meta Events Manager.', 'bricks-meta-events' ),
			'required'    => $enabled,
		);

		$controls['bmeCustomName'] = array(
			'tab'            => 'content',
			'group'          => self::GROUP,
			'label'          => esc_html__( 'Your event name', 'bricks-meta-events' ),
			'type'           => 'text',
			'hasDynamicData' => false,
			'placeholder'    => 'BrochureOpened',
			'info'           => esc_html__( 'Meta cannot use your own names for most ad goals until they build up plenty of activity. Pick one of the standard options above unless you are sure.', 'bricks-meta-events' ),
			'required'       => array( $enabled, array( 'bmeIntent', '=', Event_Map::CUSTOM_INTENT ) ),
		);

		$controls['bmeLabel'] = array(
			'tab'            => 'content',
			'group'          => self::GROUP,
			'label'          => esc_html__( 'Name in Events Manager', 'bricks-meta-events' ),
			'type'           => 'text',
			'hasDynamicData' => false,
			'placeholder'    => esc_html__( 'Auto', 'bricks-meta-events' ),
			'description'    => esc_html__( 'Left on Auto, the text on the button or link is used.', 'bricks-meta-events' ),
			'required'       => $enabled,
		);

		$controls['bmeValue'] = array(
			'tab'            => 'content',
			'group'          => self::GROUP,
			'label'          => esc_html__( 'What one of these is worth', 'bricks-meta-events' ),
			'type'           => 'number',
			'min'            => 0,
			'hasDynamicData' => false,
			'description'    => esc_html__( 'Leave it blank if you do not know, because a wrong figure is worse than none.', 'bricks-meta-events' ),
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
			'label'       => esc_html__( 'What does sending this form mean?', 'bricks-meta-events' ),
			'type'        => 'select',
			'options'     => Event_Map::options(),
			'default'     => Settings::default_intent(),
			'clearable'   => false,
			'description' => esc_html__( 'The name in brackets is what you will see in Meta Events Manager.', 'bricks-meta-events' ),
			'required'    => $enabled,
		);

		$controls['bmeCustomName'] = array(
			'tab'            => 'content',
			'group'          => self::GROUP,
			'label'          => esc_html__( 'Your event name', 'bricks-meta-events' ),
			'type'           => 'text',
			'hasDynamicData' => false,
			'placeholder'    => 'BrochureRequest',
			'info'           => esc_html__( 'Meta cannot use your own names for most ad goals until they build up plenty of activity. Pick one of the standard options above unless you are sure.', 'bricks-meta-events' ),
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

		$controls['bmeValue'] = array(
			'tab'            => 'content',
			'group'          => self::GROUP,
			'label'          => esc_html__( 'What one of these is worth', 'bricks-meta-events' ),
			'type'           => 'number',
			'min'            => 0,
			'hasDynamicData' => false,
			'description'    => esc_html__( 'Meta uses this to work out what your ads earn you. Leave it blank if you do not know, because a wrong figure is worse than none.', 'bricks-meta-events' ),
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
			'label'       => esc_html__( 'How to send it', 'bricks-meta-events' ),
			'type'        => 'select',
			'options'     => array(
				Form_Tracker::MODE_AUTO    => esc_html__( 'Automatic', 'bricks-meta-events' ),
				Form_Tracker::MODE_BOTH    => esc_html__( 'From your site and the visitor\'s browser', 'bricks-meta-events' ),
				Form_Tracker::MODE_SERVER  => esc_html__( 'From your site only', 'bricks-meta-events' ),
				Form_Tracker::MODE_BROWSER => esc_html__( 'From the visitor\'s browser only', 'bricks-meta-events' ),
			),
			'default'     => Form_Tracker::MODE_AUTO,
			'clearable'   => false,
			'description' => esc_html__( 'Leave this on Automatic: it sends both when it can, your site only when the form redirects, and the visitor\'s browser only when your site cannot reach Meta. When both go out they share one ID, so Meta counts one enquiry rather than two.', 'bricks-meta-events' ),
			'required'    => $enabled,
		);

		$controls['bmeFieldsSeparator'] = array(
			'tab'      => 'content',
			'group'    => self::GROUP,
			'label'    => esc_html__( 'Matching the customer', 'bricks-meta-events' ),
			'type'     => 'separator',
			'required' => $enabled,
		);

		$controls['bmeFieldsInfo'] = array(
			'tab'      => 'content',
			'group'    => self::GROUP,
			'type'     => 'info',
			'content'  => esc_html__( 'Email and phone are picked up automatically from your field types, and a logged in visitor is matched by their account. Only fill these in when the wrong field is being picked up. Paste the field\'s ID copied from each field in your form.', 'bricks-meta-events' ),
			'required' => $enabled,
		);

		foreach ( self::override_controls() as $key => $label ) {
			$controls[ $key ] = array(
				'tab'            => 'content',
				'group'          => self::GROUP,
				'label'          => $label,
				'type'           => 'text',
				'hasDynamicData' => false,
				'placeholder'    => esc_html__( 'Picked up automatically', 'bricks-meta-events' ),
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
		if ( ! Host_Adapter::probes()['host_active']['ok'] ) {
			return esc_html__( 'Meta pixel for WordPress is switched off, so nothing can be tracked.', 'bricks-meta-events' );
		}

		$pixel = Host_Adapter::pixel_id();

		if ( '' === $pixel ) {
			return esc_html__( 'No Meta pixel is set up yet. Add one under Settings, Meta.', 'bricks-meta-events' );
		}

		$settings = Host_Adapter::aam_settings();
		$matching = is_object( $settings )
			&& method_exists( $settings, 'getEnableAutomaticMatching' )
			&& $settings->getEnableAutomaticMatching();

		if ( ! $matching ) {
			return sprintf(
				/* translators: %s: Meta pixel ID. */
				esc_html__( 'Pixel %s is connected, but customer matching is switched off. Email and phone are removed before anything is sent, so Meta will count the enquiry but cannot tell who made it. See Settings, Bricks Meta Events.', 'bricks-meta-events' ),
				$pixel
			);
		}

		return sprintf(
			/* translators: %s: Meta pixel ID. */
			esc_html__( 'Pixel %s connected, and customers are being matched.', 'bricks-meta-events' ),
			$pixel
		);
	}
}
