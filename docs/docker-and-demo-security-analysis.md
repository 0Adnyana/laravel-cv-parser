# Docker Deployment & Demo Security Analysis

Comparison of this project's Docker self-hosting setup against [Reactive Resume](https://github.com/amruthpillai/reactive-resume) (RR), plus a security assessment of the public CV parser demo. Generated from an architecture review in July 2026, after the `simplify-docker-deploy` change.

## Executive Summary

| Area | Verdict |
|------|---------|
| Docker image & runtime | Solid for a homelab demo app |
| Portainer / env-only deploy UX | Ahead of RR for external-DB setups |
| Image publishing & supply chain | Below RR reference standard |
| Migration on upgrade | Weaker than RR (one-time marker vs every-start) |
| Demo security (public URL) | Not production-hardened without additional controls |

The single-container + external database model is a valid design choice. The main gaps are image publishing maturity, upgrade migration ergonomics, and application-level abuse controls on the cost-bearing `/api/v1/parse` endpoint.

---

## Deployment Philosophy

The two projects target different homelab operators:

```
Reactive Resume (batteries-included)          Laravel CV Parser (bring-your-own-DB)

┌─────────────────────────────────┐          ┌──────────────────────────────┐
│  postgres  redis  seaweedfs     │          │         app (single)         │
│       \      |      /           │          │   FrankenPHP + Octane :8000  │
│        reactive_resume :3000    │          └──────────────┬───────────────┘
└─────────────────────────────────┘                         │
         All in compose.yml                                 ▼
                                                    External DB (Neon, etc.)
                                                    External Redis (optional)
                                                    OpenRouter (required)
```

| Dimension | Reactive Resume | This project |
|-----------|-----------------|--------------|
| Target operator | Clone repo → full stack works | Paste compose in Portainer → set env vars |
| Database | Bundled Postgres in compose | External only (by design) |
| Dependencies | Postgres + Redis + S3 (SeaweedFS) | DB + OpenRouter only |
| First-run friction | Must set `AUTH_SECRET`, `DATABASE_URL`, `APP_URL` | `APP_KEY` auto-generated; DB + OpenRouter required |
| App complexity | Full auth product (accounts, resumes, AI agent) | Public demo + optional Fortify auth |

The `simplify-docker-deploy` change moved this project closer to RR's "set env vars and go" UX while keeping the lighter single-container model.

---

## Docker Image Quality

### What Reactive Resume does well

From RR's `Dockerfile` and `.github/workflows/docker-build.yml`:

- Multi-stage build with Turbo prune (minimal runtime image)
- Non-root user (`USER node`)
- OCI image labels (title, license, docs URL, source)
- `HEALTHCHECK` in Dockerfile and compose
- Dual registry: Docker Hub + GHCR
- Supply chain: SBOM, provenance, Cosign signing
- Tag strategy: `latest` on releases, `nightly` on main, semver tags (`v1.2.3`, `v1.2`, `v1`)
- Multi-arch: amd64 + arm64 via digest merge

### What this project already matches

From `Dockerfile`:

- Multi-stage build (FrankenPHP base → builder → runtime)
- Non-root runtime (`USER www-data`)
- Pre-built frontend assets in image (no Node.js at runtime)
- `APP_DEBUG=false` baked into runtime env
- Multi-arch CI (`linux/amd64`, `linux/arm64`) via GitHub Actions
- GHA build cache
- Container `HEALTHCHECK` probing `GET /up`

### Gaps vs RR production-grade bar

| Gap | Risk / impact | Priority |
|-----|---------------|----------|
| No GHCR mirror | Single point of trust (Docker Hub only) | Medium |
| No image signing / SBOM | Weaker supply-chain assurance | Low–medium |
| No OCI labels | Harder to trace image provenance | Low |
| `:main` tag instead of semver/`latest` | Unclear "what version am I running?" | Medium |
| No compose deployment examples | Operators reinvent nginx/Traefik/Caddy patterns | Medium |

**Verdict:** Structurally good; supply-chain and publishing maturity is below RR—not broken, just not reference-implementation tier.

---

## Compose & Orchestration

### Reactive Resume compose highlights

- `depends_on` with `condition: service_healthy` for Postgres, Redis, SeaweedFS
- Separate Docker networks (`data_network`, `storage_network`)
- Redis not exposed to host by default
- Local upload volume (`./data:/app/data`) when S3 is not configured
- Healthchecks on every service
- One-shot init container for S3 bucket creation

### This project's compose

From `docker-compose.yml`:

**Strengths (ahead of RR for some homelab cases):**

- Portainer-friendly: optional `env_file` with `required: false`, no bind-mounted `.env`
- `host.docker.internal` via `extra_hosts` for same-machine DB — practical and documented
- Single `app-storage` volume for `storage/` (APP_KEY, migration marker, logs)
- Auto-bootstrap in `docker/entrypoint.sh` + `docker/bootstrap.sh`

**Gaps:**

- No compose-level `healthcheck` (only in Dockerfile; some orchestrators prefer compose)
- No `depends_on` — acceptable since DB is external, but no documented "wait for DB" pattern
- No example stacks (nginx, Traefik, Swarm) — RR maintains a dedicated examples page
- Migration strategy differs from RR in a meaningful way (see below)

---

## Startup & Migrations

| | Reactive Resume | This project |
|--|-----------------|--------------|
| When migrations run | Every container start | Once, then marker file skips |
| On schema update | Automatic on `docker compose pull && up` | Manual: `docker compose run --rm app php artisan migrate --force` |
| On migrate failure | Container exits; health check fails | Same |

RR's "migrate every start" is better for operators who pull updates and forget to migrate. This project's marker-based approach is faster on restarts but creates an upgrade footgun.

**Spec drift:** `openspec/specs/docker-self-host/spec.md` still states migrations must not auto-run and that only `storage/logs` is persisted. Code and docs were updated by `simplify-docker-deploy`; the spec has not been archived/updated yet.

---

## Documentation Comparison

| Topic | Reactive Resume | This project |
|-------|-----------------|--------------|
| Dedicated hosting guide | Full docs site + env reference + troubleshooting | `DOCKER.md` — concise and Portainer-first |
| Required secrets checklist | `AUTH_SECRET`, `DATABASE_URL`, `APP_URL` | `APP_URL`, OpenRouter, DB — clear |
| Reverse proxy | Examples page (nginx, Swarm, etc.) | Caddy example + 300s timeout for parse |
| Update procedure | "Backup DB first", check migration logs | `pull && up`, manual migrate note |
| Security warnings | Explicit SSRF/OAuth unsafe flags | Minimal demo-security guidance |

**Verdict:** Docker docs are good and Portainer-first—clearer for external-DB setups than RR's full-stack guide. Missing: deployment pattern library and an explicit demo threat-model section.

---

## Demo Security Assessment

The demo is intentionally public by specification (`openspec/specs/demo-app-scaffold/spec.md`). Parser routes live in the web stack (session + CSRF), not behind authentication:

- `GET /demo` — public demo page
- `GET /api/v1/status` — public
- `POST /api/v1/parse` — public, CSRF-protected

### Existing controls

- CSRF on parse (blocks simple cross-site POST from other origins)
- PDF-only validation, 5 MB upload cap
- No persistence of uploads or parse results
- OpenRouter calls blocked when key/model missing (HTTP 503)
- Fortify rate limits on login, 2FA, and passkeys — **not** on parse
- `TRUSTED_PROXIES` documented for reverse-proxy deployments
- `APP_DEBUG=false` in production Docker image

### What Reactive Resume does differently

RR treats production as an authenticated product with explicit safety valves:

- `AUTH_SECRET` required (no auto-generation)
- `FLAG_DISABLE_SIGNUPS`, `FLAG_DISABLE_EMAIL_AUTH`
- `FLAG_DISABLE_API_RATE_LIMIT` — rate limiting on by default in production
- Warnings on unsafe flags (`FLAG_ALLOW_UNSAFE_AI_BASE_URL`, OAuth redirect URI)
- Redis kept internal; AI provider secrets use `ENCRYPTION_SECRET`

### Primary abuse vectors (public deployment)

```
Internet ──▶ /demo (public)
                │
                └── POST /api/v1/parse ──▶ OpenRouter (billable)
                     ▲
                     ├── No app-level rate limit on parse
                     ├── CSRF does not stop direct scripted abuse with a session
                     └── Up to 240s per request (resource + cost pressure)
```

If deployed at a public URL with a real `OPENROUTER_API_KEY`, anyone who can obtain a session (load `/demo`) can consume API credits. CSRF alone is insufficient for a public, cost-bearing endpoint.

### Threat model by deployment context

| Context | Risk level | Notes |
|---------|------------|-------|
| Local dev (`localhost`) | Low | Acceptable as-is |
| Tailscale / private network only | Low–medium | CSRF + network boundary may suffice |
| Public HTTPS with OpenRouter key | High | Needs rate limits and/or access controls |

---

## Scorecard

### Docker deployment (vs Reactive Resume)

| Area | Status |
|------|--------|
| Runnable single image | Good |
| Homelab / Portainer UX | Better than RR for external-DB setups |
| Multi-arch publishing | Good |
| Health checks | Good (Dockerfile; compose-level would improve parity) |
| Image supply chain | Below RR |
| Registry / tagging | Below RR (`:main` vs semver/`latest`/GHCR) |
| Compose orchestration | N/A by design (external DB) |
| Migration on upgrade | Weaker than RR |
| Deployment examples | Below RR |

### Demo security

| Area | Status |
|------|--------|
| Auth boundary | Intentionally open — high risk if public |
| Rate limiting on parse | Missing |
| Cost / abuse controls | Missing |
| Input validation | Reasonable |
| Data retention | Good (no persistence) |
| Proxy / TLS guidance | Good |
| Feature flags to lock down demo | Missing |

---

## Recommended Improvements

Ordered by impact for a **public Docker demo**. None of these are implemented yet; they are candidates for future changes.

### High impact

1. **Rate limit `POST /api/v1/parse`** — per IP (and optionally per session), e.g. 5–10 requests per minute.
2. **Optional `DEMO_ENABLED=false`** — disable `/demo` and parse routes in production; require authentication for parse instead.
3. **Document threat model in `DOCKER.md`** — e.g. "Do not expose publicly without rate limits; OpenRouter key represents real spend."

### Medium impact

4. **Migrate on every start** (RR pattern) or migrate when image version changes — safer upgrades than a permanent marker file.
5. **Tighten `TRUSTED_PROXIES`** — document proxy CIDR instead of `*` for production.
6. **Concurrency cap on parse** — limit parallel long-running parse requests under Octane.

### Lower impact (Docker publishing parity)

7. Publish to GHCR alongside Docker Hub.
8. Add semver/`latest` tags on release.
9. Add OCI labels to the Dockerfile.
10. Add compose-level `healthcheck` and example reverse-proxy stacks under `docs/`.

### Optional access patterns

- **`DEMO_ACCESS_TOKEN`** — query param or header for semi-public demos.
- **Separate compose overlays** — `docker-compose.demo.yml` vs production with auth required.

---

## Design Tension

Two goals coexist in the same container:

1. **Demo mode** — public, unauthenticated, same-origin CSRF-protected parse (per OpenSpec).
2. **Production self-host** — Docker docs describe deploying behind Caddy/Traefik with real secrets.

These conflict when the same image is exposed on a public domain with a paid OpenRouter key. Resolving that tension (env flag, separate overlays, or auth-gated parse in production) is a product decision, not only a Docker one.

---

## References

- This project: `Dockerfile`, `docker-compose.yml`, `docker/entrypoint.sh`, `docker/bootstrap.sh`, `DOCKER.md`
- Reactive Resume: [Self-Hosting with Docker](https://docs.rxresu.me/self-hosting/docker), [compose.yml](https://github.com/amruthpillai/reactive-resume/blob/main/compose.yml), [docker-build.yml](https://github.com/amruthpillai/reactive-resume/blob/main/.github/workflows/docker-build.yml)
- OpenSpec: `openspec/changes/simplify-docker-deploy/`, `openspec/specs/docker-self-host/spec.md`, `openspec/specs/demo-app-scaffold/spec.md`
