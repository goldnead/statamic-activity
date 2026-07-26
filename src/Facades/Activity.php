<?php

namespace Goldnead\Activity\Facades;

use Closure;
use Goldnead\Activity\ActivityRecorder;
use Goldnead\Activity\Producers\ProducerRegistry;
use Goldnead\Activity\Support\ActivityData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Goldnead\Activity\Models\Activity|null record(string $eventType, array $attributes = [])
 * @method static void recordLater(string $eventType, array $attributes = [])
 * @method static \Goldnead\Activity\Models\Activity|null write(ActivityData $data, bool $hydrate = true)
 * @method static ActivityData hydrate(ActivityData $data)
 * @method static void registerProducer(string $eventClass, Closure $mapper, ?string $eventType = null)
 * @method static ProducerRegistry producers()
 * @method static Builder query()
 * @method static bool enabled()
 *
 * @see ActivityRecorder
 */
class Activity extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'activity';
    }
}
