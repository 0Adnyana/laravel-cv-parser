# cv-parser-demo-ui Specification

## Purpose
TBD - created by archiving change project-scaffolding. Update Purpose after archive.
## Requirements
### Requirement: Demo page route and layout

The application MUST render an Inertia page at `GET /demo` titled for CV parsing (e.g. "CV Parser Demo").

The page MUST use the existing application layout components where appropriate but MUST NOT require authenticated user props.

#### Scenario: Demo page renders without auth user

- **WHEN** an unauthenticated visitor loads `/demo`
- **THEN** the Inertia page renders with upload controls visible or disabled based on parser status

### Requirement: Parser availability check on load

The demo page MUST call `GET /api/v1/status` on mount.

When `available` is `false`, the page MUST display the `warning` message and MUST disable the upload/submit control.

When `available` is `true`, the page MUST enable PDF upload and parse submission.

#### Scenario: Misconfigured OpenRouter disables upload

- **WHEN** status returns `{ "available": false, "warning": "CV parsing is unavailable until OPENROUTER_MODEL is set." }`
- **THEN** the demo page shows the warning text
- **THEN** the parse button is disabled

#### Scenario: Configured parser enables upload

- **WHEN** status returns `{ "available": true, "warning": null }`
- **THEN** the demo page enables file selection and parse submission

### Requirement: PDF file input

The demo page MUST provide a file input accepting PDF only via `accept="application/pdf"`.

The submit control MUST be disabled when no file is selected or while a parse is in progress.

#### Scenario: Submit disabled without file

- **WHEN** no PDF file is selected
- **THEN** the parse/submit button is disabled

#### Scenario: File picker restricts to PDF

- **WHEN** the file picker opens
- **THEN** only PDF files are selectable

### Requirement: Parse submission via same-origin fetch

The demo page MUST submit the selected PDF using `fetch` to `POST /api/v1/parse` with:

- `Content-Type`: omitted (browser sets multipart boundary)
- `Accept: application/json`
- `X-XSRF-TOKEN`: decoded value from the `XSRF-TOKEN` cookie set by Laravel
- Body: `FormData` with field name `cv`

The client MUST NOT set a manual multipart `Content-Type` header.

The client MUST send the CSRF token on every parse POST so the web middleware accepts the request.

#### Scenario: Successful parse displays result

- **WHEN** the user selects a valid PDF and submits
- **AND** the API returns HTTP 200
- **THEN** the demo page displays the parsed JSON from `response.data`

#### Scenario: CSRF token included on parse POST

- **WHEN** the demo page submits a PDF for parsing
- **THEN** the outbound `fetch` request includes an `X-XSRF-TOKEN` header derived from the session cookie

### Requirement: Loading and error UX states

The demo page MUST implement these states:

1. **Idle** — file selected, submit enabled (when status available)
2. **Processing** — submit disabled, visible loading indicator; parsing may take up to 60 seconds
3. **Success** — parsed JSON displayed; file input cleared
4. **Error** — error message shown; submit re-enabled

#### Scenario: Processing state during parse

- **WHEN** the user submits a PDF
- **THEN** the submit control is disabled until the response returns
- **THEN** a loading indicator is visible

#### Scenario: Validation error from API

- **WHEN** the user submits a non-PDF file and the API returns HTTP 422 with `errors.cv`
- **THEN** the demo page displays the validation message
- **THEN** the user can select another file and retry

#### Scenario: Extraction error from API

- **WHEN** the API returns HTTP 422 with `message` (extraction failure)
- **THEN** the demo page displays the error message

#### Scenario: Configuration error from API

- **WHEN** the API returns HTTP 503
- **THEN** the demo page displays the configuration error message

### Requirement: Parsed result display

On successful parse, the demo page MUST display the full parsed payload in a human-readable format.

For v1, the page MUST pretty-print the JSON (e.g. formatted `<pre>` or syntax-highlighted block) showing `personal_info`, `experience_education`, and `skills_portfolio`.

#### Scenario: Success shows formatted JSON

- **WHEN** parse succeeds with structured data
- **THEN** the user can read all three response groups in the result panel

### Requirement: Demo page TypeScript types

The frontend MUST define TypeScript types for the parse response matching the API schema (`ParseCvResponse` with nested `data` groups).

#### Scenario: Typed response consumption

- **WHEN** the demo page handles a successful parse response
- **THEN** TypeScript types cover `data.personal_info`, `data.experience_education`, and `data.skills_portfolio`

