<?php

namespace Goldnead\Activity\Tests;

/**
 * Base case for the integration suite: it boots the sibling addons so the
 * bundled producers can be exercised against their real events, and skips
 * itself entirely when those addons are not installed.
 */
abstract class SiblingsTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(\Goldnead\Marketing\Events\MarketingSubscribed::class)) {
            $this->markTestSkipped('goldnead/statamic-marketing is not installed.');
        }

        parent::setUp();
    }

    /**
     * The siblings have to boot before this addon does, so its producers can
     * see their events. Selected by name rather than by position — a positional
     * slice silently drops whichever provider the parent adds next.
     */
    protected function getPackageProviders($app): array
    {
        $foundation = array_values(array_filter(
            parent::getPackageProviders($app),
            fn (string $provider) => $provider !== \Goldnead\Activity\ServiceProvider::class,
        ));

        return [
            ...$foundation,
            \Goldnead\Leadhub\ServiceProvider::class,
            \Goldnead\Marketing\ServiceProvider::class,
            \Goldnead\Activity\ServiceProvider::class,
        ];
    }

    /**
     * The sibling addons ship their own migrations; the integration suite needs
     * their tables to dispatch real events against real models.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../vendor/goldnead/statamic-leadhub/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/goldnead/statamic-marketing/database/migrations');
    }

    /**
     * Marketing builds an unsubscribe URL inside its own event payload, so its
     * public routes have to exist before any of its events can be dispatched.
     */
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->middleware(['web'])->group(__DIR__.'/../vendor/goldnead/statamic-marketing/routes/web.php');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Flat-file marketing storage is not brand-isolatable; the ledger tests
        // need the eloquent driver for the same reason the hub does.
        $app['config']->set('marketing.driver', 'eloquent');
    }
}
