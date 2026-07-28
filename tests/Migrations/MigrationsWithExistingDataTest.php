<?php

use Goldnead\Activity\Tests\Fixtures\ActivityDataFixture;
use Illuminate\Support\Str;

/**
 * The migrations, run against a database that already holds data.
 *
 * Every migration check this addon had ran against a table it had just created
 * itself. `tests/Feature/UpgradeMigrationTest.php` comes closest — it rebuilds
 * the 1.0.2 shape by hand and puts a handful of rows in — but it rebuilds that
 * shape from a copy of the old DDL kept inside the test, calls one named
 * migration file directly, and knows in advance which two files exist. All
 * three of those go stale the moment a third migration is added.
 *
 * This file names no migration. It walks `database/migrations/`, seeds a fresh
 * generation of ledger rows into every table that already exists before each
 * file runs, and applies them one at a time. A migration added years from now
 * is covered the day it lands, against tables that already hold rows written
 * under every schema that preceded it — which is the only situation a migration
 * can actually be wrong about, and the situation none of the existing coverage
 * put it in.
 *
 * Every assertion here is behavioural. "The migration ran" and "the constraint
 * is there" are not the same statement, and neither is "an index named
 * `act_brand_dedupe_unique` exists" the same as "this ledger cannot record the
 * same fact twice". An index can be present under the right name over the wrong
 * columns, or over a column a later migration made nullable, and enforce
 * nothing at all. So nothing below checks an exit code or an index name: it
 * writes the row the constraint is supposed to refuse and requires the database
 * to refuse it.
 */
it('runs every migration against tables that already hold rows', function (): void {
    $fixture = new ActivityDataFixture($this->isolated());
    $batch = 0;
    $seeded = 0;

    // Seed before each migration, not just at the start: a migration that only
    // ever meets rows written under its own predecessor's schema is still only
    // being tested against a fresh install with a bit of data in it. The first
    // pass finds no `activities` table and writes nothing, which is correct —
    // the fixture asks the schema what exists rather than assuming.
    $this->migrateStepwise($this->currentMigrations(), function () use ($fixture, &$batch, &$seeded): void {
        $seeded += $fixture->seed($batch++);
    });

    expect($seeded)->toBeGreaterThan(0, 'the fixture never found a table to seed');

    // Nothing may have gone missing on the way. The ledger is append-only; a
    // migration that solves a constraint problem by deleting rows has not
    // solved it.
    expect($this->isolated()->table('activities')->count())->toBe($seeded);

    // The dedupe guarantee, probed on rows that were already in the table when
    // the last migration ran.
    $probe = ActivityDataFixture::dedupeProbe($batch - 1);

    expect($this->duplicateDedupeKeyIsAccepted($probe))
        ->toBeFalse('the brand-scoped dedupe unique does not bite after a stepwise migration over populated tables');

    expect($this->duplicateEventIdIsAccepted($probe))
        ->toBeFalse('the event_id unique does not bite after a stepwise migration over populated tables');

    // ...and it is a *brand-scoped* unique, not a global one. A migration that
    // rebuilt it over `dedupe_key` alone would pass the check above while
    // silently making one tenant's keys collide with another's.
    expect($this->sameDedupeKeyInAnotherBrandIsAccepted($probe))
        ->toBeTrue('the dedupe unique stopped being brand-scoped');

    // The widest subject the ledger is contracted to keep came through the
    // narrowing untouched, character for character.
    $widest = $this->isolated()->table('activities')
        ->where('dedupe_key', ActivityDataFixture::dedupeKey('wide', $batch - 1))
        ->first();

    expect(strlen($widest->subject_type))->toBe(ActivityDataFixture::SUBJECT_TYPE_MAX)
        ->and(strlen($widest->subject_id))->toBe(ActivityDataFixture::SUBJECT_ID_MAX)
        ->and($widest->subject_type)->toBe(ActivityDataFixture::longSubjectType());
});

/**
 * The released schemas, taken from the tags with `git show <tag>:<file>` and
 * kept verbatim under tests/Fixtures/released-migrations/.
 *
 * Two sets, because two is how many distinct shapes this addon has shipped:
 * 1.0.2 is the install with `subject_type`/`subject_id` still at
 * `varchar(255)` and the wide, brand-less `act_subject_idx` over them, and
 * 1.0.5 is the corrected one. 1.0.0 through 1.0.2 are byte-identical in
 * `database/migrations/`, as are 1.0.3 through 1.0.5, so a third set would
 * re-run the same upgrade under a different name. Adding one is a matter of
 * dropping another directory in and naming it below.
 */
it('upgrades a populated install from every released schema', function (string $version): void {
    // The install as it stood on that release, with its data.
    $this->migratePath($this->releasedMigrations($version));

    $fixture = new ActivityDataFixture($this->isolated());
    $seeded = $fixture->seed(0);

    expect($seeded)->toBe(count(ActivityDataFixture::EVENTS) + 1);

    $before = $fixture->counts();

    // Then the upgrade, with the ledger filling up further as it goes.
    $batch = 1;
    $this->migrateStepwise($this->currentMigrations(), function () use ($fixture, &$batch, &$seeded): void {
        $seeded += $fixture->seed($batch++);
    });

    $probe = ActivityDataFixture::dedupeProbe(0);

    expect($this->duplicateDedupeKeyIsAccepted($probe))
        ->toBeFalse("the dedupe unique does not bite after upgrading a populated {$version} install");

    expect($this->duplicateEventIdIsAccepted($probe))
        ->toBeFalse("the event_id unique does not bite after upgrading a populated {$version} install");

    // Nothing that was there before may have gone missing.
    foreach ($before as $table => $count) {
        expect($this->isolated()->table($table)->count())
            ->toBeGreaterThanOrEqual($count, "rows disappeared from {$table}");
    }

    expect($this->isolated()->table('activities')->count())->toBe($seeded);
    expect($this->isolated()->table('activities')->whereNull('brand_id')->count())->toBe(0);

    // The rows written under the *old* column widths are still whole. This is
    // the one that separates a migration that narrowed the columns from one
    // that narrowed them and lost the ends of the values.
    expect($this->isolated()->table('activities')
        ->where('dedupe_key', ActivityDataFixture::dedupeKey('wide', 0))
        ->value('subject_type')
    )->toBe(ActivityDataFixture::longSubjectType());
})->with(['v1.0.2', 'v1.0.5']);

it('refuses to run rather than shorten a subject the ledger already holds', function (): void {
    // A 1.0.2 install, where `subject_type` is still a varchar(255) and a value
    // longer than the new cap is therefore not just possible but recorded.
    $this->migratePath($this->releasedMigrations('v1.0.2'));

    $fixture = new ActivityDataFixture($this->isolated());
    $fixture->seed(0);

    $overlong = $fixture->insertOverlongSubject(200);
    $before = $this->isolated()->table('activities')->count();

    // On MySQL in strict mode the shrink would be rejected by the engine
    // anyway; on SQLite it would appear to succeed while the two engines
    // quietly drifted apart. The migration stops on either, and it says which
    // rows it stopped for — an operator who cannot find the offending row
    // cannot act on the refusal.
    expect(fn () => $this->migratePath($this->currentMigrations()))
        ->toThrow(RuntimeException::class, "(ids: {$overlong})");

    // The ledger is append-only. Nothing was shortened and nothing was removed
    // on the way out — including the row the migration objected to, which is
    // the one a migration tempted to "clean up" would have taken.
    $row = $this->isolated()->table('activities')->where('id', $overlong)->first();

    expect($row)->not->toBeNull('the migration deleted the row it refused to truncate')
        ->and(strlen($row->subject_type))->toBe(200);

    expect($this->isolated()->table('activities')->count())->toBe($before);

    // And it stopped before doing anything, rather than halfway through: the
    // ledger still accepts the values it accepted a moment ago.
    $stillWide = $this->isolated()->table('activities')->insertGetId([
        'brand_id' => $row->brand_id,
        'event_id' => (string) Str::uuid(),
        'event_type' => 'integration.record_synced',
        'subject_type' => str_repeat('a', 200),
        'subject_id' => '100',
        'dedupe_key' => 'activity.fixture.overlong.second',
        'anonymized' => false,
        'occurred_at' => now(),
        'received_at' => now(),
    ]);

    expect(strlen($this->isolated()->table('activities')->where('id', $stillWide)->value('subject_type')))
        ->toBe(200);
});
