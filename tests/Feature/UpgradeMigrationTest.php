<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The other half of the index fix. Correcting the create-migration reaches new
 * installs only — every host that already ran it has it recorded and will never
 * look at it again. `2026_07_28_000001_narrow_activity_subject_index` is what
 * gets those hosts to the same schema, so it is exercised here against the
 * table as 1.0.2 left it, not against the corrected one.
 */
function rebuildTheOldActivitiesTable(): void
{
    Schema::dropIfExists('activities');

    Schema::create('activities', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('brand_id')->index();
        $table->uuid('event_id')->unique();
        $table->string('event_type')->index();
        $table->string('actor_type')->nullable();
        $table->string('actor_id')->nullable();
        $table->uuid('contact_uuid')->nullable();
        $table->string('user_id')->nullable();
        $table->string('anonymous_id')->nullable();
        $table->string('session_id')->nullable();
        $table->string('source')->nullable();

        // The 1.0.2 shape: two varchar(255) columns, 2040 bytes of InnoDB's
        // 3072 once indexed together, and the only composite index in this
        // table that did not lead with brand_id.
        $table->string('subject_type')->nullable();
        $table->string('subject_id')->nullable();

        $table->string('dedupe_key')->nullable();
        $table->json('properties')->nullable();
        $table->json('context')->nullable();
        $table->boolean('anonymized')->default(false)->index();
        $table->timestamp('occurred_at')->index();
        $table->timestamp('received_at');

        $table->unique(['brand_id', 'dedupe_key'], 'act_brand_dedupe_unique');
        $table->index(['brand_id', 'event_type', 'occurred_at'], 'act_brand_type_time_idx');
        $table->index(['brand_id', 'contact_uuid'], 'act_brand_contact_idx');
        $table->index(['brand_id', 'user_id'], 'act_brand_user_idx');
        $table->index(['brand_id', 'anonymous_id'], 'act_brand_anon_idx');
        $table->index(['subject_type', 'subject_id'], 'act_subject_idx');
    });
}

function runSubjectIndexMigration(): void
{
    (require __DIR__.'/../../database/migrations/2026_07_28_000001_narrow_activity_subject_index.php')->up();
}

function insertLegacyActivity(array $overrides = []): int
{
    return DB::table('activities')->insertGetId(array_merge([
        'brand_id' => 1,
        'event_id' => (string) Str::uuid(),
        'event_type' => 'crm.contact_created',
        'subject_type' => 'Goldnead\\Leadhub\\Models\\Contact',
        'subject_id' => '42',
        'anonymized' => false,
        'occurred_at' => now(),
        'received_at' => now(),
    ], $overrides));
}

beforeEach(function (): void {
    rebuildTheOldActivitiesTable();
});

it('swaps the wide subject index for the brand-led one without touching the ledger', function (): void {
    $first = insertLegacyActivity();
    $second = insertLegacyActivity(['subject_id' => '43']);

    runSubjectIndexMigration();

    expect(Schema::hasIndex('activities', 'act_brand_subject_idx'))->toBeTrue()
        ->and(Schema::hasIndex('activities', 'act_subject_idx'))->toBeFalse();

    // Append-only means append-only: the migration reindexes, it does not edit.
    $rows = DB::table('activities')->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('id', $first)->subject_id)->toBe('42')
        ->and($rows->firstWhere('id', $second)->subject_id)->toBe('43')
        ->and($rows->firstWhere('id', $first)->subject_type)->toBe('Goldnead\\Leadhub\\Models\\Contact');
});

it('is a no-op the second time it runs', function (): void {
    insertLegacyActivity();

    runSubjectIndexMigration();
    runSubjectIndexMigration();

    expect(Schema::hasIndex('activities', 'act_brand_subject_idx'))->toBeTrue()
        ->and(DB::table('activities')->count())->toBe(1);
});

it('refuses to run rather than shorten a value the ledger already holds', function (): void {
    // MySQL in strict mode would reject the shrink anyway; SQLite would accept
    // it and let the two engines drift apart, which is the exact failure this
    // release exists to close. So the migration stops on either engine.
    insertLegacyActivity(['subject_id' => str_repeat('x', 200)]);

    expect(fn () => runSubjectIndexMigration())
        ->toThrow(RuntimeException::class, 'would be truncated');

    expect(Schema::hasIndex('activities', 'act_brand_subject_idx'))->toBeFalse()
        ->and(DB::table('activities')->value('subject_id'))->toBe(str_repeat('x', 200));
});

it('leaves the brand-scoped uniqueness of dedupe keys exactly as it was', function (): void {
    // The migration touches an index that contains brand_id; the tenant boundary
    // it protects must come out unchanged on both sides of the run.
    insertLegacyActivity(['brand_id' => 1, 'dedupe_key' => 'sub:1']);
    insertLegacyActivity(['brand_id' => 2, 'dedupe_key' => 'sub:1']);

    runSubjectIndexMigration();

    expect(DB::table('activities')->count())->toBe(2);

    // Same key, same brand: still refused.
    expect(fn () => insertLegacyActivity(['brand_id' => 1, 'dedupe_key' => 'sub:1']))
        ->toThrow(Illuminate\Database\QueryException::class);

    // Same key, a third brand: still allowed.
    insertLegacyActivity(['brand_id' => 3, 'dedupe_key' => 'sub:1']);

    expect(DB::table('activities')->count())->toBe(3);
});
