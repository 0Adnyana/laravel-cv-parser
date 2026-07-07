# Self-Hosting with Docker

Run the full application (Inertia web UI + `/api/v1/*` API) in a single container. No database is bundled — configure an external database via container environment variables.

## Requirements

- Docker and Docker Compose (or Portainer / another stack UI)
- External database: PostgreSQL, MySQL/MariaDB, or SQLite
- OpenRouter API key for CV parsing

## Quick Start (Portainer or stack UI)

1. Create a stack from `docker-compose.yml` (paste the file or point at this repository).

2. Add these **environment variables** in the stack UI (no host `.env` file required):

   | Variable | Required | Example |
   | --- | --- | --- |
   | `APP_URL` | Yes | `https://cv.example.com` |
   | `APP_ENV` | Yes | `production` |
   | `TRUSTED_PROXIES` | When behind a proxy | `*` |
   | `OPENROUTER_API_KEY` | Yes | `sk-or-v1-...` |
   | `OPENROUTER_MODEL` | Yes | `google/gemini-2.5-flash-lite-preview-09-2025` |
   | `DB_URL` or `DB_*` | Yes | Neon connection string or individual vars |
   | `APP_KEY` | Yes | Generate with `docker run --rm <image> php artisan key:generate --show` |

3. Deploy the stack. On first start the container will:

   - Require `APP_KEY` in environment (fails fast with instructions if missing)
   - Run pending database migrations on startup (unless `RUN_MIGRATIONS=false`)

4. Verify health:

   ```bash
   curl http://localhost:8000/up
   curl http://localhost:8000/api/v1/status
   ```

## Quick Start (Docker Compose CLI)

For local CLI workflows, generate an `APP_KEY` first, then optionally keep a `.env` file beside `docker-compose.yml`. Compose loads it when present (`required: false`) — it is **not** bind-mounted into the container.

```bash
docker run --rm 0adnyana/laravel-cv-parser:main php artisan key:generate --show
cp .env.example .env
# Add APP_KEY and other values to .env, then:
docker compose pull   # or docker compose build on ARM64 if pull fails
docker compose up -d
```

Migrations run automatically on each startup (pending only). Set `RUN_MIGRATIONS=false` to manage schema yourself. To run manually:

```bash
docker compose exec app php artisan migrate --force
```

## Port Mapping

The default compose mapping is `8000:8000`. Change the **host** port when running multiple apps on one machine:

```yaml
ports:
  - "9080:8000"
```

The container always listens on port **8000**.

## Database on the Same Machine

Inside a container, `127.0.0.1` refers to the container — not the host where PostgreSQL or MySQL may be running. Set `DB_HOST=host.docker.internal` when the database runs on the same machine as Docker.

`docker-compose.yml` includes `extra_hosts: host.docker.internal:host-gateway` so this works on Linux as well as Docker Desktop.

Ensure your database server accepts TCP connections (PostgreSQL: `listen_addresses` and `pg_hba.conf`).

## Database Options

### PostgreSQL (including Neon)

```env
DB_CONNECTION=pgsql
DB_URL=postgresql://USER:PASSWORD@ep-xxxx.region.aws.neon.tech/neondb?sslmode=require
```

Or individual variables:

```env
DB_CONNECTION=pgsql
DB_HOST=ep-xxxx.region.aws.neon.tech
DB_PORT=5432
DB_DATABASE=neondb
DB_USERNAME=your_neon_user
DB_PASSWORD=your_neon_password
DB_SSLMODE=require
```

### MySQL / MariaDB

```env
DB_CONNECTION=mysql
DB_HOST=your-mysql-host
DB_PORT=3306
DB_DATABASE=laravel_cv_parser
DB_USERNAME=your_user
DB_PASSWORD=your_password
```

### SQLite

Bind-mount a host directory for the database file:

```yaml
services:
  app:
    volumes:
      - app-storage:/app/storage
      - ./data:/app/database
```

```env
DB_CONNECTION=sqlite
DB_DATABASE=/app/database/database.sqlite
```

Create the database file before the first start:

```bash
mkdir -p data && touch data/database.sqlite
```

## Reverse Proxy

When deploying behind Caddy, Nginx Proxy Manager, or Traefik:

1. Set `APP_URL` to your public HTTPS domain (e.g. `https://cv.example.com`), not the internal Docker or Tailscale address.
2. Set `TRUSTED_PROXIES=*` (or restrict to your proxy's IP/CIDR).
3. Point the proxy upstream at your host IP and **published host port**.

### Example: Caddy on a VPS via Tailscale

Homelab container published at `9080:8000`, homelab Tailscale IP `100.x.y.z`:

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

## Long-Running Parse Requests

`/api/v1/parse` can run up to 240 seconds (`OPENROUTER_PARSE_TIME_LIMIT`). Configure your reverse proxy upstream timeout to **at least 300 seconds** to avoid 504 Gateway Timeout errors.

## Optional Redis

The default drivers use the database for session, cache, and queue. To use an external Redis instance:

```env
REDIS_HOST=your-redis-host
REDIS_PASSWORD=null
REDIS_PORT=6379

SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
```

No Redis container is included in `docker-compose.yml`.

## Storage and Logs

Application storage (logs, SQLite database files when used, file-based cache/sessions) is persisted in the `app-storage` Docker volume (`/app/storage` inside the container). Mount this volume so data survives container restarts.

If you previously used the `app-logs` volume, recreate the stack so the new volume is used. Copy any needed log files from the old volume before removing it.

## Memory Limits (Optional)

When running with a container memory limit, set `GOMEMLIMIT` to match available memory (e.g. `GOMEMLIMIT=512MiB`) so FrankenPHP's Go runtime garbage collector behaves correctly.

## Updating

```bash
docker compose pull
docker compose up -d
```

Run migrations manually after schema changes (or when `RUN_MIGRATIONS=false`):

```bash
docker compose run --rm app php artisan migrate --force
```

## Migrating from bind-mounted `.env`

Older compose setups bind-mounted `./.env` into the container. That is no longer required. Copy your values into Portainer stack environment variables (or a local `.env` for CLI compose interpolation), remove any `./.env:/app/.env` volume override, and redeploy.
