# Statamic Activity

An immutable, brand-scoped activity ledger: the append-only record of **what
happened**, for Statamic applications.

It is deliberately not an analytics product. It stores facts; it computes
nothing. Metrics, funnels, cohorts and dashboards belong in a separate addon
that reads from here — mixing the two is how a ledger quietly turns into a
reporting tool with no schema discipline.

## What it is for

Domain addons each grow their own event log: a CRM timeline, a lesson-progress
table, a webhook receipt log. Each solves its own problem and none of them can
answer a cross-domain question. This package is the one place a fact is recorded
in a shape that every consumer can read.

## Requirements

| | |
| --- | --- |
| PHP | 8.2 or newer |
| Laravel | 12 or 13 |
| Statamic | 6 |
| Database | Any Laravel-supported driver. The schema is tuned for MySQL/InnoDB with `utf8mb4`. |

## Install

```bash
composer require goldnead/statamic-activity
php artisan migrate
```

Requires `goldnead/statamic-brand-context` and
`goldnead/statamic-identity-contracts`. Both are foundation packages and
behave inertly in a single-brand, no-CRM application.

```bash
php artisan vendor:publish --tag=activity-config
```

## Usage

### Recording

```php
use Goldnead\Activity\Facades\Activity;

Activity::record('commerce.purchase_completed', [
    'actor' => $user,                       // anything IdentityContext can resolve
    'subject' => $order,                    // any Eloquent model
    'dedupe_key' => 'mollie:'.$payment->id, // the fact's fingerprint
    'properties' => ['amount' => 4900, 'currency' => 'EUR', 'product' => 'kurs'],
]);
```

Everything except the event type is optional. Brand, actor, source and request
context are filled in automatically.

`Activity::recordLater(...)` queues the insert. The actor and the request context
are captured at **dispatch** time, never in the worker — by the time the job
runs, the request that caused it is long gone.

#### Two idempotency keys

| Key | Guards against | Scope |
| --- | --- | --- |
| `event_id` | the same *physical* event arriving twice (webhook retry, job retry) | global |
| `dedupe_key` | the same *fact* being recorded twice (two producers, one truth) | per brand |

Recording an existing fact returns the original row and writes nothing. This is
the core guarantee: **a producer may be as noisy as it likes.**

Use a dedupe key for state transitions (a purchase, a confirmation, a bounce).
Leave it off for repeatable facts (an email open, a page view) — the second open
is a second fact, and `event_id` alone keeps retries safe.

#### Recording never breaks the caller

A ledger failure is reported and swallowed. A broken `activities` table must
never roll back the purchase that produced the event.

## Producers

Map a domain event onto the ledger without the ledger knowing your code:

```php
Activity::registerProducer(OrderPaid::class, fn (OrderPaid $event) => [
    'actor' => $event->customer,
    'dedupe_key' => 'order:'.$event->order->id,
    'properties' => ['amount' => $event->order->total],
], 'commerce.purchase_completed');
```

Return `null` from the mapper to skip an occurrence. Return an `event_type` key
to override the type per occurrence. Registering the same event class again
**replaces** the mapper — it never adds a second listener.

Two producers ship with the package and attach themselves only when the sibling
addon is installed:

- **marketing** — `marketing.subscription_pending|subscription_confirmed|unsubscribed`,
  `marketing.campaign_sending|campaign_sent`,
  `marketing.email_sent|email_opened|email_clicked|email_bounced|email_complained`
- **leadhub** — `crm.contact_created|contact_updated|status_changed|score_changed|
  segment_entered|opportunity_won|task_completed|…`

Event type names follow the platform catalogue rather than PHP class names, so
consumers survive a class rename.

`leadhub_events` is **not** replaced. That table is the CRM's own contact
timeline and stays untouched; the ledger records the same facts for a different
purpose and the two are allowed to overlap.

## Brands

Every row carries a `brand_id` from the first migration — there is no
single-brand phase to migrate out of later. In single-brand mode the default
brand is stamped and nothing else changes. With
`brand-context.multi_brand` on, the global scope applies and fails closed: no
current brand means no rows, never all rows.

Dedupe keys are scoped per brand, so two brands may legitimately record the same
fact independently.

## Privacy and retention

Built to §5.7 of the platform architecture:

- **No raw user agent, ever.** Only a coarse category (`mobile`, `desktop`,
  `tablet`, `bot`).
- **No IP addresses.** Derive a country upstream and pass it explicitly if you
  need one.
- **A sanitizer runs on every write.** Secret-shaped keys (`token`, `password`,
  `api_key`, `iban`, …) are redacted at any depth; oversized payloads are
  replaced with a visible marker rather than silently truncated.
- **Whole event types can be blocked** via `sanitizer.blocked_event_types` —
  the enforcement point for "this domain never mirrors into a central store".
  Bind your own `ActivitySanitizer` for anything more specific; returning `null`
  drops the activity.

```bash
php artisan activity:prune --days=365 [--dry-run]
php artisan activity:anonymize --contact=<uuid> [--user=] [--anonymous-id=] [--days=]
```

`prune` deletes; `anonymize` strips the personal fields and keeps the countable
fact, which is usually the right answer to a deletion request. Both run across
all brands — they are operator actions on the whole store. Anonymisation is
idempotent: a second run over the same rows is a no-op.

## Immutability

`activities` is append-only. Updating or deleting a row throws
`ImmutableActivity`. The retention and anonymisation commands are the only
paths that lift the guard. Correct a wrong fact by recording a correcting one.

## Control Panel

A read-only inspector at **Tools → Activity**, built on Statamic's own listing:
search, sort, per-page, column customisation and saved views work the way they
do on the Entries screen. Filters for event type, source, identity
(contact uuid / user id / anonymous id), occurrence date and anonymisation
state; open a single fact to read its properties and context. No counts, no
charts, no aggregates.

The plain query-string parameters the pre-1.1 inspector used
(`?event_type=`, `?contact_uuid=`, `?user_id=`, `?source=`, `?anonymous_id=`,
`?from=`, `?to=`) still work as entry points and are carried into every
subsequent request the listing makes.

Permission: `view activity`. Set `ACTIVITY_CP=false` to remove the screens and
the nav item entirely.

## Extension points

| Seam | Purpose |
| --- | --- |
| `Activity::registerProducer()` | map your domain events onto the ledger |
| `ActivitySanitizer` | redact, reshape or drop before persisting |
| `ContactLocator` (identity-contracts) | resolve an email to a CRM contact uuid |
| `AnonymousIdResolver` (identity-contracts) | supply the pseudonymous visitor id |
| `Activity::query()` | brand-scoped Eloquent, plus `ofType()`, `forIdentity()`, `occurredBetween()` |
| `ActivityRecorded` event | fired once per fact actually written — a deduplicated write is silent, so a read model cannot double-count |

## Schema

`activities` — `brand_id`, `event_id` (unique), `event_type`, `actor_type`,
`actor_id`, `contact_uuid`, `user_id`, `anonymous_id`, `session_id`, `source`,
`subject_type`, `subject_id`, `dedupe_key`, `properties`, `context`,
`anonymized`, `occurred_at`, `received_at`.

Unique: `(brand_id, dedupe_key)`, `event_id`.

`subject_type` is capped at 191 characters (it holds a class name) and
`subject_id` at 128 (a database identifier — an integer, a UUID, a Statamic ID).
Both go into `act_brand_subject_idx`, and under `utf8mb4` every character costs
four bytes of InnoDB's 3072 per index. Nothing is truncated to fit: the upgrade
migration refuses to run if a stored value exceeds either cap.

`(brand_id, dedupe_key)` is deliberately not binding for rows without a dedupe
key — those are facts nobody asked to be deduplicated, and `event_id` holds
them instead. A producer that cannot build a key must therefore write `null`,
never an empty-but-present one: that would be constrained, and every event of
its type in the brand would collapse onto one row.

## Configuration

`config/activity.php`, published with the command above. Every option is
documented inline; this is the summary.

| Key | Default | What it does |
| --- | --- | --- |
| `enabled` | `ACTIVITY_ENABLED`, `true` | Master switch. Off means `record()` returns `null` and writes nothing. |
| `source` | `ACTIVITY_SOURCE`, `APP_NAME` | The `source` stamped on a fact that does not name one. |
| `queue.*` | — | Connection and queue name for `recordLater()`. |
| `context.*` | — | Which request signals get captured, and whether capture happens at all. |
| `sanitizer.*` | — | Redacted key patterns, the payload size ceiling, and `blocked_event_types`. |
| `retention.*` | — | Default age for `activity:prune` and `activity:anonymize`. |
| `producers.marketing` / `producers.leadhub` | `true` | Attach the bundled producers when the sibling addon is installed. |
| `cp.enabled` | `ACTIVITY_CP`, `true` | Registers the Control Panel screens and the nav item. |
| `cp.per_page` | `50` | Rows per page the inspector opens with. |

## Upgrading

The 1.0.6 migration narrows `subject_type` to 191 characters and `subject_id`
to 128 so the composite index fits inside InnoDB's 3072-byte key limit. It
**refuses to run** rather than truncate: if a stored value is longer than the
new cap, it throws and leaves the table alone. Shorten or remove the offending
rows and migrate again.

## Tests

```bash
composer install && vendor/bin/pest
```

The Integration suite exercises the bundled producers against the real sibling
addons and skips itself when they are not installed.

## License

MIT
