# Changelog

## 1.0.3 — 2026-07-28

### Added — the suite can finally see MySQL's index rules

**Why a green suite proves nothing about the schema.** The suite runs on in-memory SQLite. SQLite has no InnoDB key-length limit, no per-character byte cost, and no fixed column widths — it accepts `varchar(255)` and ignores the 255. Every mechanism that rejects an oversized index is a MySQL mechanism, so a migration MySQL refuses outright passes this suite without a murmur. `statamic-notifications` v1.0.3 shipped exactly that way: a 3212-byte unique that had run hundreds of times locally and died on the production hub with *SQLSTATE 1071*, leaving two tables that never existed there at all.

Demonstrated rather than asserted: put back the 1.0.2 migration and **89 of the 93 tests stay green**. Only the new one fails.

`tests/Unit/IndexKeyLengthTest.php` closes the gap without needing a server. It compiles this addon's own migration files through Laravel's MySQL grammar in pretend mode and measures the DDL MySQL would have received. It asserts four things: no index over InnoDB's 3072 bytes; no index over **half** of it, because an index under the limit by accident breaks on the next column added to it; no unique covering a nullable column unless the file names it and a test proves the intent; and no composite index that fails to lead with `brand_id`, since every read here runs under the brand scope.

`phpunit.mysql.xml` runs the identical suite against a real MySQL server (`vendor/bin/pest -c phpunit.mysql.xml`, `DB_DRIVER=mysql`).

### Fixed — `act_subject_idx` was two thirds of the way to the wall

`(subject_type, subject_id)`, two `varchar(255)` columns, is **2040 bytes** under utf8mb4 — the widest index in this addon by a factor of two, and the only composite one that did not begin with `brand_id`. MySQL would have built it. That is the problem: it was under the limit by luck, not by design, and the next column added to it would have taken it past 3072 in a migration nobody would think to measure. Being one field away from an unbuildable index is a defect with a delay on it.

It is now `act_brand_subject_idx` on `(brand_id, subject_type, subject_id)` at **1284 bytes**, with the two columns narrowed to what they actually hold: `subject_type` to 191 (a class name this addon writes itself, via `$subject::class`) and `subject_id` to 128 (a database identifier — an integer, a UUID, a Statamic ID). Neither cap was chosen to make an index fit a prefix: nothing is truncated, and the upgrade migration refuses to run rather than shorten a value the ledger already holds. The ledger is append-only; that has to include migrations.

Leading with `brand_id` also makes the index usable for the first time. Every query against this table carries the brand scope, so an index starting at `subject_type` could not serve one.

The widest index is now 1284 bytes, 41% of the limit. The next is `act_brand_type_time_idx` at 1036 and `act_brand_user_idx` at 1028.

### Fixed — the dedupe key turned a missing identifier into a constant

The quieter half of the review, and the one no width measurement finds. `(brand_id, dedupe_key)` is deliberately NULL-permissive: a row without a dedupe key is a fact nobody asked to be deduplicated, and `event_id` holds it instead. That only works while "no identifier" actually produces NULL.

The marketing producer built its keys as `$eventType.':'.($payload['subscription_uuid'] ?? '')`. Where the identifier was absent that yields `marketing.subscription_confirmed:` — non-NULL, so the unique **does** bind it, and every subscription confirmation in the brand collapses onto the first row ever written. The second is silently returned as a duplicate of the first and the ledger loses the fact it exists to keep. Same construction for campaign transitions and for the three deduplicated message events; the LeadHub producer had the same hole for an empty-string key.

Reachable through the sibling addons' public API, not only in theory: their event payloads merge `$event->metadata` last, so a caller can blank any identifier in them.

Both producers now return `null` when there is nothing to deduplicate on. Deduplication with an identifier present is unchanged, which is asserted rather than assumed.

This is the notifications defect seen from the other side. There a unique enforced nothing where it should have; here it enforced everything where it should have stood aside. Both come from never deciding what the absent value means.

### Migration

- **New installs** need nothing: the corrected create-migration builds the right index straight away.
- **Existing installs** run `2026_07_28_000001_narrow_activity_subject_index`. It swaps the index, narrows the two columns, is idempotent, and is a no-op on a fresh install. It writes no row: the ledger is not edited, only reindexed.
- If any stored `subject_type` exceeds 191 or `subject_id` exceeds 128 characters, the migration **stops with the offending ids** instead of shortening them. On MySQL in strict mode the shrink would fail anyway; on SQLite it would appear to succeed while the two engines drifted apart, which is the exact failure this release exists to close.
- Brand-scoped uniqueness of dedupe keys is untouched, and proven so on both sides of the migration: same key and same brand still refused, same key in another brand still allowed.

### Notes

- No new dependency. The measurement uses Laravel's own schema grammar.
- Suite: **93 passed (272 assertions)**, baseline 81.

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
