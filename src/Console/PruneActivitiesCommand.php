<?php

namespace Goldnead\Activity\Console;

use Goldnead\Activity\Models\Activity;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PruneActivitiesCommand extends Command
{
    protected $signature = 'activity:prune
                            {--days= : Override the configured global retention window}
                            {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Delete activities past their retention window.';

    public function handle(): int
    {
        $global = $this->option('days') ?? config('activity.retention.days');
        $perType = (array) config('activity.retention.per_event_type', []);

        if ($global === null && $perType === []) {
            $this->components->info('No retention window configured — nothing to prune.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $total = 0;

        foreach ($perType as $eventType => $days) {
            $total += $this->pruneWhere(
                fn ($query) => $query->where('event_type', $eventType)->where('occurred_at', '<', Carbon::now()->subDays((int) $days)),
                $dryRun,
                $eventType.' (> '.$days.'d)',
            );
        }

        if ($global !== null) {
            // Types with their own window were handled above and must not be
            // caught a second time by the global sweep.
            $total += $this->pruneWhere(
                fn ($query) => $query
                    ->when($perType !== [], fn ($q) => $q->whereNotIn('event_type', array_keys($perType)))
                    ->where('occurred_at', '<', Carbon::now()->subDays((int) $global)),
                $dryRun,
                'all other types (> '.$global.'d)',
            );
        }

        $this->components->info(($dryRun ? 'Would delete ' : 'Deleted ').$total.' activities.');

        return self::SUCCESS;
    }

    protected function pruneWhere(callable $constraint, bool $dryRun, string $label): int
    {
        // Retention runs across every brand: it is an operator action on the
        // whole store, not something scoped to whoever happens to be logged in.
        $query = $constraint(Activity::withoutGlobalScopes());

        $count = (clone $query)->count();

        if ($count === 0) {
            return 0;
        }

        $this->line(sprintf('  %s: %d', $label, $count));

        if (! $dryRun) {
            Activity::mutable(fn () => $query->delete());
        }

        return $count;
    }
}
