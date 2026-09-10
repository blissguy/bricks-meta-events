<?php
/**
 * A rolling record of the conversions this plugin has sent.
 *
 * Sending is otherwise invisible: a conversion that never arrives looks
 * exactly like one that did. Keeping the last few dozen makes it possible to
 * answer "did that submission actually go" without guessing, and to see which
 * customer details survived on each one.
 *
 * @package BricksMetaEvents
 */

namespace BricksMetaEvents;

defined( 'ABSPATH' ) || exit;

/**
 * Newest-first ring buffer of sent conversions.
 */
class Event_Log {

	/**
	 * How many conversions to keep.
	 *
	 * Enough to cover a testing session and a quiet day, small enough that the
	 * whole log stays a single modest option.
	 */
	private const LIMIT = 50;

	/**
	 * Add a conversion to the front of the log.
	 *
	 * @param array $entry Conversion record.
	 */
	public static function record( array $entry ): void {
		$log = self::all();

		array_unshift( $log, $entry );

		update_option( Plugin::OPTION_EVENT_LOG, array_slice( $log, 0, self::LIMIT ), false );
	}

	/**
	 * Every conversion held, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$log = get_option( Plugin::OPTION_EVENT_LOG );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * The most recent conversion, or null when nothing has been sent.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function latest() {
		return self::all()[0] ?? null;
	}

	/**
	 * Mark a conversion as having actually gone out.
	 *
	 * Called from the background request, which is the only place that knows
	 * the send happened at all.
	 *
	 * @param string $event_id Meta event ID.
	 *
	 * @return bool Whether a matching conversion was found.
	 */
	public static function confirm( string $event_id ): bool {
		if ( '' === $event_id ) {
			return false;
		}

		$log = self::all();

		foreach ( $log as $index => $entry ) {
			if ( ( $entry['event_id'] ?? '' ) !== $event_id || ! empty( $entry['delivered_at'] ) ) {
				continue;
			}

			$log[ $index ]['delivered_at'] = time();
			update_option( Plugin::OPTION_EVENT_LOG, $log, false );

			return true;
		}

		return false;
	}

	/**
	 * Empty the log.
	 */
	public static function clear(): void {
		delete_option( Plugin::OPTION_EVENT_LOG );
	}
}
