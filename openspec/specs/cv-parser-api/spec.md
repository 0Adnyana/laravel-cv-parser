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

### Requirement: OpenRouter model capability detection

Before parsing a CV, the service MUST determine whether the configured `OPENROUTER_MODEL` supports native `file` input by querying OpenRouter's models API (`GET /api/v1/models`) and inspecting `architecture.input_modalities` for the matching model slug.

The lookup MUST use the same base URL and API key as chat completions. The lookup MAY be cached in memory for the duration of a single parse request.

If the models API is unreachable or the configured slug is not found, the service MUST assume the model does NOT support native `file` input and route to the text-extraction path.

#### Scenario: Text-only model detected proactively

- **WHEN** `OPENROUTER_MODEL` is set to a model whose `architecture.input_modalities` does not include `file`
- **AND** a valid PDF is uploaded
- **THEN** the parser uses the text-extraction path without first attempting the multimodal path

#### Scenario: File-capable model uses multimodal path first

- **WHEN** `OPENROUTER_MODEL` is set to a model whose `architecture.input_modalities` includes `file`
- **AND** a valid PDF is uploaded
- **THEN** the parser attempts the multimodal path first

#### Scenario: Unknown model slug defaults to text-extraction path

- **WHEN** the configured model slug is not present in the models API response
- **AND** a valid PDF is uploaded
- **THEN** the parser uses the text-extraction path

### Requirement: PDF text extraction via OpenRouter file-parser

The text-extraction path MUST extract plain text from the uploaded PDF using OpenRouter's `file-parser` plugin with engine `cloudflare-ai` (OpenRouter's lightweight text-extraction engine; deprecated `pdf-text` redirects here).

The extraction request MUST:

- Encode the PDF as base64 in a `file` content part (`data:application/pdf;base64,{payload}`)
- Include `plugins: [{ "id": "file-parser", "pdf": { "engine": "cloudflare-ai" } }]`
- Use a minimal system prompt instructing the model to acknowledge receipt of extracted content (the actual CV text is obtained from response annotations, not from model-generated prose)

Extracted text MUST be read from `choices[0].message.annotations` where `type` is `file`, concatenating all `content` parts where `type` is `text`. If annotations are absent on a successful response, extracted text MAY be taken from `error.metadata.file_annotations` on a failed extraction response (same schema).

If no extractable text is found, the parser MUST throw `CvParserExtractionException` with message `CV extraction failed: could not extract text from PDF.`

#### Scenario: Text extracted from file annotations

- **WHEN** the text-extraction request returns HTTP 200
- **AND** `choices[0].message.annotations` contains a `file` annotation with text content parts
- **THEN** the parser concatenates annotation text blocks into a single string for the structuring call

#### Scenario: Text extracted from error metadata annotations

- **WHEN** the text-extraction request returns a non-success HTTP status
- **AND** `error.metadata.file_annotations` contains text content parts
- **THEN** the parser uses those annotations as the extracted text

#### Scenario: Extraction uses cloudflare-ai engine

- **WHEN** the text-extraction path runs
- **THEN** the outbound request includes `"id": "file-parser"` with `"engine": "cloudflare-ai"`

### Requirement: Text-only structuring call

After PDF text is extracted, the parser MUST send a second chat-completions request that structures CV data from plain text only:

- User message content MUST be two `text` parts: the extraction prompt, then a delimiter block containing the extracted PDF text (e.g. `--- CV TEXT ---\n{text}`)
- The request MUST NOT include a `file` content part
- The request MUST NOT include the `file-parser` plugin
- The system prompt MUST remain: `"You extract structured CV data. Return only valid JSON matching the schema. Use null for unknown scalars, [] for empty lists. No markdown fences."`

JSON decoding, mapping, and error handling for the structuring response MUST follow existing parser rules.

#### Scenario: Structuring call is text-only

- **WHEN** the text-extraction path completes extraction
- **THEN** the structuring outbound request contains only `text` content parts and no `plugins` array

#### Scenario: Successful text-extraction path returns mapped data

- **WHEN** both extraction and structuring calls succeed with valid JSON
- **THEN** the API returns HTTP 200 with onboarding-shaped `data` payload

### Requirement: PDF-parse error reactive fallback

When the multimodal path fails, the parser MUST inspect the OpenRouter error response body (JSON `error.message`, top-level `message`, or raw body string). If the body matches a PDF-parse failure pattern — case-insensitive substring match against `unable to parse pdf` — the parser MUST automatically retry once via the text-extraction path before returning HTTP 422.

The fallback MUST NOT retry if the text-extraction path was already used (preemptive routing or prior fallback).

#### Scenario: Multimodal PDF-parse error triggers fallback

- **WHEN** the multimodal path returns a non-success response whose body contains `unable to parse pdf`
- **AND** the configured model supports `file` input
- **THEN** the parser retries via the text-extraction path
- **THEN** a successful structuring response returns HTTP 200

#### Scenario: Non-PDF errors do not trigger fallback

- **WHEN** the multimodal path returns a non-success response whose body does not match PDF-parse patterns
- **THEN** the parser returns HTTP 422 without retrying via text extraction

#### Scenario: Fallback failure surfaces original error context

- **WHEN** the multimodal path fails with a PDF-parse error
- **AND** the text-extraction path also fails
- **THEN** the parser returns HTTP 422 with an extraction error message

### Requirement: Multimodal PDF extraction via OpenRouter

The parser MUST support two extraction strategies selected automatically:

1. **Multimodal path** (default for models with `file` input modality): send the uploaded PDF to OpenRouter using chat completions with the OpenRouter `file-parser` plugin.
2. **Text-extraction path** (for text-only models or PDF-parse fallback): extract PDF text via `cloudflare-ai`, then structure via a text-only chat completion.

**Multimodal path** MUST:

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

On OpenRouter HTTP errors or non-JSON model output (after any permitted text-extraction fallback), the parser MUST return HTTP 422 with `{ "message": "<error>", "raw_content": "<optional model text>" }`.

#### Scenario: Successful OpenRouter response

- **WHEN** OpenRouter returns HTTP 200 with valid JSON in the assistant message
- **THEN** the parser maps the JSON to the grouped response payload
- **THEN** the API returns HTTP 200 with `{ "data": { ... } }`

#### Scenario: Model returns JSON wrapped in markdown fences

- **WHEN** OpenRouter returns content like `` ```json\n{...}\n``` ``
- **THEN** the parser strips fences and decodes the JSON successfully

#### Scenario: Parse request includes file-parser plugin on multimodal path

- **WHEN** a valid PDF parse request is sent via the multimodal path
- **THEN** the outbound request includes `"id": "file-parser"` with configured PDF engine

#### Scenario: Text-only model skips multimodal file attachment

- **WHEN** the configured model lacks `file` input modality
- **THEN** the outbound structuring request does not include a `file` content part

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
- Parse request includes `file-parser` plugin with configured PDF engine on multimodal path
- Text-only model routes to text-extraction path (models API returns no `file` modality)
- PDF-parse error on multimodal path triggers text-extraction fallback and succeeds
- PDF-parse error fallback failure returns HTTP 422

#### Scenario: CI test fakes OpenRouter

- **WHEN** the parse feature test runs
- **THEN** `Http::fake()` intercepts OpenRouter requests
- **THEN** the test passes without network access to openrouter.ai

#### Scenario: Text-only model test fakes models API and two-step parse

- **WHEN** a test configures a text-only model via mocked models API
- **THEN** the test asserts an extraction call with `cloudflare-ai` engine
- **THEN** the test asserts a follow-up text-only structuring call without `file` parts

#### Scenario: PDF-parse fallback test fakes retry

- **WHEN** a test fakes a multimodal failure with `unable to parse pdf` in the error body
- **THEN** the test asserts a subsequent text-extraction path call
- **THEN** the test asserts HTTP 200 on successful fallback

