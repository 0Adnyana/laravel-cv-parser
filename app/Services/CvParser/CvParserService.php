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
Extract structured data from this CV/resume PDF for job seeker onboarding pre-fill.

Return a single flat JSON object (not nested under step names). Match the schema below.

Rules:
- Extract only information explicitly present in the CV. Do not guess or invent values, except for headline and summary as defined below.
- Use null for unknown scalars and [] for empty lists. Keep every top-level key. No placeholder objects in experiences/educations arrays.
- Trim whitespace. Truncate at max length rather than omit.
- Order experiences and educations most-recent first.
- Names: Split into first_name and last_name when discernible. Mononyms → first_name only, last_name null. Do not invent missing parts.

Date fields (start_month, start_year, end_month, end_year — every experience and education entry):
- All four MUST be JSON strings, never numbers.
- Months: zero-padded "01"–"12". Convert month names/abbreviations; never output month names, unpadded digits, or quarters.
- Years: four digits, e.g. "2020". Never two-digit years.
- Month+year shown (e.g. "Mar 2021"): extract both. "Jan 2020 – Dec 2022" → "01"/"2020"/"12"/"2022".
- Year only (e.g. "2018–2020"): month fields null, keep years.
- Undetermined → null. Do not guess.

first_name / last_name: max 255 chars.
phone: Full number as written on the CV; copy verbatim, do not reformat. Null if absent.
location: City, state/region, country, max 255. e.g. "Sydney, NSW, Australia".
headline: Derived from most recent job_title, simplified (drop company, codes, excess qualifiers), max 255. No experiences → use CV header title; null if none.
summary: Verbatim from summary/profile/objective if present (light clarity edits only). Otherwise 2–4 sentence third-person synthesis from CV facts only. Null if insufficient content.

experiences: Work, internship, freelance, and contract roles. Each entry:
- company_name, job_title: max 255, required to include entry.
- employment_type: Exactly one of {$employmentTypes}. Map full-time→full_time, part-time→part_time, permanent→permanent, casual/temporary→casual_temporary, contractor→contract or freelance; null if unclear.
- currently_working: JSON boolean. True only for the single most recent ongoing role. Treat "Present", "Current", "Now", "Ongoing", "To date", and "–" with no end date as ongoing. Other ongoing-looking roles → false; keep stated end dates or leave end null with false.
- start/end month/year: per date rules. end_month and end_year null when currently_working true. Do not merge distinct roles.
- description: Combine bullet points into a single paragraph; no line breaks between items.
- location: Workplace city/region or "Remote"; null if unmentioned.

educations: Degrees, diplomas, and schooling. Each entry:
- school_name: max 255, required.
- school_location: Institution location from that entry, max 255. "City, State, Country" when known; not campus/street. Null if unstated.
- education_level: Exactly one of {$educationLevels}, or null if uncertain. Map common CV abbreviations to canonical values: BSc/BA/BEng→bachelor, MBA→master, PhD/DPhil→doctorate, HSC/VCE/Year 12→high_school, Cert I→certificate_1, Cert II→certificate_2, Cert III→certificate_3, Cert IV→certificate_4, Diploma→diploma, Associate Degree→associate_degree, Graduate Diploma→graduate_diploma; null if uncertain. Do NOT use shorthand aliases such as associate or phd.
- field_of_study: max 255.
- dates: per date rules. In-progress → end_month/end_year null.
- description: Honors, thesis, coursework as a single paragraph; no line breaks. Null if absent.

Licenses/certifications (AWS, CPA, First Aid) → skills, not educations.

skills: Flat string array, max 30. Prefer skills/technologies sections; from experience bullets only named tools/languages/frameworks/certifications. Split comma/slash/pipe lists. Deduplicate case-insensitively; keep CV casing. Strip proficiency/levels/years ("Advanced Excel"→"Excel"). Human languages → English names. If >30, keep skills-section items first, then experience tools until limit. No categories or nested objects.

portfolio_url: Personal site, GitHub, Behance, etc. Null if absent.
linkedin_url: linkedin.com/in/... or www.linkedin.com/in/... only; strip trailing slashes and query params. Null if absent.
No email or mailto: in URL fields.

Return JSON with this exact shape:

{
  "first_name": null,
  "last_name": null,
  "phone": null,
  "location": null,
  "headline": null,
  "summary": null,
  "experiences": [],
  "educations": [],
  "skills": [],
  "portfolio_url": null,
  "linkedin_url": null
}
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
