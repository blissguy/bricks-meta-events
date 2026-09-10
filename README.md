# Bricks Meta Events

Sends server-verified Meta conversion events for Bricks Builder forms, deduplicated against the browser pixel.

The official **Meta pixel for WordPress** plugin auto-tracks conversions for nine form plugins — Contact Form 7, WPForms, Ninja, Formidable, Caldera, Mailchimp for WP, EDD, WP eCommerce and WooCommerce — from a hardcoded list, with no filter to extend it. Bricks is not among them. On a Bricks site the only Meta signals are site-wide `PageView` plus the WooCommerce funnel; every Bricks form is invisible.

This plugin closes that gap.

## It has no settings of its own

Pixel ID, Conversions API access token, advanced matching and consent state are all inherited from Meta pixel for WordPress. There is nothing to copy, paste or keep in sync, and no credential lives in this plugin.

## Requirements

| | |
|---|---|
| WordPress | 6.5+ |
| PHP | 8.1+ |
| Bricks | 2.0+ (developed against 2.4) |
| Meta pixel for WordPress | 5.2+ — **required**, enforced via `Requires Plugins` |

## How it works

```
Bricks form submit
      │
      ▼
bricks/form/response ── gate: success, tracked, not an internal user
      │
      ├─► detect identity fields ──► Conversions API event (hashed)
      │
      └─► same event_id handed to the browser ──► pixel event
                                                  └─ Meta deduplicates the pair
```

Tracking hooks `bricks/form/response`, never `bricks/form/submit`. Submit fires before validation and spam checks, and fires a *second* time when Bricks regenerates an expired nonce and resubmits — so it would count failed and duplicate submissions as conversions.

## Configuring a form

Select a form in Bricks, open **Content → Meta tracking**:

- **Track this form** — off by default.
- **What does submitting this form mean?** — plain-language outcomes ("They become a lead", "They contact us", …) that map to Meta's standard events. Meta's event list is closed, so there is no reason to ask you to type an event name.
- **Name in Events Manager** — becomes `content_name`. Left on Auto, the first of these that exists is used: the **Form name** under Save submission → the element's name in the structure panel → the submit button text → the page or template title. A value containing unresolved dynamic data is skipped, because `content_name` is a breakdown dimension and a label that varies per page produces hundreds of unusable rows.
- **Identity matching** — email and phone are detected from field *types*, names from field labels, and a logged-in visitor by account ID. Override by pasting a field's **ID** (Bricks shows it with a copy button on every field); IDs survive reordering and relabelling. Enter `none` to never send that identity.

## Detection rules

A wrong identifier is worse than a missing one — Meta counts a garbage hash as an attempted match key — so every ambiguous case sends nothing and records why.

| Case | Behaviour |
|---|---|
| One `email` field | Used |
| Two `email` fields, same value | Confirm-email pair, first used |
| Two `email` fields, different values | **Nothing sent**, ambiguity logged |
| No `email` field, one `text` field holding an address | Inferred, and flagged as inferred |
| Phone | `tel` fields only — never inferred from `text` or `number` |
| Honeypot fields | Always skipped |

## Diagnostics

**Settings → Bricks Meta Events.** Built first, because the host plugin fails silently in ways that look identical to working:

- **Advanced matching off.** `AAMFieldsExtractor` returns an empty array when automatic matching is disabled, stripping every hashed email, phone and name before the request is built. It defaults to *off* on any site not connected through Facebook Business Extension. Events arrive, totals look right, match quality is zero, and nothing is logged anywhere. Re-checked daily, because the setting is fetched from Meta and cached.
- **Your own events are discarded.** The host plugin drops events from anyone who can `edit_posts` or `upload_files`, with no filter. You cannot test as an administrator. Every role holding either capability is listed — on a membership site, a member role with `upload_files` silently voids every paying customer's conversion.
- **Loopback delivery.** Conversions API events normally ride a non-blocking request from WordPress to its own `admin-ajax.php`. Security plugins routinely block it and the events vanish with no error. When the probe detects this, events are sent **inline** during submission instead — one extra round trip to Meta, rather than silent total loss. Filterable via `bme_send_synchronously`.
- **Send a test event** — builds a real event and sends it synchronously, bypassing the background loopback, then prints Meta's raw response and which identifiers survived matching.
- **Last conversion sent** — event, resolved label, event ID, page, delivery route, outcome and which identifiers were sent, followed by the recent history behind it.
- **Test mode** — save a code from Events Manager → Test Events and conversions from your forms go there instead of counting towards live figures. Real submissions carry no test code otherwise, so without this there is no way to check a setup without dirtying the data you report on.

## Tracking a button or a link

Buttons and text links get the same **Meta tracking** panel, with a shorter set of outcomes: a click cannot honestly mean somebody created an account, so those options are not offered.

It works on a Bricks **button with no link at all** — one that opens a popup, say — because Bricks renders those as a `button` element rather than an `a`. That is also why the event is never guessed from the `href`: on a large share of buttons there is no href to read.

Settings are resolved on the server and travel on the element in a `data-bme` attribute, read by one delegated listener rather than a script per element. That keeps it working inside popups, query loops and anything rendered later.

- **Counted once per element per visit.** Someone tapping a phone number twice is one intention.
- **An element you configured beats the phone and email sweep**, so a tracked `tel:` link fires once, not twice.
- **The label is resolved server-side.** Text containing unresolved dynamic data is skipped, because a button reading `Download {post_title}` would otherwise become a separate entry for every post. Where no label resolves, the visible text is used at click time.

## Tracking anything else

`window.bmeTrack( name, params )` records an event the plugin cannot see for itself. It goes through the same sender as everything else, so it respects cookie consent and skips excluded visitors.

```js
bmeTrack( 'Contact', { content_name: 'Live chat opened' } );
```

From Bricks, wire it without code: select the element, add an **Interaction**, set trigger **click**, action **JavaScript (Function)**, function name `bmeTrack`, and pass the event name and details as arguments.

Anything outside Meta's standard event list is sent as a custom event automatically. Like all click tracking these are browser only and carry no customer details, because a click is not a confirmed enquiry.

## Updates

Updates arrive on the Plugins screen from this repository's GitHub releases, via [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) vendored in `lib/`.

The release workflow publishes a versioned ZIP built from `.distignore`, and the updater is pointed at that asset specifically. Left to itself it would offer GitHub's own source archive, which unpacks as `bricks-meta-events-main/` and would not activate.

The repository is public, so no credentials are needed. GitHub rate limits anonymous API calls per IP, which shared hosting can occasionally hit; if that happens, define `BME_GITHUB_TOKEN` in `wp-config.php` or filter `bme_github_token`.

**Both ignore files anchor their patterns to the plugin root.** An unanchored `vendor` matches `lib/plugin-update-checker/vendor` too, which strips Parsedown and the readme parser and ships a broken updater. Verified in both files.

## Site-wide settings

**Settings → Bricks Meta Events → Settings.** Each one is a default that any individual form can override:

- **What most of your forms mean** — newly tracked forms start on this outcome.
- **Currency** — used when a form sets a value but no currency. Falls back to the WooCommerce currency, then USD.
- **Do not track these people** — signed-in roles to skip, on top of those the host plugin already discards. Only roles our setting can actually affect are listed; offering a checkbox for an already-excluded role would be a control that does nothing.
- **Never send customer details** — strips every identifier from every form. Meta still counts the conversion but cannot attribute it.
- **Phone and email links** — off by default. Turning it on counts a `tel:` or `mailto:` click as `Contact`, deduplicated per link per visit, via one delegated listener rather than per-element markup. Off by default because optimising ads towards unverified clicks makes them worse, so it should be a deliberate choice.

## Filters

| Filter | Purpose |
|---|---|
| `bme_send_synchronously` | Force or prevent inline Conversions API delivery. Defaults to inline when the loopback probe has failed, when a handed-over conversion was never confirmed, or when test mode is on. |
| `bme_enqueue_tracking` | Return false to stop loading the browser tracking script. |

## Status

`0.8.0` — feature complete against the build plan: form tracking, diagnostics, the browser pixel event sharing an `event_id` with the Conversions API event, a recent-conversions log, test mode, site-wide settings, per-element button and link tracking, `bmeTrack()`, and updates from GitHub releases.

## Licence

GPL-2.0-or-later.
