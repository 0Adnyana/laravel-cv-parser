<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

function fakeOpenRouterModelsApi(bool $supportsFile = true, string $model = 'anthropic/claude-3.5-sonnet'): void
{
    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response([
            'data' => [
                [
                    'id' => $model,
                    'architecture' => [
                        'input_modalities' => $supportsFile ? ['text', 'file'] : ['text'],
                    ],
                ],
            ],
        ]),
    ]);
}

function fakeOpenRouterCvResponse(array $cvData, bool $supportsFile = true, string $model = 'anthropic/claude-3.5-sonnet'): void
{
    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response([
            'data' => [
                [
                    'id' => $model,
                    'architecture' => [
                        'input_modalities' => $supportsFile ? ['text', 'file'] : ['text'],
                    ],
                ],
            ],
        ]),
        'openrouter.ai/api/v1/chat/completions' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode($cvData, JSON_THROW_ON_ERROR),
                    ],
                ],
            ],
        ]),
    ]);
}

function fakeOpenRouterPdfTextAnnotations(string $text = 'Jane Doe - Software Engineer'): array
{
    return [
        'choices' => [
            [
                'message' => [
                    'content' => 'Acknowledged.',
                    'annotations' => [
                        [
                            'type' => 'file',
                            'file' => [
                                'hash' => 'abc123',
                                'name' => 'resume.pdf',
                                'content' => [
                                    [
                                        'type' => 'text',
                                        'text' => $text,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function sampleCvData(): array
{
    return [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'phone' => '0400 000 000',
        'location' => 'Sydney, NSW',
        'headline' => 'Software Engineer',
        'summary' => 'Experienced developer.',
        'experiences' => [
            [
                'company_name' => 'Acme Corp',
                'job_title' => 'Developer',
                'employment_type' => 'full_time',
                'currently_working' => true,
                'start_month' => '01',
                'start_year' => '2020',
                'end_month' => null,
                'end_year' => null,
                'description' => 'Built web apps.',
                'location' => 'Sydney',
            ],
        ],
        'educations' => [
            [
                'school_name' => 'Example University',
                'school_location' => 'Sydney',
                'education_level' => 'bachelor',
                'field_of_study' => 'Computer Science',
                'start_month' => '02',
                'start_year' => '2015',
                'end_month' => '11',
                'end_year' => '2018',
                'description' => 'Graduated with honours.',
            ],
        ],
        'skills' => ['PHP', 'Laravel'],
        'portfolio_url' => 'jane.dev',
        'linkedin_url' => 'https://linkedin.com/in/janedoe',
    ];
}

beforeEach(function () {
    config([
        'services.openrouter.api_key' => 'test-api-key',
        'services.openrouter.model' => 'anthropic/claude-3.5-sonnet',
        'services.openrouter.base_url' => 'https://openrouter.ai/api/v1',
        'services.openrouter.pdf_engine' => 'cloudflare-ai',
        'app.url' => 'http://localhost',
        'app.name' => 'CV Parser Demo',
    ]);
});

test('status endpoint reflects fully configured state', function () {
    $this->getJson('/api/v1/status')
        ->assertSuccessful()
        ->assertJson([
            'available' => true,
            'warning' => null,
        ]);
});

test('status endpoint reflects missing model configuration', function () {
    config(['services.openrouter.model' => null]);

    $this->getJson('/api/v1/status')
        ->assertSuccessful()
        ->assertJson([
            'available' => false,
            'warning' => 'CV parsing is unavailable until OPENROUTER_MODEL is set.',
        ]);
});

test('successful parse returns grouped json under data', function () {
    fakeOpenRouterCvResponse(sampleCvData());

    $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

    $response = $this->postJson('/api/v1/parse', ['cv' => $file]);

    $response->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'personal_info' => [
                    'first_name',
                    'last_name',
                    'phone_code',
                    'phone_number',
                    'location',
                    'headline',
                    'summary',
                ],
                'experience_education' => [
                    'experiences',
                    'educations',
                ],
                'skills_portfolio' => [
                    'skills',
                    'portfolio_url',
                    'linkedin_url',
                ],
            ],
        ])
        ->assertJsonPath('data.personal_info.first_name', 'Jane')
        ->assertJsonPath('data.personal_info.phone_code', '+61')
        ->assertJsonPath('data.personal_info.phone_number', '400000000')
        ->assertJsonPath('data.skills_portfolio.portfolio_url', 'https://jane.dev');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), 'openrouter.ai/api/v1/chat/completions')
            && $body['model'] === 'anthropic/claude-3.5-sonnet'
            && $body['plugins'][0]['id'] === 'file-parser'
            && $body['plugins'][0]['pdf']['engine'] === 'cloudflare-ai';
    });
});

test('missing openrouter api key returns 503 without outbound call', function () {
    Http::fake();
    config(['services.openrouter.api_key' => null]);

    $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

    $this->postJson('/api/v1/parse', ['cv' => $file])
        ->assertStatus(503)
        ->assertJsonFragment([
            'message' => 'CV parsing is unavailable until OPENROUTER_API_KEY is set.',
        ]);

    Http::assertNothingSent();
});

test('missing openrouter model returns 503 without outbound call', function () {
    Http::fake();
    config(['services.openrouter.model' => null]);

    $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

    $this->postJson('/api/v1/parse', ['cv' => $file])
        ->assertStatus(503)
        ->assertJsonFragment([
            'message' => 'CV parsing is unavailable until OPENROUTER_MODEL is set.',
        ]);

    Http::assertNothingSent();
});

test('invalid file type returns 422', function () {
    Http::fake();

    $file = UploadedFile::fake()->create('resume.png', 100, 'image/png');

    $this->postJson('/api/v1/parse', ['cv' => $file])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cv']);

    Http::assertNothingSent();
});

test('parse does not require authentication', function () {
    fakeOpenRouterCvResponse(sampleCvData());

    $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

    $this->postJson('/api/v1/parse', ['cv' => $file])
        ->assertSuccessful();
});

test('text-only model uses cloudflare-ai extraction and text-only structuring', function () {
    config(['services.openrouter.model' => 'openai/gpt-4.1-mini']);

    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response([
            'data' => [
                [
                    'id' => 'openai/gpt-4.1-mini',
                    'architecture' => [
                        'input_modalities' => ['text'],
                    ],
                ],
            ],
        ]),
        'openrouter.ai/api/v1/chat/completions' => Http::sequence()
            ->push(fakeOpenRouterPdfTextAnnotations())
            ->push([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode(sampleCvData(), JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ]),
    ]);

    $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

    $this->postJson('/api/v1/parse', ['cv' => $file])
        ->assertSuccessful()
        ->assertJsonPath('data.personal_info.first_name', 'Jane');

    Http::assertSentCount(3);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'openrouter.ai/api/v1/chat/completions')) {
            return false;
        }

        $body = $request->data();

        return ($body['plugins'][0]['pdf']['engine'] ?? null) === 'cloudflare-ai'
            && collect($body['messages'][1]['content'] ?? [])->contains(
                fn (array $part): bool => ($part['type'] ?? null) === 'file',
            );
    });

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'openrouter.ai/api/v1/chat/completions')) {
            return false;
        }

        $body = $request->data();
        $content = $body['messages'][1]['content'] ?? [];

        $hasOnlyTextParts = collect($content)->every(
            fn (array $part): bool => ($part['type'] ?? null) === 'text',
        );
        $hasCvTextDelimiter = collect($content)->contains(
            fn (array $part): bool => str_contains((string) ($part['text'] ?? ''), '--- CV TEXT ---'),
        );

        return ! array_key_exists('plugins', $body)
            && $hasOnlyTextParts
            && $hasCvTextDelimiter;
    });
});

test('multimodal pdf parse error triggers text extraction fallback', function () {
    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response([
            'data' => [
                [
                    'id' => 'anthropic/claude-3.5-sonnet',
                    'architecture' => [
                        'input_modalities' => ['text', 'file'],
                    ],
                ],
            ],
        ]),
        'openrouter.ai/api/v1/chat/completions' => Http::sequence()
            ->push([
                'error' => [
                    'message' => 'Unable to parse PDF data from the uploaded file.',
                ],
            ], 422)
            ->push(fakeOpenRouterPdfTextAnnotations())
            ->push([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode(sampleCvData(), JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
            ]),
    ]);

    $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

    $this->postJson('/api/v1/parse', ['cv' => $file])
        ->assertSuccessful()
        ->assertJsonPath('data.personal_info.first_name', 'Jane');

    Http::assertSentCount(4);
});

test('multimodal pdf parse error with failed fallback returns 422', function () {
    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response([
            'data' => [
                [
                    'id' => 'anthropic/claude-3.5-sonnet',
                    'architecture' => [
                        'input_modalities' => ['text', 'file'],
                    ],
                ],
            ],
        ]),
        'openrouter.ai/api/v1/chat/completions' => Http::sequence()
            ->push([
                'error' => [
                    'message' => 'Unable to parse PDF data from the uploaded file.',
                ],
            ], 422)
            ->push([
                'error' => [
                    'code' => 503,
                    'message' => 'Extraction service unavailable.',
                    'metadata' => [
                        'provider' => 'DeepInfra',
                    ],
                ],
            ], 503),
    ]);

    $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

    $this->postJson('/api/v1/parse', ['cv' => $file])
        ->assertUnprocessable()
        ->assertJson([
            'message' => 'The provider is temporarily unavailable. Try again shortly.',
        ])
        ->assertJsonMissing(['openrouter_error' => true]);
});

test('non-pdf openrouter error does not trigger text extraction fallback', function () {
    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response([
            'data' => [
                [
                    'id' => 'anthropic/claude-3.5-sonnet',
                    'architecture' => [
                        'input_modalities' => ['text', 'file'],
                    ],
                ],
            ],
        ]),
        'openrouter.ai/api/v1/chat/completions' => Http::response([
            'error' => [
                'code' => 429,
                'message' => 'Rate limit exceeded.',
                'metadata' => [
                    'provider' => 'OpenAI',
                ],
            ],
        ], 429),
    ]);

    $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

    $this->postJson('/api/v1/parse', ['cv' => $file])
        ->assertUnprocessable()
        ->assertJson([
            'message' => 'The provider is rate limiting requests. Wait and try again, or switch to a different model.',
        ])
        ->assertJsonMissing(['openrouter_error' => true]);

    Http::assertSentCount(2);
});

test('provider returned error surfaces provider rate limit message', function () {
    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response([
            'data' => [
                [
                    'id' => 'anthropic/claude-3.5-sonnet',
                    'architecture' => [
                        'input_modalities' => ['text', 'file'],
                    ],
                ],
            ],
        ]),
        'openrouter.ai/api/v1/chat/completions' => Http::response([
            'error' => [
                'code' => 429,
                'message' => 'Provider returned error',
                'metadata' => [
                    'error_type' => 'rate_limit_exceeded',
                ],
            ],
        ], 429),
    ]);

    $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

    $this->postJson('/api/v1/parse', ['cv' => $file])
        ->assertUnprocessable()
        ->assertJson([
            'message' => 'The provider is rate limiting requests. Wait and try again, or switch to a different model.',
        ]);
});
