<?php

use App\Enums\EducationLevel;
use App\Enums\EmploymentType;
use App\Services\CvParser\CvParserService;

test('extraction prompt includes all employment enum values', function () {
    $service = app(CvParserService::class);
    $prompt = $service->extractionPrompt();

    foreach (EmploymentType::cases() as $type) {
        expect($prompt)->toContain($type->value);
    }
});

test('extraction prompt includes all education enum values', function () {
    $service = app(CvParserService::class);
    $prompt = $service->extractionPrompt();

    foreach (EducationLevel::cases() as $level) {
        expect($prompt)->toContain($level->value);
    }
});

test('extraction prompt does not list shorthand education aliases as allowed values', function () {
    $service = app(CvParserService::class);
    $prompt = $service->extractionPrompt();

    expect($prompt)->toContain('Do NOT use shorthand aliases such as associate or phd');
});

test('json wrapped in markdown fences is decoded successfully', function () {
    $service = app(CvParserService::class);
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('decodeJson');

    $decoded = $method->invoke($service, "```json\n{\"first_name\":\"Jane\"}\n```");

    expect($decoded)->toBe(['first_name' => 'Jane']);
});
