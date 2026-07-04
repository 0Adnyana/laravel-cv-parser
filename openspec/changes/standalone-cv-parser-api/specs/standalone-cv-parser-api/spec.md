## ADDED Requirements

### Requirement: Service authentication

All `/api/v1/*` endpoints MUST require a valid Bearer token in the `Authorization` header.

The service MUST read the expected token from the `CV_PARSER_API_KEY` environment variable.

Requests with a missing, malformed, or incorrect token MUST return HTTP 401 with JSON body `{ "message": "Unauthorized." }` and MUST NOT invoke the parser or OpenRouter.

#### Scenario: Valid Bearer token allows access

- **WHEN** a client sends `Authorization: Bearer {CV_PARSER_API_KEY}` with a valid token
- **AND** the request is otherwise valid
- **THEN** the request proceeds to route handling

#### Scenario: Missing token returns 401

- **WHEN** a client calls `/api/v1/parse` without an `Authorization` header
- **THEN** the system returns HTTP 401 with `{ "message": "Unauthorized." }`
- **THEN** OpenRouter is not called

#### Scenario: Invalid token returns 401

- **WHEN** a client sends `Authorization: Bearer wrong-token`
- **THEN** the system returns HTTP 401
- **THEN** OpenRouter is not called

### Requirement: Status endpoint

The service MUST expose `GET /api/v1/status` returning JSON:

```json
{
  "available": true,
  "warning": null
}
```

`available` MUST be `true` when both `OPENROUTER_API_KEY` and `OPENROUTER_MODEL` are non-empty AND `OPENROUTER_PDF_ENGINE` (if set) is in the allowed set.

`warning` MUST be a human-readable string when configuration is incomplete or invalid, matching the messages from `CvParserService::getConfigurationWarning()`:

- Missing API key: `"CV parsing is unavailable until OPENROUTER_API_KEY is set."`
- Missing model: `"CV parsing is unavailable until OPENROUTER_MODEL is set."`

When `OPENROUTER_PDF_ENGINE` is invalid, `available` MUST be `false` and `warning` MUST describe the allowed values.

#### Scenario: Fully configured status

- **WHEN** `OPENROUTER_API_KEY` and `OPENROUTER_MODEL` are set
- **AND** `OPENROUTER_PDF_ENGINE` is `cloudflare-ai` or unset
- **THEN** `GET /api/v1/status` returns HTTP 200 with `{ "available": true, "warning": null }`

#### Scenario: Missing model warning

- **WHEN** `OPENROUTER_MODEL` is empty
- **THEN** `GET /api/v1/status` returns HTTP 200 with `{ "available": false, "warning": "...OPENROUTER_MODEL..." }`

### Requirement: OpenRouter configuration

The service MUST read OpenRouter credentials and model from configuration backed by environment variables:

| Config key | Env var | Purpose |
|---|---|---|
| `services.openrouter.api_key` | `OPENROUTER_API_KEY` | Bearer token for OpenRouter API |
| `services.openrouter.model` | `OPENROUTER_MODEL` | Model slug sent in chat completion requests |
| `services.openrouter.base_url` | `OPENROUTER_BASE_URL` | Defaults to `https://openrouter.ai/api/v1` |
| `services.openrouter.pdf_engine` | `OPENROUTER_PDF_ENGINE` | PDF parsing engine for the `file-parser` plugin; defaults to `cloudflare-ai` |

When `OPENROUTER_API_KEY` or `OPENROUTER_MODEL` is missing or empty, the service MUST NOT use a hardcoded or default model slug.

When `OPENROUTER_API_KEY` is missing or empty, parse requests MUST fail with HTTP 503 and MUST NOT call OpenRouter.

When `OPENROUTER_MODEL` is missing or empty, parse requests MUST fail with HTTP 503 and MUST NOT call OpenRouter.

When `OPENROUTER_PDF_ENGINE` is set to a value outside the allowed set (`cloudflare-ai`, `mistral-ocr`, `native`), parse requests MUST fail with HTTP 503 and MUST NOT call OpenRouter.

#### Scenario: Missing API key blocks parse

- **WHEN** a client submits a valid PDF upload
- **AND** `OPENROUTER_API_KEY` is not configured
- **THEN** the system returns HTTP 503 with `{ "message": "...OPENROUTER_API_KEY..." }`
- **THEN** no outbound HTTP request is made to OpenRouter

#### Scenario: Missing model blocks parse

- **WHEN** a client submits a valid PDF upload
- **AND** `OPENROUTER_MODEL` is not configured
- **THEN** the system returns HTTP 503 with `{ "message": "...OPENROUTER_MODEL..." }`
- **THEN** no outbound HTTP request is made to OpenRouter

#### Scenario: Invalid PDF engine blocks parse

- **WHEN** `OPENROUTER_PDF_ENGINE` is set to an unsupported value
- **AND** a client submits a valid PDF upload
- **THEN** the system returns HTTP 503 with `{ "message": "...OPENROUTER_PDF_ENGINE..." }`
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
- Include a `plugins` array with `{ "id": "file-parser", "pdf": { "engine": "<configured engine>" } }`
- Use a system prompt: `"You extract structured CV data. Return only valid JSON matching the schema. Use null for unknown scalars, [] for empty lists. No markdown fences."`

The parser MUST use Laravel's HTTP client with a 60-second timeout.

OpenRouter request headers MUST include:

- `Authorization: Bearer {OPENROUTER_API_KEY}`
- `HTTP-Referer: {APP_URL}`
- `X-Title: {APP_NAME}`

On OpenRouter HTTP errors or non-JSON model output, the parser MUST return HTTP 422 with `{ "message": "<error>", "raw_content": "<optional model text>" }` without persisting data.

#### Scenario: Successful OpenRouter response

- **WHEN** OpenRouter returns HTTP 200 with a chat completion containing valid JSON in the assistant message
- **THEN** the parser decodes the JSON and maps it to the onboarding-shaped response payload
- **THEN** the API returns HTTP 200 with `{ "data": { ... } }`

#### Scenario: OpenRouter API failure

- **WHEN** OpenRouter returns HTTP 4xx/5xx or times out
- **THEN** the system returns HTTP 422 with a generic error message in `message`
- **THEN** no data is persisted

#### Scenario: Model returns non-JSON

- **WHEN** OpenRouter returns 200 but assistant content is not parseable JSON
- **THEN** the system returns HTTP 422 with `message` indicating extraction failed
- **THEN** `raw_content` contains the unparsed model text

#### Scenario: Model returns JSON wrapped in markdown fences

- **WHEN** OpenRouter returns content like `` ```json\n{...}\n``` ``
- **THEN** the parser strips fences and decodes the JSON successfully

#### Scenario: Parse request includes file-parser plugin

- **WHEN** a valid PDF parse request is sent to OpenRouter
- **THEN** the outbound request body includes a `plugins` array
- **THEN** the plugin entry has `"id": "file-parser"`
- **THEN** the plugin entry includes `"pdf": { "engine": "<value from services.openrouter.pdf_engine>" }`

### Requirement: Extraction prompt uses canonical enum values

The CV parser extraction prompt MUST inject the full set of backed enum string values from `EmploymentType::cases()` and `EducationLevel::cases()` into the LLM instructions.

Allowed `employment_type` values:

`full_time`, `part_time`, `contract`, `freelance`, `internship`, `permanent`, `casual_temporary`

Allowed `education_level` values:

`high_school`, `certificate_1`, `certificate_2`, `certificate_3`, `certificate_4`, `diploma`, `associate_degree`, `bachelor`, `graduate_diploma`, `master`, `doctorate`

The prompt MUST NOT use shorthand or legacy aliases (`associate`, `phd`) as allowed output values for `education_level`.

The prompt MUST include mapping guidance from common CV abbreviations to canonical enum values (BSc/BA/BEng → `bachelor`, MBA → `master`, PhD/DPhil → `doctorate`, HSC/VCE/Year 12 → `high_school`, Cert I–IV → `certificate_1`–`certificate_4`, Diploma → `diploma`, Associate Degree → `associate_degree`, Graduate Diploma → `graduate_diploma`).

The prompt MUST include all extraction rules from the monolith `CvParserService::extractionPrompt()` including:

- Extract only explicit CV information; no guessing except headline/summary rules
- Date fields as zero-padded string months `"01"`–`"12"` and four-digit years
- `currently_working` true only for single most recent ongoing role
- Skills max 30, deduplicated case-insensitively
- `linkedin_url` linkedin.com/in/ only; strip trailing slashes and query params
- Licenses/certifications → skills, not educations
- Experience/education descriptions as single paragraphs without line breaks

The LLM MUST return a flat JSON object with keys: `first_name`, `last_name`, `phone`, `location`, `headline`, `summary`, `experiences`, `educations`, `skills`, `portfolio_url`, `linkedin_url`.

#### Scenario: Education prompt lists all EducationLevel cases

- **WHEN** the extraction prompt is built
- **THEN** every `EducationLevel` backed value appears in the allowed `education_level` values
- **THEN** shorthand values `associate` and `phd` do NOT appear as allowed output values

#### Scenario: Employment prompt lists all EmploymentType cases

- **WHEN** the extraction prompt is built
- **THEN** every `EmploymentType` backed value appears in the allowed `employment_type` values

### Requirement: Onboarding field mapping

Parsed output MUST be returned under `data` as JSON grouped by onboarding step:

**`data.personal_info`:**

| Field | Type | Notes |
|---|---|---|
| `first_name` | string, nullable | Trimmed; empty string → null |
| `last_name` | string, nullable | Trimmed; empty string → null |
| `phone_code` | string, nullable | Dial prefix with leading `+`, e.g. `+61` |
| `phone_number` | string, nullable | National number digits from libphonenumber |
| `location` | string, nullable | |
| `headline` | string, nullable | |
| `summary` | string, nullable | |

The mapper MUST NOT expose a top-level `phone` field in `personal_info`. Raw phone text from the LLM (`phone` key) MUST be split into `phone_code` and `phone_number` using `PhoneNormalizer::splitForForm($phone, 'AU')`. When phone text cannot be parsed, both fields MUST be `null`.

If the LLM returns nested groups (`personal_info`, `experience_education`, `skills_portfolio`) or a flat object, the mapper MUST accept both shapes (matching `OnboardingFieldMapper::map()`).

**`data.experience_education`:**

| Field | Type |
|---|---|
| `experiences` | array of objects |
| `educations` | array of objects |

Each experience object fields: `company_name`, `job_title`, `employment_type`, `currently_working`, `start_month`, `start_year`, `end_month`, `end_year`, `description`, `location` — all nullable strings except `currently_working` (boolean or null passthrough).

Each education object fields: `school_name`, `school_location`, `education_level`, `field_of_study`, `start_month`, `start_year`, `end_month`, `end_year`, `description`.

`employment_type` MUST use `EmploymentType` backed string values when present. Unknown values MUST be mapped to `null`.

`education_level` MUST use `EducationLevel` backed string values when present. Unknown values and shorthand aliases (`phd`, `associate`) MUST be mapped to `null`.

**`data.skills_portfolio`:**

| Field | Type |
|---|---|
| `skills` | array of strings |
| `portfolio_url` | string, nullable |
| `linkedin_url` | string, nullable |

URL normalization:

- Bare domains (`jane.dev`) → `https://jane.dev`
- Protocol-relative (`//example.com`) → `https://example.com`
- Existing `http://` or `https://` preserved
- Empty/null → null

Non-array `experiences`, `educations`, or `skills` inputs MUST map to empty arrays.

#### Scenario: Response includes all step groups

- **WHEN** a PDF is parsed successfully
- **THEN** the JSON response contains `data.personal_info`, `data.experience_education`, and `data.skills_portfolio`
- **THEN** each group contains the fields defined above (nullable when not extracted)

#### Scenario: Phone split into code and number

- **WHEN** the LLM extracts phone text `+61 412 345 678`
- **THEN** `data.personal_info.phone_code` is `+61`
- **THEN** `data.personal_info.phone_number` is `412345678`
- **THEN** `data.personal_info` does not contain a `phone` key

#### Scenario: AU local phone split

- **WHEN** the LLM extracts phone text `0400 000 000`
- **THEN** `data.personal_info.phone_code` is `+61`
- **THEN** `data.personal_info.phone_number` is `400000000`

#### Scenario: Unparseable phone yields null fields

- **WHEN** the LLM extracts phone text that cannot be normalized
- **THEN** `data.personal_info.phone_code` is `null`
- **THEN** `data.personal_info.phone_number` is `null`

#### Scenario: Unknown enum values nulled

- **WHEN** the LLM extracts `employment_type` as `Self-employed`
- **THEN** the mapped experience has `employment_type` `null`

#### Scenario: Shorthand education aliases nulled

- **WHEN** the LLM extracts `education_level` as `phd` or `associate`
- **THEN** the mapped education has `education_level` `null`

#### Scenario: Canonical education values preserved

- **WHEN** the LLM extracts `education_level` as `doctorate` or `associate_degree`
- **THEN** the mapped education retains the canonical value

#### Scenario: Bare URLs normalized

- **WHEN** the LLM extracts `portfolio_url` as `jane.dev`
- **THEN** `data.skills_portfolio.portfolio_url` is `https://jane.dev`

### Requirement: No persistence in v1

The CV parser service MUST NOT write to a database, store uploaded files on disk, or create any persistent records as a direct result of parsing.

Parse results MUST be returned in the HTTP response only.

#### Scenario: Parse does not persist data

- **WHEN** a parse request completes successfully
- **THEN** no files remain on disk from the upload
- **THEN** no database rows are created or updated

### Requirement: CORS for browser clients

When `CV_PARSER_CORS_ORIGINS` is set to a comma-separated list of origins, the service MUST respond to browser preflight requests and include appropriate `Access-Control-Allow-Origin` headers for those origins on `/api/v1/*` routes.

When `CV_PARSER_CORS_ORIGINS` is unset, CORS headers MUST NOT be emitted (server-to-server only).

#### Scenario: Allowed origin receives CORS headers

- **WHEN** `CV_PARSER_CORS_ORIGINS` includes `https://app.example.com`
- **AND** a browser sends an OPTIONS preflight from that origin
- **THEN** the response includes `Access-Control-Allow-Origin: https://app.example.com`

### Requirement: Automated tests with mocked OpenRouter

Feature tests MUST fake OpenRouter HTTP responses using `Http::fake()` and MUST NOT require a live API key in CI.

Tests MUST cover at minimum:

- Successful parse returns onboarding-shaped JSON under `data`
- Missing API key returns HTTP 503 without OpenRouter call
- Missing model returns HTTP 503 without OpenRouter call
- Invalid PDF engine returns HTTP 503 without OpenRouter call
- Invalid file type returns HTTP 422
- Missing Bearer token returns HTTP 401
- Invalid Bearer token returns HTTP 401
- Parse request includes `file-parser` plugin with configured PDF engine
- Status endpoint reflects configuration state

#### Scenario: CI test fakes OpenRouter

- **WHEN** the parse feature test runs
- **THEN** `Http::fake()` intercepts OpenRouter requests
- **THEN** the test passes without network access to openrouter.ai
