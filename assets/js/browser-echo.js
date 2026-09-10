/**
 * Fires the browser half of a Bricks form conversion.
 *
 * The event name, method and event ID all come from the server response. The
 * client never decides any of them: a divergent name would produce two
 * conversions in Meta rather than one deduplicated pair, and a client-minted
 * ID would never match the one the Conversions API sent.
 */
( function () {
	'use strict';

	document.addEventListener( 'bricks/form/success', function ( event ) {
		var detail = event && event.detail;
		var response = detail && detail.res;
		var payload = response && response.data && response.data.bme_event;

		if ( ! payload || ! payload.name || ! payload.event_id ) {
			return;
		}

		var signal = window.FacebookSignal;

		// Deliberately not fbq(). FacebookSignal holds events while consent is
		// revoked, queues those fired before fbq('init') has run, and dedupes
		// against event IDs it has already seen. Calling fbq directly skips
		// all three, and skipping the first is a consent bug.
		if ( ! signal || typeof signal.trackEvent !== 'function' ) {
			return;
		}

		signal.trackEvent(
			payload.name,
			payload.custom || {},
			// Advanced matching: accepted by the signature, never read. The
			// server event carries the hashed identifiers.
			null,
			payload.method || 'track',
			payload.event_id
		);
	} );
}() );
