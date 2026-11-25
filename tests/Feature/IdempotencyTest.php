<?php

use Goldnead\Activity\ActivityRecorder;
use Goldnead\Activity\Facades\Activity;
use Goldnead\Activity\Models\Activity as ActivityModel;
use Goldnead\Activity\Support\ActivityData;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

it('records the same dedupe key only once and returns the original', function (): void {
    $first = Activity::record('commerce.purchase_completed', [
        'dedupe_key' => 'mollie:tr_123',
        'properties' => ['amount' => 4900],
    ]);

    $second = Activity::record('commerce.purchase_completed', [
        'dedupe_key' => 'mollie:tr_123',
        'properties' => ['amount' => 9999],
    ]);

    expect(ActivityModel::count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->properties['amount'])->toBe(4900);
});

it('records the same event id only once', function (): void {
    $id = (string) Str::uuid();

    Activity::record('marketing.email_opened', ['event_id' => $id]);
    Activity::record('marketing.email_opened', ['event_id' => $id]);

    expect(ActivityModel::count())->toBe(1);
});

it('treats a repeatable event without a dedupe key as a new fact each time', function (): void {
    Activity::record('marketing.email_opened', ['properties' => ['message_uuid' => 'm-1']]);
    Activity::record('marketing.email_opened', ['properties' => ['message_uuid' => 'm-1']]);

    expect(ActivityModel::count())->toBe(2);
});

it('survives a concurrent insert that wins the race', function (): void {
    // Simulates the interleaving the recorder is built for: the duplicate check
    // misses because the competing row is written between check and insert.
    $data = ['dedupe_key' => 'race:1'];

    $first = Activity::record('commerce.purchase_completed', $data);

    // A second writer with a *different* event id but the same dedupe key hits
    // the unique index rather than the pre-check.
    $second = Activity::record('commerce.purchase_completed', $data + ['event_id' => (string) Str::uuid()]);

    expect(ActivityModel::count())->toBe(1)
        ->and($second->id)->toBe($first->id);
});

it('scopes dedupe keys per brand so two brands may record the same fact', function (): void {
    $this->enableMultiBrand();
    $brandA = $this->makeBrand('brand-a');
    $brandB = $this->makeBrand('brand-b');

    BrandContext::runFor($brandA, fn () => Activity::record('marketing.subscription_confirmed', ['dedupe_key' => 'sub:1']));
    BrandContext::runFor($brandB, fn () => Activity::record('marketing.subscription_confirmed', ['dedupe_key' => 'sub:1']));

    $all = BrandContext::withoutBrandScope(fn () => ActivityModel::count());

    expect($all)->toBe(2);
});

it('never lets a ledger failure escape into the caller', function (): void {
    // Run the recorder against a database without the ledger table: a broken
    // ledger must not roll back the purchase that produced the event.
    $this->withUnmigratedDatabase(function (): void {
        expect(Activity::record('commerce.purchase_completed'))->toBeNull();
    });
});

it('logs when a unique violation leaves nothing to return', function (): void {
    Log::spy();

    // A row that the duplicate lookup cannot see, rejected by the unique index.
    $armed = true;
    ActivityModel::creating(function () use (&$armed): void {
        if (! $armed) {
            return;
        }

        $armed = false;

        throw new UniqueConstraintViolationException('testing', 'insert', [], new Exception('UNIQUE constraint failed'));
    });

    expect(Activity::record('commerce.purchase_completed', ['dedupe_key' => 'lost:1']))->toBeNull();

    Log::shouldHaveReceived('warning')->once();
});

it('treats a unique violation as a duplicate by SQLSTATE, not by message text', function (string $sqlstate, string $message): void {
    // The competing row is written between the duplicate check and the insert.
    $first = null;
    $armed = true;
    ActivityModel::creating(function () use (&$armed, &$first, $sqlstate, $message): void {
        if (! $armed) {
            return;
        }

        $armed = false;
        $first = Activity::record('commerce.purchase_completed', ['dedupe_key' => 'sqlstate:1']);

        throw new QueryException('testing', 'insert', [], activityPdoError($message, $sqlstate));
    });

    $second = Activity::record('commerce.purchase_completed', ['dedupe_key' => 'sqlstate:1']);

    expect(ActivityModel::count())->toBe(1)
        ->and($second->id)->toBe($first->id);
})->with([
    'SQL Server, message without the usual keywords' => ['23000', "Violation of UNIQUE KEY constraint 'act_brand_dedupe_unique'. Cannot insert duplicate key"],
    'PostgreSQL' => ['23505', 'duplicate key value violates unique constraint "act_brand_dedupe_unique"'],
]);

it('still rethrows a non-unique integrity error on the queued path', function (): void {
    // SQLSTATE 23000 also covers foreign key failures. Without a competing row
    // it is not a duplicate and must reach the queue, not be swallowed.
    $armed = true;
    ActivityModel::creating(function () use (&$armed): void {
        if (! $armed) {
            return;
        }

        $armed = false;

        throw new QueryException('testing', 'insert', [], activityPdoError('Cannot add or update a child row: a foreign key constraint fails', '23000'));
    });

    $write = fn () => app(ActivityRecorder::class)->write(
        ActivityData::make('commerce.purchase_completed', ['dedupe_key' => 'fk:1']),
        true,
        true,
    );

    expect($write)->toThrow(QueryException::class);
});

function activityPdoError(string $message, string $sqlstate): PDOException
{
    return new class($message, $sqlstate) extends PDOException
    {
        public function __construct(string $message, string $sqlstate)
        {
            parent::__construct($message);
            $this->code = $sqlstate;
        }
    };
}
