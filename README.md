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

## Site-wide settings

**Settings → Bricks Meta Events → Settings.** Each one is a default that any individual form can override:

- **What most of your forms mean** — newly tracked forms start on this outcome.
- **Currency** — used when a form sets a value but no currency. Falls back to the WooCommerce currency, then USD.
- **Do not track these people** — signed-in roles to skip, on top of those the host plugin already discards. Only roles our setting can actually affect are listed; offering a checkbox for an already-excluded role would be a control that does nothing.
- **Never send customer details** — strips every identifier from every form. Meta still counts the conversion but cannot attribute it.

## Filters

| Filter | Purpose |
|---|---|
| `bme_send_synchronously` | Force or prevent inline Conversions API delivery. Defaults to inline when the loopback probe has failed, when a handed-over conversion was never confirmed, or when test mode is on. |
| `bme_enqueue_browser_echo` | Return false to stop loading the browser listener. |

## Status

`0.6.0` — form tracking, diagnostics, the browser pixel event sharing an `event_id` with the Conversions API event, a recent-conversions log, test mode and site-wide settings. Click tracking for buttons and links is not in this release.

## Licence

GPL-2.0-or-later.
