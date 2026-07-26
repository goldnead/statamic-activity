<?php

use Goldnead\Activity\Support\ContextCapture;
use Illuminate\Http\Request;

beforeEach(function (): void {
    $this->capture = app(ContextCapture::class);
});

function fakeRequest(string $uri, array $server = []): void
{
    app()->instance('request', Request::create($uri, 'GET', [], [], [], $server));
    // The capture guard skips console context, which the test runner always is.
    app()->bind('runningInConsole', fn () => false);
}

it('captures nothing in the console', function (): void {
    expect($this->capture->capture())->toBe([]);
});

it('captures utm parameters, referrer and page url from a request', function (): void {
    $capture = new class extends ContextCapture
    {
        protected function request(): ?Request
        {
            return Request::create(
                'https://example.com/kurs?utm_source=newsletter&utm_medium=email&utm_campaign=launch',
                'GET',
                [],
                [],
                [],
                ['HTTP_REFERER' => 'https://google.com', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone) Mobile']
            );
        }
    };

    $context = $capture->capture();

    expect($context['utm_source'])->toBe('newsletter')
        ->and($context['utm_medium'])->toBe('email')
        ->and($context['utm_campaign'])->toBe('launch')
        ->and($context['referrer'])->toBe('https://google.com')
        ->and($context['page_url'])->toContain('/kurs')
        ->and($context['user_agent_category'])->toBe('mobile');
});

it('never stores the raw user agent', function (): void {
    $capture = new class extends ContextCapture
    {
        protected function request(): ?Request
        {
            return Request::create('https://example.com/', 'GET', [], [], [], [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
            ]);
        }
    };

    $context = $capture->capture();

    expect($context['user_agent_category'])->toBe('desktop')
        ->and(json_encode($context))->not->toContain('AppleWebKit');
});

it('categorises bots separately', function (): void {
    $capture = new class extends ContextCapture
    {
        protected function request(): ?Request
        {
            return Request::create('https://example.com/', 'GET', [], [], [], [
                'HTTP_USER_AGENT' => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
            ]);
        }
    };

    expect($capture->capture()['user_agent_category'])->toBe('bot');
});

it('captures nothing when capture is switched off', function (): void {
    config()->set('activity.context.capture', false);

    $capture = new class extends ContextCapture
    {
        protected function request(): ?Request
        {
            return Request::create('https://example.com/?utm_source=x');
        }
    };

    expect($capture->capture())->toBe([]);
});

it('omits individually disabled fields', function (): void {
    config()->set('activity.context.page_url', false);
    config()->set('activity.context.referrer', false);

    $capture = new class extends ContextCapture
    {
        protected function request(): ?Request
        {
            return Request::create('https://example.com/?utm_source=x', 'GET', [], [], [], [
                'HTTP_REFERER' => 'https://google.com',
            ]);
        }
    };

    $context = $capture->capture();

    expect($context)->toHaveKey('utm_source')
        ->and($context)->not->toHaveKey('page_url')
        ->and($context)->not->toHaveKey('referrer');
});
