<?php

namespace Goldnead\Activity\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The check a CP screen runs before its first query.
 *
 * The ledger is a table-backed addon whose Control Panel screens do nothing but
 * read `activities`. Install the addon without running its migrations — the
 * ordinary state of a fresh site, and of a demo — and the first thing
 * `/cp/activity` does is `Activity::query()->exists()`, which throws
 * `no such table: activities` while the page is being built. That is an
 * operator's unfinished setup, not a bug, and it owes the reader a sentence
 * rather than a stack trace.
 *
 * The reason must not disappear along with the 500, though: every guarded
 * screen that turns somebody away writes why to the log first. A page that
 * renders an empty state and says nothing anywhere would be worse than the
 * crash it replaced — the site would look installed and never record a thing.
 */
final class Setup
{
    /**
     * The setup screen for a CP page, or null when the page can run.
     *
     * @param  string  $title  The page's own heading, so the screen still reads as that page.
     * @param  string  ...$tables  Every table the page touches while rendering.
     */
    public static function guard(string $title, string ...$tables): ?Response
    {
        $missing = array_values(array_filter(
            $tables,
            fn (string $table) => ! Schema::hasTable($table)
        ));

        if ($missing === []) {
            return null;
        }

        Log::error(sprintf(
            'statamic-activity: the CP page "%s" cannot load because these database tables do not exist: %s. Run `php artisan migrate`.',
            $title,
            implode(', ', $missing)
        ));

        return Inertia::render('activity::SetupRequired', [
            'title' => $title,
            'heading' => __('activity::cp.setup_required_heading'),
            'description' => __('activity::cp.setup_required_description'),
            'tables' => $missing,
        ]);
    }
}
