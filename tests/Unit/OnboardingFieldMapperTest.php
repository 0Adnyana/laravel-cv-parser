<?php

use App\Services\CvParser\OnboardingFieldMapper;

test('mapper splits au local phone into code and number', function () {
    $mapper = app(OnboardingFieldMapper::class);

    $mapped = $mapper->map([
        'phone' => '0400 000 000',
    ]);

    expect($mapped['personal_info']['phone_code'])->toBe('+61')
        ->and($mapped['personal_info']['phone_number'])->toBe('400000000')
        ->and($mapped['personal_info'])->not->toHaveKey('phone');
});

test('mapper normalizes bare portfolio urls', function () {
    $mapper = app(OnboardingFieldMapper::class);

    $mapped = $mapper->map([
        'portfolio_url' => 'jane.dev',
    ]);

    expect($mapped['skills_portfolio']['portfolio_url'])->toBe('https://jane.dev');
});

test('mapper nulls unknown employment types', function () {
    $mapper = app(OnboardingFieldMapper::class);

    $mapped = $mapper->map([
        'experiences' => [
            [
                'company_name' => 'Acme',
                'employment_type' => 'Self-employed',
            ],
        ],
    ]);

    expect($mapped['experience_education']['experiences'][0]['employment_type'])->toBeNull();
});

test('mapper nulls shorthand education aliases', function () {
    $mapper = app(OnboardingFieldMapper::class);

    $mapped = $mapper->map([
        'educations' => [
            ['education_level' => 'phd'],
            ['education_level' => 'associate'],
        ],
    ]);

    expect($mapped['experience_education']['educations'][0]['education_level'])->toBeNull()
        ->and($mapped['experience_education']['educations'][1]['education_level'])->toBeNull();
});

test('mapper accepts pre nested group shapes', function () {
    $mapper = app(OnboardingFieldMapper::class);

    $mapped = $mapper->map([
        'personal_info' => [
            'first_name' => 'Jane',
            'phone' => '+61 412 345 678',
        ],
        'experience_education' => [
            'experiences' => [],
            'educations' => [],
        ],
        'skills_portfolio' => [
            'skills' => ['PHP'],
            'portfolio_url' => null,
            'linkedin_url' => null,
        ],
    ]);

    expect($mapped['personal_info']['first_name'])->toBe('Jane')
        ->and($mapped['personal_info']['phone_code'])->toBe('+61')
        ->and($mapped['personal_info']['phone_number'])->toBe('412345678')
        ->and($mapped['skills_portfolio']['skills'])->toBe(['PHP']);
});
