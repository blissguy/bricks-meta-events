=== Bricks Meta Events ===
Contributors: blissguy
Tags: bricks, meta, facebook, pixel, conversions api
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.8.2
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

= 0.8.2 =
* Corrected the help text under How to send it on a form. It said Automatic always sends from both your site and the visitor's browser, which is not what it does: it sends from your site alone when the form redirects, and from the browser alone when your site cannot reach Meta.

= 0.8.1 =
* While test mode is on, tracking now reports what it is doing in the browser console. A click leaves no record on the server, so this is the only way to tell a button that fired from one that quietly did not.
* It also says why nothing was sent, the usual reason being that you are signed in as staff, which Meta pixel for WordPress ignores. Clicks already counted during the same visit are reported too.

= 0.8.0 =
* Buttons and text links now have their own Meta tracking panel, so you choose exactly which ones count. Works on a link styled as a button and on a real button, including one with no link at all such as a popup trigger.
* A button or link you have set up yourself takes priority over the phone and email sweep, so a tracked phone link is counted once rather than twice.
* Clicks are counted once per button per visit.
* Updates now appear on the Plugins screen and install like any other plugin, instead of uploading a ZIP by hand.

= 0.7.0 =
* Added window.bmeTrack, for recording something the plugin cannot see for itself. It can be called straight from a Bricks Interaction with no code, using trigger click and action JavaScript (Function).
* Added an optional setting to count a click on a phone number or email address link as getting in touch. Clicks are counted once per link per visit. Off by default, because a click is not a confirmed enquiry and pointing your ads at unverified clicks makes them worse.
* Anything sent this way is browser only and carries no customer details.

= 0.6.0 =
* Added a Settings section, so a site with many forms does not need the same decision made on every one of them.
* Choose what most of your forms mean, and newly tracked forms start on it.
* Set a currency once, used whenever a form has a value but no currency of its own.
* Choose roles that should not be tracked, on top of the ones Meta pixel for WordPress already skips. Useful when you do not want existing customers counted as new enquiries.
* Added a switch to never send customer details from any form, for sites that are not allowed to.

= 0.5.1 =
* The Earlier conversions heading now appears as soon as you have sent one, and says that the list fills up from the second onwards. It used to be hidden entirely, which looked like the list was broken.
* The raw reply from Meta is now only shown when Meta refused a conversion, where it explains why. On a successful one the result line already says everything useful.

= 0.5.0 =
* Added a list of recent conversions, so you can see everything that has been sent rather than only the last one, and whether each one reached Meta.
* Added test mode. Save a code from Events Manager, Test Events and conversions from your forms go there instead of counting towards your real figures. Empty the box when you have finished checking.
* Test mode also makes sure conversions are sent in a way the code can be attached to, which the background route cannot do.

= 0.4.0 =
* Background sending is now confirmed rather than assumed. A conversion that is handed over and never goes out is reported as a failure, instead of sitting at "result not known" forever.
* When background sending turns out to be blocked, the plugin starts sending while the form submits instead, and goes back to background sending on its own once the check passes again.

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
