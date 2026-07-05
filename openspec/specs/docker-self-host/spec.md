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

### Requirement: External database via environment

The application container SHALL connect to a database configured entirely through environment variables (`DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`). The compose file MUST NOT define a database service.

#### Scenario: PostgreSQL connection

- **WHEN** `DB_CONNECTION=pgsql` and valid PostgreSQL credentials are set in `.env`
- **THEN** the application connects to the external PostgreSQL instance and migrations succeed

#### Scenario: MySQL connection

- **WHEN** `DB_CONNECTION=mysql` and valid MySQL credentials are set in `.env`
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

### Requirement: Logs volume persistence

The compose configuration SHALL mount a volume for `storage/logs` so log files survive container restarts.

#### Scenario: Logs persist after restart

- **WHEN** the application writes log entries and the container is restarted
- **THEN** previous log files remain available in the mounted volume

### Requirement: Container health check

The application container SHALL define a Docker HEALTHCHECK that probes the `/up` endpoint.

#### Scenario: Healthy container

- **WHEN** Octane is running and the application is ready
- **THEN** the Docker health check reports healthy

### Requirement: Explicit database migrations

Database migrations SHALL NOT run automatically on container start. The project SHALL document an explicit migration command for deployers.

#### Scenario: Documented migration command

- **WHEN** a deployer follows the self-host documentation before first use
- **THEN** they can run `docker compose run --rm app php artisan migrate --force` to apply migrations

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

