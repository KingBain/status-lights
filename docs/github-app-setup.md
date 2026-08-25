# GitHub App operator setup

This guide is for people deploying their own Status Lights instance. Users of the public service do
not need to register an App; follow the [getting-started guide](getting-started.md) and install
[Status Lights from GitHub](https://github.com/apps/status-lights) instead.

The PHP backend receives GitHub Actions webhook events and stores the latest workflow and job state
locally, so normal SVG requests do not poll the GitHub REST API.

## Register the app

In GitHub, open **Settings → Developer settings → GitHub Apps → New GitHub App** and use:

- **GitHub App name:** `Status Lights` (or another unique name while testing)
- **Homepage URL:** `https://statuslights.dev`
- **Webhook URL:** `https://g.statuslights.dev/webhooks/github`
- **Webhook secret:** generate at least 32 random bytes (64 hexadecimal characters) and also
  configure it on the PHP host as `STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET`
- **Callback URL:** not required for the current server-to-server design
- **Expire user authorization tokens:** not relevant; Status Lights does not request user authorization

Under **Repository permissions**, set:

- **Actions:** Read-only
- **Metadata:** Read-only (GitHub grants this automatically)

Do not grant write permissions.

Subscribe to these repository events:

- `Workflow run`
- `Workflow job`

Keep the installation and installation-repository lifecycle events enabled so Status Lights can track which repositories are installed.

Set **Where can this GitHub App be installed?** to **Any account** when you are ready for public installs.

The public Status Lights service is intended for public repositories. The SVG routes are public and
unauthenticated, so do not install the public instance on private repositories.

## Configure the host

Set this environment variable through the hosting environment or web-server configuration. Never
commit it to the repository:

```text
STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET=<same random secret configured in GitHub>
```

The deployed PHP application writes runtime state beneath `app-data/`. Apache denies direct HTTP
access to that directory. The application also accepts `STATUS_LIGHTS_APP_STORE_DIR` when runtime
data must live somewhere else. Webhook bodies are limited to 1 MiB by default; self-hosters can set
`STATUS_LIGHTS_MAX_WEBHOOK_BYTES` from 65536 through 26214400 when an installation legitimately
needs a larger payload.

After deployment, `https://g.statuslights.dev/health` should report both `app_store_writable` and `webhook_secret_configured` as `true`.

## Install and test

1. Open the App's public GitHub page and install it on a test account or organization.
2. Select the public repositories Status Lights may observe.
3. Run one of the repository's GitHub Actions workflows on its default branch.
4. GitHub sends `workflow_run` and `workflow_job` events to Status Lights.
5. Existing URLs such as the following read the webhook-backed local state:

```text
https://g.statuslights.dev/github/KingBain/status-lights/pages.yml.svg
https://g.statuslights.dev/github/KingBain/status-lights/pages.yml/job/Validate%20site.svg
```

A newly installed repository shows `unknown` until the relevant workflow or job emits its first
webhook event. GitHub does not replay earlier events during installation. A future bootstrap step can
use a GitHub App installation token to seed existing workflow states immediately; this does not
require changing the public SVG URL format.

## Maintain the file store

Workflow run records only connect `workflow_job` deliveries to their parent workflow. They are
temporary and should not accumulate indefinitely. Run the included pruning command at least daily;
it deletes only run records older than seven days by default:

```bash
STATUS_LIGHTS_APP_STORE_DIR=/path/to/app-data \
  php scripts/prune-app-runs.php
```

Set `STATUS_LIGHTS_RUN_RETENTION_DAYS` from 1 through 365 to change the retention period. Schedule
the command with your hosting platform's task runner or another cron-compatible scheduler. An
app-data lock prevents overlapping scans, and `STATUS_LIGHTS_RUN_PRUNE_INTERVAL_SECONDS` controls
the minimum interval between scans from 300 through 604800 seconds.

## Security model

Status Lights bounds the request body before parsing it, then validates every accepted GitHub
webhook with `X-Hub-Signature-256` using HMAC-SHA256 and the configured webhook secret. Oversized
payloads are rejected with HTTP 413 and invalid signatures with HTTP 401.

The webhook-first implementation does not require a personal access token and does not need
repository write access. Runtime data contains repository, workflow, job, run, installation, and
status metadata only and is stored outside direct HTTP access.

Public SVG routes should also be protected with web-server or WAF rate limits. Enforce those limits
before PHP, and keep webhook traffic on a separate rule so valid signed GitHub deliveries are not
throttled with public image requests.
