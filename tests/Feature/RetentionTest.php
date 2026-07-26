<?php

use Goldnead\Activity\Facades\Activity;
use Goldnead\Activity\Models\Activity as ActivityModel;
use Goldnead\IdentityContracts\Identity;

it('does nothing when no retention window is configured', function (): void {
    Activity::record('account.login', ['occurred_at' => now()->subYears(5)]);

    $this->artisan('activity:prune')->assertSuccessful();

    expect(ActivityModel::count())->toBe(1);
});

it('deletes activities past the global window', function (): void {
    Activity::record('account.login', ['occurred_at' => now()->subDays(400)]);
    Activity::record('account.login', ['occurred_at' => now()->subDays(10)]);

    $this->artisan('activity:prune', ['--days' => 365])->assertSuccessful();

    expect(ActivityModel::count())->toBe(1);
});

it('lets a per-type window win over the global one', function (): void {
    config()->set('activity.retention.days', 365);
    config()->set('activity.retention.per_event_type', ['marketing.email_opened' => 30]);

    Activity::record('marketing.email_opened', ['occurred_at' => now()->subDays(60)]);
    Activity::record('commerce.purchase_completed', ['occurred_at' => now()->subDays(60)]);

    $this->artisan('activity:prune')->assertSuccessful();

    expect(ActivityModel::count())->toBe(1)
        ->and(ActivityModel::first()->event_type)->toBe('commerce.purchase_completed');
});

it('reports without deleting on a dry run', function (): void {
    Activity::record('account.login', ['occurred_at' => now()->subDays(400)]);

    $this->artisan('activity:prune', ['--days' => 30, '--dry-run' => true])->assertSuccessful();

    expect(ActivityModel::count())->toBe(1);
});

it('strips personal fields but keeps the fact when anonymising a contact', function (): void {
    Activity::record('commerce.purchase_completed', [
        'actor' => Identity::user(9, 'gone@example.com', 'Gone', 'c-9'),
        'session_id' => 'sess-1',
        'properties' => ['amount' => 4900],
        'context' => ['page_url' => 'https://example.com/checkout'],
    ]);

    $this->artisan('activity:anonymize', ['--contact' => 'c-9'])->assertSuccessful();

    $activity = ActivityModel::first();

    expect(ActivityModel::count())->toBe(1)
        ->and($activity->event_type)->toBe('commerce.purchase_completed')
        ->and($activity->occurred_at)->not->toBeNull()
        ->and($activity->contact_uuid)->toBeNull()
        ->and($activity->user_id)->toBeNull()
        ->and($activity->session_id)->toBeNull()
        ->and($activity->actor_id)->toBeNull()
        ->and($activity->properties)->toBeNull()
        ->and($activity->context)->toBeNull()
        ->and($activity->anonymized)->toBeTrue();
});

it('anonymises by user id and by age', function (): void {
    Activity::record('account.login', ['actor' => Identity::user(11), 'occurred_at' => now()->subDays(500)]);
    Activity::record('account.login', ['actor' => Identity::user(12), 'occurred_at' => now()->subDay()]);

    $this->artisan('activity:anonymize', ['--days' => 365])->assertSuccessful();

    expect(ActivityModel::where('anonymized', true)->count())->toBe(1)
        ->and(ActivityModel::where('user_id', 12)->count())->toBe(1);
});

it('is a no-op on a second anonymisation run', function (): void {
    Activity::record('account.login', ['actor' => Identity::user(13, contactUuid: 'c-13')]);

    $this->artisan('activity:anonymize', ['--contact' => 'c-13'])->assertSuccessful();
    $this->artisan('activity:anonymize', ['--contact' => 'c-13'])
        ->expectsOutputToContain('Nothing to anonymise')
        ->assertSuccessful();
});

it('refuses to anonymise without a selection', function (): void {
    $this->artisan('activity:anonymize')->assertFailed();
});
