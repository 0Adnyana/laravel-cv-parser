## Why

The CV parser currently lives inside the Genuine Solutions Laravel monolith (`CvParserService`, OpenRouter integration, and onboarding field mapping). We need to deploy it as a separate service so it can be reused, scaled, and operated independently while preserving **identical** extraction behavior and response shape. This change produces a 1:1 specification for the standalone backend API and the frontend client contract used to call it.

## What Changes

- Add a **standalone CV parser API spec** that mirrors the existing implementation byte-for-byte in behavior: OpenRouter multimodal extraction, PDF validation, enum-aware prompts, onboarding-shaped JSON mapping, and error semantics.
- Add a **frontend integration spec** documenting how any client (React/Inertia, fetch, axios) calls the standalone API: request format, auth, loading/error handling, and response consumption.
- Document environment configuration, allowed enum values, phone normalization rules, and HTTP status codes so the separate app can be built without reading monolith source code.
- No changes to the monolith implementation in this change — this is a specification-only artifact for the new deployment target.

## Capabilities

### New Capabilities

- `standalone-cv-parser-api`: REST API contract for the standalone CV parser service — endpoints, validation, OpenRouter pipeline, response schema, error codes, and configuration.
- `cv-parser-frontend-client`: Client-side integration guide — how to upload a PDF, handle async parsing, display results, and map errors from the standalone API.

### Modified Capabilities

<!-- None — existing `openspec/specs/cv-parser/spec.md` describes in-app behavior; this change documents the extracted service without altering monolith requirements. -->

## Impact

- **New deployment target**: A separate Laravel (or compatible) app hosting the CV parser as a stateless HTTP API.
- **Consumers**: Genuine Solutions main app (future migration), internal staff tools, and any other frontend that needs CV extraction.
- **External dependencies**: OpenRouter API (`OPENROUTER_API_KEY`, `OPENROUTER_MODEL`, optional `OPENROUTER_PDF_ENGINE`), `libphonenumber` (via `propaganistas/laravel-phone`) for phone splitting.
- **No database or persistence** in v1 — parse results are returned in the HTTP response only, matching current behavior.
