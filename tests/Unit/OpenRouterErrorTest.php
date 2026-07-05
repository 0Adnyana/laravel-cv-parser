<?php

use App\Services\CvParser\OpenRouterError;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;

function openRouterErrorResponse(int $status, array $body): Response
{
    return new Response(new PsrResponse(
        $status,
        ['Content-Type' => 'application/json'],
        (string) json_encode($body, JSON_THROW_ON_ERROR),
    ));
}

test('provider returned error with 429 maps to provider rate limit message', function () {
    $response = openRouterErrorResponse(429, [
        'error' => [
            'code' => 429,
            'message' => 'Provider returned error',
            'metadata' => [
                'error_type' => 'rate_limit_exceeded',
            ],
        ],
    ]);

    expect(OpenRouterError::fromResponse($response)->userMessage())
        ->toBe('The provider is rate limiting requests. Wait and try again, or switch to a different model.');
});

test('provider rate limit uses generic provider label', function () {
    $response = openRouterErrorResponse(429, [
        'error' => [
            'message' => 'Rate limit exceeded.',
            'metadata' => [
                'error_type' => 'rate_limit_exceeded',
                'provider' => 'OpenAI',
            ],
        ],
    ]);

    expect(OpenRouterError::fromResponse($response)->userMessage())
        ->toBe('The provider is rate limiting requests. Wait and try again, or switch to a different model.');
});

test('openrouter rate limit without provider metadata maps to api key message', function () {
    $response = openRouterErrorResponse(429, [
        'error' => [
            'message' => 'Rate limit exceeded.',
        ],
    ]);

    expect(OpenRouterError::fromResponse($response)->userMessage())
        ->toBe('OpenRouter rate limit reached for this API key. Wait and try again.');
});

test('insufficient credits maps to billing message', function () {
    $response = openRouterErrorResponse(402, [
        'error' => [
            'message' => 'Insufficient credits',
            'metadata' => [
                'error_type' => 'insufficient_credits',
            ],
        ],
    ]);

    expect(OpenRouterError::fromResponse($response)->userMessage())
        ->toBe('Insufficient API credits on your OpenRouter account.');
});

test('specific provider messages are passed through unchanged', function () {
    $response = openRouterErrorResponse(400, [
        'error' => [
            'message' => 'Invalid file format for model input.',
        ],
    ]);

    expect(OpenRouterError::fromResponse($response)->userMessage())
        ->toBe('Invalid file format for model input.');
});

test('provider service unavailable maps to provider message', function () {
    $response = openRouterErrorResponse(503, [
        'error' => [
            'message' => 'Extraction service unavailable.',
            'metadata' => [
                'provider' => 'DeepInfra',
            ],
        ],
    ]);

    expect(OpenRouterError::fromResponse($response)->userMessage())
        ->toBe('The provider is temporarily unavailable. Try again shortly.');
});

test('openrouter routing failure maps to openrouter message', function () {
    $response = openRouterErrorResponse(503, [
        'error' => [
            'message' => 'Service unavailable.',
        ],
    ]);

    expect(OpenRouterError::fromResponse($response)->userMessage())
        ->toBe('OpenRouter could not route to an available provider. Try again or switch models.');
});

test('generic provider error uses status based message', function () {
    $response = openRouterErrorResponse(502, [
        'error' => [
            'message' => 'Provider returned error',
        ],
    ]);

    expect(OpenRouterError::fromResponse($response)->userMessage())
        ->toBe('The provider is unavailable right now. Try again or use a different model.');
});
