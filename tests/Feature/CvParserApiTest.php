<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

function fakeOpenRouterCvResponse(array $cvData): void
{
    Http::fake([
        'openrouter.ai/*' => Http::response([
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
