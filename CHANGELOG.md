# Changelog

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
