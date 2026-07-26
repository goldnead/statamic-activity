<?php

namespace Goldnead\Activity\Sanitizers;

use Goldnead\Activity\Contracts\ActivitySanitizer;
use Goldnead\Activity\Support\ActivityData;

/**
 * Redacts secret-shaped keys and caps payload size. Deliberately conservative:
 * it never drops an activity, only its dangerous parts, so that switching the
 * sanitizer off is never the difference between "recorded" and "lost".
 */
class DefaultActivitySanitizer implements ActivitySanitizer
{
    public function sanitize(ActivityData $data): ?ActivityData
    {
        if (in_array($data->eventType, (array) config('activity.sanitizer.blocked_event_types', []), true)) {
            return null;
        }

        return $data
            ->withProperties($this->clean($data->properties))
            ->withContext($this->clean($data->context));
    }

    protected function clean(array $payload): array
    {
        $stripped = $this->strip($payload);

        return $this->cap($stripped);
    }

    protected function strip(array $payload): array
    {
        $blocked = array_map('strtolower', (array) config('activity.sanitizer.strip_keys', []));

        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->matchesBlockedKey(strtolower($key), $blocked)) {
                $payload[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->strip($value);
            }
        }

        return $payload;
    }

    protected function matchesBlockedKey(string $key, array $blocked): bool
    {
        foreach ($blocked as $needle) {
            if ($needle !== '' && str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A ledger row must stay writable on every driver. Rather than truncating in
     * the middle of a JSON structure, oversized payloads are replaced wholesale
     * with a marker so the loss is visible instead of silent.
     */
    protected function cap(array $payload): array
    {
        $max = (int) config('activity.sanitizer.max_payload_bytes', 60000);

        if ($max <= 0) {
            return $payload;
        }

        $encoded = json_encode($payload);

        if ($encoded === false || strlen($encoded) <= $max) {
            return $payload;
        }

        return [
            '_truncated' => true,
            '_original_bytes' => $encoded === false ? null : strlen($encoded),
        ];
    }
}
