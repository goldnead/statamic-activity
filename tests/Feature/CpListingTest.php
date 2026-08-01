<?php

use Goldnead\Activity\Facades\Activity;
use Statamic\Facades\User;

beforeEach(function (): void {
    $user = User::make()->email('listing@example.com')->makeSuper();
    $user->save();

    $this->actingAs($user);
});

/** Base64-encoded JSON is what <ui-listing> puts on the wire; see Listing.vue. */
function encodedFilters(array $filters): string
{
    return base64_encode(json_encode($filters));
}

it('refuses the json endpoint without the view permission', function (): void {
    $this->app['auth']->forgetGuards();
    auth()->logout();

    $this->getJson('/cp/activity')->assertForbidden();
});

it('answers the listing contract', function (): void {
    Activity::record('commerce.purchase_completed', ['source' => 'checkout']);

    $response = $this->getJson('/cp/activity')->assertOk();

    $response->assertJsonStructure([
        'data' => [['id', 'occurred_at', 'event_type', 'actor', 'source', 'anonymized', 'show_url']],
        'meta' => ['columns', 'activeFilterBadges', 'current_page', 'last_page', 'per_page', 'from', 'to', 'total'],
    ]);

    // Listing overwrites its local columns from meta on every response, so the
    // key has to be present on every page, not only the first.
    expect($response->json('meta.columns'))->not->toBeEmpty();
});

it('returns the newest fact first', function (): void {
    Activity::record('a.old', ['occurred_at' => now()->subDay()]);
    Activity::record('a.new', ['occurred_at' => now()]);

    expect($this->getJson('/cp/activity')->json('data.0.event_type'))->toBe('a.new');
});

it('ignores a sort column it does not own', function (): void {
    Activity::record('a.one');

    $this->getJson('/cp/activity?sort=properties->secret&order=asc')->assertOk();
    $this->getJson('/cp/activity?sort=id) --&order=asc')->assertOk();
});

it('sorts by a whitelisted column', function (): void {
    Activity::record('b.second');
    Activity::record('a.first');

    expect($this->getJson('/cp/activity?sort=event_type&order=asc')->json('data.0.event_type'))
        ->toBe('a.first');
});

it('paginates and caps a runaway per-page', function (): void {
    foreach (range(1, 12) as $i) {
        Activity::record('a.event', ['occurred_at' => now()->subMinutes($i)]);
    }

    $page = $this->getJson('/cp/activity?perPage=5&page=2')->assertOk();

    expect($page->json('data'))->toHaveCount(5)
        ->and($page->json('meta.current_page'))->toBe(2)
        ->and($page->json('meta.total'))->toBe(12);

    expect($this->getJson('/cp/activity?perPage=100000')->json('meta.per_page'))->toBe(500);
});

it('searches the identifying columns as a prefix', function (): void {
    Activity::record('commerce.purchase_completed');
    Activity::record('account.login');

    $hits = $this->getJson('/cp/activity?search=commerce')->json('data');

    expect($hits)->toHaveCount(1)
        ->and($hits[0]['event_type'])->toBe('commerce.purchase_completed');
});

it('treats a search wildcard as text', function (): void {
    Activity::record('commerce.purchase_completed');

    expect($this->getJson('/cp/activity?search=%25')->json('data'))->toBeEmpty();
});

it('keeps the pre-rewrite query-string filters working', function (): void {
    Activity::record('commerce.purchase_completed', ['contact_uuid' => 'c-1']);
    Activity::record('account.login', ['contact_uuid' => 'c-2']);

    expect($this->getJson('/cp/activity?event_type=account.login')->json('data'))->toHaveCount(1);
    expect($this->getJson('/cp/activity?contact_uuid=c-1')->json('data.0.event_type'))
        ->toBe('commerce.purchase_completed');
});

it('hands those deep-link parameters to the listing so they survive the next request', function (): void {
    Activity::record('account.login', ['contact_uuid' => 'c-1']);

    $html = $this->get('/cp/activity?contact_uuid=c-1')->assertOk()->getContent();

    preg_match('/:additional-parameters="([^"]*)"/', $html, $matches);

    expect(json_decode(html_entity_decode($matches[1], ENT_QUOTES), true))
        ->toBe(['contact_uuid' => 'c-1']);
});

it('does not narrow the ledger because a date boundary was unparseable', function (): void {
    Activity::record('account.login');

    expect($this->getJson('/cp/activity?from=not-a-date')->json('data'))->toHaveCount(1);
    expect($this->getJson('/cp/activity?to=13/45/2026')->json('data'))->toHaveCount(1);
});

it('still honours a boundary it can parse', function (): void {
    Activity::record('a.old', ['occurred_at' => now()->subDays(10)]);
    Activity::record('a.new', ['occurred_at' => now()]);

    $hits = $this->getJson('/cp/activity?from='.now()->subDay()->toDateString())->json('data');

    expect($hits)->toHaveCount(1)->and($hits[0]['event_type'])->toBe('a.new');
});

it('links every row to its own fact', function (): void {
    $activity = Activity::record('account.login');

    expect($this->getJson('/cp/activity')->json('data.0.show_url'))
        ->toBe(cp_route('activity.show', ['id' => $activity->id]));
});

it('applies the native event type filter', function (): void {
    Activity::record('commerce.purchase_completed');
    Activity::record('account.login');

    $response = $this->getJson('/cp/activity?filters='.encodedFilters([
        'activity_event_type' => ['event_type' => 'account.login'],
    ]))->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.event_type'))->toBe('account.login')
        ->and($response->json('meta.activeFilterBadges'))->toHaveKey('activity_event_type');
});

it('applies the native source filter the readme has always advertised', function (): void {
    Activity::record('a.one', ['source' => 'checkout']);
    Activity::record('a.two', ['source' => 'crm']);

    $hits = $this->getJson('/cp/activity?filters='.encodedFilters([
        'activity_source' => ['source' => 'crm'],
    ]))->json('data');

    expect($hits)->toHaveCount(1)->and($hits[0]['source'])->toBe('crm');
});

it('applies the native identity filter', function (): void {
    Activity::record('a.one', ['contact_uuid' => 'c-1']);
    Activity::record('a.two', ['contact_uuid' => 'c-2']);

    $hits = $this->getJson('/cp/activity?filters='.encodedFilters([
        'activity_identity' => ['contact_uuid' => 'c-2', 'user_id' => null, 'anonymous_id' => null],
    ]))->json('data');

    expect($hits)->toHaveCount(1)->and($hits[0]['event_type'])->toBe('a.two');
});

it('applies the native date range filter', function (): void {
    Activity::record('a.old', ['occurred_at' => now()->subDays(10)]);
    Activity::record('a.new', ['occurred_at' => now()]);

    $hits = $this->getJson('/cp/activity?filters='.encodedFilters([
        'activity_occurred_at' => [
            'operator' => 'between',
            'range_value' => [
                'start' => now()->subDays(2)->toDateString(),
                'end' => now()->toDateString(),
            ],
        ],
    ]))->json('data');

    expect($hits)->toHaveCount(1)->and($hits[0]['event_type'])->toBe('a.new');
});

it('drops an unparseable bound in the native date filter instead of emptying the ledger', function (): void {
    Activity::record('a.one');

    $hits = $this->getJson('/cp/activity?filters='.encodedFilters([
        'activity_occurred_at' => ['operator' => '>=', 'value' => 'nonsense'],
    ]))->json('data');

    expect($hits)->toHaveCount(1);
});

it('applies the native anonymisation filter', function (): void {
    Activity::record('a.plain');
    $anonymised = Activity::record('a.anonymised');
    $anonymised::mutable(fn () => $anonymised->forceFill(['anonymized' => true])->save());

    $hits = $this->getJson('/cp/activity?filters='.encodedFilters([
        'activity_anonymized' => ['anonymized' => 'yes'],
    ]))->json('data');

    expect($hits)->toHaveCount(1)->and($hits[0]['event_type'])->toBe('a.anonymised');
});
