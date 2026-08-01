<?php

namespace Goldnead\Activity;

use Goldnead\Activity\Console\AnonymizeActivitiesCommand;
use Goldnead\Activity\Console\PruneActivitiesCommand;
use Goldnead\Activity\Contracts\ActivitySanitizer;
use Goldnead\Activity\Producers\LeadHubProducer;
use Goldnead\Activity\Producers\MarketingProducer;
use Goldnead\Activity\Producers\ProducerRegistry;
use Goldnead\Activity\Sanitizers\DefaultActivitySanitizer;
use Goldnead\Activity\Scopes\Filters;
use Goldnead\Activity\Support\ContextCapture;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    /**
     * The filters offered by the Control Panel listing. Registered explicitly
     * rather than autoloaded: AddonServiceProvider only scans `Scopes`,
     * `Query/Scopes` and `Query/Scopes/Filters` at their top level, and these
     * live one folder deeper.
     */
    protected $scopes = [
        Filters\EventType::class,
        Filters\Source::class,
        Filters\Identity::class,
        Filters\OccurredAt::class,
        Filters\Anonymized::class,
    ];

    /**
     * Read by bootCommands() below, not by the parent. The parent's $commands
     * path only fires once Statamic has booted, which leaves the commands
     * missing in a plain console or test context — see bootCommands().
     */
    protected $commands = [
        PruneActivitiesCommand::class,
        AnonymizeActivitiesCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/activity.php', 'activity');

        // Registered against the resolving translator rather than in boot: the
        // nav and permission labels are built before bootAddon() runs.
        $this->app->resolving('translator', function ($translator): void {
            $translator->addNamespace('activity', __DIR__.'/../resources/lang');
        });

        $this->app->singleton(ProducerRegistry::class);
        $this->app->singleton(ContextCapture::class);

        $this->app->singleton('activity', fn ($app) => new ActivityRecorder(
            $app->make(ProducerRegistry::class),
            $app->make(ContextCapture::class),
        ));
        $this->app->alias('activity', ActivityRecorder::class);

        $this->app->bind(ActivitySanitizer::class, DefaultActivitySanitizer::class);
    }

    public function bootAddon(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'activity');

        $this->registerNavigation()
            ->registerPermissions()
            ->bootCommands()
            ->bootFilters()
            ->registerProducers()
            ->registerPublishables();
    }

    /**
     * Same reasoning as bootCommands(): the parent's $scopes path only runs once
     * Statamic's own boot sequence has fired, which never happens in a plain
     * console or test context. Registration is idempotent, so the parent
     * repeating it later costs nothing.
     */
    protected function bootFilters(): self
    {
        foreach ($this->scopes as $scope) {
            $scope::register();
        }

        return $this;
    }

    /**
     * Registered explicitly rather than relying on the addon's $commands
     * property: that path only runs once Statamic's own boot sequence has fired,
     * which leaves the commands missing in a plain console or test context.
     */
    protected function bootCommands(): self
    {
        if ($this->app->runningInConsole()) {
            $this->commands($this->commands);
        }

        return $this;
    }

    /**
     * Bundled producers attach only when the sibling addon is present. The
     * dependency stays one-directional: activity knows about marketing, never
     * the other way round.
     */
    protected function registerProducers(): self
    {
        $registry = $this->app->make(ProducerRegistry::class);

        if (config('activity.producers.marketing', true) && class_exists(\Goldnead\Marketing\Events\MarketingSubscribed::class)) {
            MarketingProducer::register($registry);
        }

        if (config('activity.producers.leadhub', true) && class_exists(\Goldnead\Leadhub\Events\LeadHubContactCreated::class)) {
            LeadHubProducer::register($registry);
        }

        return $this;
    }

    protected function registerNavigation(): self
    {
        if (! config('activity.cp.enabled', true)) {
            return $this;
        }

        Nav::extend(function ($nav): void {
            $nav->create(__('activity::cp.nav'))
                ->section('Tools')
                // A name from Statamic's own set, not a raw SVG: only the named
                // icons pick up the CP's sizing and stroke conventions.
                ->icon('pulse')
                ->route('activity.index')
                ->can('view activity');
        });

        return $this;
    }

    protected function registerPermissions(): self
    {
        // One permission, because there is one thing to permit. `manage
        // activity retention` used to sit beneath this and was checked
        // nowhere: retention and anonymisation are artisan-only paths and
        // artisan does not consult Gates. A checkbox that controls nothing is
        // worse than no checkbox.
        Permission::extend(function (): void {
            Permission::group('activity', __('activity::cp.nav'), function (): void {
                Permission::register('view activity')
                    ->label(__('activity::cp.permission_view'));
            });
        });

        return $this;
    }

    protected function registerPublishables(): self
    {
        $this->publishes([
            __DIR__.'/../config/activity.php' => config_path('activity.php'),
        ], 'activity-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'activity-migrations');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/activity'),
        ], 'activity-translations');

        return $this;
    }
}
