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
use Goldnead\Activity\Support\Settings;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    /**
     * Statamic 6 reads an addon's Vite config from this property and from
     * nowhere else — `extra.statamic.vite` in composer.json is carried for
     * documentation, but the CP never looks at it. The three values have to
     * byte-match `laravel()` in vite.config.js, or registerVite() publishes
     * from a directory the build never wrote to and the CP loads nothing.
     *
     * The parent annotates this `list<string>`, which is not what registerVite()
     * reads — it asks for `input`, `publicDirectory` and `hotFile` by key. The
     * annotation is wrong upstream and cannot be corrected from here: a `@var`
     * describing the real shape is rejected as non-covariant with the parent's.
     * Hence the one baseline entry, which every sibling addon carries too.
     */
    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
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

    /**
     * Die Einstellungs-Seite wird hier angemeldet, nicht in `bootAddon()`.
     *
     * Das ist keine Stilfrage. `statamic-brand-context` schreibt die
     * gespeicherten Abweichungen aus einem `app->booted()`-Rückruf auf die
     * Konfiguration, absichtlich, damit jedes `boot()` sich vorher anmelden
     * konnte. `bootAddon()` läuft selbst aus einem `app->booted()`-Rückruf
     * (Statamics AppServiceProvider), und welcher der beiden zuerst feuert,
     * hängt an der Ladereihenfolge der Pakete — eine Anmeldung dort erreicht
     * die Konfiguration auf manchen Installationen und auf anderen nicht,
     * ohne dass irgendetwas auf dem Bildschirm sagt, auf welchen.
     */
    public function boot(): void
    {
        parent::boot();

        $this->app->make(SettingsRegistry::class)->register(Settings::class);
    }

    public function bootAddon(): void
    {
        // No loadViewsFrom: the Control Panel is Inertia + Vue and the addon
        // ships no Blade views at all any more. Registering an empty namespace
        // would only advertise an extension point that does not exist.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

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

                // Bewacht den Abschnitt dieses Addons auf der gemeinsamen
                // Einstellungs-Seite. Daneben, nicht darunter: die Zahlen für
                // Aufbewahrung und Anonymisierung ändert, wer die
                // Datenpolitik verantwortet, und das ist nicht zwingend, wer
                // in den Ereignissen liest.
                //
                // Anders als `manage activity retention`, das hier einmal
                // stand und nichts bewachte: dieses Recht bewacht ein
                // Formular, nicht einen Artisan-Befehl.
                Permission::register('manage activity settings')
                    ->label(__('activity::settings.permission_manage_settings'));
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
