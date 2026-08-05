<?php

namespace Goldnead\Activity\Models;

use Closure;
use Goldnead\Activity\Exceptions\ImmutableActivity;
use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\IdentityContracts\Identity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A single recorded fact. Append-only by construction: updates and deletes throw
 * unless they go through the retention/anonymisation path, which is the only
 * legitimate reason a stored fact may change after the fact.
 *
 * The column list below is the whole table. It was a partial list before, which
 * is why half the ledger's own fields were reported as undefined properties and
 * had to be carried in the PHPStan baseline — a baseline entry per column of the
 * central table is a type gap, not a known exception.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $event_id
 * @property string $event_type
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $contact_uuid
 * @property string|null $user_id
 * @property string|null $anonymous_id
 * @property string|null $session_id
 * @property string|null $source
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $dedupe_key
 * @property array<string, mixed>|null $properties
 * @property array<string, mixed>|null $context
 * @property bool $anonymized
 * @property \Illuminate\Support\Carbon|null $occurred_at
 * @property \Illuminate\Support\Carbon|null $received_at
 */
class Activity extends Model
{
    use HasBrand;

    protected $table = 'activities';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'properties' => 'array',
        'context' => 'array',
        'anonymized' => 'boolean',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    /**
     * Lifted only by the retention and anonymisation paths. Static rather than
     * per-instance because the guard also covers mass updates and deletes
     * issued through the query builder (see ImmutableBuilder).
     */
    protected static bool $mutable = false;

    public static function isMutable(): bool
    {
        return static::$mutable;
    }

    /**
     * Routes every query for this model through the guarded builder. Without
     * this, `Activity::query()->update()` and `->delete()` bypass the model
     * events entirely and rewrite the ledger unchallenged.
     */
    public function newEloquentBuilder($query): ImmutableBuilder
    {
        return new ImmutableBuilder($query);
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            if (! static::$mutable) {
                throw ImmutableActivity::cannotUpdate();
            }
        });

        static::deleting(function (): void {
            if (! static::$mutable) {
                throw ImmutableActivity::cannotDelete();
            }
        });
    }

    /**
     * Runs a callback with the immutability guard lifted. Internal — retention
     * and anonymisation only.
     *
     * @internal
     */
    public static function mutable(Closure $callback): mixed
    {
        $previous = static::$mutable;
        static::$mutable = true;

        try {
            return $callback();
        } finally {
            static::$mutable = $previous;
        }
    }

    public function actor(): Identity
    {
        return new Identity(
            type: $this->actor_type ?? Identity::TYPE_ANONYMOUS,
            id: $this->actor_id,
            userId: $this->user_id,
            contactUuid: $this->contact_uuid,
            anonymousId: $this->anonymous_id,
        );
    }

    public function scopeOfType(Builder $query, string|array $eventType): Builder
    {
        return $query->whereIn('event_type', (array) $eventType);
    }

    /** Everything a given actor did, matched on whichever join key is present. */
    public function scopeForIdentity(Builder $query, Identity $identity): Builder
    {
        return $query->where(function (Builder $query) use ($identity): void {
            if ($identity->userId !== null) {
                $query->orWhere('user_id', $identity->userId);
            }

            if ($identity->contactUuid !== null) {
                $query->orWhere('contact_uuid', $identity->contactUuid);
            }

            if ($identity->anonymousId !== null) {
                $query->orWhere('anonymous_id', $identity->anonymousId);
            }

            // No join key at all must never match the whole table.
            if (! $identity->isIdentified() && $identity->anonymousId === null) {
                $query->whereRaw('1 = 0');
            }
        });
    }

    public function scopeOccurredBetween(Builder $query, mixed $from = null, mixed $to = null): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->where('occurred_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->where('occurred_at', '<=', $to));
    }
}
