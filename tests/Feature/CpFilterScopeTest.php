<?php

use Statamic\Facades\Scope;

/**
 * `Filter::visibleTo()` defaults to true and Statamic registers scopes
 * globally, so a filter that forgets to answer the question shows up on the
 * Entries, Assets and Users listings of every site that installs this addon.
 */
it('offers its filters to its own listing', function (): void {
    $handles = Scope::filters('activity')->map->handle()->all();

    expect($handles)->toEqualCanonicalizing([
        'activity_event_type',
        'activity_source',
        'activity_identity',
        'activity_occurred_at',
        'activity_anonymized',
    ]);
});

it('keeps its filters off every other listing', function (string $key): void {
    $handles = Scope::filters($key)->map->handle()->all();

    expect(array_filter($handles, fn ($handle) => str_starts_with($handle, 'activity_')))->toBeEmpty();
})->with(['assets', 'users', 'form-submissions']);
