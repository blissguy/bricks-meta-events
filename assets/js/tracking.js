/**
 * Browser side tracking.
 *
 * Three jobs, all going through the same send path:
 *
 *  1. The browser half of a form conversion. Name, method and event ID all
 *     come from the server response. The client never decides any of them: a
 *     name that disagreed would produce two conversions in Meta rather than
 *     one deduplicated pair, and a client-minted ID would never match the one
 *     the Conversions API sent.
 *
 *  2. window.bmeTrack(), for anything the plugin cannot see. Bricks
 *     Interactions can call it directly.
 *
 *  3. Optional tel: and mailto: clicks. One delegated listener rather than
 *     per-element wiring, so it survives popups, query loops and anything
 *     loaded in later, and nothing needs baking into cached HTML.
 *
 * A click is not a confirmed enquiry, so 2 and 3 are browser only and carry
 * no customer details.
 */
( function () {
	'use strict';

	var config = window.bmeConfig || {};
	var SEEN_KEY = 'bmeSeenLinks';

	/**
	 * The Meta plugin's own sender, never fbq directly.
	 *
	 * It holds events while consent is revoked, queues those fired before the
	 * pixel has initialised, and ignores an event ID it has already seen.
	 * Calling fbq skips all three, and skipping the first is a consent bug.
	 */
	function send( name, params, eventId, method ) {
		var signal = window.FacebookSignal;

		if ( ! name || ! signal || typeof signal.trackEvent !== 'function' ) {
			return false;
		}

		signal.trackEvent( name, params || {}, null, method || 'track', eventId );

		return true;
	}

	function newEventId() {
		try {
			return window.crypto.randomUUID();
		} catch ( e ) {
			return 'bme-' + Date.now() + '-' + Math.random().toString( 16 ).slice( 2 );
		}
	}

	function methodFor( name ) {
		var standard = config.standard || [];

		return standard.indexOf( name ) === -1 ? 'trackCustom' : 'track';
	}

	/* --- 1. Form conversions ------------------------------------------- */

	document.addEventListener( 'bricks/form/success', function ( event ) {
		var detail = event && event.detail;
		var response = detail && detail.res;
		var payload = response && response.data && response.data.bme_event;

		if ( ! payload || ! payload.name || ! payload.event_id ) {
			return;
		}

		send( payload.name, payload.custom, payload.event_id, payload.method );
	} );

	/* --- 2. Public API -------------------------------------------------- */

	/**
	 * Record something Meta should know about.
	 *
	 * @param {string} name   Meta event name, standard or your own.
	 * @param {Object} params Optional details, for example { content_name: 'Brochure' }.
	 *
	 * @return {boolean} Whether it was sent.
	 */
	window.bmeTrack = function ( name, params ) {
		if ( ! config.enabled ) {
			return false;
		}

		return send( name, params, newEventId(), methodFor( name ) );
	};

	/* --- 3. Phone and email links --------------------------------------- */

	/**
	 * Whether this link has already been counted this visit.
	 *
	 * Someone tapping a phone number twice is one intention, not two. Storage
	 * can throw or be unavailable, in which case counting twice is better than
	 * not counting at all.
	 */
	function alreadyCounted( href ) {
		try {
			var seen = JSON.parse( window.sessionStorage.getItem( SEEN_KEY ) || '[]' );

			if ( seen.indexOf( href ) !== -1 ) {
				return true;
			}

			seen.push( href );
			window.sessionStorage.setItem( SEEN_KEY, JSON.stringify( seen ) );
		} catch ( e ) {}

		return false;
	}

	function describe( link, href ) {
		var text = ( link.textContent || '' ).replace( /\s+/g, ' ' ).trim();

		return text || href.replace( /^(tel:|mailto:)/, '' );
	}

	if ( config.enabled && config.trackLinks ) {
		// Capture phase and delegated from the document, so it still fires
		// when something else stops the event or replaces the markup.
		document.addEventListener(
			'click',
			function ( event ) {
				var target = event.target;

				if ( ! target || typeof target.closest !== 'function' ) {
					return;
				}

				var link = target.closest( 'a[href^="tel:"], a[href^="mailto:"]' );

				if ( ! link ) {
					return;
				}

				var href = link.getAttribute( 'href' ) || '';

				if ( ! href || alreadyCounted( href ) ) {
					return;
				}

				window.bmeTrack( config.contactEvent || 'Contact', {
					content_name: describe( link, href ),
				} );
			},
			true
		);
	}
}() );
