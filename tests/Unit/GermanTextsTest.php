<?php

// The German texts go without dashes as punctuation (house style of the
// suite); the intro of the ledger carried one ("nur lesend — Kennzahlen").
it('writes the German texts without dashes', function (): void {
    $found = [];

    foreach (glob(__DIR__.'/../../resources/lang/de/*.php') ?: [] as $file) {
        $texts = require $file;

        array_walk_recursive($texts, function ($text, $key) use ($file, &$found): void {
            if (is_string($text) && preg_match('/\s[—–]\s|—/u', $text)) {
                $found[] = basename($file).": {$key}";
            }
        });
    }

    expect($found)->toBe([]);
});

// The settings entry read "Aktivität" only because other addons translated
// the name "Activity" globally. They no longer do; the entry names itself.
it('names its settings entry in the CP language', function (): void {
    app()->setLocale('de');

    expect(\Goldnead\Activity\Support\Settings::settingsTitle())->toBe('Aktivität');
});
