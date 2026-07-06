# Laravel CV Parser

Upload a CV/resume PDF and get structured JSON back — personal info, work experience, education, skills, and links. The app includes a browser demo and optional user accounts (register, login, dashboard).

Parsing is powered by [OpenRouter](https://openrouter.ai/) (you bring your own API key and model).

## Features

- **Web demo** at `/demo` — upload a PDF and view parsed results in the browser
- **Parser endpoints** at `/api/v1/status` and `/api/v1/parse` — same-origin only (session + CSRF required for parse)
- **Authentication** — Fortify-based register/login, email verification, profile and security settings
- **Self-hostable** — single Docker container, external database, no bundled DB

---

## Using the app

### Web demo

1. Open `/demo` (the home page redirects here).
2. The page checks `/api/v1/status` and shows a warning if OpenRouter is not configured.
3. Choose a **PDF file** (max 5 MB) and submit.
4. Parsed data appears grouped into:
   - **Personal info** — name, phone, location, headline, summary
   - **Experience & education** — jobs and schools
   - **Skills & portfolio** — skills, portfolio URL, LinkedIn URL

### Parser endpoints (same-origin only)

The parser endpoints live at `/api/v1/*` but are **not a public REST API**. They are registered on the web middleware stack and intended for use from the in-app demo UI on the same origin.

- **GET `/api/v1/status`** — check whether parsing is configured (no CSRF required)
- **POST `/api/v1/parse`** — upload and parse a PDF (requires a Laravel session cookie and valid CSRF token)

External clients (curl, Postman, third-party integrations) cannot call `POST /api/v1/parse` without first obtaining a session and CSRF token from the application. Requests without a valid CSRF token receive HTTP **419**.

#### Check availability

From the same origin (e.g. after loading `/demo` in a browser), or for read-only status checks:

```bash
curl https://your-domain.com/api/v1/status
```

Response:

```json
{
  "available": true,
  "warning": null
}
```

If `OPENROUTER_API_KEY` or `OPENROUTER_MODEL` is missing, `available` is `false` and `warning` explains why.

#### Parse a CV

Parsing is only supported from the demo UI, which automatically sends the required CSRF token. The response shape for successful parses:

```json
{
  "data": {
    "personal_info": {
      "first_name": "Jane",
      "last_name": "Doe",
      "phone_code": "+61",
      "phone_number": "400000000",
      "location": "Sydney, NSW",
      "headline": "Software Engineer",
      "summary": "..."
    },
    "experience_education": {
      "experiences": [...],
      "educations": [...]
    },
    "skills_portfolio": {
      "skills": ["PHP", "Laravel"],
      "portfolio_url": "https://jane.dev",
      "linkedin_url": "https://linkedin.com/in/janedoe"
    }
  }
}
```

Error responses:

| Status | Meaning |
|--------|---------|
| `422` | Invalid file or extraction failed |
| `503` | OpenRouter not configured (`OPENROUTER_API_KEY` / `OPENROUTER_MODEL`) |

**Note:** Parsing can take up to **240 seconds** depending on model and PDF size. Clients and reverse proxies need timeouts ≥ 300s.

### User accounts

- **Register / login** — standard Fortify auth flows
- **Dashboard** — `/dashboard` (requires verified email)
- **Settings** — profile and security (password, two-factor when enabled)

---

## Self-hosting (Docker)

The recommended production setup is a **single container** (FrankenPHP + Laravel Octane) pulled from [Docker Hub](https://hub.docker.com/r/0adnyana/laravel-cv-parser) as `0adnyana/laravel-cv-parser:main`. No database is bundled — point `DB_*` or `DB_URL` at PostgreSQL, MySQL, or SQLite.

Configure the app with **container environment variables** (Portainer stack UI, compose `environment`, or an optional local `.env` for CLI). No host `.env` bind mount is required. `APP_KEY` is auto-generated on first start when omitted; migrations run automatically once when database env vars are set.

### Requirements

- Docker and Docker Compose (or Portainer)
- External database
- [OpenRouter](https://openrouter.ai/) API key and model name

### Quick start (Portainer)

1. Create a stack from `docker-compose.yml`.
2. Add environment variables:

```env
APP_ENV=production
APP_URL=https://cv.example.com
TRUSTED_PROXIES=*

OPENROUTER_API_KEY=sk-or-v1-...
OPENROUTER_MODEL=google/gemini-2.5-flash-lite-preview-09-2025

DB_URL=postgresql://USER:PASSWORD@ep-xxxx.region.aws.neon.tech/neondb?sslmode=require
```

3. Deploy. First start generates `APP_KEY` and runs migrations.

### Quick start (Docker Compose CLI)

```bash
git clone https://github.com/0adnyana/laravel-cv-parser.git
cd laravel-cv-parser
cp .env.example .env   # optional — loaded by compose when present, not mounted into the container
# Edit .env, then:
docker compose pull
docker compose up -d
```

On **Apple Silicon (ARM64)**, if `docker compose pull` fails with a platform error, build locally instead (compose includes `build: .` as a fallback):

```bash
docker compose build
docker compose up -d
```

Published images support both `linux/amd64` and `linux/arm64`.

Verify:

```bash
curl http://localhost:8000/up
curl http://localhost:8000/api/v1/status
```

Open `http://localhost:8000/demo` in a browser.

### Database on the same machine

Inside a container, `127.0.0.1` is the container itself — not your Mac or Linux host. If PostgreSQL or MySQL runs on the host, set:

```env
DB_HOST=host.docker.internal
```

Ensure PostgreSQL accepts TCP connections on port 5432 (not just a local socket). If migration still fails, check `listen_addresses` in `postgresql.conf` and host-based auth in `pg_hba.conf`.

### Port mapping

Default mapping is `8000:8000`. To run multiple apps on one host, change the **host** port in `docker-compose.yml`:

```yaml
ports:
  - "9080:8000"
```

### Reverse proxy

Set `APP_URL` to your public domain (not the internal Docker or Tailscale address). Point your proxy at the host IP and published port.

Example — Caddy on a VPS reaching homelab over Tailscale (`9080:8000`, homelab IP `100.x.y.z`):

```caddy
cv.example.com {
    reverse_proxy 100.x.y.z:9080 {
        transport http {
            read_timeout 300s
            write_timeout 300s
        }
    }
}
```

### Updating

Pull the latest image and restart:

```bash
docker compose pull
docker compose up -d
```

Run migrations manually after schema changes:

```bash
docker compose run --rm app php artisan migrate --force
```

To pin a release, set the `image` tag in `docker-compose.yml` (e.g. `0adnyana/laravel-cv-parser:v1.0.0`) instead of `:main`.

**Breaking change:** older setups bind-mounted `./.env` into the container. Copy those values into stack/container environment variables instead.

See [DOCKER.md](DOCKER.md) for SQLite volumes, Redis, Neon, logs, and other deployment details.

---

## Local development

### Requirements

- PHP 8.3+
- Composer
- Node.js 22+
- SQLite (default) or another database

### Setup

```bash
composer setup
```

This installs dependencies, creates `.env`, generates `APP_KEY`, runs migrations, and builds frontend assets.

Or step by step:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install && npm run build
```

Set `OPENROUTER_API_KEY` and `OPENROUTER_MODEL` in `.env`.

### Run

```bash
composer dev
```

Or separately:

```bash
php artisan serve
npm run dev
```

For Octane locally:

```bash
php artisan octane:frankenphp --host=127.0.0.1 --port=8000
```

### Tests

```bash
composer test
```

---

## Configuration reference

| Variable | Required | Description |
|----------|----------|-------------|
| `APP_KEY` | Docker: optional (auto-generated) / Local: yes | Laravel encryption key |
| `APP_URL` | Yes | Public application URL |
| `TRUSTED_PROXIES` | Production | `*` or comma-separated IPs when behind a reverse proxy |
| `OPENROUTER_API_KEY` | For parsing | OpenRouter API key |
| `OPENROUTER_MODEL` | For parsing | Model ID (must support file/PDF input) |
| `OPENROUTER_PDF_ENGINE` | No | PDF engine: `cloudflare-ai` (default), `mistral-ocr`, `native` |
| `OPENROUTER_PARSE_TIME_LIMIT` | No | Max seconds for parse requests (default: `240`) |
| `DB_*` | Yes | Database connection (external in Docker) |

Full list: [.env.example](.env.example).

---

## License

MIT
