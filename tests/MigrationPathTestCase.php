<?php

namespace Goldnead\Activity\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A bed for migrating a database by hand, from any released schema forward.
 *
 * The rest of the suite runs against a database that `RefreshDatabase` has
 * already migrated to head, which is the one shape a migration can never be
 * wrong about. Everything here needs the opposite: an empty database, an
 * arbitrary earlier release installed into it, rows put in, and then the
 * migrations run one at a time with the tables no longer empty.
 *
 * That cannot share the suite's connection. `RefreshDatabase` wraps every test
 * in a transaction, and DDL under MySQL commits implicitly — a `migrate` run
 * inside that transaction would end it and leak its tables into every test
 * that followed. So these tests get a connection of their own, outside
 * anything the trait manages: a temp-file SQLite database by default, and a
 * second throwaway schema beside the configured one when the suite is pointed
 * at MySQL (see phpunit.mysql.xml). It is torn down between tests either way.
 *
 * `brands` is a hard precondition for this addon rather than a nicety.
 * `activities.brand_id` is NOT NULL from the very first migration and every
 * index in the table leads with it, so there is no version of this ledger that
 * can be seeded without a brand to point the rows at. The isolated database is
 * therefore given statamic-brand-context's migrations before anything of this
 * addon's own runs.
 */
abstract class MigrationPathTestCase extends TestCase
{
    /**
     * The name of the isolated connection these tests migrate.
     */
    protected const CONNECTION = 'migration_path';

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->dropIsolatedSqliteFile();

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.'.self::CONNECTION, $this->isolatedConnection());

        // A server-level handle with no database selected, used for nothing but
        // `create database`. Issuing that on the suite's own connection would
        // implicitly commit the transaction RefreshDatabase is holding open,
        // and every test after this one would roll back into nothing.
        $app['config']->set('database.connections.'.self::CONNECTION.'_server', [
            ...$this->isolatedConnection(),
            'database' => null,
        ]);
    }

    /**
     * Mirrors TestCase::testingConnection(), so these tests exercise the same
     * engine the rest of the run does — including the MySQL run, where the
     * index rules and the fixed column widths that SQLite does not have are
     * the whole point.
     */
    protected function isolatedConnection(): array
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            return [
                'driver' => 'sqlite',
                'database' => $this->isolatedSqlitePath(),
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => $this->isolatedDatabaseName(),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }

    protected function isolatedDatabaseName(): string
    {
        return env('DB_DATABASE', 'activity_test').'_migration_path';
    }

    protected function isolatedSqlitePath(): string
    {
        return sys_get_temp_dir().'/activity-migration-path-'.getmypid().'.sqlite';
    }

    /**
     * Empty database, brand-context installed, nothing of this addon's own.
     */
    protected function resetIsolatedDatabase(): void
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            $this->dropIsolatedSqliteFile();
            touch($this->isolatedSqlitePath());
        } else {
            DB::connection(self::CONNECTION.'_server')->statement(
                'create database if not exists `'.$this->isolatedDatabaseName().'` character set utf8mb4 collate utf8mb4_unicode_ci'
            );

            DB::purge(self::CONNECTION.'_server');
        }

        DB::purge(self::CONNECTION);

        Schema::connection(self::CONNECTION)->dropAllTables();

        DB::purge(self::CONNECTION);

        $this->migratePath(__DIR__.'/../vendor/goldnead/statamic-brand-context/database/migrations');
    }

    protected function dropIsolatedSqliteFile(): void
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            DB::purge(self::CONNECTION);

            if (file_exists($this->isolatedSqlitePath())) {
                @unlink($this->isolatedSqlitePath());
            }
        }
    }

    /**
     * Run every not-yet-run migration in a directory against the isolated
     * connection. Failures are not swallowed: the point of these tests is what
     * happens when one throws.
     */
    protected function migratePath(string $path): void
    {
        // `Migrator::setConnection()` makes the migrated connection the default
        // one for the duration, which is what lets a migration written with the
        // bare `DB::` and `Schema::` facades reach the isolated database at all.
        // It does not put the default back afterwards, so this does: leaving it
        // pointed at `migration_path` would send RefreshDatabase's rollback to a
        // connection it never opened a transaction on.
        $default = DB::getDefaultConnection();

        try {
            Artisan::call('migrate', [
                '--database' => self::CONNECTION,
                '--path' => $path,
                '--realpath' => true,
                '--force' => true,
            ]);
        } finally {
            DB::setDefaultConnection($default);
        }
    }

    /**
     * Run the migrations in a directory one file at a time, handing control
     * back between each so a caller can put rows in first.
     *
     * @param  callable(string): void|null  $before  receives the migration name
     */
    protected function migrateStepwise(string $path, ?callable $before = null): void
    {
        foreach ($this->migrationFilesIn($path) as $file) {
            if ($before) {
                $before(basename($file, '.php'));
            }

            $this->migratePath($file);
        }
    }

    /**
     * @return list<string>
     */
    protected function migrationFilesIn(string $path): array
    {
        $files = glob(rtrim($path, '/').'/*.php') ?: [];

        sort($files);

        return $files;
    }

    protected function releasedMigrations(string $version): string
    {
        return __DIR__.'/Fixtures/released-migrations/'.$version;
    }

    protected function currentMigrations(): string
    {
        return __DIR__.'/../database/migrations';
    }

    protected function isolated(): \Illuminate\Database\Connection
    {
        return DB::connection(self::CONNECTION);
    }

    protected function isolatedSchema(): \Illuminate\Database\Schema\Builder
    {
        return Schema::connection(self::CONNECTION);
    }

    /**
     * The migration names the isolated database has recorded as run.
     *
     * @return list<string>
     */
    protected function ranMigrations(): array
    {
        if (! $this->isolatedSchema()->hasTable('migrations')) {
            return [];
        }

        return $this->isolated()->table('migrations')->pluck('migration')->all();
    }

    /**
     * Whether the ledger will accept a second row carrying a dedupe key it has
     * already recorded for that brand.
     *
     * `act_brand_dedupe_unique` over `(brand_id, dedupe_key)` is the guarantee
     * the whole ledger rests on: a producer that fires twice — a retried job, a
     * redelivered webhook, a second addon reporting the same fact — must not be
     * able to write the fact twice. This check is deliberately behavioural.
     * "The migration ran" and "the constraint is there" are not the same
     * statement. An index by name can exist over the wrong columns, over a
     * column that has since become nullable, or not bite at all; the only thing
     * that settles it is writing the row the constraint is supposed to refuse
     * and seeing what the database does.
     *
     * The copy carries a fresh `event_id`, so a refusal can only have come from
     * the dedupe unique and not from the event-id one. Anything that slips
     * through is removed again, because the caller's next assertion is usually
     * about how many rows the ledger holds.
     */
    protected function duplicateDedupeKeyIsAccepted(string $dedupeKey): bool
    {
        $row = $this->rowByDedupeKey($dedupeKey);

        $copy = collect($row)
            ->except('id')
            ->put('event_id', (string) Str::uuid())
            ->all();

        return $this->insertIsAccepted($copy);
    }

    /**
     * The same question for the other idempotency key: `event_id` is unique on
     * its own, and guards against one physical event being recorded twice.
     *
     * The copy gets a dedupe key nothing else holds, so a refusal can only have
     * come from the event-id unique.
     */
    protected function duplicateEventIdIsAccepted(string $dedupeKey): bool
    {
        $row = $this->rowByDedupeKey($dedupeKey);

        $copy = collect($row)
            ->except('id')
            ->put('dedupe_key', 'probe:'.Str::uuid())
            ->all();

        return $this->insertIsAccepted($copy);
    }

    /**
     * Whether the same dedupe key is accepted under a *different* brand.
     *
     * The counterpart to the check above, and the reason it is not enough on
     * its own. A migration that rebuilt the unique over `dedupe_key` alone
     * would make every probe for a refused duplicate pass — while quietly
     * turning a per-tenant guarantee into a global one, so that one brand's
     * producer could block another brand's fact from ever being recorded. The
     * only way to tell the two apart is to write the row the *narrower*
     * constraint has no business refusing.
     */
    protected function sameDedupeKeyInAnotherBrandIsAccepted(string $dedupeKey): bool
    {
        $row = $this->rowByDedupeKey($dedupeKey);

        $copy = collect($row)
            ->except('id')
            ->put('event_id', (string) Str::uuid())
            ->put('brand_id', $this->secondBrandId())
            ->all();

        return $this->insertIsAccepted($copy);
    }

    /**
     * A brand that is not the default one, created on demand. `activities`
     * carries no foreign key to `brands`, but seeding a real row keeps the
     * fixture honest about what a second tenant looks like.
     */
    protected function secondBrandId(): int
    {
        $existing = $this->isolated()->table('brands')->where('handle', 'second-brand')->value('id');

        if ($existing) {
            return (int) $existing;
        }

        return (int) $this->isolated()->table('brands')->insertGetId([
            'handle' => 'second-brand',
            'name' => 'Second Brand',
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The row a dedupe key belongs to, as an array. Fails loudly rather than
     * letting a probe silently assert nothing.
     */
    protected function rowByDedupeKey(string $dedupeKey): array
    {
        $row = $this->isolated()->table('activities')->where('dedupe_key', $dedupeKey)->first();

        if (! $row) {
            throw new \RuntimeException("No activity carrying the dedupe key [{$dedupeKey}] to duplicate.");
        }

        return (array) $row;
    }

    /**
     * Try the insert, report whether the database took it, and leave no trace
     * either way.
     */
    private function insertIsAccepted(array $row): bool
    {
        try {
            $id = $this->isolated()->table('activities')->insertGetId($row);
        } catch (\Illuminate\Database\QueryException) {
            return false;
        }

        $this->isolated()->table('activities')->where('id', $id)->delete();

        return true;
    }
}
