## ADDED Requirements

### Requirement: Client configuration

Frontend or backend clients MUST configure:

| Variable | Purpose |
|---|---|
| `CV_PARSER_BASE_URL` | Base URL of the standalone service, e.g. `https://cv-parser.example.com` (no trailing slash) |
| `CV_PARSER_SERVICE_TOKEN` | Same value as the service's `CV_PARSER_API_KEY` |

Clients MUST NOT hardcode OpenRouter credentials — those live on the standalone service only.

#### Scenario: Missing base URL prevents calls

- **WHEN** a client attempts to parse without `CV_PARSER_BASE_URL` configured
- **THEN** the client surfaces a configuration error without making a network request

### Requirement: Check parser availability before upload

Clients SHOULD call `GET {CV_PARSER_BASE_URL}/api/v1/status` before enabling parse UI.

Request headers:

```
Authorization: Bearer {CV_PARSER_SERVICE_TOKEN}
Accept: application/json
```

Response handling:

| `available` | Client behavior |
|---|---|
| `true` | Enable parse/upload controls |
| `false` | Show `warning` text; disable parse or show degraded UX |

#### Scenario: Status check enables parse button

- **WHEN** status returns `{ "available": true, "warning": null }`
- **THEN** the client enables the PDF upload and parse action

#### Scenario: Status warning shown to user

- **WHEN** status returns `{ "available": false, "warning": "CV parsing is unavailable until OPENROUTER_MODEL is set." }`
- **THEN** the client displays the warning message
- **THEN** the parse action is disabled

### Requirement: Parse request format

Clients MUST submit parse requests as:

```
POST {CV_PARSER_BASE_URL}/api/v1/parse
Authorization: Bearer {CV_PARSER_SERVICE_TOKEN}
Accept: application/json
Content-Type: multipart/form-data
```

Form fields:

| Field | Type | Required | Notes |
|---|---|---|---|
| `cv` | File | Yes | PDF only, max 5 MB |

Clients MUST NOT JSON-encode the file; use `FormData`.

#### Scenario: Successful multipart upload

- **WHEN** the client appends a PDF file to `FormData` under key `cv`
- **AND** sends POST with Bearer token
- **THEN** the server returns HTTP 200 with parsed JSON

### Requirement: Parse response typing

On HTTP 200, the response body shape is:

```typescript
type ParseCvResponse = {
  data: {
    personal_info: {
      first_name: string | null;
      last_name: string | null;
      phone_code: string | null;
      phone_number: string | null;
      location: string | null;
      headline: string | null;
      summary: string | null;
    };
    experience_education: {
      experiences: Array<{
        company_name: string | null;
        job_title: string | null;
        employment_type: string | null;
        currently_working: boolean | null;
        start_month: string | null;
        start_year: string | null;
        end_month: string | null;
        end_year: string | null;
        description: string | null;
        location: string | null;
      }>;
      educations: Array<{
        school_name: string | null;
        school_location: string | null;
        education_level: string | null;
        field_of_study: string | null;
        start_month: string | null;
        start_year: string | null;
        end_month: string | null;
        end_year: string | null;
        description: string | null;
      }>;
    };
    skills_portfolio: {
      skills: string[];
      portfolio_url: string | null;
      linkedin_url: string | null;
    };
  };
};
```

This shape is identical to the monolith's `CvParserService::parse()` return value.

#### Scenario: Client reads nested groups

- **WHEN** parse succeeds
- **THEN** the client accesses `response.data.personal_info`, `response.data.experience_education`, and `response.data.skills_portfolio`

### Requirement: Error response handling

Clients MUST handle non-200 responses:

| HTTP status | Body shape | Client action |
|---|---|---|
| 401 | `{ "message": "Unauthorized." }` | Log config error; do not retry without fixing token |
| 422 (validation) | `{ "message": "...", "errors": { "cv": ["..."] } }` | Show field-level error on file input |
| 422 (extraction) | `{ "message": "...", "raw_content"?: string }` | Show error message; optionally display `raw_content` in debug UI |
| 503 | `{ "message": "..." }` | Show configuration/service unavailable message |
| Network timeout | — | Show non-blocking warning; allow user to continue without pre-fill |

Clients MUST treat parse failures as non-fatal in onboarding flows — the uploaded file may still be stored by the main app even when parsing fails.

#### Scenario: Validation error on non-PDF

- **WHEN** the user selects a PNG file and submits
- **THEN** the client receives HTTP 422 with `errors.cv`
- **THEN** the client displays the validation message on the file field

#### Scenario: Extraction failure shows message

- **WHEN** OpenRouter returns unparseable output
- **THEN** the client receives HTTP 422 with `message` describing the failure
- **THEN** the client displays the error to the user

#### Scenario: Configuration error on 503

- **WHEN** the service returns HTTP 503
- **THEN** the client displays the `message` indicating parsing is unavailable

### Requirement: Loading and UX states

Clients MUST implement these UI states during parse:

1. **Idle** — file selected, submit enabled (when status `available` is true)
2. **Processing** — disable submit, show spinner/label (e.g. "Parsing…"); parsing may take up to 60 seconds
3. **Success** — display or consume parsed JSON; reset file input if appropriate
4. **Error** — show error message; re-enable submit

Clients SHOULD use `preserveScroll` or equivalent when re-rendering after parse so the user stays oriented.

#### Scenario: Processing state during parse

- **WHEN** the user submits a PDF for parsing
- **THEN** the submit control is disabled until the response returns
- **THEN** a loading indicator is visible

#### Scenario: Success clears file input

- **WHEN** parse succeeds on a debug/test page
- **THEN** the file input is cleared
- **THEN** parsed JSON is displayed

### Requirement: Server-side proxy pattern (recommended)

When the frontend runs in a browser, clients SHOULD NOT expose `CV_PARSER_SERVICE_TOKEN` in client-side JavaScript.

Instead, the main Laravel app SHOULD proxy parse requests:

1. Browser POSTs PDF to main app route (e.g. `POST /internal/cv-parser/parse`) with session auth
2. Main app forwards multipart to standalone `POST /api/v1/parse` with Bearer token
3. Main app returns JSON to browser

This matches the security model of the current Inertia staff test page (auth on main app, OpenRouter key on server).

#### Scenario: Browser never sees service token

- **WHEN** a staff user parses via the main app UI
- **THEN** the browser request contains only session cookies
- **THEN** the service token is sent server-to-server only

### Requirement: Direct browser call pattern (optional)

When calling the standalone API directly from a browser (e.g. standalone debug UI on the same origin as the parser service), clients MUST:

- Include `Authorization: Bearer {token}` — only acceptable when the UI is staff-only on the parser origin
- Set `accept="application/pdf"` on file inputs
- Handle CORS: standalone service must list the frontend origin in `CV_PARSER_CORS_ORIGINS`

#### Scenario: Cross-origin parse with CORS

- **WHEN** a browser at `https://app.example.com` calls `https://cv-parser.example.com/api/v1/parse`
- **AND** `CV_PARSER_CORS_ORIGINS` includes `https://app.example.com`
- **THEN** the browser receives a successful CORS response

### Requirement: Fetch API reference implementation

Clients MAY implement parse using the Fetch API:

```typescript
async function parseCv(file: File, baseUrl: string, token: string): Promise<ParseCvResponse> {
  const formData = new FormData();
  formData.append('cv', file);

  const response = await fetch(`${baseUrl}/api/v1/parse`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
    },
    body: formData,
  });

  const body = await response.json();

  if (!response.ok) {
    throw new CvParserError(response.status, body.message, body.errors, body.raw_content);
  }

  return body as ParseCvResponse;
}
```

Status check:

```typescript
async function getCvParserStatus(baseUrl: string, token: string) {
  const response = await fetch(`${baseUrl}/api/v1/status`, {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
    },
  });
  return response.json() as Promise<{ available: boolean; warning: string | null }>;
}
```

#### Scenario: Fetch client throws on 422

- **WHEN** `parseCv` receives HTTP 422
- **THEN** it throws an error carrying `message` and optional `errors` or `raw_content`

### Requirement: React form integration

React clients using controlled file inputs MUST:

- Store selected file in component state (`File | null`)
- Disable submit when `processing || !file`
- Use `onChange` on `<input type="file" accept="application/pdf">` to capture the file
- NOT set `Content-Type` manually on FormData requests (browser sets boundary)

For Inertia apps, prefer a server proxy route rather than `useForm().post()` to an external domain — Inertia expects same-origin responses. Use `fetch` to the proxy route, or a dedicated API route returning JSON.

#### Scenario: React file input accepts PDF only

- **WHEN** the file picker opens
- **THEN** only PDF files are selectable via `accept="application/pdf"`

### Requirement: Mapping parse results to onboarding pre-fill

When the main app consumes parse results for job seeker onboarding, it MUST apply the same session boundary rules as the monolith:

- Strip `first_name` and `last_name` from `personal_info` before writing to `onboarding.cv_prefill` session
- Replace (not merge) existing session pre-fill on re-scan
- Clear pre-fill when user skips CV scanning

The standalone API response includes names; stripping is the **caller's** responsibility, not the parser service's.

#### Scenario: Onboarding strips names from API response

- **WHEN** the main app receives `data.personal_info.first_name` and `last_name` from the standalone API
- **AND** writes onboarding session pre-fill
- **THEN** session `personal_info` omits `first_name` and `last_name`
