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
 *  3. Clicks. Buttons and links configured in the builder carry their
 *     settings in a data-bme attribute, and optionally every tel: and mailto:
 *     link is swept up as well. Both go through one delegated listener rather
 *     than a script per element, so they survive popups, query loops and
 *     anything rendered later. A configured element wins over the sweep, so a
 *     configured phone link fires once, not twice.
 *
 * A click is not a confirmed enquiry, so 2 and 3 are browser only and carry
 * no customer details.
 */
( function () {
	'use strict';

	var config = window.bmeConfig || {};
	var SEEN_KEY = 'bmeSeenLinks';

	/**
	 * Say what happened, while test mode is on.
	 *
	 * A click leaves no record anywhere on the server, so during setup this is
	 * the only way to tell a button that fired from one that quietly did not.
	 */
	function note( message, detail ) {
		if ( ! config.debug || ! window.console ) {
			return;
		}

		if ( detail ) {
			window.console.info( '[Meta tracking] ' + message, detail );
		} else {
			window.console.info( '[Meta tracking] ' + message );
		}
	}

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

		note( 'sent ' + name, { method: method || 'track', eventId: eventId, details: params || {} } );

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
			note( blockedReason( name ) );

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

	function describe( element, href ) {
		var text = ( element.textContent || '' ).replace( /\s+/g, ' ' ).trim();

		return text || String( href ).replace( /^(tel:|mailto:)/, '' );
	}

	/**
	 * One listener for every tracked click.
	 *
	 * An element configured in the builder wins outright: if it also happens
	 * to be a phone link, the sweep below must not fire a second event for the
	 * same click.
	 */
	/**
	 * Why nothing was sent, in words the reader can act on.
	 */
	function blockedReason( name ) {
		if ( config.staff ) {
			return 'did not send ' + name + ' because you are signed in as staff. Meta pixel for WordPress ignores anyone who can edit posts or upload files. Try again in a private window.';
		}

		return 'did not send ' + name + ' because tracking is switched off for this visitor.';
	}

	function onClick( event ) {
		var target = event.target;

		if ( ! target || typeof target.closest !== 'function' ) {
			return;
		}

		var configured = target.closest( '[data-bme]' );

		if ( configured ) {
			fireConfigured( configured );

			return;
		}

		if ( config.trackLinks ) {
			fireLink( target.closest( 'a[href^="tel:"], a[href^="mailto:"]' ) );
		}
	}

	function fireConfigured( element ) {
		var payload;

		try {
			payload = JSON.parse( element.getAttribute( 'data-bme' ) || 'null' );
		} catch ( e ) {
			return;
		}

		if ( ! payload || ! payload.name ) {
			return;
		}

		if ( ! config.enabled ) {
			note( blockedReason( payload.name ) );

			return;
		}

		if ( alreadyCounted( 'el:' + ( payload.id || payload.name ) ) ) {
			note( 'skipped ' + payload.name + ' because this one has already been counted during this visit. Open a new private window to count it again.' );

			return;
		}

		var params = payload.custom || {};

		// No label was resolved on the server, so fall back to what the
		// visitor actually sees.
		if ( ! params.content_name ) {
			params = Object.assign( {}, params, { content_name: describe( element, '' ) } );
		}

		send( payload.name, params, newEventId(), payload.method );
	}

	function fireLink( link ) {
		if ( ! link ) {
			return;
		}

		var href = link.getAttribute( 'href' ) || '';

		if ( ! href ) {
			return;
		}

		if ( alreadyCounted( href ) ) {
			note( 'skipped this link because it has already been counted during this visit.' );

			return;
		}

		window.bmeTrack( config.contactEvent || 'Contact', {
			content_name: describe( link, href ),
		} );
	}

	// Attached while debugging even when this visitor is not tracked, so the
	// console can explain the silence rather than leaving someone guessing.
	if ( config.enabled || config.debug ) {
		// Capture phase and delegated from the document, so it still fires
		// when something else stops the event or replaces the markup.
		document.addEventListener( 'click', onClick, true );
	}
}() );
