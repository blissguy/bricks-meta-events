<?php
/**
 * Mapping between plain-language intent and Meta's event taxonomy.
 *
 * Meta's standard event list is closed, which is what lets this plugin ask
 * "what does this form mean?" instead of asking for an event name. The author
 * picks an outcome; the event name is derived.
 *
 * @package BricksMetaEvents
 */

namespace BricksMetaEvents;

defined( 'ABSPATH' ) || exit;

/**
 * Intent vocabulary and Meta standard-event facts.
 */
class Event_Map {

	public const DEFAULT_INTENT = 'lead';
	public const CUSTOM_INTENT  = 'custom';

	/**
	 * Intent key => Meta standard event name.
	 *
	 * Ordered by how often a Bricks form means each thing.
	 *
	 * @return array<string, string>
	 */
	public static function events(): array {
		return array(
			'lead'                 => 'Lead',
			'contact'              => 'Contact',
			'completeRegistration' => 'CompleteRegistration',
			'schedule'             => 'Schedule',
			'submitApplication'    => 'SubmitApplication',
			'subscribe'            => 'Subscribe',
			'startTrial'           => 'StartTrial',
		);
	}

	/**
	 * Intent key => the sentence shown in the builder.
	 *
	 * Phrased as what the visitor did, not as an event name. The resulting
	 * event is shown separately so the mapping stays visible to anyone who
	 * cares what actually fires.
	 *
	 * @return array<string, string>
	 */
	public static function options(): array {
		return array(
			'lead'                 => __( 'They become a lead (Lead)', 'bricks-meta-events' ),
			'contact'              => __( 'They get in touch (Contact)', 'bricks-meta-events' ),
			'completeRegistration' => __( 'They create an account (CompleteRegistration)', 'bricks-meta-events' ),
			'schedule'             => __( 'They book or arrange something (Schedule)', 'bricks-meta-events' ),
			'submitApplication'    => __( 'They apply for something (SubmitApplication)', 'bricks-meta-events' ),
			'subscribe'            => __( 'They start a paid plan (Subscribe)', 'bricks-meta-events' ),
			'startTrial'           => __( 'They start a free trial (StartTrial)', 'bricks-meta-events' ),
			self::CUSTOM_INTENT    => __( 'Something else', 'bricks-meta-events' ),
		);
	}

	/**
	 * Resolve a form's settings to the event name that should fire.
	 *
	 * @param array $settings Bricks element settings.
	 */
	public static function resolve( array $settings ): string {
		$intent = $settings['bmeIntent'] ?? self::DEFAULT_INTENT;

		if ( self::CUSTOM_INTENT === $intent ) {
			$custom = trim( (string) ( $settings['bmeCustomName'] ?? '' ) );

			return '' !== $custom ? $custom : '';
		}

		return self::events()[ $intent ] ?? self::events()[ self::DEFAULT_INTENT ];
	}

	/**
	 * Meta's 17 standard events.
	 *
	 * Determines whether the browser call is fbq('track') or
	 * fbq('trackCustom'), which the server decides so the client can never
	 * disagree with it.
	 *
	 * @return array<int, string>
	 */
	public static function standard_events(): array {
		return array(
			'AddPaymentInfo',
			'AddToCart',
			'AddToWishlist',
			'CompleteRegistration',
			'Contact',
			'CustomizeProduct',
			'Donate',
			'FindLocation',
			'InitiateCheckout',
			'Lead',
			'Purchase',
			'Schedule',
			'Search',
			'StartTrial',
			'SubmitApplication',
			'Subscribe',
			'ViewContent',
		);
	}

	/**
	 * Whether an event name is one Meta recognises as standard.
	 */
	public static function is_standard( string $event ): bool {
		return in_array( $event, self::standard_events(), true );
	}
}
