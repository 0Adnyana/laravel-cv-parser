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

    exec($command, $output, $exitCode);

    return [
        'output' => implode("\n", $output),
        'exitCode' => $exitCode,
    ];
}

test('bootstrap respects existing APP_KEY environment variable', function () {
    $keyFile = sys_get_temp_dir().'/bootstrap-app-key-'.uniqid();
    file_put_contents($keyFile, 'base64:from-file');

    $result = runBootstrap('bootstrap_app_key; printf "%s" "$APP_KEY"', [
        'APP_KEY' => 'base64:from-env',
        'APP_KEY_FILE' => $keyFile,
    ]);

    expect($result['exitCode'])->toBe(0)
        ->and($result['output'])->toBe('base64:from-env');
});

test('bootstrap loads APP_KEY from persisted file', function () {
    $directory = sys_get_temp_dir().'/bootstrap-'.uniqid();
    mkdir($directory);
    $keyFile = $directory.'/.app_key';
    file_put_contents($keyFile, 'base64:persisted-key');

    $result = runBootstrap('bootstrap_app_key; printf "%s" "$APP_KEY"', [
        'APP_KEY_FILE' => $keyFile,
    ]);

    expect($result['exitCode'])->toBe(0)
        ->and($result['output'])->toBe('base64:persisted-key');
});

test('bootstrap generates and persists APP_KEY when unset', function () {
    $directory = sys_get_temp_dir().'/bootstrap-'.uniqid();
    mkdir($directory);
    $keyFile = $directory.'/.app_key';

    $result = runBootstrap('bootstrap_app_key', [
        'APP_KEY_FILE' => $keyFile,
    ]);

    expect($result['exitCode'])->toBe(0)
        ->and(file_exists($keyFile))->toBeTrue()
        ->and(file_get_contents($keyFile))->toStartWith('base64:')
        ->and($result['output'])->toContain('Auto-generated APP_KEY and persisted to '.$keyFile);
});

test('bootstrap skips migrations when marker file exists', function () {
    $directory = sys_get_temp_dir().'/bootstrap-'.uniqid();
    mkdir($directory);
    $marker = $directory.'/.migrations_applied';
    touch($marker);

    $result = runBootstrap('bootstrap_migrations', [
        'MIGRATIONS_MARKER' => $marker,
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => 'localhost',
    ]);

    expect($result['exitCode'])->toBe(0);
});

test('bootstrap skips migrations without database configuration', function () {
    $directory = sys_get_temp_dir().'/bootstrap-'.uniqid();
    mkdir($directory);
    $marker = $directory.'/.migrations_applied';

    $result = runBootstrap(
        'unset DB_URL DB_HOST DB_CONNECTION DB_DATABASE; bootstrap_migrations; test ! -f '.escapeshellarg($marker),
        [
            'MIGRATIONS_MARKER' => $marker,
        ],
    );

    expect($result['exitCode'])->toBe(0)
        ->and(file_exists($marker))->toBeFalse();
});
