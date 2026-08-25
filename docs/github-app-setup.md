# GitHub App setup

Status Lights is designed to run as an installable GitHub App. The PHP backend receives GitHub Actions webhook events and stores the latest workflow/job state locally, so normal SVG requests do not poll the GitHub REST API.

## Register the app

In GitHub, open **Settings → Developer settings → GitHub Apps → New GitHub App** and use:

- **GitHub App name:** `Status Lights` (or another unique name while testing)
- **Homepage URL:** `https://statuslights.dev`
- **Webhook URL:** `https://g.statuslights.dev/webhooks/github`
- **Webhook secret:** generate a long random value and also configure it on the PHP host as `STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET`
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

## Configure the host

Set this environment variable outside the web root:

```text
STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET=<same random secret configured in GitHub>
```

The deployed PHP application writes runtime state beneath `app-data/`. Apache denies direct HTTP access to that directory.

After deployment, `https://g.statuslights.dev/health` should report both `app_store_writable` and `webhook_secret_configured` as `true`.

## Install and test

1. Install the GitHub App on a test account or organization.
2. Select the repositories Status Lights may observe.
3. Run one of the repository's GitHub Actions workflows on its default branch.
4. GitHub sends `workflow_run` and `workflow_job` events to Status Lights.
5. Existing URLs such as the following read the webhook-backed local state:

```text
https://g.statuslights.dev/github/KingBain/status-lights/pages.yml.svg
https://g.statuslights.dev/github/KingBain/status-lights/pages.yml/job/Validate%20site.svg
```

A newly installed repository can initially show `unknown` until the relevant workflow/job emits its first webhook event. A future bootstrap step can use a GitHub App installation token to seed existing workflow states immediately after installation; this does not require changing the public SVG URL format.

## Security model

Status Lights validates every GitHub webhook with `X-Hub-Signature-256` using HMAC-SHA256 and the configured webhook secret. Invalid signatures are rejected with HTTP 401.

The current webhook-first implementation does not require a personal access token and does not need repository write access. Runtime data contains repository/workflow status metadata only and is stored outside direct HTTP access.
