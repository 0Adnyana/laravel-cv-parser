<?php

function bootstrapScript(): string
{
    return dirname(__DIR__, 2).'/docker/bootstrap.sh';
}

function runBootstrap(string $function, array $environment = []): array
{
    $exports = collect($environment)
        ->map(fn (string $value, string $key): string => $key.'='.escapeshellarg($value))
        ->implode(' ');

    $command = trim($exports.' sh -c '.escapeshellarg('. '.escapeshellarg(bootstrapScript()).'; '.$function));

    exec($command.' 2>&1', $output, $exitCode);

    return [
        'output' => implode("\n", $output),
        'exitCode' => $exitCode,
    ];
}

test('bootstrap respects existing APP_KEY environment variable', function () {
    $result = runBootstrap('bootstrap_app_key; printf "%s" "$APP_KEY"', [
        'APP_KEY' => 'base64:from-env',
    ]);

    expect($result['exitCode'])->toBe(0)
        ->and($result['output'])->toBe('base64:from-env');
});

test('bootstrap exits when APP_KEY is missing', function () {
    $result = runBootstrap('bootstrap_app_key');

    expect($result['exitCode'])->toBe(1)
        ->and($result['output'])->toContain('FATAL: APP_KEY is not set.')
        ->and($result['output'])->toContain('php artisan key:generate --show');
});

test('bootstrap skips migrations when RUN_MIGRATIONS is false', function () {
    $result = runBootstrap('bootstrap_migrations', [
        'RUN_MIGRATIONS' => 'false',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => 'localhost',
    ]);

    expect($result['exitCode'])->toBe(0)
        ->and($result['output'])->toContain("RUN_MIGRATIONS is not 'true', skipping migrations.");
});

test('bootstrap skips migrations without database configuration', function () {
    $result = runBootstrap(
        'unset DB_URL DB_HOST DB_CONNECTION DB_DATABASE; bootstrap_migrations',
    );

    expect($result['exitCode'])->toBe(0)
        ->and($result['output'])->toContain('No database configured, skipping migrations.');
});

test('bootstrap announces migrations when database is configured', function () {
    $result = runBootstrap('bootstrap_migrations', [
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => '/tmp/nonexistent-'.uniqid().'.sqlite',
    ]);

    expect($result['output'])->toContain('Running database migrations...');
});
