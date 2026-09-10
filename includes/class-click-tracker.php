<?php
/**
 * Marks up buttons and links that should be tracked when clicked.
 *
 * A click has no server round trip, so unlike a form there is nowhere else to
 * put the settings: they have to travel with the element. They go on the
 * element's own root attribute, and a single delegated listener reads them,
 * rather than a script per element. That keeps it working inside popups,
 * query loops and anything else rendered later.
 *
 * The event name and label are resolved here rather than in the browser. The
 * label especially: reading the visible text at click time would turn a
 * button that says "Download {post_title}" into a separate entry for every
 * post, which is the same reason forms resolve their label on the server.
 *
 * @package BricksMetaEvents
 */

namespace BricksMetaEvents;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the tracking attribute to clickable elements.
 */
class Click_Tracker {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_filter( 'bricks/element/set_root_attributes', array( self::class, 'add_attributes' ), 10, 2 );
	}

	/**
	 * Attach the tracking config to a tracked element.
	 *
	 * @param array  $attributes Root attributes.
	 * @param object $element    Bricks element instance.
	 *
	 * @return array
	 */
	public static function add_attributes( $attributes, $element ) {
		if ( ! is_array( $attributes ) || ! is_object( $element ) ) {
			return $attributes;
		}

		if ( ! in_array( (string) ( $element->name ?? '' ), Element_Controls::CLICK_ELEMENTS, true ) ) {
			return $attributes;
		}

		$settings = (array) ( $element->settings ?? array() );

		if ( empty( $settings['bmeEnabled'] ) ) {
			return $attributes;
		}

		$event = Event_Map::resolve_click( $settings );

		if ( '' === $event ) {
			return $attributes;
		}

		$config = array(
			'name'   => $event,
			'method' => Event_Map::is_standard( $event ) ? 'track' : 'trackCustom',
			// Used to count one click per element per visit. The base ID stays
			// the same across every instance of a component.
			'id'     => self::base_id( (string) ( $element->id ?? '' ) ),
			// Cast so an empty set encodes as {} rather than [].
			'custom' => (object) self::custom_data( $settings ),
		);

		$attributes['data-bme'] = wp_json_encode( $config );

		return $attributes;
	}

	/**
	 * The details sent alongside the event.
	 *
	 * @param array $settings Element settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function custom_data( array $settings ): array {
		$custom = array( 'content_name' => self::label( $settings ) );

		$value = isset( $settings['bmeValue'] ) ? (float) $settings['bmeValue'] : 0.0;

		if ( $value > 0 ) {
			$currency = trim( (string) ( $settings['bmeCurrency'] ?? '' ) );

			$custom['value']    = $value;
			$custom['currency'] = '' !== $currency ? strtoupper( $currency ) : Settings::default_currency();
		}

		return array_filter(
			$custom,
			static fn( $item ) => '' !== $item && null !== $item
		);
	}

	/**
	 * What this click is called in Events Manager.
	 *
	 * Falls back to the button or link text as authored. Text containing
	 * unresolved dynamic data is skipped, because Meta groups by this name and
	 * one that differs per page produces hundreds of unusable rows.
	 *
	 * @param array $settings Element settings.
	 */
	private static function label( array $settings ): string {
		foreach ( array( $settings['bmeLabel'] ?? '', $settings['text'] ?? '' ) as $candidate ) {
			$candidate = trim( wp_strip_all_tags( (string) $candidate ) );

			if ( '' === $candidate || str_contains( $candidate, '{' ) ) {
				continue;
			}

			return mb_substr( $candidate, 0, 100 );
		}

		return '';
	}

	/**
	 * Strip the component instance suffix from an element ID.
	 */
	private static function base_id( string $id ): string {
		$dash = strpos( $id, '-' );

		return false === $dash ? $id : substr( $id, 0, $dash );
	}
}
