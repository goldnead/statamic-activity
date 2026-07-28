<?php

use Illuminate\Support\Facades\DB;

/**
 * Measures every index this addon's migrations create the way MySQL would.
 *
 * The suite runs on in-memory SQLite, and SQLite has no InnoDB key-length
 * limit, no per-character byte cost and no fixed column widths — it accepts
 * `varchar(255)` and ignores the 255. Every mechanism that rejects an oversized
 * index is a MySQL mechanism, so a migration MySQL refuses outright passes this
 * suite without a murmur. `statamic-notifications` v1.0.3 shipped exactly that
 * way: a 3212-byte unique that had run hundreds of times locally and died on
 * the production hub with *SQLSTATE 1071*.
 *
 * So this test does not ask a database. It compiles the addon's own migration
 * files through Laravel's MySQL grammar in pretend mode — no server, no
 * connection, nothing to install in CI — and measures the DDL MySQL would have
 * received. It reads the real migration files, so it cannot drift from them.
 *
 * What is measured is the schema of a **fresh install**. A migration guarded by
 * `Schema::hasTable()` or `hasIndex()` sees no rows under pretend and stops, so
 * an upgrade path is not covered here — `tests/Feature/UpgradeMigrationTest.php`
 * is where an existing install's schema is proven to arrive at the same place.
 *
 * Ported from `statamic-notifications` v1.0.4.
 */
const INNODB_MAX_KEY_BYTES = 3072;

/**
 * Uniques that are deliberately not binding when a column is NULL, with the
 * reason. A SQL unique does not constrain NULL on any engine, so leaving a
 * nullable column inside one is a decision — and in notifications it was an
 * unnoticed one, which is why every contact preference went unconstrained in
 * the very table written to keep them apart. Anything not listed here has to
 * cover NOT NULL columns only.
 */
const DELIBERATELY_NULL_PERMISSIVE_UNIQUES = [
    // A row without a dedupe key is a fact nobody asked to be deduplicated:
    // `event_id` is its idempotency key instead. ActivityRecorder never looks a
    // duplicate up by a NULL dedupe key, so the check and the constraint agree
    // about which rows this index governs — see DedupeKeyTest.
    'act_brand_dedupe_unique' => 'dedupe_key',
];

it('keeps every index the migrations create inside the InnoDB key limit', function () {
    $schema = compileActivityMigrationsForMysql();

    expect($schema['indexes'])->not->toBeEmpty();

    foreach ($schema['indexes'] as $index) {
        $bytes = activityIndexWidth($index, $schema);

        expect($bytes)->toBeLessThanOrEqual(
            INNODB_MAX_KEY_BYTES,
            "Index {$index['name']} on {$index['table']} needs {$bytes} bytes under utf8mb4; ".
            'InnoDB allows '.INNODB_MAX_KEY_BYTES.'. MySQL would refuse this migration with SQLSTATE 1071.'
        );
    }
});

it('still spends less than half the key limit, leaving room for another column', function () {
    // Being under the limit by accident is what made the notifications schema
    // fragile: its digest-runs unique sat at 2196 of 3072 and would have broken
    // on the next column added to it. Headroom is asserted, not hoped for.
    $schema = compileActivityMigrationsForMysql();

    foreach ($schema['indexes'] as $index) {
        $bytes = activityIndexWidth($index, $schema);

        expect($bytes)->toBeLessThan(
            INNODB_MAX_KEY_BYTES / 2,
            "Index {$index['name']} on {$index['table']} uses {$bytes} bytes — over half the limit, ".
            'so the next column added to it is likely to break the migration.'
        );
    }
});

it('lets no unique index cover a nullable column without saying why', function () {
    $schema = compileActivityMigrationsForMysql();

    $uniques = array_filter($schema['indexes'], fn ($index) => $index['unique']);

    expect($uniques)->not->toBeEmpty();

    foreach ($uniques as $index) {
        $excused = DELIBERATELY_NULL_PERMISSIVE_UNIQUES[$index['name']] ?? null;

        foreach ($index['columns'] as $column) {
            if ($column === $excused) {
                continue;
            }

            expect($schema['columns'][$index['table']][$column]['nullable'] ?? true)->toBeFalse(
                "Unique {$index['name']} on {$index['table']} covers the nullable column {$column}. ".
                'Rows leaving it NULL are not constrained at all, so the index does not enforce '.
                'what its name claims. If that is intended, say so in '.
                'DELIBERATELY_NULL_PERMISSIVE_UNIQUES and prove it with a test.'
            );
        }
    }
});

it('keeps the ledger addressable per brand, with brand_id leading every composite index', function () {
    // Every read of this table runs under the brand scope, so an index that
    // does not lead with brand_id cannot serve it. `act_subject_idx` was the one
    // exception until 1.0.3 — and, not coincidentally, the widest index here.
    $schema = compileActivityMigrationsForMysql();

    foreach ($schema['indexes'] as $index) {
        if (count($index['columns']) < 2) {
            continue;
        }

        expect($index['columns'][0])->toBe(
            'brand_id',
            "Composite index {$index['name']} on {$index['table']} does not lead with brand_id, ".
            'so the brand scope cannot use it.'
        );
    }
});

/** Worst-case bytes this index occupies under utf8mb4. */
function activityIndexWidth(array $index, array $schema): int
{
    $bytes = 0;

    foreach ($index['columns'] as $column) {
        $definition = $schema['columns'][$index['table']][$column] ?? null;

        expect($definition)->not->toBeNull("Index {$index['name']} covers unknown column {$column}.");

        $bytes += $definition['bytes'];
    }

    return $bytes;
}

/**
 * Replays every migration against a MySQL connection that is never opened and
 * returns the column widths and index definitions MySQL would end up with.
 *
 * @return array{columns: array<string, array<string, array{bytes: int, nullable: bool}>>, indexes: list<array{table: string, name: string, unique: bool, columns: list<string>}>}
 */
function compileActivityMigrationsForMysql(): array
{
    config()->set('database.connections.key_length_probe', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'key_length_probe',
        'username' => 'probe',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ]);

    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('key_length_probe');

    try {
        // pretend() short-circuits every statement before a PDO instance is
        // needed, so this compiles the DDL without a server in sight.
        $queries = DB::connection('key_length_probe')->pretend(function () {
            foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) {
                (require $file)->up();
            }
        });
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('key_length_probe');
    }

    $columns = [];
    $indexes = [];

    foreach (array_column($queries, 'query') as $sql) {
        if (preg_match('/^create table `(\w+)` \((.*?)\)(?: default character set| collate|$)/s', $sql, $match)) {
            foreach (activitySplitTopLevel($match[2]) as $definition) {
                if (preg_match('/^`(\w+)` (.+)$/', trim($definition), $column)) {
                    $columns[$match[1]][$column[1]] = activityDescribeColumn($column[2]);
                }
            }

            continue;
        }

        if (preg_match('/^alter table `(\w+)` add (unique|index) `(\w+)`\((.+)\)$/', $sql, $match)) {
            $indexes[$match[3]] = [
                'table' => $match[1],
                'name' => $match[3],
                'unique' => $match[2] === 'unique',
                'columns' => array_map(fn ($column) => trim($column, ' `'), explode(',', $match[4])),
            ];

            continue;
        }

        // Columns added or retyped by a later migration. Without these, what is
        // measured is the schema the create-migration described rather than the
        // one that ends up on disk.
        if (preg_match('/^alter table `(\w+)` (add|modify) (`\w+` .+)$/', $sql, $match)) {
            foreach (explode(', '.$match[2].' ', $match[3]) as $definition) {
                if (preg_match('/^`(\w+)` (.+)$/', trim($definition), $column)) {
                    $columns[$match[1]][$column[1]] = activityDescribeColumn($column[2]);
                }
            }

            continue;
        }

        if (preg_match('/^alter table `(\w+)` drop index `(\w+)`$/', $sql, $match)) {
            unset($indexes[$match[2]]);
        }
    }

    return ['columns' => $columns, 'indexes' => array_values($indexes)];
}

/** Splits a column list on commas that are not inside parentheses. */
function activitySplitTopLevel(string $list): array
{
    $parts = [];
    $depth = 0;
    $buffer = '';

    foreach (str_split($list) as $character) {
        if ($character === '(') {
            $depth++;
        } elseif ($character === ')') {
            $depth--;
        }

        if ($character === ',' && $depth === 0) {
            $parts[] = $buffer;
            $buffer = '';

            continue;
        }

        $buffer .= $character;
    }

    return array_merge($parts, [$buffer]);
}

/** @return array{bytes: int, nullable: bool} */
function activityDescribeColumn(string $type): array
{
    return [
        'bytes' => activityIndexBytes($type),
        // The grammar always states one or the other explicitly, so the absence
        // of "not null" is a decision rather than a default.
        'nullable' => ! str_contains($type, ' not null'),
    ];
}

/** Worst-case bytes this column type occupies in an index under utf8mb4. */
function activityIndexBytes(string $type): int
{
    if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $match)) {
        return (int) $match[1] * 4;
    }

    return match (true) {
        str_starts_with($type, 'tinyint') => 1,
        str_starts_with($type, 'smallint') => 2,
        str_starts_with($type, 'mediumint') => 3,
        str_starts_with($type, 'int') => 4,
        str_starts_with($type, 'bigint') => 8,
        str_starts_with($type, 'timestamp'), str_starts_with($type, 'datetime') => 8,
        str_starts_with($type, 'date') => 3,
        // Blobs, text and json cannot be indexed whole at all. Reported as
        // oversized so an index that reaches for one fails here rather than on
        // MySQL.
        default => INNODB_MAX_KEY_BYTES + 1,
    };
}
