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
        $app['config']->set('database.connections.testing', $this->testingConnection());

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        // File-backed users: the ledger has no opinion on where users live, and
        // the CP route tests only need somebody the gate recognises.
        $app['config']->set('statamic.users.repository', 'file');
        $app['config']->set('brand-context.multi_brand', false);
        $app['config']->set('activity.enabled', true);
        $app['config']->set('activity.source', 'test-suite');
        $app['config']->set('queue.default', 'sync');
    }

    /**
     * In-memory SQLite by default, so the suite keeps running anywhere with no
     * setup. Set `DB_DRIVER=mysql` to point the identical suite at a real MySQL
     * server instead — see phpunit.mysql.xml.
     *
     * SQLite is not a substitute for that run. It has no InnoDB key-length
     * limit, no utf8mb4 byte arithmetic and no fixed column widths, which is
     * precisely why a fully green suite let an unbuildable index reach
     * production in statamic-notifications v1.0.3.
     */
    protected function testingConnection(): array
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'activity_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
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
