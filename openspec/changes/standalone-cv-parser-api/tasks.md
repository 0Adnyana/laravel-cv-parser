## 1. Standalone Laravel App Scaffold

- [ ] 1.1 Create new Laravel 12 API-only project for the CV parser service
- [ ] 1.2 Add dependencies: `propaganistas/laravel-phone` (for `PhoneNormalizer`)
- [ ] 1.3 Configure environment variables: `CV_PARSER_API_KEY`, `OPENROUTER_*`, `CV_PARSER_CORS_ORIGINS`, `APP_URL`, `APP_NAME`
- [ ] 1.4 Register API routes under `/api/v1` prefix in `bootstrap/app.php`

## 2. Port Core Parser Services

- [ ] 2.1 Copy `CvParserService`, `OpenRouterClient`, `OnboardingFieldMapper`, and exception classes from monolith
- [ ] 2.2 Copy `EmploymentType` and `EducationLevel` enums
- [ ] 2.3 Copy `PhoneNormalizer` support class
- [ ] 2.4 Add `services.openrouter` config block matching monolith `config/services.php`
- [ ] 2.5 Verify extraction prompt byte-matches monolith (port unit tests from `CvParserServiceTest`)

## 3. HTTP API Layer

- [ ] 3.1 Create `VerifyCvParserApiKey` middleware checking `Authorization: Bearer {CV_PARSER_API_KEY}`
- [ ] 3.2 Create `ParseCvRequest` with rules: `cv` required, file, mimes:pdf, max:5120
- [ ] 3.3 Create `StatusController` returning `{ available, warning }` via `getConfigurationWarning()`
- [ ] 3.4 Create `ParseCvController` calling `CvParserService::parse()` and returning JSON `{ data: ... }`
- [ ] 3.5 Map exceptions: `CvParserConfigurationException` → 503, `CvParserExtractionException` → 422 with optional `raw_content`, validation → 422 with `errors`
- [ ] 3.6 Configure CORS middleware for `CV_PARSER_CORS_ORIGINS`

## 4. Automated Tests

- [ ] 4.1 Port feature tests from `tests/Feature/CvParserTest.php` adapted for JSON API responses
- [ ] 4.2 Port unit tests for `OnboardingFieldMapper` and extraction prompt
- [ ] 4.3 Add tests for 401 unauthorized (missing/invalid Bearer token)
- [ ] 4.4 Add test for `GET /api/v1/status` configuration states
- [ ] 4.5 Verify all tests pass with `Http::fake()` (no live OpenRouter calls)

## 5. Deployment & Verification

- [ ] 5.1 Deploy standalone service with OpenRouter env vars configured
- [ ] 5.2 Smoke test: `GET /api/v1/status` returns `{ "available": true }`
- [ ] 5.3 Smoke test: upload sample PDF via `POST /api/v1/parse`, verify onboarding-shaped JSON
- [ ] 5.4 Diff output against monolith staff test page for same PDF to confirm 1:1 behavior

## 6. Frontend Client Integration (Consumer Apps)

- [ ] 6.1 Add `CV_PARSER_BASE_URL` and `CV_PARSER_SERVICE_TOKEN` to main app `.env`
- [ ] 6.2 Create server-side proxy route in main app (recommended) forwarding multipart to standalone API
- [ ] 6.3 Implement `parseCv()` and `getCvParserStatus()` client helpers per frontend spec
- [ ] 6.4 Add TypeScript types for `ParseCvResponse` matching API schema
- [ ] 6.5 Wire loading, error, and success states in consuming UI (or update existing staff test page to use proxy)

## 7. Documentation

- [ ] 7.1 Document API endpoints, auth, and env vars in standalone app README
- [ ] 7.2 Document proxy vs direct-call patterns for frontend teams
- [ ] 7.3 Archive OpenSpec change and sync specs to `openspec/specs/` when implementation is complete
