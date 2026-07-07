<?php

use Illuminate\Support\Facades\Artisan;

test('trusted proxies configuration is cached for production', function () {
    putenv('TRUSTED_PROXIES=*');
    $_ENV['TRUSTED_PROXIES'] = '*';
    $_SERVER['TRUSTED_PROXIES'] = '*';

    Artisan::call('config:clear');
    Artisan::call('config:cache');

    expect(config('app.trusted_proxies'))->toBe('*');

    Artisan::call('config:clear');
    putenv('TRUSTED_PROXIES');
    unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
});

test('demo page generates https asset urls behind a trusted reverse proxy', function () {
    config(['app.url' => 'https://cv-parser.example.com']);

    $response = $this->withHeaders([
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'cv-parser.example.com',
        'X-Forwarded-Port' => '443',
    ])->get('/demo');

    $response->assertSuccessful();

    $content = $response->getContent();

    expect($content)->not->toMatch('/http:\/\/[^"\'\s]+\/build\/assets\//');

    if (str_contains($content, '/build/assets/')) {
        expect($content)->toMatch('/https:\/\/[^"\'\s]+\/build\/assets\//');
    }
});
