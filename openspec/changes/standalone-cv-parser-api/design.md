## Context

The Genuine Solutions monolith implements CV parsing in `app/Services/CvParser/`:

| Component | Role |
|---|---|
| `CvParserService` | Orchestrates OpenRouter multimodal extraction, builds prompts, decodes JSON |
| `OpenRouterClient` | HTTP client for `POST /chat/completions` with 60s timeout |
| `OnboardingFieldMapper` | Maps flat LLM JSON → onboarding-shaped `{ personal_info, experience_education, skills_portfolio }` |
| `PhoneNormalizer` | Splits raw phone text into `phone_code` + `phone_number` (default country `AU`) |

The staff test page (`CvParserTestController` + `tools/cv-parser.tsx`) and job seeker onboarding (`JobSeekerOnboardingController`) both call `CvParserService::parse()`. v1 is stateless — no DB writes, no file persistence on the parser side.

This design describes how to extract that pipeline into a **standalone Laravel API** with identical behavior, consumed by any frontend via HTTP.

## Goals / Non-Goals

**Goals:**

- Standalone service returns **byte-for-byte equivalent** mapped JSON to the monolith's `CvParserService::parse()` output for the same PDF and OpenRouter config.
- Document a stable REST API (`POST /api/v1/parse`, `GET /api/v1/status`) with JSON responses (not Inertia).
- Document frontend client integration: multipart upload, auth header, error handling, and result typing.
- Preserve all extraction prompt rules, enum values, phone normalization, URL normalization, and OpenRouter plugin configuration from the monolith.
- Stateless v1 — no database, no uploaded file storage.

**Non-Goals:**

- Migrating the monolith to call the standalone service (future change).
- Onboarding session pre-fill logic (`onboarding.cv_prefill`, name stripping) — that remains in the main app.
- Staff Inertia test page UI — the standalone app MAY ship its own debug UI, but it is not required.
- Image resume parsing (JPG/PNG) — PDF only, matching current parser.
- Rate limiting, queueing, or async job processing in v1.

## Decisions

### 1. Framework: Laravel 12 API-only app

**Decision:** Build the standalone service as a minimal Laravel 12 API application.

**Rationale:** The reference implementation is Laravel. Copying `CvParserService`, `OpenRouterClient`, `OnboardingFieldMapper`, enums, and `PhoneNormalizer` verbatim minimizes behavioral drift. PHPUnit + `Http::fake()` tests port directly.

**Alternatives considered:**
- Node/Fastify microservice — would require reimplementing prompt, mapper, and phone logic; high drift risk.
- Serverless function — 60s OpenRouter timeout and large PDF base64 payloads make cold-start + payload limits awkward.

### 2. API surface: JSON REST, not Inertia

**Decision:**

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/v1/status` | Returns `{ "available": bool, "warning": string\|null }` — mirrors `getConfigurationWarning()` |
| `POST` | `/api/v1/parse` | Accepts multipart PDF, returns mapped JSON |

**Rationale:** External frontends and the main app need a language-agnostic HTTP contract. The monolith's Inertia responses become JSON equivalents:

| Monolith (Inertia) | Standalone (JSON) |
|---|---|
| HTTP 200 + `result` prop | HTTP 200 + `{ "data": { ... } }` |
| HTTP 503 + `error` prop | HTTP 503 + `{ "message": "..." }` |
| HTTP 422 + `error` + optional `rawContent` | HTTP 422 + `{ "message": "...", "raw_content": "..." }` |
| HTTP 422 validation redirect | HTTP 422 + `{ "message": "...", "errors": { "cv": ["..."] } }` |

### 3. Authentication: shared secret Bearer token

**Decision:** All `/api/v1/*` routes require `Authorization: Bearer {CV_PARSER_API_KEY}`.

**Rationale:** Service-to-service calls from the main app or internal tools. Simple to configure; no user session coupling.

**Alternatives considered:**
- No auth (internal network only) — rejected; standalone deployment may be on public internet.
- OAuth/JWT — overkill for v1 internal tool.

Env var: `CV_PARSER_API_KEY` on the service; clients store the same value as `CV_PARSER_SERVICE_TOKEN`.

### 4. Request field name: `cv`

**Decision:** Multipart form field MUST be named `cv` (matches `ParseCvRequest` and staff test page).

Validation: `required`, `file`, `mimes:pdf`, `max:5120` (5 MB).

### 5. Response schema: onboarding-shaped groups

**Decision:** Success body:

```json
{
  "data": {
    "personal_info": { ... },
    "experience_education": { ... },
    "skills_portfolio": { ... }
  }
}
```

Field definitions, enum values, and mapper behavior are specified in `specs/standalone-cv-parser-api/spec.md` and MUST match `OnboardingFieldMapper` exactly.

### 6. OpenRouter pipeline: copy verbatim

**Decision:** Port these without modification:

- System prompt: `"You extract structured CV data. Return only valid JSON matching the schema. Use null for unknown scalars, [] for empty lists. No markdown fences."`
- Full extraction prompt from `CvParserService::extractionPrompt()` including `{{employment_types}}` and `{{education_levels}}` placeholders.
- PDF attachment as `data:application/pdf;base64,{payload}` in user message `file` content part.
- `plugins: [{ "id": "file-parser", "pdf": { "engine": "<configured>" } }]`
- Allowed engines: `cloudflare-ai`, `mistral-ocr`, `native` (default `cloudflare-ai`)
- JSON decode with optional markdown fence stripping
- OpenRouter headers: `Authorization`, `HTTP-Referer`, `X-Title`

Config env vars (same names as monolith):

| Env var | Purpose |
|---|---|
| `OPENROUTER_API_KEY` | Bearer token |
| `OPENROUTER_MODEL` | Model slug (required, no default) |
| `OPENROUTER_BASE_URL` | Default `https://openrouter.ai/api/v1` |
| `OPENROUTER_PDF_ENGINE` | Default `cloudflare-ai` |

### 7. Phone normalization default country

**Decision:** `PhoneNormalizer::splitForForm($phone, 'AU')` — hardcoded `AU` default country, matching monolith.

Optional future: accept `?default_country=AU` query param; **not in v1** to preserve 1:1 behavior.

### 8. CORS

**Decision:** Enable CORS for configured frontend origins (`CV_PARSER_CORS_ORIGINS` comma-separated) when browsers call the API directly.

**Rationale:** If the main app's React frontend calls the parser cross-origin, CORS is required. Server-side proxy calls from Laravel do not need CORS.

### 9. Code port strategy

**Decision:** Copy these files/classes into the standalone app with namespace adjustments only:

```
app/Services/CvParser/CvParserService.php
app/Services/CvParser/OpenRouterClient.php
app/Services/CvParser/OnboardingFieldMapper.php
app/Services/CvParser/CvParserExtractionException.php
app/Services/CvParser/CvParserConfigurationException.php
app/Enums/EmploymentType.php
app/Enums/EducationLevel.php
app/Support/PhoneNormalizer.php
```

Add thin HTTP layer:

```
app/Http/Controllers/Api/V1/ParseCvController.php
app/Http/Controllers/Api/V1/StatusController.php
app/Http/Requests/ParseCvRequest.php
app/Http/Middleware/VerifyCvParserApiKey.php
```

Port tests from `tests/Feature/CvParserTest.php`, `tests/Unit/Services/CvParser/*`.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Behavioral drift between monolith and standalone | Port code verbatim; share extraction prompt tests; compare JSON outputs against golden fixtures |
| OpenRouter latency (up to 60s) | Keep 60s HTTP timeout; frontend shows loading state; consider proxy timeout alignment in main app |
| Large PDF base64 payloads | 5 MB upload limit matches monolith; monitor memory on small instances |
| API key leakage in browser | Prefer server-side proxy from main app; if browser-direct, use short-lived tokens (future) |
| Phone parsing AU-centric | Document default country; matches current monolith behavior |

## Migration Plan

1. Deploy standalone service with env vars configured.
2. Verify `GET /api/v1/status` returns `{ "available": true }`.
3. Run ported PHPUnit suite against standalone app.
4. Manual smoke test: upload same PDF to monolith staff page and standalone API; diff JSON outputs.
5. (Future) Update monolith to call standalone API via HTTP client instead of in-process `CvParserService`.

Rollback: monolith continues using in-process parser until migration is explicitly switched.

## Open Questions

- **Hosting:** Fly.io, Railway, or internal VPS — does not affect API contract.
- **Main app proxy vs direct browser calls:** Recommend Laravel HTTP proxy in main app to avoid exposing API key in frontend. Client spec documents both patterns.
- **Observability:** Structured logging of OpenRouter latency and error rates — recommended but not blocking v1.
