# cv-parser-api Specification

## Purpose
TBD - created by archiving change project-scaffolding. Update Purpose after archive.
## Requirements
### Requirement: Status endpoint

The service MUST expose `GET /api/v1/status` returning JSON:

```json
{
  "available": true,
  "warning": null
}
```

`available` MUST be `true` when both `OPENROUTER_API_KEY` and `OPENROUTER_MODEL` are non-empty AND `OPENROUTER_PDF_ENGINE` (if set) is in the allowed set (`cloudflare-ai`, `mistral-ocr`, `native`).

`warning` MUST be a human-readable string when configuration is incomplete or invalid:

- Missing API key: `"CV parsing is unavailable until OPENROUTER_API_KEY is set."`
- Missing model: `"CV parsing is unavailable until OPENROUTER_MODEL is set."`
- Invalid PDF engine: message describing allowed values

The status endpoint MUST NOT require authentication.

#### Scenario: Fully configured status

- **WHEN** `OPENROUTER_API_KEY` and `OPENROUTER_MODEL` are set
- **AND** `OPENROUTER_PDF_ENGINE` is `cloudflare-ai` or unset
- **THEN** `GET /api/v1/status` returns HTTP 200 with `{ "available": true, "warning": null }`

#### Scenario: Missing model warning

- **WHEN** `OPENROUTER_MODEL` is empty
- **THEN** `GET /api/v1/status` returns HTTP 200 with `{ "available": false, "warning": "...OPENROUTER_MODEL..." }`

### Requirement: OpenRouter configuration

The service MUST read OpenRouter settings from configuration backed by environment variables:

| Config key | Env var | Purpose |
|---|---|---|
| `services.openrouter.api_key` | `OPENROUTER_API_KEY` | Bearer token for OpenRouter API |
| `services.openrouter.model` | `OPENROUTER_MODEL` | Model slug sent in chat completion requests |
| `services.openrouter.base_url` | `OPENROUTER_BASE_URL` | Defaults to `https://openrouter.ai/api/v1` |
| `services.openrouter.pdf_engine` | `OPENROUTER_PDF_ENGINE` | PDF engine for `file-parser` plugin; defaults to `cloudflare-ai` |

When `OPENROUTER_API_KEY` or `OPENROUTER_MODEL` is missing or empty, parse requests MUST fail with HTTP 503 and MUST NOT call OpenRouter.

When `OPENROUTER_PDF_ENGINE` is set to an unsupported value, parse requests MUST fail with HTTP 503 and MUST NOT call OpenRouter.

The service MUST NOT use a hardcoded default model slug.

#### Scenario: Missing API key blocks parse

- **WHEN** a client submits a valid PDF upload
- **AND** `OPENROUTER_API_KEY` is not configured
- **THEN** the system returns HTTP 503 with `{ "message": "...OPENROUTER_API_KEY..." }`
- **THEN** no outbound HTTP request is made to OpenRouter

#### Scenario: Configured model is used as-is

- **WHEN** `OPENROUTER_MODEL` is set to `anthropic/claude-3.5-sonnet`
- **AND** a parse request succeeds
- **THEN** the outbound OpenRouter request body includes `"model": "anthropic/claude-3.5-sonnet"`

### Requirement: PDF upload validation

The parse endpoint MUST be `POST /api/v1/parse` and MUST accept a single PDF file via multipart form field `cv`.

Validation rules:

- Required on parse POST
- MIME/type: PDF only (`application/pdf`)
- Max size: 5120 KB (5 MB)

Invalid uploads MUST return HTTP 422 with JSON body `{ "message": "The given data was invalid.", "errors": { "cv": ["..."] } }` and MUST NOT call OpenRouter.

The parse endpoint MUST NOT require authentication.

#### Scenario: Rejects non-PDF file

- **WHEN** a client uploads a `.png` file as `cv`
- **THEN** the system returns HTTP 422 with validation errors for `cv`
- **THEN** OpenRouter is not called

#### Scenario: Rejects oversized PDF

- **WHEN** a client uploads a PDF larger than 5 MB
- **THEN** the system returns HTTP 422 with validation errors for `cv`
- **THEN** OpenRouter is not called

#### Scenario: Rejects missing file

- **WHEN** a client POSTs without a `cv` field
- **THEN** the system returns HTTP 422 with validation errors for `cv`

### Requirement: Multimodal PDF extraction via OpenRouter

The parser MUST send the uploaded PDF to OpenRouter using chat completions with the OpenRouter `file-parser` plugin:

- Encode the PDF as base64
- Include it in the user message as a `file` content part with `file_data` URL form `data:application/pdf;base64,{payload}`
- Use the uploaded file's original filename, or `cv.pdf` if unavailable
- Include `plugins: [{ "id": "file-parser", "pdf": { "engine": "<configured engine>" } }]`
- System prompt: `"You extract structured CV data. Return only valid JSON matching the schema. Use null for unknown scalars, [] for empty lists. No markdown fences."`

The parser MUST use Laravel's HTTP client with a 60-second timeout.

OpenRouter request headers MUST include:

- `Authorization: Bearer {OPENROUTER_API_KEY}`
- `HTTP-Referer: {APP_URL}`
- `X-Title: {APP_NAME}`

On OpenRouter HTTP errors or non-JSON model output, the parser MUST return HTTP 422 with `{ "message": "<error>", "raw_content": "<optional model text>" }`.

#### Scenario: Successful OpenRouter response

- **WHEN** OpenRouter returns HTTP 200 with valid JSON in the assistant message
- **THEN** the parser maps the JSON to the grouped response payload
- **THEN** the API returns HTTP 200 with `{ "data": { ... } }`

#### Scenario: Model returns JSON wrapped in markdown fences

- **WHEN** OpenRouter returns content like `` ```json\n{...}\n``` ``
- **THEN** the parser strips fences and decodes the JSON successfully

#### Scenario: Parse request includes file-parser plugin

- **WHEN** a valid PDF parse request is sent to OpenRouter
- **THEN** the outbound request includes `"id": "file-parser"` with configured PDF engine

### Requirement: Extraction prompt schema and enum values

The extraction prompt MUST instruct the model to return a flat JSON object with keys: `first_name`, `last_name`, `phone`, `location`, `headline`, `summary`, `experiences`, `educations`, `skills`, `portfolio_url`, `linkedin_url`.

Allowed `employment_type` values in the prompt:

`full_time`, `part_time`, `contract`, `freelance`, `internship`, `permanent`, `casual_temporary`

Allowed `education_level` values in the prompt:

`high_school`, `certificate_1`, `certificate_2`, `certificate_3`, `certificate_4`, `diploma`, `associate_degree`, `bachelor`, `graduate_diploma`, `master`, `doctorate`

The prompt MUST NOT list shorthand aliases `associate` or `phd` as allowed output values.

The prompt MUST include mapping guidance from common CV abbreviations (BSc/BA → `bachelor`, MBA → `master`, PhD → `doctorate`, HSC/VCE → `high_school`, Cert I–IV → `certificate_1`–`certificate_4`, etc.).

The prompt MUST include these extraction rules:

- Extract only explicit CV information; no guessing except headline/summary inference rules
- Date fields as zero-padded months `"01"`–`"12"` and four-digit years
- `currently_working` true only for the single most recent ongoing role
- Skills max 30, deduplicated case-insensitively
- `linkedin_url` linkedin.com/in/ only; strip trailing slashes and query params
- Licenses/certifications map to skills, not educations
- Experience/education descriptions as single paragraphs without line breaks

#### Scenario: Prompt lists all employment enum values

- **WHEN** the extraction prompt is built
- **THEN** every `EmploymentType` backed value appears in allowed `employment_type` values

#### Scenario: Prompt lists all education enum values

- **WHEN** the extraction prompt is built
- **THEN** every `EducationLevel` backed value appears in allowed `education_level` values

### Requirement: Onboarding-shaped field mapping

Parsed output MUST be returned under `data` grouped as:

**`data.personal_info`:** `first_name`, `last_name`, `phone_code`, `phone_number`, `location`, `headline`, `summary` — all nullable strings except phone fields split from raw `phone` text.

Phone splitting MUST use default country `AU`. Unparseable phone text MUST yield `phone_code: null` and `phone_number: null`. The response MUST NOT include a top-level `phone` field in `personal_info`.

**`data.experience_education`:** `experiences[]`, `educations[]` with fields as defined in the design document.

`employment_type` unknown values MUST map to `null`. `education_level` unknown values and shorthand `phd`/`associate` MUST map to `null`.

**`data.skills_portfolio`:** `skills[]`, `portfolio_url`, `linkedin_url`.

URL normalization:

- Bare domains → `https://` prefix
- Protocol-relative `//` → `https://`
- Empty → `null`

Non-array `experiences`, `educations`, or `skills` MUST map to empty arrays.

The mapper MUST accept flat LLM JSON or pre-nested group shapes.

#### Scenario: Response includes all step groups

- **WHEN** a PDF is parsed successfully
- **THEN** the JSON response contains `data.personal_info`, `data.experience_education`, and `data.skills_portfolio`

#### Scenario: AU local phone split

- **WHEN** the LLM extracts phone text `0400 000 000`
- **THEN** `data.personal_info.phone_code` is `+61`
- **THEN** `data.personal_info.phone_number` is `400000000`

#### Scenario: Unknown employment type nulled

- **WHEN** the LLM extracts `employment_type` as `Self-employed`
- **THEN** the mapped experience has `employment_type` null

### Requirement: Automated tests with mocked OpenRouter

Feature tests MUST fake OpenRouter HTTP responses using `Http::fake()` and MUST NOT require a live API key in CI.

Tests MUST cover at minimum:

- Successful parse returns onboarding-shaped JSON under `data`
- Missing OpenRouter config returns HTTP 503 without outbound call
- Invalid file type returns HTTP 422
- Status endpoint reflects configuration state
- Parse request includes `file-parser` plugin with configured PDF engine

#### Scenario: CI test fakes OpenRouter

- **WHEN** the parse feature test runs
- **THEN** `Http::fake()` intercepts OpenRouter requests
- **THEN** the test passes without network access to openrouter.ai

