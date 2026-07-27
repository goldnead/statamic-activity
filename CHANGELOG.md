# Changelog

## 1.0.2 — 2026-07-27

### Fixed — append-only did not cover the query builder

- **`Activity::query()->update()` and `->delete()` rewrote the ledger unchallenged.** Model events only fire for instance operations, so `$activity->save()` was blocked while the bulk path went straight through. The guard covered the polite route and missed the fast one — and the model's own docblock claimed otherwise. A ledger that can be rewritten in bulk is not a ledger.
- Every query for the model now runs through `ImmutableBuilder`, which refuses `update`, `delete` and `forceDelete` unless `Activity::mutable()` has lifted the guard. Retention and anonymisation are unaffected; they already ran inside that helper.
- Found in the local QA run by an agent that deliberately attacked the guarantee from four directions instead of trusting it. The existing tests only ever exercised the instance path.

### Notes

- Suite: **81 passed (169 assertions)**, four new tests covering bulk update, bulk delete, bulk update without global scopes, and the legitimate retention path.

## 1.0.1 — 2026-07-27

### Fixed — the CP inspector was largely unstyled

- **Statamic 6 ships no utility classes.** Its Control Panel is a Vue component library, and the stylesheet contains only what Statamic's own source uses. An addon's Blade file is never scanned, so `mb-4`, `flex`, `gap-3`, `btn-primary` and `badge-sm` did not exist at runtime: the filter inputs rendered invisible, the buttons as bare text, and the layout without spacing. Verified by counting the classes in the shipped CSS: `.card` and `.data-table` exist (and are kept), `.btn-primary` and `.badge-sm` do not.
- **A `<style>` tag inside the page content is silently dropped.** Statamic 6 compiles a Blade CP page into a Vue component template (`NonInertiaPage`), and Vue's template compiler strips `<style>`. The rules now live in `@section('scripts')`, which the layout yields *outside* the `#statamic` mount point.
- Styling derives from the CP's own design tokens (`--color-primary`, `--radius-*`, `--text-*`) and `currentColor`, so it follows the active theme in light and dark without hardcoding a palette.

### Notes

- Found by looking at the page in a browser. The existing tests asserted HTTP 200 and that certain strings appear — both of which were true the whole time. They cannot see styling, and no test was added that pretends otherwise.
- Suite unchanged: **77 passed (164 assertions)**.

## 1.0.0 — 2026-07-26

### Added — the activity ledger

- **`activities` table, brand-scoped from the first migration.** No single-brand phase to migrate out of later — the lesson from retrofitting `brand_id` across five existing addons. Short index names throughout, because MySQL caps identifiers at 64 characters and the generated names for these column combinations exceed it (the failure that broke the hub's first MySQL deploy).
- **`Activity::record()` / `recordLater()`, the single write path.** Fills in brand, actor, source and request context automatically. The queued variant captures the actor and request context at **dispatch** time, never in the worker — by the time the job runs, the request that caused it is gone.
- **Two independent idempotency keys.** `event_id` (globally unique) guards against the same physical event arriving twice; `(brand_id, dedupe_key)` guards against the same fact being recorded twice by different producers. The read-then-write race is handled by catching the unique violation and re-reading, which is the only approach that holds under concurrent workers.
- **Recording never breaks the caller.** A ledger failure is reported and swallowed: a broken `activities` table must not roll back the purchase that produced the event.
- **Append-only enforcement.** Updates and deletes throw `ImmutableActivity`. Only the retention and anonymisation paths lift the guard, and the guard is restored even when they throw.
- **`ProducerRegistry` extension point.** `Activity::registerProducer()` maps a domain event onto the ledger without the ledger depending on it. Mappers may skip an occurrence (`null`) or override the event type per occurrence.
- **Bundled producers for marketing (10 events) and leadhub (18 events)**, attaching only when the sibling addon is installed. Event type names follow the platform catalogue, not PHP class names, so consumers survive a class rename. `leadhub_events` is left untouched — the CRM's contact timeline and the ledger are allowed to overlap.
- **Privacy by construction (§5.7).** Raw user agents are never stored, only a coarse category; IP addresses are never captured. A sanitizer runs on every write, redacting secret-shaped keys at any depth and replacing oversized payloads with a visible marker rather than truncating silently. Whole event types can be blocked, and a custom `ActivitySanitizer` may drop anything.
- **Retention and erasure.** `activity:prune` deletes past a global or per-event-type window; `activity:anonymize` strips the personal fields while keeping the countable fact, and is idempotent across repeated runs. Both operate across all brands — they are operator actions on the whole store.
- **Read-only CP inspector** at Tools → Activity with `view activity` / `manage activity retention` permissions. Filters and raw detail only: no counts, no charts, no aggregates.

### Fixed

- **Duplicate producer registration wrote every fact twice.** Registering the same event class again bound a second dispatcher listener instead of replacing the mapper — the exact shape of an application overriding a bundled mapping. Listener binding is now tracked separately from the mapper table. Covered by a regression test.

### Notes

- Suite green: **77 passed (164 assertions)** across Unit, Feature and Integration. Coverage includes cross-brand isolation (six cases, incl. fail-closed with no current brand and the CP detail route refusing another brand's row by id), idempotency under a simulated write race, immutability and guard restoration, all four anonymous-id modes, sanitizer redaction/blocking/replacement, queued capture semantics, and both bundled producers against their real sibling events.
- The Integration suite skips itself when the sibling addons are not installed.
- Deliberately absent: read models, metrics, dashboards. Those belong to a separate analytics addon reading from this one.
