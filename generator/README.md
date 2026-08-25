# Generator service

This folder contains the dependency-free PHP service deployed at
[`g.statuslights.dev`](https://g.statuslights.dev). It resolves the latest run on the repository's
default branch for a public GitHub Actions workflow and returns a compact SVG status light.

The complete runtime is in [`index.php`](index.php). There is no Composer install or application
build step. The accompanying [`.htaccess`](.htaccess) sends friendly status-light URLs to that PHP
file and prevents the cache directory from being served.

## Requirements

- PHP 8.3 or newer
- PHP cURL and JSON extensions
- Apache `mod_rewrite`
- A writable `cache` directory beside `index.php`

## Run locally

From the repository root:

```bash
php -S 127.0.0.1:8080 generator/index.php
```

Then try:

```text
http://127.0.0.1:8080/github/KingBain/status-lights/pages.yml.svg
http://127.0.0.1:8080/github/KingBain/status-lights/pages.yml/size/40/text/Build%3A%20%7Bstatus%7D.svg
http://127.0.0.1:8080/health
```

## Test

The generator has no runtime or test dependencies:

```bash
find generator -name '*.php' -print0 | xargs -0 -n1 php -l
php generator/tests/run.php
bash -n scripts/cpanel-pull-deploy.sh
```

## Configuration

| Environment variable | Default | Purpose |
| --- | ---: | --- |
| `STATUS_LIGHTS_GITHUB_TOKEN` | unset | Optional public-repository GitHub token used only by the server to increase API limits |
| `STATUS_LIGHTS_CACHE_DIR` | `cache` beside `index.php` | Writable filesystem cache |
| `STATUS_LIGHTS_CACHE_TTL` | `60` | Fresh workflow-state cache duration in seconds |
| `STATUS_LIGHTS_STALE_TTL` | `3600` | Maximum age of cached state used when GitHub is unavailable |
| `STATUS_LIGHTS_HTTP_CACHE_TTL` | `60` | Browser and image-proxy cache duration in seconds |
| `STATUS_LIGHTS_GITHUB_TIMEOUT` | `5` | GitHub request timeout in seconds |

`GITHUB_TOKEN` is accepted as a fallback, but the service-specific variable is preferred. Never put
a token in a URL or commit one to the repository. If a token is configured, give it read-only access
to public repository Actions data; do not give the public service access to private repositories.

## cPanel layout

The cPanel-managed clone and the live website are deliberately separate:

```text
/home/kingbain/g.statuslights.dev/
├── .htaccess       # deployed from generator/.htaccess
├── cache/          # writable runtime data
├── cgi-bin/        # existing cPanel directory, left untouched
├── gh/             # cPanel-managed clone of this repository
└── index.php       # deployed from generator/index.php
```

The root [`.cpanel.yml`](../.cpanel.yml) creates the cache directory and copies only `index.php` and
`.htaccess` from the clone into the live directory. It does not copy the documentation site, tests,
Git metadata, or other repository content, and it does not delete existing hosting files. The
deployed rewrite rules also deny web requests for both `/cache` and the `/gh` repository clone.

### First deployment

After this change reaches `main`:

1. Open **cPanel → Git Version Control**.
2. Open **Manage** for `/home/kingbain/g.statuslights.dev/gh`.
3. Select **Update from Remote**.
4. Select **Deploy HEAD Commit**.
5. Open `https://g.statuslights.dev/health` and confirm that it reports `"status":"ok"`.

### Automatic pull deployment without hooks

The repository includes [`scripts/cpanel-pull-deploy.sh`](../scripts/cpanel-pull-deploy.sh). It:

1. refuses to run when the cPanel clone contains uncommitted changes;
2. pulls `main` from GitHub using `--ff-only`;
3. exits without deploying when the commit did not change; and
4. asks cPanel UAPI to run `.cpanel.yml` when a new commit arrives.

In **cPanel → Cron Jobs**, add this command at a five-minute interval:

```cron
*/5 * * * * /bin/bash /home/kingbain/g.statuslights.dev/gh/scripts/cpanel-pull-deploy.sh
```

This is polling, not a webhook. A GitHub push will normally reach the live service within five
minutes. Successful no-change checks are silent; pull or deployment errors are written to the cron
job's standard error output.

The script defaults to the known WHC paths. Its repository path, branch, Git executable, and UAPI
executable can be overridden with `STATUS_LIGHTS_REPOSITORY_PATH`, `STATUS_LIGHTS_BRANCH`,
`STATUS_LIGHTS_GIT_BIN`, and `STATUS_LIGHTS_UAPI_BIN` if the hosting environment differs.

## URL format

```text
/github/{owner}/{repository}/{workflow}.svg
/github/{owner}/{repository}/{workflow}/{option}/{value}...svg
```

Supported options are `size`, `width`, `font`, `font-size`, `radius`, `text`, `success-color`,
`failure-color`, `running-color`, and `unknown-color`. The `text` value may contain `{status}`.
