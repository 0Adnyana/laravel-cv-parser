# demo-app-scaffold Specification

## Purpose
TBD - created by archiving change project-scaffolding. Update Purpose after archive.
## Requirements
### Requirement: Public demo entry point

The application MUST expose a CV parser demo page without requiring authentication.

The demo page MUST be reachable at `GET /demo` without login, session, or API token.

The application root (`GET /`) MUST redirect to `/demo` or render the demo page directly.

#### Scenario: Unauthenticated visitor opens demo

- **WHEN** a visitor navigates to `/demo` without cookies or credentials
- **THEN** the demo page loads successfully
- **THEN** no login redirect occurs

#### Scenario: Root redirects to demo

- **WHEN** a visitor navigates to `/`
- **THEN** the browser ends on the CV parser demo page

### Requirement: Demo environment configuration

The application MUST document required environment variables in `.env.example`:

| Variable | Required | Purpose |
|---|---|---|
| `OPENROUTER_API_KEY` | Yes (for parse) | OpenRouter API bearer token |
| `OPENROUTER_MODEL` | Yes (for parse) | Model slug for chat completions |
| `OPENROUTER_BASE_URL` | No | Defaults to `https://openrouter.ai/api/v1` |
| `OPENROUTER_PDF_ENGINE` | No | Defaults to `cloudflare-ai` |
| `APP_URL` | Yes | Sent as OpenRouter `HTTP-Referer` |
| `APP_NAME` | Yes | Sent as OpenRouter `X-Title` |

The application MUST NOT require `CV_PARSER_API_KEY`, `CV_PARSER_BASE_URL`, or other service-to-service auth variables for the demo.

#### Scenario: Env example lists OpenRouter vars

- **WHEN** a developer copies `.env.example` to `.env`
- **THEN** OpenRouter variables are present with comments explaining their purpose

### Requirement: No RBAC or multi-role access

The demo MUST NOT implement role-based access control, staff permissions, or job-seeker-specific flows.

There MUST NOT be more than one access tier for the demo — all visitors have the same capabilities (upload and view results).

Fortify authentication routes MAY exist in the codebase but MUST NOT gate the demo page or parser API routes.

#### Scenario: Demo does not check user role

- **WHEN** a visitor uses the demo page
- **THEN** the system does not evaluate user roles or permissions

### Requirement: Parser API routes are public

Routes under `/api/v1/*` MUST NOT require session authentication or Bearer tokens for the demo.

#### Scenario: Parse without Authorization header

- **WHEN** a client POSTs a valid PDF to `/api/v1/parse` without an `Authorization` header
- **THEN** the request is processed normally when OpenRouter is configured

### Requirement: Same-origin demo architecture

The demo UI MUST call parser API routes on the same application origin (no cross-origin requests in v1).

The application MUST NOT require CORS configuration for the demo flow.

#### Scenario: Demo fetch uses relative API path

- **WHEN** the demo page submits a PDF for parsing
- **THEN** the client requests `/api/v1/parse` on the same host

### Requirement: No persistence for demo uploads

The demo MUST NOT persist uploaded PDFs or parse results to disk or database as part of the parse flow.

#### Scenario: Upload is not stored after response

- **WHEN** a parse request completes
- **THEN** no uploaded file remains on disk from that request
- **THEN** no database records are created

