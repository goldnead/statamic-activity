<?php

namespace Goldnead\Activity\Support;

use Goldnead\BrandContext\Contracts\ProvidesSettings;
use Goldnead\BrandContext\Settings\SettingsRegistry;

/**
 * Die Einstellungen, die ein Betreiber im Control Panel ändern darf, und die
 * einzige Stelle, die weiß, welche das sind.
 *
 * **Nur die Feldliste steht hier.** Seite, Formular, Validierung, Speicher,
 * Rechteprüfung und die Markendimension kommen aus der gemeinsamen
 * Einstellungs-Schicht in `statamic-brand-context`; angemeldet wird diese
 * Klasse über {@see SettingsRegistry} im Service Provider. Kein eigener
 * Controller, keine eigene Vue-Seite, keine eigene Route, keine eigene
 * Tabelle.
 *
 * **Warum dieses Addon die Seite braucht.** Was hier eingestellt wird, sind
 * fast ausschließlich Datenschutzentscheidungen: welche Felder einer Anfrage
 * überhaupt in die Zeile kommen, welche Schlüssel vor dem Schreiben entfernt
 * werden, wie lange eine Zeile bleibt und wann die Person daraus verschwindet.
 * Das sind Entscheidungen des Verantwortlichen, nicht des Entwicklers, und sie
 * standen bis hierher ausschließlich in einer Datei, die er nie öffnet.
 *
 * **Was nicht hier steht, und warum.**
 *
 * - `producers.marketing` und `producers.leadhub`. Beide werden in
 *   `ServiceProvider::registerProducers()` gelesen, und dort werden
 *   Ereignis-Zuhörer angemeldet. `registerProducers()` läuft aus
 *   `bootAddon()`, das Statamic aus einem `app->booted()`-Rückruf aufruft —
 *   also aus derselben Warteschlange, aus der die Einstellungs-Schicht ihre
 *   Werte schreibt, mit unentschiedener Reihenfolge. Ein Zuhörer, der schon
 *   hängt, hört weiter zu; der Schalter wäre halb wirksam, und halb wirksam
 *   ist die Sorte Lüge, die man erst drei Wochen später bemerkt.
 * - `cp.enabled`. Wird beim Registrieren der CP-Routen gelesen
 *   (`routes/cp.php:8`) und noch einmal bei der Nav-Registrierung, beides vor
 *   dem Schreiben der Abweichungen.
 * - `source`. Die Kennung dieser Anwendung, sie steht auf jeder geschriebenen
 *   Zeile. Sie zu ändern schneidet die Vergangenheit ab: die alten Zeilen
 *   tragen weiter die alte Kennung, jeder Filter darauf zerfällt in zwei.
 * - `queue.*`. Ob und über welche Verbindung geschrieben wird, gehört zum
 *   Betrieb der Maschine, nicht zur Datenpolitik.
 * - `retention.per_event_type`. Eine verschachtelte Abbildung von Ereignisart
 *   auf Tage. Sie bleibt in `config/activity.php`, wo die Zeile daneben
 *   erklärt, dass sie den globalen Wert schlägt.
 */
class Settings implements ProvidesSettings
{
    /**
     * Steht auf jeder Zeile in `brand_settings.namespace`. Ihn später zu
     * ändern verwaist jede Abweichung, die eine Installation gespeichert hat.
     */
    public static function settingsNamespace(): string
    {
        return 'activity';
    }

    public static function settingsConfigPath(): string
    {
        return 'activity';
    }

    /**
     * Das Recht, das den Abschnitt dieses Addons bewacht.
     *
     * Neu, also frei wählbar; nach der Regel `manage <handle> settings` mit
     * dem Paketnamen ohne `statamic-`-Präfix.
     *
     * Nicht zu verwechseln mit dem `manage activity retention`, das hier
     * einmal stand und wieder entfernt wurde, weil es nichts bewachte:
     * Aufbewahrung und Anonymisierung liefen und laufen über Artisan, und
     * Artisan fragt keine Gates. Dieses Recht bewacht etwas — nämlich das
     * Formular, das die Zahlen ändert, die Artisan danach liest.
     */
    public static function settingsPermission(): string
    {
        return 'manage activity settings';
    }

    /**
     * The sidebar entry and tab, read by brand-context 1.15 and later. Before,
     * the entry was the addon name "Activity", and it read "Aktivität" only
     * because other addons translated that name globally.
     */
    public static function settingsTitle(): string
    {
        return (string) __('activity::cp.nav');
    }

    /**
     * @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('activity::settings.groups.recording.title'),
                'description' => __('activity::settings.groups.recording.description'),
                'fields' => [
                    static::field('enabled', 'boolean'),
                ],
            ],
            [
                'title' => __('activity::settings.groups.context.title'),
                'description' => __('activity::settings.groups.context.description'),
                'fields' => [
                    static::field('context.capture', 'boolean'),
                    static::field('context.utm', 'boolean'),
                    static::field('context.referrer', 'boolean'),
                    static::field('context.page_url', 'boolean'),
                    static::field('context.user_agent_category', 'boolean'),
                ],
            ],
            [
                'title' => __('activity::settings.groups.sanitizer.title'),
                'description' => __('activity::settings.groups.sanitizer.description'),
                'fields' => [
                    static::field('sanitizer.strip_keys', 'list'),
                    static::field('sanitizer.blocked_event_types', 'list'),
                    static::field('sanitizer.max_payload_bytes', 'integer', ['min' => 1]),
                ],
            ],
            [
                'title' => __('activity::settings.groups.retention.title'),
                'description' => __('activity::settings.groups.retention.description'),
                'fields' => [
                    // `nullable`, weil leer „alles behalten" heißt und das ein
                    // anderer Zustand ist als eine Zahl.
                    //
                    // Und `min => 1`, obwohl jede erfundene Untergrenze falsch
                    // ist: 0 ist der eine Wert, der keine Aufbewahrungsregel
                    // ist, sondern „lösche den ganzen Bestand" — `subDays(0)`
                    // ist jetzt, und alles ist älter als jetzt. Jede andere
                    // Zahl ist eine vertretbare Politik, die niemand von hier
                    // aus besser weiß, also steht der Boden dort, wo die
                    // Bedeutung kippt, und nicht bei einer Wunschzahl.
                    static::field('retention.days', 'integer', ['min' => 1, 'nullable' => true]),
                    static::field('retention.anonymize_after_days', 'integer', ['min' => 1, 'nullable' => true]),
                ],
            ],
            [
                'title' => __('activity::settings.groups.cp.title'),
                'description' => __('activity::settings.groups.cp.description'),
                'fields' => [
                    static::field('cp.per_page', 'integer', ['min' => 1]),
                ],
            ],
        ];
    }

    /**
     * Ein Feld, mit Beschriftung und Hilfetext aus den Sprachdateien.
     *
     * Der Übersetzungsschlüssel ist der Konfigurationspfad mit flachgelegten
     * Punkten: ein Punkt in einem Sprachschlüssel ist für den Übersetzer ein
     * Pfadtrenner, und `settings.fields.context.utm.label` würde als drei
     * verschachtelte Felder gesucht, die es nicht gibt.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected static function field(string $key, string $type, array $extra = []): array
    {
        $handle = str_replace('.', '_', $key);

        return array_merge([
            'key' => $key,
            'type' => $type,
            'label' => __("activity::settings.fields.{$handle}.label"),
            'description' => __("activity::settings.fields.{$handle}.description"),
            'nullable' => false,
        ], $extra);
    }
}
