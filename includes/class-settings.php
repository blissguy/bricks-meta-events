<?php
/**
 * Site-wide defaults.
 *
 * Everything here can be overridden on an individual form. These exist so a
 * site with thirty forms does not need the same decision made thirty times,
 * and so the two settings that are genuinely site-wide, who to skip and
 * whether to send customer details at all, live in one place.
 *
 * @package BricksMetaEvents
 */

namespace BricksMetaEvents;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's own settings.
 */
class Settings {

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( Plugin::OPTION_SETTINGS );

		return array_merge(
			array(
				'default_intent'      => Event_Map::DEFAULT_INTENT,
				'currency'            => '',
				'excluded_roles'      => array(),
				'never_send_details'  => false,
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	/**
	 * The outcome a newly tracked form starts on.
	 */
	public static function default_intent(): string {
		$intent = (string) self::all()['default_intent'];

		return isset( Event_Map::options()[ $intent ] ) ? $intent : Event_Map::DEFAULT_INTENT;
	}

	/**
	 * The currency to use when a form sets a value but no currency.
	 *
	 * Falls back to the store's own setting, then to US dollars, because a
	 * value with the wrong currency is worse than no value at all.
	 */
	public static function default_currency(): string {
		$chosen = trim( (string) self::all()['currency'] );

		if ( '' !== $chosen ) {
			return strtoupper( $chosen );
		}

		$woo = get_option( 'woocommerce_currency' );

		return is_string( $woo ) && '' !== $woo ? strtoupper( $woo ) : 'USD';
	}

	/**
	 * Roles whose submissions are not tracked at all.
	 *
	 * @return array<int, string>
	 */
	public static function excluded_roles(): array {
		$roles = self::all()['excluded_roles'];

		return is_array( $roles ) ? array_values( array_filter( array_map( 'strval', $roles ) ) ) : array();
	}

	/**
	 * Whether the signed in visitor is one of the excluded roles.
	 */
	public static function current_user_excluded(): bool {
		$excluded = self::excluded_roles();

		if ( empty( $excluded ) || ! is_user_logged_in() ) {
			return false;
		}

		$user = wp_get_current_user();

		return ! empty( array_intersect( (array) $user->roles, $excluded ) );
	}

	/**
	 * Whether to strip every customer detail before sending, everywhere.
	 */
	public static function never_send_details(): bool {
		return ! empty( self::all()['never_send_details'] );
	}

	/**
	 * Save submitted settings.
	 *
	 * @param array $raw Raw request data.
	 */
	public static function save( array $raw ): void {
		$roles = isset( $raw['excluded_roles'] ) && is_array( $raw['excluded_roles'] )
			? array_map( 'sanitize_key', $raw['excluded_roles'] )
			: array();

		$intent = isset( $raw['default_intent'] ) ? sanitize_text_field( $raw['default_intent'] ) : '';

		update_option(
			Plugin::OPTION_SETTINGS,
			array(
				'default_intent'     => isset( Event_Map::options()[ $intent ] ) ? $intent : Event_Map::DEFAULT_INTENT,
				'currency'           => isset( $raw['currency'] ) ? strtoupper( sanitize_text_field( $raw['currency'] ) ) : '',
				'excluded_roles'     => array_values( array_intersect( $roles, array_keys( wp_roles()->roles ) ) ),
				'never_send_details' => ! empty( $raw['never_send_details'] ),
			),
			false
		);
	}
}
