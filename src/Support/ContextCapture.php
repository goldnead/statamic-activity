<?php

namespace Goldnead\Activity\Support;

use Illuminate\Http\Request;

/**
 * Pulls the request-shaped context an event happened in. Every field is
 * individually switchable because each one carries a different privacy weight:
 * UTM parameters are marketing metadata, a raw user agent is a fingerprinting
 * surface. Nothing is captured outside an HTTP request.
 */
class ContextCapture
{
    public function capture(): array
    {
        if (! config('activity.context.capture', true)) {
            return [];
        }

        $request = $this->request();

        if ($request === null) {
            return [];
        }

        $context = [];

        if (config('activity.context.utm', true)) {
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
                $value = $request->query($key);

                if (is_string($value) && $value !== '') {
                    $context[$key] = $value;
                }
            }
        }

        if (config('activity.context.referrer', true) && ($referrer = $request->headers->get('referer'))) {
            $context['referrer'] = $referrer;
        }

        if (config('activity.context.page_url', true)) {
            $context['page_url'] = $request->fullUrl();
        }

        // Deliberately a coarse category, not the raw string: enough to tell
        // mobile from desktop from bot, useless for fingerprinting.
        if (config('activity.context.user_agent_category', true)) {
            $context['user_agent_category'] = $this->categoriseUserAgent($request->userAgent());
        }

        return $context;
    }

    protected function request(): ?Request
    {
        if (app()->runningInConsole() || ! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? $request : null;
    }

    protected function categoriseUserAgent(?string $userAgent): string
    {
        if ($userAgent === null || $userAgent === '') {
            return 'unknown';
        }

        $agent = strtolower($userAgent);

        return match (true) {
            str_contains($agent, 'bot') || str_contains($agent, 'crawler') || str_contains($agent, 'spider') => 'bot',
            str_contains($agent, 'ipad') || str_contains($agent, 'tablet') => 'tablet',
            str_contains($agent, 'mobile') || str_contains($agent, 'iphone') || str_contains($agent, 'android') => 'mobile',
            default => 'desktop',
        };
    }
}
