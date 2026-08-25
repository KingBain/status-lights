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
- `app-data/` contains generated repository, run, and status records. It must be writable by PHP and
  must never be served directly.
- [`.htaccess`](.htaccess) routes requests to `app.php` and denies direct access to `app-data/`,
  `cache/`, and the cPanel repository clone.

There is no Composer install or application build step.

## Requirements

- PHP 8.3 or newer
- PHP JSON extension
- Apache or LiteSpeed with `mod_rewrite`-compatible rules
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
find generator -name '*.php' -print0 | xargs -0 -n1 php -l
php generator/tests/run.php
bash -n scripts/cpanel-pull-deploy.sh
```

## App configuration

| Environment variable | Default | Purpose |
| --- | ---: | --- |
| `STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET` | unset | Required secret used to verify `X-Hub-Signature-256` |
| `STATUS_LIGHTS_APP_STORE_DIR` | `app-data` beside `app.php` | Writable GitHub App state directory |
| `STATUS_LIGHTS_HTTP_CACHE_TTL` | `60` | Browser and image-proxy cache duration in seconds |

The webhook secret must exactly match the secret configured in the GitHub App. Keep it out of the
repository and web content. Generate at least 32 random bytes; 64 hexadecimal characters is a
convenient representation.

The legacy request-time resolver in `index.php` also recognizes
`STATUS_LIGHTS_GITHUB_TOKEN`, `STATUS_LIGHTS_CACHE_DIR`, `STATUS_LIGHTS_CACHE_TTL`,
`STATUS_LIGHTS_STALE_TTL`, and `STATUS_LIGHTS_GITHUB_TIMEOUT`. Those settings are not used by normal
production App-backed SVG requests.

See [`../docs/github-app-setup.md`](../docs/github-app-setup.md) for GitHub App registration,
permissions, events, and host configuration.

## Webhook flow

GitHub sends `POST /webhooks/github` with an `X-Hub-Signature-256` header. Status Lights:

1. verifies the complete request body with HMAC-SHA256;
2. tracks installations and selected repositories from `installation` and
   `installation_repositories` events;
3. records the latest default-branch workflow state from `workflow_run` events;
4. associates `workflow_job` events with the workflow run and records the job display name; and
5. returns HTTP 202 after accepting a supported or safely ignored event.

Invalid signatures return HTTP 401. A `ping` event returns HTTP 200 so GitHub can verify the webhook
during App registration.

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

## cPanel layout

The cPanel-managed clone and the live service are deliberately separate:

```text
/home/kingbain/g.statuslights.dev/
├── .htaccess       # routing and access-denial rules
├── app-data/       # writable webhook state
├── app.php         # production GitHub App entry point
├── cache/          # legacy resolver cache
├── cgi-bin/        # existing cPanel directory, left untouched
├── gh/             # cPanel-managed clone of this repository
└── index.php       # shared parser and renderer
```

The root [`.cpanel.yml`](../.cpanel.yml) creates the runtime directories and deploys `app.php`,
`index.php`, and the rewrite rules. It does not copy the documentation site, tests, Git metadata,
or other repository content, and it does not delete existing hosting files.

The live `.htaccess` must retain the PHP handler block managed by cPanel's MultiPHP Manager in
addition to the Status Lights rewrite and access-denial rules. If a deployment replaces that block,
reapply PHP 8.4 in MultiPHP Manager before testing the service.

### First App deployment

1. Open **cPanel → Git Version Control**.
2. Open **Manage** for `/home/kingbain/g.statuslights.dev/gh`.
3. Select **Update from Remote**.
4. Select **Deploy HEAD Commit**.
5. Confirm the live `.htaccess` still contains cPanel's PHP handler block.
6. Open `https://g.statuslights.dev/health` and confirm the service is
   `status-lights-github-app` with both checks set to `true`.
7. In the GitHub App settings, use **Redeliver** on a recent webhook or run a selected repository's
   workflow on its default branch.

### Automatic pull deployment

The repository includes [`scripts/cpanel-pull-deploy.sh`](../scripts/cpanel-pull-deploy.sh). It:

1. refuses to run when the cPanel clone contains uncommitted changes;
2. pulls `main` from GitHub using `--ff-only`;
3. exits without deploying when the commit did not change; and
4. asks cPanel UAPI to run `.cpanel.yml` when a new commit arrives.

In **cPanel → Cron Jobs**, choose **Once Per 5 Minutes** and put this value in the **Command** field:

```bash
/bin/bash /home/kingbain/g.statuslights.dev/gh/scripts/cpanel-pull-deploy.sh
```

This polling deploys repository changes; it is separate from the GitHub App webhook that delivers
workflow state. Successful no-change checks are silent. Pull or deployment errors are written to the
cron job's standard error output.

The script defaults to the known WHC paths. Its repository path, branch, Git executable, and UAPI
executable can be overridden with `STATUS_LIGHTS_REPOSITORY_PATH`, `STATUS_LIGHTS_BRANCH`,
`STATUS_LIGHTS_GIT_BIN`, and `STATUS_LIGHTS_UAPI_BIN`.

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

The public URL format did not change when Status Lights moved to the GitHub App architecture.
