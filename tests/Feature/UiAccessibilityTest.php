<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

test('shared actions meet touch target size without changing inline prose links', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.button size="sm">Small action</x-ui.button>
        <x-ui.link href="/action" action>Linked action</x-ui.link>
        <p>Continue to <x-ui.link href="/inline">inline help</x-ui.link>.</p>
    BLADE);

    $document = new DOMDocument;
    $document->loadHTML($html);
    $xpath = new DOMXPath($document);

    $buttonClass = $xpath->query('//button[normalize-space()="Small action"]')->item(0)?->getAttribute('class');
    $actionClass = $xpath->query('//a[normalize-space()="Linked action"]')->item(0)?->getAttribute('class');
    $inlineClass = $xpath->query('//a[normalize-space()="inline help"]')->item(0)?->getAttribute('class');

    expect($buttonClass)->toContain('min-h-11')->not->toContain('min-h-9')
        ->and($actionClass)->toContain('inline-flex')->toContain('min-h-11')->toContain('items-center')
        ->and($inlineClass)->not->toContain('inline-flex')->not->toContain('min-h-11');
});

test('reduced motion disables animations and transitions globally', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('@media (prefers-reduced-motion: reduce)')
        ->toContain('animation-duration: 0.01ms !important;')
        ->toContain('animation-iteration-count: 1 !important;')
        ->toContain('transition-duration: 0.01ms !important;')
        ->toContain('scroll-behavior: auto !important;');
});

test('input descriptions and errors are both programmatically described', function () {
    $errors = new ViewErrorBag;
    $errors->put('default', new MessageBag(['email' => ['Enter a valid email.']]));
    view()->share('errors', $errors);

    $html = Blade::render(<<<'BLADE'
        <x-ui.input name="email" label="Email" description="Used for Account access." />
    BLADE);

    $document = new DOMDocument;
    $document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $input = $xpath->query('//input[@id="email"]')->item(0);

    expect($input?->getAttribute('aria-describedby'))->toBe('email-description email-error')
        ->and($xpath->query('//p[@id="email-description" and normalize-space()="Used for Account access."]')->length)->toBe(1)
        ->and($xpath->query('//p[@id="email-error" and normalize-space()="Enter a valid email."]')->length)->toBe(1);
});

test('authentication and passkey feedback use live-region semantics', function () {
    $html = Blade::render(<<<'BLADE'
        <x-auth-session-status status="Account access email sent." />
        <x-passkey-verify />
        <x-passkey-registration />
    BLADE);

    $document = new DOMDocument;
    $document->loadHTML($html);
    $xpath = new DOMXPath($document);

    expect($xpath->query('//*[@role="status" and @aria-live="polite" and contains(normalize-space(), "Account access email sent.")]')->length)->toBe(1)
        ->and($xpath->query('//p[@x-show="error" and @role="alert"]')->length)->toBe(2);
});

test('canceling passkey registration restores the stable Add passkey trigger', function () {
    $html = Blade::render('<x-passkey-registration />');

    expect($html)
        ->toContain('x-ref="addPasskeyTrigger"')
        ->toContain('this.$nextTick(() => this.$refs.addPasskeyTrigger?.focus());');
});
