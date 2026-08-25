# GitHub App backend

This folder contains the dependency-free PHP service deployed at
[`g.statuslights.dev`](https://g.statuslights.dev). The production entry point receives signed
GitHub App webhooks, stores the latest default-branch workflow and job states locally, and returns
compact SVG status lights.

Normal SVG requests do not call the GitHub REST API.

## Runtime files

- [`app.php`](app.php) is the production GitHub App entry point. It handles the webhook, health, and
  SVG routes and resolves statuses from local webhook state.
- [`index.php`](index.php) provides the shared URL parser, state mapping, SVG renderer, response
  helpers, and legacy request-time resolver used by the test suite.
- `app-data/` contains generated repository, run, delivery, and status records. It must be writable
  by PHP, must never be served directly, and should have its transient run and delivery records
  pruned with the included maintenance command.

The hosting environment must route the health, webhook, and SVG paths to `app.php` and prevent
direct HTTP access to runtime data, caches, and repository files. Keep platform-specific routing
and PHP configuration on the host; no web-server configuration file is shipped with the application.

There is no Composer install or application build step.

## Requirements

- PHP 8.3 or newer
- PHP JSON extension
- A web server or application platform that can route requests to the PHP entry point
- A writable `app-data` directory beside `app.php`, or a writable path configured through
  `STATUS_LIGHTS_APP_STORE_DIR`

PHP cURL is required only when running the legacy `index.php` request-time resolver directly. The
production GitHub App path does not use cURL for normal webhook or SVG requests.

## Run locally

From the repository root:

```bash
STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET=local-development-secret \
  php -S 127.0.0.1:8080 generator/app.php
```

Then open:

```text
http://127.0.0.1:8080/health
http://127.0.0.1:8080/github/KingBain/status-lights/pages.yml.svg
```

The status light remains `unknown` until the local instance receives a correctly signed GitHub
webhook. The test suite exercises webhook handling without requiring a live GitHub App.

## Test

The backend has no runtime or test dependencies:

```bash
find generator scripts -name '*.php' -print0 | xargs -0 -n1 php -l
php generator/tests/run.php
```

## App configuration

| Environment variable | Default | Purpose |
| --- | ---: | --- |
| `STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET` | unset | Required secret used to verify `X-Hub-Signature-256` |
| `STATUS_LIGHTS_APP_STORE_DIR` | `app-data` beside `app.php` | Writable GitHub App state directory |
| `STATUS_LIGHTS_MAX_WEBHOOK_BYTES` | `1048576` | Maximum accepted webhook body size (64 KiB to 25 MiB) |
| `STATUS_LIGHTS_RUN_RETENTION_DAYS` | `7` | Workflow run-link retention used by the pruning script (1 to 365 days) |
| `STATUS_LIGHTS_DELIVERY_RETENTION_DAYS` | `7` | Webhook replay-record retention used by the pruning script (1 to 365 days) |
| `STATUS_LIGHTS_RUN_PRUNE_INTERVAL_SECONDS` | `86400` | Minimum interval between scans (5 minutes to 7 days) |
| `STATUS_LIGHTS_HTTP_CACHE_TTL` | `60` | Browser and image-proxy cache duration in seconds |

The webhook secret must exactly match the secret configured in the GitHub App. Keep it out of the
repository and web content. Generate at least 32 random bytes; 64 hexadecimal characters is a
convenient representation.

The legacy request-time resolver in `index.php` also recognizes
`STATUS_LIGHTS_GITHUB_TOKEN`, `STATUS_LIGHTS_CACHE_DIR`, `STATUS_LIGHTS_CACHE_TTL`,
`STATUS_LIGHTS_STALE_TTL`, and `STATUS_LIGHTS_GITHUB_TIMEOUT`. Those settings are not used by normal
production App-backed SVG requests.

See the [self-hosting documentation](https://statuslights.dev/docs/#self-hosting) for GitHub App
registration, permissions, events, and host configuration.

## Webhook flow

GitHub sends `POST /webhooks/github` with an `X-Hub-Signature-256` header. Status Lights:

1. rejects request bodies larger than the configured limit and verifies the complete accepted body
   with HMAC-SHA256;
2. validates `X-GitHub-Delivery` and atomically ignores a delivery ID that was already accepted;
3. tracks installations and selected repositories from `installation` and
   `installation_repositories` events;
4. records the latest default-branch workflow state from `workflow_run` events;
5. temporarily associates `workflow_job` events with the workflow run and records the job display
   name;
6. returns HTTP 202 after accepting a supported, duplicate, or safely ignored event.

Oversized payloads return HTTP 413 and invalid signatures return HTTP 401. A `ping` event returns
HTTP 200 so GitHub can verify the webhook during App registration.

## Health endpoint

`GET /health` reports the GitHub App service and verifies both required runtime conditions:

```json
{
  "status": "ok",
  "service": "status-lights-github-app",
  "checks": {
    "app_store_writable": true,
    "webhook_secret_configured": true
  }
}
```

The endpoint returns HTTP 503 with `"status":"degraded"` when the state directory is not writable
or the webhook secret is missing.

## Deploy and maintain

There is no build step. Deploy `app.php` and `index.php` together on a PHP 8.3-or-newer host,
and provide a persistent writable directory for application state:

```text
/path/to/status-lights/
├── app.php
├── index.php
└── app-data/       # writable by PHP; never served directly
```

Configure the hosting environment to:

1. set `STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET` through its environment or secret configuration;
2. set any optional environment variables from the table above;
3. route `/health`, `/webhooks/github`, and `/github/...` requests to `app.php`;
4. keep `app-data/`, caches, source files, and repository metadata outside the public path or deny
   direct HTTP access to them;
5. provide HTTPS for the webhook and public SVG endpoints; and
6. apply request throttling at the web server, platform edge, or WAF.

After deployment, request `https://your-status-lights-host.example/health` and confirm that
`app_store_writable` and `webhook_secret_configured` are both `true`.

Schedule the dependency-free pruning command at least once per day using the task scheduler supplied
by the host:

```bash
STATUS_LIGHTS_APP_STORE_DIR=/path/to/app-data \
  php scripts/prune-app-runs.php
```

Only transient files in `app-data/runs/` and `app-data/deliveries/` are pruned. Current repository
and workflow/job status records remain available. Use `STATUS_LIGHTS_RUN_RETENTION_DAYS`,
`STATUS_LIGHTS_DELIVERY_RETENTION_DAYS`, and `STATUS_LIGHTS_RUN_PRUNE_INTERVAL_SECONDS` to control
retention and the minimum interval between scans.

## Public endpoint rate limiting

Apply request throttling at the web server or WAF for the public SVG routes. That layer can reject
abusive clients before PHP starts and can combine rate limits with edge caching. A file-per-IP PHP
limiter is intentionally not included because it would add disk I/O to every request and create a
second unbounded file store. Keep `/health` and `/webhooks/github` on separate rules so monitoring
and signed GitHub deliveries are not accidentally blocked by SVG traffic limits.

## URL format

```text
/github/{owner}/{repository}/{workflow}.svg
/github/{owner}/{repository}/{workflow}/{option}/{value}...svg
/github/{owner}/{repository}/{workflow}/job/{job-name}.svg
/github/{owner}/{repository}/{workflow}/job/{job-name}/{option}/{value}...svg
```

Supported options are `size`, `width`, `font`, `font-size`, `radius`, `text`, `success-color`,
`failure-color`, `running-color`, and `unknown-color`. The `text` value may contain `{status}`.

The optional `job/{job-name}` selector must appear immediately after the workflow. Use the job's
display name from the Actions UI or its workflow `name:` value, not the key under `jobs:`. Encode
spaces and other path characters in the URL; matching is case-sensitive.

For repository names beginning with a period, prefix the repository path segment with `@` so the
front-end web server does not treat it as a hidden path. For example, use `@.github` for the
repository named `.github`. The website builder adds this escape automatically.

GitHub owner, repository, and workflow identifiers must not be the canonical path segments `.` or
`..`. Encoded forms of those segments are rejected after URL decoding.

The public URL format did not change when Status Lights moved to the GitHub App architecture.
