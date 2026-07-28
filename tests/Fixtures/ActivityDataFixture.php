<?php

namespace Goldnead\Activity\Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * Real-shaped ledger data, insertable into any released schema.
 *
 * A migration test is only worth running against rows. An empty `activities`
 * table will accept any schema change at all — it is the rows that decide
 * whether a column can be narrowed, whether an index can be rebuilt, and
 * whether the dedupe unique still holds afterwards.
 *
 * The awkward part is that the schema those rows go into changes underneath
 * them. `subject_type` was a `varchar(255)` up to 1.0.2 and is a
 * `varchar(191)` from 1.0.3, and a future release will add columns this file
 * has never heard of. A fixture with a fixed column list can only seed one
 * version of the database, and would quietly stop seeding the interesting
 * columns the moment a migration was added.
 *
 * This one asks the schema what it has. Every row is built at its widest, then
 * reduced to the columns that exist at the moment of the insert, and anything
 * NOT NULL that the fixture does not know about is filled generically and
 * uniquely, so a migration added next year is seeded without this file being
 * touched.
 *
 * The shape is the one the ledger actually sees: a mix of subjects and events
 * with no subject at all, identified and anonymous actors, several producers,
 * and — because this addon's migration narrows `subject_type` to 191 and
 * `subject_id` to 128 characters and refuses to truncate — one row sitting
 * exactly on both limits. That row is the difference between a migration that
 * was tested against short values and one that was tested against the widest
 * value it is contracted to keep.
 */
class ActivityDataFixture
{
    /**
     * What the narrowing migration caps the two subject columns at. Duplicated
     * here on purpose: a fixture that read the constants off the migration
     * would stop describing the data and start agreeing with the code under
     * test.
     */
    public const SUBJECT_TYPE_MAX = 191;

    public const SUBJECT_ID_MAX = 128;

    /**
     * The ledger rows. `subject` is `[type, id]` or null for the events that
     * are about nobody in particular — a page view, a broadcast send — which
     * are the majority of a real ledger and the rows most likely to be
     * forgotten by a migration that assumes a subject is always there.
     *
     * @var list<array{type: string, subject: array{0: string, 1: string}|null, source: string, actor: string|null}>
     */
    public const EVENTS = [
        ['type' => 'crm.contact_created', 'subject' => ['Goldnead\\LeadHub\\Models\\Contact', '1041'], 'source' => 'leadhub', 'actor' => 'user:7'],
        ['type' => 'crm.contact_updated', 'subject' => ['Goldnead\\LeadHub\\Models\\Contact', '1041'], 'source' => 'leadhub', 'actor' => 'user:7'],
        ['type' => 'marketing.subscribed', 'subject' => ['Goldnead\\Marketing\\Models\\Subscription', '8f2a1c44-1d3e-4b6a-9f0c-2a1b3c4d5e6f'], 'source' => 'marketing', 'actor' => null],
        ['type' => 'marketing.confirmed', 'subject' => ['Goldnead\\Marketing\\Models\\Subscription', '8f2a1c44-1d3e-4b6a-9f0c-2a1b3c4d5e6f'], 'source' => 'marketing', 'actor' => null],
        ['type' => 'marketing.unsubscribed', 'subject' => ['Goldnead\\Marketing\\Models\\Subscription', '5c7e9a11-8b2d-4e3f-9a6b-7c8d9e0f1a2b'], 'source' => 'marketing', 'actor' => null],
        ['type' => 'site.page_viewed', 'subject' => null, 'source' => 'web', 'actor' => null],
        ['type' => 'site.page_viewed', 'subject' => null, 'source' => 'web', 'actor' => null],
        ['type' => 'site.form_submitted', 'subject' => ['Statamic\\Forms\\Submission', 'kontakt.1753619400.abcdef'], 'source' => 'web', 'actor' => null],
        ['type' => 'course.lesson_completed', 'subject' => ['Goldnead\\Courses\\Models\\Lesson', '312'], 'source' => 'courses', 'actor' => 'user:19'],
        ['type' => 'billing.invoice_paid', 'subject' => ['Goldnead\\Billing\\Models\\Invoice', '2026-000417'], 'source' => 'billing', 'actor' => 'user:19'],
    ];

    /**
     * The index of the row every probe is taken from. It has a subject, an
     * actor and a dedupe key, so it is a fair stand-in for the rows an
     * idempotent producer writes.
     */
    public const PROBE_EVENT = 0;

    public function __construct(private Connection $connection) {}

    /**
     * Put one full generation of ledger rows into the tables that exist.
     *
     * Repeatable: pass a different `$batch` to add another generation without
     * colliding with the last one. Batch 0 is the fixture above verbatim, so
     * assertions can name a row by hand.
     *
     * @return int the number of activity rows written
     */
    public function seed(int $batch = 0): int
    {
        if (! $this->has('activities')) {
            return 0;
        }

        $written = 0;

        foreach (self::EVENTS as $index => $event) {
            $this->insert('activities', [
                'event_id' => (string) Str::uuid(),
                'event_type' => $event['type'],
                'actor_type' => $event['actor'] ? 'user' : null,
                'actor_id' => $event['actor'] ? Str::after($event['actor'], ':') : null,
                'contact_uuid' => $event['source'] === 'leadhub' ? (string) Str::uuid() : null,
                'user_id' => $event['actor'] ? Str::after($event['actor'], ':') : null,
                'anonymous_id' => $event['actor'] ? null : 'anon-'.$batch.'-'.$index,
                'session_id' => 'sess-'.$batch.'-'.$index,
                'source' => $event['source'],
                'subject_type' => $event['subject'][0] ?? null,
                'subject_id' => $event['subject'][1] ?? null,
                'dedupe_key' => self::dedupeKey($index, $batch),
                'properties' => json_encode(['batch' => $batch, 'index' => $index]),
                'context' => json_encode(['ip' => '203.0.113.'.($index + 1), 'ua' => 'fixture']),
                'anonymized' => false,
                'occurred_at' => now()->subMinutes(count(self::EVENTS) - $index),
                'received_at' => now(),
            ]);

            $written++;
        }

        // The widest subject this addon is contracted to keep: exactly on both
        // caps, so the narrowing migration has to accept it and leave it whole.
        // One character more and it would have to refuse — which is the case
        // `insertOverlongSubject()` sets up.
        $this->insert('activities', [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'integration.record_synced',
            'source' => 'integration',
            'subject_type' => self::longSubjectType(),
            'subject_id' => self::longSubjectId(),
            'dedupe_key' => self::dedupeKey('wide', $batch),
            'anonymized' => false,
            'occurred_at' => now(),
            'received_at' => now(),
        ]);

        return $written + 1;
    }

    /**
     * A single row whose `subject_type` is longer than the migration's cap.
     *
     * Only insertable while the column is still a `varchar(255)`, i.e. on a
     * pre-1.0.3 install — which is precisely the install the refusal exists
     * for. Deliberately not part of `seed()`: it is the one row that must stop
     * a migration, so it is put in by the test that wants it stopped.
     *
     * @return int the id the ledger gave it
     */
    public function insertOverlongSubject(int $length = 200): int
    {
        return $this->insert('activities', [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'integration.record_synced',
            'source' => 'integration',
            'subject_type' => self::padded('Goldnead\\Legacy\\Models\\', $length),
            'subject_id' => '99',
            'dedupe_key' => 'activity.fixture.overlong',
            'anonymized' => false,
            'occurred_at' => now(),
            'received_at' => now(),
        ]);
    }

    /**
     * The dedupe key a given fixture row carries. A producer builds these from
     * the fact it is reporting; the shape does not matter here, only that two
     * different rows never share one and that a probe can find one again.
     */
    public static function dedupeKey(int|string $index, int $batch = 0): string
    {
        return 'activity.fixture.'.$batch.'.'.$index;
    }

    /**
     * The dedupe key to probe a given seed batch with.
     */
    public static function dedupeProbe(int $batch = 0): string
    {
        return self::dedupeKey(self::PROBE_EVENT, $batch);
    }

    public static function longSubjectType(): string
    {
        return self::padded('Goldnead\\Integrations\\Models\\', self::SUBJECT_TYPE_MAX);
    }

    public static function longSubjectId(): string
    {
        return self::padded('record-', self::SUBJECT_ID_MAX);
    }

    /**
     * How many rows every table this fixture writes to currently holds.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (self::tables() as $table) {
            if ($this->has($table)) {
                $counts[$table] = $this->connection->table($table)->count();
            }
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    public static function tables(): array
    {
        return ['activities'];
    }

    /**
     * A class-name-shaped string of an exact length. Real class names are what
     * these columns hold, and a value made of one repeated character would not
     * survive a reviewer asking whether the limit is realistic.
     */
    private static function padded(string $prefix, int $length): string
    {
        return substr($prefix.str_repeat('Segment', $length), 0, $length);
    }

    private function has(string $table): bool
    {
        return $this->connection->getSchemaBuilder()->hasTable($table);
    }

    /**
     * Reduce a row to the columns the table has today, add timestamps, fill any
     * NOT NULL column the fixture does not know about, stamp the brand, and
     * insert.
     */
    private function insert(string $table, array $row): int
    {
        $columns = collect($this->connection->getSchemaBuilder()->getColumns($table))
            ->keyBy('name');

        $row = collect($row)
            ->only($columns->keys()->all())
            ->all();

        if ($columns->has('created_at')) {
            $row['created_at'] = now();
            $row['updated_at'] = now();
        }

        // Every index in this table leads with `brand_id` and the column is NOT
        // NULL from the first migration, so there is no shape of this ledger
        // that can be seeded without a brand to hang the rows on.
        if ($columns->has('brand_id') && ! isset($row['brand_id'])) {
            $row['brand_id'] = $this->defaultBrandId();
        }

        foreach ($columns as $name => $column) {
            if (array_key_exists($name, $row)) {
                continue;
            }

            if (($column['auto_increment'] ?? false) || ($column['nullable'] ?? true) || ($column['default'] ?? null) !== null) {
                continue;
            }

            $row[$name] = $this->genericValueFor($column, $table, $name);
        }

        return (int) $this->connection->table($table)->insertGetId($row);
    }

    /**
     * A value for a NOT NULL column this fixture has never heard of.
     *
     * Unique per row, because a column added by a future migration is most
     * likely to be added together with a unique over it — which is the shape
     * this whole file exists to catch.
     */
    private function genericValueFor(array $column, string $table, string $name): string|int
    {
        $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? 'string'));

        return match (true) {
            str_contains($type, 'int') => random_int(1, PHP_INT_MAX),
            str_contains($type, 'bool') => 0,
            str_contains($type, 'date'), str_contains($type, 'time') => (string) now(),
            default => substr(hash('sha256', $table.$name.Str::uuid()), 0, 32),
        };
    }

    private function defaultBrandId(): ?int
    {
        return $this->connection->table('brands')->where('is_default', true)->value('id')
            ?? $this->connection->table('brands')->min('id');
    }
}
