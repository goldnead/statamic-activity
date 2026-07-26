<?php

namespace Goldnead\Activity\Tests;

use Goldnead\Activity\ServiceProvider;
use Goldnead\BrandContext\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Statamic\Providers\StatamicServiceProvider;
use Statamic\Statamic;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Testbench never fires Statamic::booted callbacks, so bootAddon() —
        // which loads the migrations, views, nav, permissions and producers —
        // has to be invoked by hand.
        $this->app->getProvider(ServiceProvider::class)?->bootAddon();

        $this->artisan('migrate')->run();

        // The CP layout pulls Statamic's own Vite bundle, which is not published
        // into the testbench app. The inspector's markup is what we assert on.
        $this->withoutVite();

        $this->giveTestbenchAComposerLock();

        app('brand-context')->forget();
        app('identity-context')->forget();
        app('activity')->producers()->forget();
    }

    protected function getPackageProviders($app): array
    {
        return [
            StatamicServiceProvider::class,
            \Goldnead\BrandContext\ServiceProvider::class,
            \Goldnead\IdentityContracts\ServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Statamic' => Statamic::class,
            'Activity' => \Goldnead\Activity\Facades\Activity::class,
            'BrandContext' => \Goldnead\BrandContext\Facades\BrandContext::class,
            'IdentityContext' => \Goldnead\IdentityContracts\Facades\IdentityContext::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        // File-backed users: the ledger has no opinion on where users live, and
        // the CP route tests only need somebody the gate recognises.
        $app['config']->set('statamic.users.repository', 'file');
        $app['config']->set('brand-context.multi_brand', false);
        $app['config']->set('activity.enabled', true);
        $app['config']->set('activity.source', 'test-suite');
        $app['config']->set('queue.default', 'sync');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(['web'])
            ->prefix('cp')
            ->name('statamic.cp.')
            ->group(__DIR__.'/../routes/cp.php');
    }

    /**
     * Statamic's CP layout resolves its own version from `base_path('composer.lock')`
     * and throws without one. The testbench app has no lock file of its own, so
     * lend it ours — otherwise no CP view can be rendered in a test at all.
     */
    protected function giveTestbenchAComposerLock(): void
    {
        $target = base_path('composer.lock');

        if (file_exists($target)) {
            return;
        }

        $source = __DIR__.'/../composer.lock';

        if (file_exists($source)) {
            @copy($source, $target);
        }
    }

    protected function enableMultiBrand(): void
    {
        config()->set('brand-context.multi_brand', true);
        app('brand-context')->forget();
    }

    protected function makeBrand(string $handle): Brand
    {
        return Brand::create(['handle' => $handle, 'name' => ucfirst($handle)]);
    }
}
