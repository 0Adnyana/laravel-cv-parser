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

### Requirement: Production PHP configuration

The runtime Docker image SHALL use production PHP settings and an explicit opcache configuration tuned for immutable container code.

#### Scenario: Production php.ini active

- **WHEN** the runtime container starts
- **THEN** PHP loads `php.ini-production` (not `php.ini-development`) as the active `php.ini`

#### Scenario: Opcache enabled with immutable code settings

- **WHEN** the runtime container serves requests via FrankenPHP
- **THEN** opcache is enabled with explicit settings including `opcache.validate_timestamps=0` because application code is fixed inside the image

### Requirement: Container environment configuration without host env file

The `docker-compose.yml` SHALL configure the application using container environment variables only. The compose file MUST NOT bind-mount a host `.env` file into the container. An `env_file` reference MAY be present but MUST be optional (`required: false`) so stack deployers (e.g. Portainer) are not blocked when no host `.env` file exists.

#### Scenario: Portainer stack deploy without host env file

- **WHEN** a deployer creates a stack in Portainer with environment variables set in the stack UI and no `.env` file on the Docker host
- **THEN** `docker compose up` succeeds and the application container receives those environment variables

#### Scenario: Optional local env file for CLI workflows

- **WHEN** a deployer places a `.env` file beside `docker-compose.yml` on a machine using Docker Compose CLI
- **THEN** compose MAY load it via optional `env_file` without requiring a bind mount into the container

### Requirement: Required operator-supplied APP_KEY

The container entrypoint SHALL require `APP_KEY` to be supplied by the operator via environment variable. The container MUST NOT auto-generate or persist an application key.

#### Scenario: Container refuses to start without APP_KEY

- **WHEN** the container starts with no `APP_KEY` environment variable
- **THEN** the entrypoint exits with a non-zero status and prints instructions to generate a key using `php artisan key:generate --show`

#### Scenario: Operator-provided APP_KEY accepted

- **WHEN** the deployer sets `APP_KEY` in container environment variables
- **THEN** the entrypoint proceeds without generating or overwriting that value

#### Scenario: APP_KEY generation documented

- **WHEN** a deployer reads the self-host documentation
- **THEN** they find the one-time key generation command as the first setup step before running the container

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

The compose configuration SHALL mount a volume for `/app/storage` so logs, SQLite database files (when used), and file-based cache/session data survive container restarts.

#### Scenario: Storage persists after restart

- **WHEN** the application writes log entries or database files to storage and the container is restarted
- **THEN** previous storage contents remain available in the mounted volume

#### Scenario: Storage volume documented for SQLite

- **WHEN** a deployer reads the self-host documentation
- **THEN** they find guidance to mount `/app/storage` as a volume, especially when using SQLite

### Requirement: Container health check

The application container SHALL define a Docker HEALTHCHECK that probes the `/up` endpoint.

#### Scenario: Healthy container

- **WHEN** Octane is running and the application is ready
- **THEN** the Docker health check reports healthy

### Requirement: Boot-time migrations with opt-out

The entrypoint SHALL run pending database migrations on every container start by default, using Laravel's idempotent migration tracking. Operators MAY disable this behavior via `RUN_MIGRATIONS=false`.

#### Scenario: Default boot-time migrations

- **WHEN** the container starts with valid database environment variables, pending migrations, and `RUN_MIGRATIONS` unset or set to `true`
- **THEN** the entrypoint runs `php artisan migrate --force --isolated` before Octane starts and logs that migrations are running

#### Scenario: Migration opt-out

- **WHEN** the container starts with `RUN_MIGRATIONS=false`
- **THEN** the entrypoint skips migrations and logs that migrations were skipped by operator choice

#### Scenario: No database configured

- **WHEN** the container starts without sufficient database configuration
- **THEN** the entrypoint skips migrations and logs that no database is configured

#### Scenario: Migration opt-out documented

- **WHEN** a deployer reads the self-host documentation
- **THEN** they find instructions to set `RUN_MIGRATIONS=false` and run migrations manually when ready

### Requirement: Explicit database migrations

Database migrations SHALL run automatically on container start when a database is configured (unless disabled via `RUN_MIGRATIONS=false`). The project SHALL document manual migration commands for operators who manage schema themselves.

#### Scenario: Automatic boot migrations apply pending only

- **WHEN** the container starts with valid database environment variables and some migrations already applied in the database
- **THEN** the entrypoint runs only pending migrations via `php artisan migrate --force --isolated`

#### Scenario: Documented manual migration command

- **WHEN** a deployer follows the self-host documentation for manual schema management
- **THEN** they can run `docker compose exec app php artisan migrate --force` (or equivalent) to apply migrations manually

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
