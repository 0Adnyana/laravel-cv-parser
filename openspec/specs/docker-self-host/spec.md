# docker-self-host Specification

## Purpose
TBD - created by archiving change add-docker-self-host. Update Purpose after archive.
## Requirements
### Requirement: Single-container application image

The project SHALL provide a `Dockerfile` that produces a production-ready image containing the full Laravel + Inertia application (web UI and `/api/v1/*` API) without a bundled database server.

#### Scenario: Image builds successfully

- **WHEN** a self-hoster runs `docker compose build` from the repository root
- **THEN** the build completes without error and produces a runnable application image

#### Scenario: Frontend assets are pre-built in the image

- **WHEN** the application container starts
- **THEN** Vite-built assets are present in `public/build` and the web UI renders without requiring Node.js at runtime

### Requirement: FrankenPHP Octane runtime

The application container SHALL serve HTTP via Laravel Octane using the FrankenPHP driver, listening on port 8000 inside the container.

#### Scenario: Octane serves the health endpoint

- **WHEN** a client sends `GET /up` to the container on port 8000
- **THEN** the response status is 200

#### Scenario: Octane serves the API

- **WHEN** a client sends `GET /api/v1/status` to the container on port 8000
- **THEN** the response status is 200 with valid JSON

### Requirement: Container environment configuration without host env file

The `docker-compose.yml` SHALL configure the application using container environment variables only. The compose file MUST NOT bind-mount a host `.env` file into the container. An `env_file` reference MAY be present but MUST be optional (`required: false`) so stack deployers (e.g. Portainer) are not blocked when no host `.env` file exists.

#### Scenario: Portainer stack deploy without host env file

- **WHEN** a deployer creates a stack in Portainer with environment variables set in the stack UI and no `.env` file on the Docker host
- **THEN** `docker compose up` succeeds and the application container receives those environment variables

#### Scenario: Optional local env file for CLI workflows

- **WHEN** a deployer places a `.env` file beside `docker-compose.yml` on a machine using Docker Compose CLI
- **THEN** compose MAY load it via optional `env_file` without requiring a bind mount into the container

### Requirement: Automatic APP_KEY bootstrap

When the container starts and `APP_KEY` is empty or unset, the entrypoint SHALL generate a new application key before serving traffic. If a writable `storage/` volume is mounted, the generated key SHALL be persisted to a file under `storage/` so restarts reuse the same key.

#### Scenario: First start without APP_KEY

- **WHEN** the container starts with no `APP_KEY` environment variable and no persisted key file
- **THEN** the entrypoint generates an `APP_KEY`, writes it to persistent storage when available, and Octane starts successfully

#### Scenario: Restart reuses persisted key

- **WHEN** the container restarts and a previously generated key exists in the persisted storage volume
- **THEN** the same `APP_KEY` is used without regeneration

#### Scenario: Operator-provided APP_KEY

- **WHEN** the deployer sets `APP_KEY` in container environment variables
- **THEN** the entrypoint does not generate or overwrite that value

### Requirement: External database via environment

The application container SHALL connect to a database configured entirely through container environment variables (`DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, or `DB_URL`). The compose file MUST NOT define a database service.

#### Scenario: PostgreSQL connection

- **WHEN** `DB_CONNECTION=pgsql` and valid PostgreSQL credentials are set as container environment variables
- **THEN** the application connects to the external PostgreSQL instance and migrations succeed

#### Scenario: MySQL connection

- **WHEN** `DB_CONNECTION=mysql` and valid MySQL credentials are set as container environment variables
- **THEN** the application connects to the external MySQL instance and migrations succeed

#### Scenario: SQLite connection

- **WHEN** `DB_CONNECTION=sqlite` and `DB_DATABASE` points to a writable database file path
- **THEN** the application connects to SQLite and migrations succeed

### Requirement: Configurable host port mapping

The `docker-compose.yml` SHALL publish the application container port 8000 to a configurable host port, defaulting to `8000:8000`.

#### Scenario: Default port mapping

- **WHEN** a self-hoster runs `docker compose up` with default compose configuration
- **THEN** the application is reachable on the host at port 8000

#### Scenario: Custom host port

- **WHEN** a self-hoster changes the compose port mapping to `"9080:8000"`
- **THEN** the application is reachable on the host at port 9080

### Requirement: Storage volume persistence

The compose configuration SHALL mount a volume for `/app/storage` so logs, bootstrap state (including auto-generated `APP_KEY`), and migration markers survive container restarts.

#### Scenario: Storage persists after restart

- **WHEN** the application writes log entries or bootstrap files and the container is restarted
- **THEN** previous storage contents remain available in the mounted volume

### Requirement: Container health check

The application container SHALL define a Docker HEALTHCHECK that probes the `/up` endpoint.

#### Scenario: Healthy container

- **WHEN** Octane is running and the application is ready
- **THEN** the Docker health check reports healthy

### Requirement: Explicit database migrations

Database migrations SHALL run automatically on first container start when a database is configured and migrations have not yet been applied. The project SHALL also document a manual migration command for redeploys and troubleshooting.

#### Scenario: Automatic first-run migrations

- **WHEN** the container starts for the first time with valid database environment variables and pending migrations
- **THEN** the entrypoint runs `php artisan migrate --force` once before Octane starts

#### Scenario: Documented manual migration command

- **WHEN** a deployer follows the self-host documentation for redeploys or troubleshooting
- **THEN** they can run `docker compose run --rm app php artisan migrate --force` to apply migrations manually

### Requirement: Reverse-proxy deployment support

The project SHALL document requirements for deploying behind a reverse proxy, including correct `APP_URL` configuration and proxy timeout settings for long-running parse requests.

#### Scenario: APP_URL documentation

- **WHEN** a deployer reads the self-host documentation
- **THEN** they find guidance to set `APP_URL` to the public HTTPS domain served by their reverse proxy

#### Scenario: Parse timeout documentation

- **WHEN** a deployer reads the self-host documentation
- **THEN** they find guidance to configure reverse-proxy upstream timeouts of at least 300 seconds for `/api/v1/parse`

### Requirement: Optional Redis

The project SHALL document how to switch session, cache, and queue drivers to Redis using an external Redis instance configured via environment variables, without including a Redis container in the default compose file.

#### Scenario: Redis upgrade documentation

- **WHEN** a deployer reads the self-host documentation
- **THEN** they find instructions to set `REDIS_HOST` and update driver env vars to use an external Redis instance
