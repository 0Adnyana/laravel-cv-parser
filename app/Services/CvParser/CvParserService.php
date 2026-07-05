<?php

namespace App\Services\CvParser;

use App\Enums\EducationLevel;
use App\Enums\EmploymentType;
use Illuminate\Http\UploadedFile;

class CvParserService
{
    private const ALLOWED_PDF_ENGINES = ['cloudflare-ai', 'mistral-ocr', 'native'];

    public function __construct(
        private readonly OpenRouterClient $openRouterClient,
        private readonly OnboardingFieldMapper $mapper,
    ) {}

    public function getConfigurationWarning(): ?string
    {
        $config = config('services.openrouter');

        if (empty($config['api_key'])) {
            return 'CV parsing is unavailable until OPENROUTER_API_KEY is set.';
        }

        if (empty($config['model'])) {
            return 'CV parsing is unavailable until OPENROUTER_MODEL is set.';
        }

        $engine = $config['pdf_engine'] ?? 'cloudflare-ai';

        if (! in_array($engine, self::ALLOWED_PDF_ENGINES, true)) {
            return 'CV parsing is unavailable until OPENROUTER_PDF_ENGINE is set to one of: cloudflare-ai, mistral-ocr, native.';
        }

        return null;
    }

    public function isAvailable(): bool
    {
        return $this->getConfigurationWarning() === null;
    }

    /**
     * @return array{personal_info: array<string, mixed>, experience_education: array<string, mixed>, skills_portfolio: array<string, mixed>}
     */
    public function parse(UploadedFile $file): array
    {
        $warning = $this->getConfigurationWarning();

        if ($warning !== null) {
            throw new CvParserConfigurationException($warning);
        }

        $config = config('services.openrouter');
        $pdfContents = file_get_contents($file->getRealPath());

        if ($pdfContents === false) {
            throw new CvParserExtractionException('Failed to read uploaded PDF.');
        }

        $filename = $file->getClientOriginalName() ?: 'cv.pdf';
        $base64 = base64_encode($pdfContents);

        $response = $this->openRouterClient->chatCompletions([
            'model' => $config['model'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You extract structured CV data. Return only valid JSON matching the schema. Use null for unknown scalars, [] for empty lists. No markdown fences.',
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $this->extractionPrompt(),
                        ],
                        [
                            'type' => 'file',
                            'file' => [
                                'filename' => $filename,
                                'file_data' => 'data:application/pdf;base64,'.$base64,
                            ],
                        ],
                    ],
                ],
            ],
            'plugins' => [
                [
                    'id' => 'file-parser',
                    'pdf' => [
                        'engine' => $config['pdf_engine'],
                    ],
                ],
            ],
        ]);

        if (! $response->successful()) {
            throw new CvParserExtractionException(
                'CV extraction failed due to an OpenRouter API error.',
            );
        }

        $content = data_get($response->json(), 'choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new CvParserExtractionException('CV extraction failed: empty model response.');
        }

        try {
            $decoded = $this->decodeJson($content);
        } catch (CvParserExtractionException $exception) {
            throw new CvParserExtractionException($exception->getMessage(), $content);
        }

        return $this->mapper->map($decoded);
    }

    public function extractionPrompt(): string
    {
        $employmentTypes = implode(', ', array_map(
            fn (EmploymentType $type): string => $type->value,
            EmploymentType::cases(),
        ));

        $educationLevels = implode(', ', array_map(
            fn (EducationLevel $level): string => $level->value,
            EducationLevel::cases(),
        ));

        return <<<PROMPT
Extract structured data from this CV/resume and return a single flat JSON object with these keys:
first_name, last_name, phone, location, headline, summary, experiences, educations, skills, portfolio_url, linkedin_url.

Rules:
- Extract only explicit CV information. Do not guess values except headline/summary may be inferred from role and experience when not stated.
- Use null for unknown scalar fields and [] for empty lists.
- Date fields must use zero-padded string months "01" through "12" and four-digit years.
- Set currently_working to true for only the single most recent ongoing role; false or null for others.
- Include at most 30 skills, deduplicated case-insensitively.
- linkedin_url must be linkedin.com/in/ profile URLs only; strip trailing slashes and query parameters.
- Map licenses and certifications to skills, not educations.
- Experience and education descriptions must be single paragraphs without line breaks.

Allowed employment_type values for each experience object:
{$employmentTypes}

Allowed education_level values for each education object:
{$educationLevels}

Do not output shorthand education aliases "associate" or "phd". Map common CV abbreviations to canonical values:
BSc, BA, BEng, Bachelor → bachelor
MBA → master
PhD, DPhil → doctorate
HSC, VCE, Year 12 → high_school
Cert I, Cert II, Cert III, Cert IV → certificate_1, certificate_2, certificate_3, certificate_4
Diploma → diploma
Associate Degree → associate_degree
Graduate Diploma → graduate_diploma

Each experience object should include: company_name, job_title, employment_type, currently_working, start_month, start_year, end_month, end_year, description, location.

Each education object should include: school_name, school_location, education_level, field_of_study, start_month, start_year, end_month, end_year, description.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $content): array
    {
        $stripped = trim($content);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $stripped, $matches)) {
            $stripped = trim($matches[1]);
        }

        $decoded = json_decode($stripped, true);

        if (! is_array($decoded)) {
            throw new CvParserExtractionException('CV extraction failed: model response was not valid JSON.');
        }

        return $decoded;
    }
}
