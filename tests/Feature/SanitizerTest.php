<?php

use Goldnead\Activity\Contracts\ActivitySanitizer;
use Goldnead\Activity\Facades\Activity;
use Goldnead\Activity\Models\Activity as ActivityModel;
use Goldnead\Activity\Support\ActivityData;

it('redacts secret-shaped keys at any depth', function (): void {
    $activity = Activity::record('integration.callback', [
        'properties' => [
            'api_key' => 'sk_live_123',
            'payload' => ['authorization' => 'Bearer abc', 'product' => 'kurs'],
            'amount' => 4900,
        ],
    ]);

    expect($activity->properties['api_key'])->toBe('[redacted]')
        ->and($activity->properties['payload']['authorization'])->toBe('[redacted]')
        ->and($activity->properties['payload']['product'])->toBe('kurs')
        ->and($activity->properties['amount'])->toBe(4900);
});

it('replaces an oversized payload with a visible marker', function (): void {
    config()->set('activity.sanitizer.max_payload_bytes', 100);

    $activity = Activity::record('integration.callback', [
        'properties' => ['blob' => str_repeat('x', 500)],
    ]);

    expect($activity->properties['_truncated'])->toBeTrue()
        ->and($activity->properties)->not->toHaveKey('blob');
});

it('drops a blocked event type entirely', function (): void {
    config()->set('activity.sanitizer.blocked_event_types', ['family.task_completed']);

    expect(Activity::record('family.task_completed'))->toBeNull()
        ->and(ActivityModel::count())->toBe(0);
});

it('can be replaced wholesale by the application', function (): void {
    app()->bind(ActivitySanitizer::class, fn () => new class implements ActivitySanitizer
    {
        public function sanitize(ActivityData $data): ?ActivityData
        {
            return $data->withProperties(['rewritten' => true]);
        }
    });

    expect(Activity::record('account.login', ['properties' => ['original' => true]])->properties)
        ->toBe(['rewritten' => true]);
});
