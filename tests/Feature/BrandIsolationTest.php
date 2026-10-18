<?php

use Goldnead\Activity\Facades\Activity;
use Goldnead\Activity\Models\Activity as ActivityModel;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\IdentityContracts\Identity;

beforeEach(function (): void {
    $this->enableMultiBrand();
    $this->brandA = $this->makeBrand('brand-a');
    $this->brandB = $this->makeBrand('brand-b');
});

afterEach(function (): void {
    BrandContext::withoutBrandScope(fn () => ActivityModel::mutable(fn () => ActivityModel::query()->delete()));
});

it('hides an activity recorded in brand A from the brand B context', function (): void {
    $inA = BrandContext::runFor($this->brandA, fn () => Activity::record('commerce.purchase_completed', [
        'actor' => Identity::user(1, 'a@example.com'),
    ]));

    expect($inA->brand_id)->toBe($this->brandA->id);

    BrandContext::setCurrent($this->brandB);

    expect(ActivityModel::find($inA->id))->toBeNull()
        ->and(ActivityModel::count())->toBe(0);
});

it('stamps the current brand automatically', function (): void {
    BrandContext::setCurrent($this->brandB);

    expect(Activity::record('account.login')->brand_id)->toBe($this->brandB->id);
});

it('does not leak a brand A actor into a brand B identity query', function (): void {
    $identity = Identity::user(55, 'shared@example.com', contactUuid: 'c-55');

    BrandContext::runFor($this->brandA, fn () => Activity::record('lms.course_started', ['actor' => $identity]));

    BrandContext::setCurrent($this->brandB);

    expect(ActivityModel::forIdentity($identity)->count())->toBe(0);

    BrandContext::setCurrent($this->brandA);

    expect(ActivityModel::forIdentity($identity)->count())->toBe(1);
});

it('fails closed when multi-brand is on and no brand is current', function (): void {
    BrandContext::runFor($this->brandA, fn () => Activity::record('account.login'));

    BrandContext::forget();

    expect(ActivityModel::count())->toBe(0);
});

it('never matches every row for an identity with no join keys', function (): void {
    BrandContext::runFor($this->brandA, fn () => Activity::record('account.login', [
        'actor' => Identity::user(1),
    ]));

    BrandContext::setCurrent($this->brandA);

    expect(ActivityModel::forIdentity(Identity::system())->count())->toBe(0)
        ->and(ActivityModel::count())->toBe(1);
});

it('keeps retention sweeps from crossing brands unintentionally', function (): void {
    BrandContext::runFor($this->brandA, fn () => Activity::record('marketing.email_opened', [
        'occurred_at' => now()->subDays(400),
    ]));
    BrandContext::runFor($this->brandB, fn () => Activity::record('marketing.email_opened', [
        'occurred_at' => now()->subDays(400),
    ]));

    // Retention is an operator action across the whole store — it is expected to
    // see both, which is exactly why it must never run inside a brand context by
    // accident. This asserts the documented behaviour explicitly.
    $this->artisan('activity:prune', ['--days' => 30])->assertSuccessful();

    expect(BrandContext::withoutBrandScope(fn () => ActivityModel::count()))->toBe(0);
});

it('deletes by the config window whichever brand starts the run', function (): void {
    // Die Aufbewahrungsfrist gehört der Installation (Entscheidung 17.10.2026):
    // eine Zahl in config/activity.php. Eine Marke, die eine eigene Zahl
    // gespeichert hat, darf den Lauf nicht verkürzen oder verlängern.
    config()->set('activity.retention.days', 365);

    // Die Zahlen so, wie die alte Einstellungsseite sie geschrieben hat.
    BrandContext::runFor($this->brandA, fn () => app('brand-context.settings')
        ->for('activity')->save(['retention.days' => 30]));
    BrandContext::runFor($this->brandB, fn () => app('brand-context.settings')
        ->for('activity')->save(['retention.days' => 90]));

    BrandContext::runFor($this->brandA, fn () => Activity::record('marketing.email_opened', [
        'occurred_at' => now()->subDays(100),
    ]));
    BrandContext::runFor($this->brandB, fn () => Activity::record('marketing.email_opened', [
        'occurred_at' => now()->subDays(100),
    ]));
    BrandContext::runFor($this->brandA, fn () => Activity::record('marketing.email_opened', [
        'occurred_at' => now()->subDays(400),
    ]));

    // Der Lauf startet unter Marke A, danach unter Marke B. Beide Male gilt 365.
    BrandContext::setCurrent($this->brandA);
    $this->artisan('activity:prune')->assertSuccessful();

    BrandContext::setCurrent($this->brandB);
    $this->artisan('activity:prune')->assertSuccessful();

    expect(BrandContext::withoutBrandScope(fn () => ActivityModel::count()))->toBe(2)
        ->and(config('activity.retention.days'))->toBe(365);
});
