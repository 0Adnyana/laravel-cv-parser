<?php

use App\Enums\EducationLevel;
use App\Enums\EmploymentType;
use App\Services\CvParser\OpenRouter\OpenRouterStructuredResponseParser;
use App\Services\CvParser\Prompts\CvParserPromptBuilder;

test('extraction prompt includes all employment enum values', function () {
    $promptBuilder = app(CvParserPromptBuilder::class);
    $prompt = $promptBuilder->extractionPrompt();

    foreach (EmploymentType::cases() as $type) {
        expect($prompt)->toContain($type->value);
    }
});

test('extraction prompt includes all education enum values', function () {
    $promptBuilder = app(CvParserPromptBuilder::class);
    $prompt = $promptBuilder->extractionPrompt();

    foreach (EducationLevel::cases() as $level) {
        expect($prompt)->toContain($level->value);
    }
});

test('extraction prompt does not list shorthand education aliases as allowed values', function () {
    $promptBuilder = app(CvParserPromptBuilder::class);
    $prompt = $promptBuilder->extractionPrompt();

    expect($prompt)->toContain('Do NOT use shorthand aliases such as associate or phd');
});

test('json wrapped in markdown fences is decoded successfully', function () {
    $parser = app(OpenRouterStructuredResponseParser::class);

    $decoded = $parser->decodeJson("```json\n{\"first_name\":\"Jane\"}\n```");

    expect($decoded)->toBe(['first_name' => 'Jane']);
});
