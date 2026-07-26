<?php

namespace Goldnead\Activity\Console;

use Goldnead\Activity\Models\Activity;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Erasure without amnesia: the personal fields go, the countable fact stays.
 * This is what a deletion request should usually do to a ledger — the fact that
 * *a* purchase happened is business record, the identity behind it is not.
 */
class AnonymizeActivitiesCommand extends Command
{
    protected $signature = 'activity:anonymize
                            {--contact= : Anonymise everything belonging to a contact uuid}
                            {--user= : Anonymise everything belonging to a user id}
                            {--anonymous-id= : Anonymise everything belonging to a pseudonymous visitor id}
                            {--days= : Anonymise everything older than N days (defaults to the configured window)}
                            {--dry-run : Report what would be anonymised without writing}';

    protected $description = 'Strip personal fields from activities while keeping the facts.';

    public function handle(): int
    {
        $query = Activity::withoutGlobalScopes();
        $targeted = false;

        if ($contact = $this->option('contact')) {
            $query->where('contact_uuid', $contact);
            $targeted = true;
        }

        if ($user = $this->option('user')) {
            $query->where('user_id', $user);
            $targeted = true;
        }

        if ($anonymousId = $this->option('anonymous-id')) {
            $query->where('anonymous_id', $anonymousId);
            $targeted = true;
        }

        $days = $this->option('days') ?? config('activity.retention.anonymize_after_days');

        if ($days !== null) {
            $query->where('occurred_at', '<', Carbon::now()->subDays((int) $days));
            $targeted = true;
        }

        if (! $targeted) {
            $this->components->error('Nothing selected. Pass --contact, --user, --anonymous-id or --days.');

            return self::FAILURE;
        }

        // Rows already stripped must not be counted or rewritten again, so a
        // repeated run is a no-op rather than a fresh sweep.
        $query->where('anonymized', false);

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->components->info('Nothing to anonymise.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->info('Would anonymise '.$count.' activities.');

            return self::SUCCESS;
        }

        Activity::mutable(fn () => $this->strip($query));

        $this->components->info('Anonymised '.$count.' activities.');

        return self::SUCCESS;
    }

    protected function strip(Builder $query): void
    {
        $query->update([
            'contact_uuid' => null,
            'user_id' => null,
            'anonymous_id' => null,
            'session_id' => null,
            'actor_id' => null,
            'properties' => null,
            'context' => null,
            'anonymized' => true,
        ]);
    }
}
