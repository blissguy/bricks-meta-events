<?php
/**
 * Works out the human-readable name for a form's conversions.
 *
 * The result becomes content_name, which is a breakdown dimension in Events
 * Manager. That makes cardinality discipline more important than richness: a
 * label that varies per post turns one useful row into hundreds of useless
 * ones, so anything containing unresolved dynamic data is rejected rather
 * than rendered.
 *
 * @package BricksMetaEvents
 */

namespace BricksMetaEvents;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a stable, readable label for a Bricks form.
 */
class Label_Resolver {

	private const MAX_LENGTH = 100;

	/**
	 * Resolve the label, falling through until something usable is found.
	 *
	 * @param object $form Bricks form object from bricks/form/response.
	 */
	public static function resolve( $form ): string {
		$settings = (array) $form->get_settings();

		foreach ( self::candidates( $form, $settings ) as $candidate ) {
			$candidate = trim( (string) $candidate );

			if ( '' === $candidate ) {
				continue;
			}

			// Unresolved dynamic data would explode the cardinality of the
			// Events Manager breakdown. Skip rather than render.
			if ( str_contains( $candidate, '{' ) ) {
				continue;
			}

			return self::clean( $candidate );
		}

		return self::clean( 'Bricks form ' . $form->get_id() );
	}

	/**
	 * Label sources, best first.
	 *
	 * @param object $form     Bricks form object.
	 * @param array  $settings Element settings.
	 *
	 * @return array<int, string>
	 */
	private static function candidates( $form, array $settings ): array {
		$element = self::element_data( $form );

		$candidates = array(
			// Explicitly set by the author for this purpose.
			(string) ( $settings['bmeLabel'] ?? '' ),
			// Bricks' own form name, when submissions storage is enabled.
			(string) ( $settings['submissionFormName'] ?? '' ),
			// The name the author gave the element in the structure panel.
			(string) ( $element['element']['label'] ?? '' ),
			// Usually well-authored: "Get the guide", "Book a call".
			(string) ( $settings['submitButtonText'] ?? '' ),
		);

		// For a form living in a popup or header template, the template's own
		// title describes it better than whichever page it appeared on.
		$source_id = (int) ( $element['source_id'] ?? 0 );

		if ( $source_id > 0 && $source_id !== (int) $form->get_post_id() ) {
			$candidates[] = (string) get_the_title( $source_id );
		}

		$candidates[] = (string) get_the_title( (int) $form->get_post_id() );

		return $candidates;
	}

	/**
	 * Look up the element's own data, including the template it came from.
	 *
	 * Helpers::get_element_data() understands the {elementId}-{instanceId}
	 * form that get_id() returns inside a component, so component instances
	 * need no special handling here.
	 *
	 * @param object $form Bricks form object.
	 *
	 * @return array<string, mixed>
	 */
	private static function element_data( $form ): array {
		if ( ! is_callable( array( '\\Bricks\\Helpers', 'get_element_data' ) ) ) {
			return array();
		}

		$data = \Bricks\Helpers::get_element_data( (int) $form->get_post_id(), $form->get_id() );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Strip markup and clamp to a sane breakdown-dimension length.
	 */
	private static function clean( string $label ): string {
		$label = wp_strip_all_tags( $label );
		$label = preg_replace( '/\s+/', ' ', $label );

		return trim( mb_substr( (string) $label, 0, self::MAX_LENGTH ) );
	}
}
