=== Bricks Meta Events ===
Contributors: blissguy
Tags: bricks, meta, facebook, pixel, conversions api
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tracks Bricks form submissions in Meta, sent from your site and the visitor's browser and counted only once.

== Description ==

The official Meta pixel for WordPress plugin auto-tracks conversions for nine form plugins from a hardcoded list, with no filter to extend it. Bricks is not among them, so on a Bricks site every form is invisible to Meta.

This plugin closes that gap. It has no settings of its own: pixel ID, Conversions API access token, advanced matching and consent state are all inherited from Meta pixel for WordPress, which is a hard dependency.

Tracking hooks `bricks/form/response`, never `bricks/form/submit`. Submit fires before validation and spam checks, and fires again when Bricks regenerates an expired nonce and resubmits.

= Diagnostics =

Settings → Bricks Meta Events reports the host plugin's silent failure modes: advanced matching being off (which strips every hashed identifier while totals still look correct), your own events being discarded because you can edit posts, and Conversions API delivery being blocked at the loopback. When the loopback is blocked, events are sent inline during submission instead of being lost.

== Changelog ==

= 0.3.0 =
* Plain English throughout the settings screen and the form panel, with the jargon taken out.
* Each option now shows the event Meta will record beside it, so you can see what you will get in Events Manager before you save.
* Added a value and currency per form, so Meta can work out what your ads earn you.
* The background sending check now runs when the plugin is switched on and again every day. It used to wait until someone opened the settings screen, and until then a site that blocks background sending lost every conversion with no warning.

= 0.2.0 =
* Added the browser pixel event, sharing an event ID with the Conversions API event so Meta deduplicates the pair rather than counting two conversions.
* Added a per-form Send via control. Automatic sends both, drops to the Conversions API alone on forms that redirect, and to the browser alone when the Conversions API is unavailable.
* Field overrides now take a Bricks field ID, which survives reordering and relabelling. Label and field name still work as fallbacks.
* An override pointing at a field the visitor left blank now resolves to empty instead of reporting that no such field exists.
* The health screen now shows the resolved Events Manager name for the last conversion.

= 0.1.0 =
* Conversion tracking for Bricks forms via the Conversions API, with identity detection, a per-form intent picker and label resolution.
* Diagnostics screen covering advanced matching, internal-user exclusion, the access token, the circuit breaker and loopback delivery.
* Synchronous delivery fallback when the loopback is blocked.
* Synchronous test-event sender that prints Meta's raw response.
