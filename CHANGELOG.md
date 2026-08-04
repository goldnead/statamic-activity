# Changelog

## 1.2.0 — 2026-08-04
### Changed — the Control Panel is an Inertia + Vue app now

1.1.0 rebuilt the two screens out of Statamic's own components but kept them as Blade, rendered through core's NonInertiaPage compatibility path. That path is legacy, not a target: no breadcrumbs, no Inertia navigation, no shared props. Both screens are now Inertia pages backed by single-file Vue components, built by Vite like the other addons in this family.

Nothing about what the screens do has changed. Same routes, same route names, same columns, same filters, same JSON contract, same deep links, same permission. This is a port, not a redesign.

- **`ActivityController` returns `Inertia::render()`** for `activity::Index` and `activity::Show`. `index()` still answers the same route twice — the page for a browser, the listing contract for `<Listing>`.
- **The detail payload is assembled field by field.** Handing the model to Inertia would put whatever the table happens to carry into a prop the browser can read, including columns a later migration adds. A test pins the exact field list.
- **The two documentation URLs are props**, not strings baked into the bundle.
- **`resources/views` is gone**, and with it `loadViewsFrom`. The addon ships no Blade views at all.
- **`resources/dist/build` is committed** and guarded by `npm run build:check` plus a CI job. Composer installs never run npm, so the compiled bundle has to ship in git and has to match the source.

### Fixed — a stored property can no longer be evaluated as a Vue expression

1.1.0 handled this by putting `v-pre` on every element that printed ledger content, because the yielded Blade was compiled as a Vue template in the operator's browser. A compiled component interpolates instead of compiling, so the whole class of failure is gone rather than guarded against. The tests that asserted `v-pre` now assert that a stored mustache arrives as literal text.

## 1.1.0 — 2026-08-01
### Changed — the Control Panel is built out of Statamic's own components now

The domain half of this addon and its Control Panel were never the same quality. The recorder, the immutability guard on both the model and the query builder, the two-key idempotency with its race handler and the migration work were all left untouched here. The two Blade screens were rebuilt.

They were hand-rolled markup on a false premise. `resources/views/cp/_styles.blade.php` opened by justifying its own existence: `mb-4`, `flex` and `gap-3`, it said, "simply do not exist at runtime". All three are in the shipped CP stylesheet of `statamic/cms` v6.26.0. The class the file actually depended on was `.card`, and in Statamic 6 that is `{border-radius:var(--radius-md)}` and nothing else — no background, no border, no padding. So every "panel" rendered as a transparent box on the CP background, and the stated reason not to use native components was wrong.

- **The listing is `<ui-listing>` in server mode.** `ActivityController@index` now answers the same route twice: HTML for the page shell, JSON (`data` plus a `meta` carrying columns on every response) for the listing itself. Search, sorting, per-page, column customisation, saved views and pagination come from core and behave the way they do on the Entries screen.
- **Five real filters**, registered as `Statamic\Query\Scopes\Filter` classes: event type, source, identity (contact uuid / user id / anonymous id), occurrence date range, and anonymisation state. Each one answers `visibleTo()` so it does not turn up on the Entries, Assets and Users listings of every site that installs this addon.
- **The source filter exists.** The README has advertised it since 1.0.0; the controller implemented it; no screen ever rendered an input for it. It was reachable only by hand-editing the query string.
- **Date bounds are parsed, not passed through.** `from` and `to` went unvalidated into a raw comparison, so a malformed date returned an empty result set indistinguishable from "no matches". An unparseable bound is now dropped rather than allowed to narrow the query.
- **The detail page has a way back.** `<ui-header>` with a back button, `<ui-panel>` + `<ui-card>` sections, and a title that names the fact instead of reading "Activity" on every page in the browser history.
- **`_styles.blade.php` is gone**, and with it the inline `<style>` block that was injected into `@section('scripts')` on every render.
- **The nav icon is `pulse`**, a name from Statamic's own set, rather than a raw inline SVG that never matched the sizing of the items around it.
- No build step was introduced. The screens stay Blade and render through core's NonInertiaPage path, where the yielded content is compiled as a Vue template and the globally registered `<ui-*>` components resolve.

### Fixed — a stored property could be evaluated as a Vue expression

Because the yielded Blade is compiled as a Vue template in the browser, a fact whose properties contained `{{ … }}` had that mustache evaluated as an expression on the detail page. Every element that prints ledger content now carries `v-pre`, and a test asserts it.

### Fixed — `ACTIVITY_CP=false` removes the screens

The flag hid the nav item and left both routes registered, so the inspector stayed reachable by URL. That is a hidden Control Panel, not a disabled one.

### Removed — the `manage activity retention` permission

It was registered and checked nowhere: retention and anonymisation are artisan-only paths and artisan does not consult Gates. Operators were shown a checkbox that controlled nothing. If a Control Panel retention action is ever added, the permission comes back with it.

### Added — an `ActivityRecorded` event

A ledger whose stated purpose is to be read by other addons gave downstream consumers no hook; they had to poll the table. `Goldnead\Activity\Events\ActivityRecorded` fires once per fact actually written. A deduplicated write returns the row that already existed and fires nothing, because to a read model that is not a new event.

### Changed — Laravel 11 is out of the declared range

`require` said `^11.0|^12.0|^13.0`. `laravel/framework` v11.0.0 through v11.55.0 are covered by security advisories and Composer refuses the line, so nobody could install on it; `statamic/cms ^6.0` could not resolve alongside it either. The range is now `^12.0|^13.0`, and `orchestra/testbench` moved to `^10.0|^11.0` to match.

### Added — the tooling this repo never had

- `pint.json` and `laravel/pint` in `require-dev`. Two preset rules are off: `fully_qualified_strict_types` imports the optional sibling event classes that `registerProducers()` only passes to `class_exists()`, and `php_unit_method_casing` renames plain helper methods on the test bed and breaks their callers.
- `larastan/larastan` with `phpstan.neon` at level 5 and a generated baseline — a ratchet for new code, not a mandate to rewrite the package.
- `.gitattributes`, so tests and CI config stop shipping to every installing site.
- CI: a Laravel × PHP × stability matrix (every cell resolved with `composer update --dry-run` before the workflow was committed), a MySQL 8 leg that finally runs the `phpunit.mysql.xml` config the repo has carried unused since 1.0.6, and a Pint + PHPStan job.
- `extra.statamic` gained `slug`, `url`, `developer` and `developer-url`, so the addon card in the Control Panel has a developer link and the manifest slug is not `null`.

### Notes

- Suite: **133 passed (391 assertions)**, baseline 102. The test bed now clears the file user repository between tests — it writes into the testbench app inside `vendor/`, where two saved users survived the test and made the third CP request in a run die on "Statamic Pro is required for multiple users".
- The dead `col_brand` translation key was removed rather than turned into a column. The brand scope restricts the listing to the current brand, so a brand column would print the same value on every row.

## 1.0.6 — 2026-07-28

### Added — the migrations are finally tested against a database with data in it

No defect in this addon and nothing in `src/` was touched. What changed is that this addon's migration coverage was measuring something other than what it claimed to.

A sweep across all eight addons in this family, prompted by `statamic-marketing` 1.6.4, looked for a check that runs a migration against tables that already hold rows. It found none, anywhere. Every migration in every addon had only ever met tables the test created moments earlier — which is the one shape a migration can never be wrong about. `statamic-marketing` shipped three releases with its consent unique silently dropped through exactly that blind spot, and `statamic-notifications` shipped three with a migration that deleted rows nobody had agreed to lose.

`tests/Feature/UpgradeMigrationTest.php` came closest here: it rebuilds the 1.0.2 shape and puts a handful of rows in. But it rebuilds that shape from a copy of the old DDL kept inside the test, calls one named migration file directly, and knows in advance which two files exist. All three go stale the moment a third migration is added, which is the property that matters — the migration that hurts is never the one somebody wrote a test for.

`tests/Migrations/` names no migration. It walks `database/migrations/`, seeds a fresh generation of ledger rows into **every table that already exists before each file runs**, and applies them one at a time. A migration added years from now is covered the day it lands, against rows written under every schema that preceded it. `tests/Fixtures/released-migrations/` holds the migration sets as published in 1.0.2 — the install still carrying `varchar(255)` subjects and the wide, brand-less `act_subject_idx` — and in 1.0.5, and the suite installs each, fills it and upgrades forward.

The suite is in both `phpunit.xml` and `phpunit.mysql.xml`: the column widths this addon narrows are advisory on SQLite and enforced by the engine on MySQL, and only the second run can say that the values already in the ledger survived.

Every check is behavioural. "The migration ran" and "the constraint is there" are not the same statement, and neither is "an index named `act_brand_dedupe_unique` exists" the same as "this ledger cannot record the same fact twice". So nothing here asserts an exit code or an index name. It writes the row the constraint is supposed to refuse and requires the database to refuse it — including the counterpart nobody thinks to write, that the same dedupe key in a *different* brand is still accepted, which is what separates a brand-scoped unique from one quietly rebuilt over the key alone.

The case worth naming: `it refuses to run rather than shorten a subject the ledger already holds` installs 1.0.2, records a 200-character `subject_type` that schema permits, and requires the narrowing migration to stop with the offending id named — and then requires the row to still be there, still 200 characters, with the table still accepting wide values. The ledger is append-only. A migration that resolves a width problem by trimming what is already recorded has not resolved it.

### Notes — one thing that was reviewed and deliberately left alone

`2026_07_28_000001_narrow_activity_subject_index` drops `act_subject_idx` before it builds `act_brand_subject_idx`, so there is a window in which neither is on the table. Building the new one first was considered and rejected: it is a plain index rather than a unique, so only query speed is affected and no guarantee is ever open; the migration is already guarded on `Schema::hasIndex()` and heals itself on a retry; and creating the index before the columns narrow would make MySQL build a 2048-byte index it immediately rebuilds when they do — two full index builds on an append-only ledger, to close a window that costs nothing while it is open.

- Suite: **102 passed (309 assertions)** on SQLite, baseline 98. Green against MySQL 8.0 as well, through `phpunit.mysql.xml`, including the new `Migrations` suite.

## 1.0.5 — 2026-07-28

### Changed — the route parameter guard checks the rule, not a snapshot of the siblings

No defect in this addon, no route changed, and nothing in `src/` was touched. What changed is that 1.0.4's guard test was asserting something false.

That test carried a hand-written map of the names other installed packages bind application-wide, and it named `webhook`, `endpoint`, `rule` and `template` as claimed by `goldnead/statamic-webhook-manager` and `automation` as claimed by `goldnead/statamic-automations`. Webhook-manager renamed its four in its 1.7.0 and automations renamed its one in its 1.6.0. All five names are free. The entries were harmless — an entry for a name nobody binds matches nothing, which is why the suite stayed green — but a check that describes the world incorrectly is a check nobody can rely on, and correcting the five names would only have reset the clock on the same problem.

A snapshot of the siblings can only ever describe them as they are today. It says nothing about the addon that starts binding `{handle}` next month, which is exactly the case that hurts, and it has to be maintained by five repositories at once. What replaces it is the rule webhook-manager arrived at in its 1.7.0:

> **A `Route::bind()` is registered on the router, not on the package that calls it. Bind only names that unambiguously belong to your addon — specific enough that no sibling would reach for one by accident. Names you do *not* bind may stay as generic as they like: nothing resolves them, so nothing can be taken from anyone.**

That is a property of *this* package, so this package's own suite can enforce it without knowing anything about its neighbours.

`it binds only parameter names that belong to this addon` reads the `Route::bind()` calls out of this package's own `src/` — comments stripped, string literals only, and a call whose name is not a literal fails the test rather than escaping it — and requires every name found to match `activity` + a capital. This addon binds nothing at all today, so the rule costs it nothing, which is precisely why it is worth pinning now: the binding that hurts is never the one somebody weighed, it is the one added later because binding by the entity's obvious name looked like the obvious thing to do.

`it does not swallow a sibling addon's generic route parameter` is the behavioural half. `tests/TestCase.php` now mounts stand-in routes for a sibling package — `{automation}`, `{rule}`, `{template}`, `{webhook}`, `{endpoint}`, `{handle}`, `{id}`, `{slug}`, `{record}`, each doing nothing but echoing its own value — and the test asserts every one answers with what it was given. They live in the bed rather than in the test body deliberately: a route added from inside a test body is shadowed by Statamic's `{segments?}` frontend catch-all and answers 404 whatever the bindings do, which would have made the check pass for the wrong reason.

Demonstrated rather than asserted: with a `Route::bind('handle', …)` added to a service provider in this family, the old three-test file stayed **green on all three**, while the new file fails three of its five and names `{handle}` in both directions — once as bound-but-not-ours, once as a sibling route answering 404 instead of its own value.

`1.0.4`'s first test is kept as it was: it pins that the CP bed mounts `SubstituteBindings`, without which no `Route::bind()` has any effect in tests and the whole file would pass for nothing. So is the check against `statamic/cms`, reduced to the ten CMS entity names it actually binds — that list is third-party, short and stable, and stays hand-kept for the same reason the sibling list could not.

**What deliberately did not change: `{id}`, this addon's only route parameter. It is as generic as a name gets and it is staying. Renaming it would move text without removing any exposure, because it is not bound — nothing resolves it, so nothing can collide. The rule above is what protects it.**

## 1.0.4 — 2026-07-28

### Added — the route parameter name is checked against the rest of the family

No defect in this addon, and no route changed. What is added is the check that would have caught one, and a pin on the property that made the check possible.

`Route::bind()` is registered on the router, not on a package. A binding one addon registers for `{rule}` or `{template}` applies to every route with that parameter name in every other addon installed beside it. Nothing warns, nothing logs, and the losing route does not fail loudly: it resolves its id against a repository that has never heard of it and returns 404. `goldnead/statamic-leadhub` 1.8.0 shipped `/scoring/{rule}` while `goldnead/statamic-webhook-manager` binds `rule` to its own rule repository, and on the production hub, which has both, editing or deleting a scoring rule did nothing at all and said nothing at all, through a release.

**Why a green suite did not find it.** Two things have to hold before that failure is observable in an addon's own bed: the sibling addon has to be installed there, which it never is, and the bed has to mount the CP routes with `SubstituteBindings`, the middleware that applies a binding at all. LeadHub's bed had neither. This one mounts its CP routes through the `web` group, which already carries the middleware, so the second half was true here by inheritance rather than by decision — and nothing asserted it, so narrowing that group would have taken it away silently. It is now asserted: swap `middleware(['web'])` for `middleware([])` in `tests/TestCase.php` and the first case in the new `tests/Feature/RouteParameterCollisionTest.php` fails while the other 95 tests stay green.

The rest of that file reads this addon's parameter names out of `routes/cp.php` — string literals only, so example URLs in comments are not mistaken for routes — and checks them two ways: exactly, against a hand-maintained list of names that packages installed beside this one bind application-wide (`automation` from statamic-automations, `webhook` / `endpoint` / `rule` / `template` from statamic-webhook-manager, ten CMS entity names from statamic/cms), and then softly, by requiring every generic name to be recorded with a reason so that a *new* one has to be a decision.

**What this cannot do.** A collision only exists once two packages are installed together, and no package can see its siblings from inside its own suite. The reserved list is a snapshot maintained by hand and will not catch an addon that starts binding a name nobody binds today — and `{id}`, this addon's only parameter, is exactly such a name. It is recorded as accepted rather than renamed: nothing binds it, the URL would be identical either way, and the honest statement is that the hub is where that answer is measurable. What the test buys is that the next `{rule}` fails in the addon that introduces it, before it reaches a hub.

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
