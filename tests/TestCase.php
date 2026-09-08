<?php

namespace Goldnead\Activity\Tests;

use Goldnead\Activity\ServiceProvider;
use Goldnead\BrandContext\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Inertia;
use Orchestra\Testbench\TestCase as Orchestra;
use Statamic\Http\Middleware\CP\HandleInertiaRequests;
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
        // into the testbench app. The props the server ships are what we assert on.
        $this->withoutVite();

        // Testbench flushes the addon's CP routes into Laravel's `web` group
        // rather than into `statamic.cp`, so Statamic's HandleInertiaRequests
        // never runs and Inertia keeps its own default root view — every CP
        // page would 500 on "View [app] not found". Naming the CP's root view
        // here is exactly what core does when it has to render outside that
        // middleware (RendersControlPanelExceptions).
        //
        // Read through `defined()` rather than named directly: the constant is
        // newer than the `statamic/cms: ^6.0` this addon supports, so on the
        // lowest core the suite is meant to prove installable, naming it was a
        // fatal error before a single test ran. Nothing in `src/` needs it — the
        // addon runs on those cores, only the harness did not — so tolerating
        // its absence is the honest fix, and raising the constraint to make the
        // harness happy would have narrowed the addon for no reason. The literal
        // is the value the constant carries and the CP layout's stable name.
        Inertia::setRootView(
            defined(HandleInertiaRequests::class.'::ROOT_VIEW')
                ? HandleInertiaRequests::ROOT_VIEW
                : 'statamic::layout'
        );

        $this->giveTestbenchAComposerLock();
        $this->forgetUsersLeftBehindByOtherTests();

        app('brand-context')->forget();
        app('identity-context')->forget();
        app('activity')->producers()->forget();

        $this->unpinSettingsLeftOnTheConfigByTheBoot();
    }

    /**
     * Nimmt die Abweichungen von der Config zurueck, die der Boot dieses Tests
     * aus der Datenbank des *vorigen* gelesen hat.
     *
     * Die gemeinsame Einstellungs-Schicht schreibt die gespeicherten Werte aus
     * einem `app->booted()`-Rueckruf auf die Config. Dieser Rueckruf laeuft in
     * `parent::setUp()`, und zwar **bevor** `RefreshDatabase` die Datenbank fuer
     * diesen Test zuruecksetzt. Auf SQLite faellt das nie auf: `:memory:` ist mit
     * jeder Verbindung neu, der Boot findet also nie eine Zeile. Auf MySQL steht
     * die Tabelle bis zum `migrate:fresh` noch so da, wie der vorige Test sie
     * verlassen hat — der Boot liest dessen Zeilen und nagelt sie auf die Config,
     * wo sie auch dann noch stehen, wenn die Tabelle Millisekunden spaeter geleert
     * wird. Gemessen am 08.09.2026 im MySQL-Bein: `SettingsTest` speichert
     * `enabled => false`, und der naechste Test schreibt keine Zeile mehr in den
     * Bestand, ohne dass irgendetwas das mit dem vorigen Test in Verbindung
     * bringt.
     *
     * `app('brand-context')->forget()` allein reicht dagegen nicht: das meldet
     * einen Markenwechsel, und von „keine Marke" auf „keine Marke" ist keiner —
     * der Rueckruf steigt sofort wieder aus. Also hier ausdruecklich, nachdem die
     * Datenbank steht: Zwischenspeicher weg, dann erzwungen neu anwenden. Die
     * Baseline dafuer hat die Schicht beim Boot festgehalten, bevor sie das erste
     * Mal ueberschrieben hat, also landet die Config wieder auf den Paketwerten
     * plus dem, was in der frisch migrierten Tabelle steht — nichts.
     */
    protected function unpinSettingsLeftOnTheConfigByTheBoot(): void
    {
        $settings = app('brand-context.settings');

        $settings->forget();
        $settings->apply(force: true);
    }

    protected function getPackageProviders($app): array
    {
        return [
            StatamicServiceProvider::class,
            // The CP is an Inertia app and its exception handler asks the
            // request whether it is an Inertia visit. Without the provider the
            // macro is missing and every error page throws instead of
            // rendering, which turns an expected 404 into a 500.
            \Inertia\ServiceProvider::class,
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

        // Inertia's test helper otherwise tries to resolve every asserted
        // component to a .vue file under the host app's page paths. An addon
        // page is not a file there: it is registered at runtime from the
        // addon's own bundle (Statamic.$inertia.register in resources/js/cp.js),
        // so the check can never pass and would only assert that the host app
        // does not ship our pages.
        $app['config']->set('inertia.testing.ensure_pages_exist', false);
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
        $this->mountStandInSiblingRoutes($router);
    }

    /**
     * Stand-ins for the routes of a sibling addon installed next to this one.
     *
     * They belong to the bed rather than to the test that reads them because a
     * sibling registers its routes the same way this addon does: at boot, and
     * therefore ahead of Statamic's `{segments?}` frontend catch-all. A route
     * added later — from inside a test body — is shadowed by that catch-all and
     * answers 404 no matter what the bindings do, which would make the check
     * pass for the wrong reason.
     *
     * Each one does nothing but echo its own parameter. If this addon ever
     * binds a name they use, the echo stops happening: the binder resolves the
     * value against a repository here first, finds nothing, and aborts 404 —
     * precisely what LeadHub's delete button did.
     *
     * @see \Goldnead\\Activity\\Tests\Feature\RouteParameterCollisionTest
     */
    protected function mountStandInSiblingRoutes($router): void
    {
        $router->middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)
            ->group(function ($router) {
                foreach (static::NAMES_A_SIBLING_MIGHT_USE as $name) {
                    $router->get(
                        'sibling-probe/'.$name.'/{'.$name.'}',
                        fn ($value) => (string) $value
                    );
                }
            });
    }

    /**
     * Generic names a sibling addon could plausibly put in one of its own
     * routes. None of them is bound by anything in this application today —
     * `rule` and `template` were claimed by statamic-webhook-manager until its
     * 1.7.0 and `automation` by statamic-automations until its 1.6.0. They are
     * here because a sibling reaching for one is what the rule prevents.
     *
     * @var list<string>
     */
    public const NAMES_A_SIBLING_MIGHT_USE = [
        'automation', 'rule', 'template', 'webhook', 'endpoint', 'handle', 'id', 'slug', 'record',
    ];

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

    /**
     * The file user repository writes into the testbench app, which lives in
     * vendor/ and survives both the test and the run. Two tests that each save
     * a user leave two on disk, and the third request into the CP dies on
     * "Statamic Pro is required for multiple users" — a failure with nothing to
     * do with the test that hits it.
     */
    protected function forgetUsersLeftBehindByOtherTests(): void
    {
        $directory = config('statamic.stache.stores.users.directory');

        if ($directory && is_dir($directory)) {
            foreach (glob(rtrim($directory, '/').'/*.yaml') ?: [] as $file) {
                @unlink($file);
            }
        }

        \Statamic\Facades\Stache::store('users')->clear();
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
