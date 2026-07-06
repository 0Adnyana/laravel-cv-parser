# Self-Hosting with Docker

Run the full application (Inertia web UI + `/api/v1/*` API) in a single container. No database is bundled — configure an external database via `.env`.

## Requirements

- Docker and Docker Compose
- External database: PostgreSQL, MySQL/MariaDB, or SQLite
- OpenRouter API key for CV parsing

## Quick Start

1. Copy environment file and configure secrets:

   ```bash
   cp .env.example .env
   ```

2. Set required values in `.env`:

   - `APP_KEY` — run `php artisan key:generate` locally, or `docker compose run --rm app php artisan key:generate`
   - `APP_URL` — public URL users visit (see [Reverse proxy](#reverse-proxy) below)
   - `TRUSTED_PROXIES=*` — when behind a reverse proxy
   - `DB_*` — external database credentials
   - `OPENROUTER_API_KEY` and `OPENROUTER_MODEL`

3. Pull the image (or build locally on Apple Silicon if pull fails — see below):

   ```bash
   docker compose pull
   ```

   On **ARM64** hosts, if pull fails with `no matching manifest for linux/arm64`, build locally:

   ```bash
   docker compose build
   ```

4. Run migrations (explicit step — not automatic on start):

   ```bash
   docker compose run --rm app php artisan migrate --force
   ```

5. Start the application:

   ```bash
   docker compose up -d
   ```

6. Verify health:

   ```bash
   curl http://localhost:8000/up
   curl http://localhost:8000/api/v1/status
   ```

## Port Mapping

The default compose mapping is `8000:8000`. Change the **host** port when running multiple apps on one machine:

```yaml
ports:
  - "9080:8000"
```

The container always listens on port **8000**.

## Database Options

### PostgreSQL

```env
DB_CONNECTION=pgsql
DB_HOST=your-postgres-host
DB_PORT=5432
DB_DATABASE=laravel_cv_parser
DB_USERNAME=your_user
DB_PASSWORD=your_password
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
      - app-logs:/app/storage/logs
      - ./data:/app/database
```

```env
DB_CONNECTION=sqlite
DB_DATABASE=/app/database/database.sqlite
```

Create the database file before migrating:

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

## Logs

Application logs are persisted in the `app-logs` Docker volume (`storage/logs` inside the container).

## Updating

```bash
docker compose pull
docker compose run --rm app php artisan migrate --force
docker compose up -d
```
