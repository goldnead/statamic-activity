<?php

/**
 * Die Einstellungen dieses Addons auf der gemeinsamen Seite.
 *
 * Der Test geht den ganzen Weg: über das CP-Formular speichern, und danach
 * **den echten Leser fragen**, nicht `config()`. Ein Test, der nach dem
 * Speichern `config('activity.…')` prüft, belegt nur, dass die Schicht
 * geschrieben hat, was sie geschrieben hat. Er belegt nicht, dass irgendwer
 * das liest — und genau daran scheitert ein Schlüssel, der beim Booten gelesen
 * wird: der Wert steht in der Konfiguration, und der Zuhörer, der ihn
 * brauchte, hängt seit dem Booten.
 */

use Goldnead\Activity\Facades\Activity;
use Goldnead\Activity\Models\Activity as ActivityModel;
use Goldnead\Activity\Support\Settings;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Statamic\Facades\User;

beforeEach(function (): void {
    $this->user = User::make()->email('settings@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);
});

/**
 * Speichert über das CP-Formular. Das Formular schickt immer den ganzen
 * Abschnitt, also werden die übrigen Felder aus der Konfiguration aufgefüllt;
 * ein Teil-Payload wäre ein 422 und würde nichts über den geänderten Wert
 * aussagen.
 */
function patchActivitySettings($test, array $overrides)
{
    $settings = [];

    foreach (array_keys(app(SettingsRegistry::class)->fields('activity')) as $key) {
        $settings[$key] = config('activity.'.$key);
    }

    return $test->patchJson(cp_route('brand-context.settings.update'), [
        'namespace' => 'activity',
        'settings' => array_replace($settings, $overrides),
    ]);
}

it('meldet sich bei der gemeinsamen Einstellungs-Schicht an', function (): void {
    $registry = app(SettingsRegistry::class);

    expect($registry->has('activity'))->toBeTrue()
        ->and($registry->provider('activity'))->toBe(Settings::class)
        ->and($registry->configPath('activity'))->toBe('activity')
        ->and($registry->permission('activity'))->toBe('manage activity settings');
});

it('bietet keinen Schlüssel an, der beim Booten gelesen wird', function (): void {
    $keys = array_keys(app(SettingsRegistry::class)->fields('activity'));

    // `cp.enabled` wird beim Registrieren der Routen gelesen
    // (`routes/cp.php:8`) und noch einmal in der Nav-Registrierung.
    // `producers.*` ist der weniger offensichtliche Fall: sie werden in
    // `ServiceProvider::registerProducers()` gelesen, und dort werden
    // Ereignis-Zuhörer angemeldet. Ein Zuhörer, der schon hängt, hört weiter
    // zu — der Schalter wäre halb wirksam.
    expect($keys)->not->toContain('cp.enabled')
        ->and($keys)->not->toContain('producers.marketing')
        ->and($keys)->not->toContain('producers.leadhub')
        ->and($keys)->not->toContain('source');
});

it('lässt den Abschnitt speichern, ohne dass ein Feld angefasst wurde', function (): void {
    // Eine frische Installation öffnet die Seite und drückt Speichern; jedes
    // Feld trägt dann seinen Wert aus der Datei. Ein Typ, der den eigenen
    // Vorgabewert nicht annimmt, macht den ganzen Abschnitt unspeicherbar,
    // und keine andere Behauptung hier würde das merken.
    patchActivitySettings($this, [])->assertRedirect();
});

it('schaltet die Aufzeichnung ab, und es entsteht keine Zeile mehr', function (): void {
    // Vorher: es wird geschrieben. Ohne diese Zeile bewiese das Ausbleiben
    // danach nur, dass hier nie etwas ankam.
    Activity::record('test.vorher', ['anonymous_id' => 'a1']);
    expect(ActivityModel::withoutGlobalScopes()->count())->toBe(1);

    patchActivitySettings($this, ['enabled' => false])->assertRedirect();

    // Nachher: derselbe Aufruf, gleicher Prozess, kein Ergebnis. Der
    // Rekorder hat den gespeicherten Wert gelesen, nicht den aus der Datei.
    Activity::record('test.nachher', ['anonymous_id' => 'a1']);
    expect(ActivityModel::withoutGlobalScopes()->count())->toBe(1);
});

it('verkürzt die Aufbewahrung, und der nächste Lauf sieht die neue Zahl', function (): void {
    // Der Weg, an dem eine Änderung wehtut: die Zahl wird nicht in einem
    // Controller gelesen, sondern in einem Artisan-Befehl, der löscht.
    Activity::record('test.alt', ['anonymous_id' => 'a1']);

    // Zurückdatiert statt von Hand eingefügt: die Zeile soll durch denselben
    // Schreibweg entstanden sein wie im Betrieb, sonst prüft der Lauf gleich
    // eine Zeile, die es so nie gibt.
    ActivityModel::mutable(fn () => ActivityModel::withoutGlobalScopes()
        ->update(['occurred_at' => now()->subDays(200)]));

    // Vorgabe ist leer, also „alles behalten": ein Lauf jetzt löscht nichts.
    $this->artisan('activity:prune')->assertExitCode(0);
    expect(ActivityModel::withoutGlobalScopes()->count())->toBe(1);

    patchActivitySettings($this, ['retention.days' => 90])->assertRedirect();

    $this->artisan('activity:prune')->assertExitCode(0);
    expect(ActivityModel::withoutGlobalScopes()->count())->toBe(0);
});

it('nimmt keine Aufbewahrung von null Tagen an', function (): void {
    // 0 ist der eine Wert, der keine Aufbewahrungsregel ist, sondern
    // „lösche den ganzen Bestand": `subDays(0)` ist jetzt, und alles ist
    // älter als jetzt. Leer bleibt erlaubt und heißt „alles behalten".
    $abgelehnt = patchActivitySettings($this, ['retention.days' => 0])->assertStatus(422);

    // Der Schlüssel trägt einen echten Punkt; `assertJsonValidationErrors`
    // und `assertJsonPath` lesen ihn beide als Pfadtrenner und suchen an einer
    // Stelle, die es nicht gibt.
    expect($abgelehnt->json('errors'))->toHaveKey('settings.retention.days');

    patchActivitySettings($this, ['retention.days' => null])->assertRedirect();
});
