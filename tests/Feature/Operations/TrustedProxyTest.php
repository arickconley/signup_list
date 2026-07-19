<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

test('forwarded HTTPS is trusted only from configured proxies in proxy mode', function () {
    $originalEnvironment = $_ENV;
    $originalServer = $_SERVER;

    try {
        $_ENV['HTTPS_TERMINATION'] = $_SERVER['HTTPS_TERMINATION'] = 'proxy';
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = '192.0.2.10';
        $this->refreshApplication();
        Route::get('/proxy-scheme', fn (Request $request): string => $request->getScheme());

        $trustedResponse = $this->withServerVariables([
            'HTTP_HOST' => 'signup.example',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'REMOTE_ADDR' => '192.0.2.10',
        ])->get('http://signup.example/proxy-scheme');

        $untrustedResponse = $this->withServerVariables([
            'HTTP_HOST' => 'signup.example',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'REMOTE_ADDR' => '192.0.2.11',
        ])->get('http://signup.example/proxy-scheme');

        expect($trustedResponse->getContent())->toBe('https')
            ->and($untrustedResponse->getContent())->toBe('http');

        $_ENV['HTTPS_TERMINATION'] = $_SERVER['HTTPS_TERMINATION'] = 'direct';
        $this->refreshApplication();
        Route::get('/proxy-scheme', fn (Request $request): string => $request->getScheme());

        $directResponse = $this->withServerVariables([
            'HTTP_HOST' => 'signup.example',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'REMOTE_ADDR' => '192.0.2.10',
        ])->get('http://signup.example/proxy-scheme');

        expect($directResponse->getContent())->toBe('http');
    } finally {
        $_ENV = $originalEnvironment;
        $_SERVER = $originalServer;
        $this->refreshApplication();
    }
});
