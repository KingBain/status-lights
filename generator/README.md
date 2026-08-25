# Generator service

This folder contains the dependency-free PHP service deployed at
[`g.statuslights.dev`](https://g.statuslights.dev). It resolves the latest run of a public GitHub
Actions workflow and returns a compact SVG status light.

## Requirements

- PHP 8.3 or newer
- PHP cURL and JSON extensions
- Apache `mod_rewrite` when using the included `.htaccess`
- A writable cache directory

## Run locally

From the repository root:

```bash
php -S 127.0.0.1:8080 -t generator/public generator/router.php
```

Then try:

```text
http://127.0.0.1:8080/github/KingBain/status-lights/pages.yml.svg
http://127.0.0.1:8080/github/KingBain/status-lights/pages.yml/size/40/text/Build%3A%20%7Bstatus%7D.svg
http://127.0.0.1:8080/health
```

## Test

The application has no runtime dependencies. Composer is optional and only provides convenient
script aliases:

```bash
cd generator
composer check
```

Without Composer:

```bash
find generator/public generator/src generator/tests generator/router.php -name '*.php' -print0 | xargs -0 -n1 php -l
php generator/tests/run.php
```

## Configuration

| Environment variable | Default | Purpose |
| --- | ---: | --- |
| `STATUS_LIGHTS_GITHUB_TOKEN` | unset | Optional GitHub token used only by the server to increase API limits |
| `STATUS_LIGHTS_CACHE_DIR` | `generator/var/cache` | Writable filesystem cache |
| `STATUS_LIGHTS_CACHE_TTL` | `60` | Fresh workflow-state cache duration in seconds |
| `STATUS_LIGHTS_STALE_TTL` | `3600` | Maximum age of cached state used when GitHub is unavailable |
| `STATUS_LIGHTS_HTTP_CACHE_TTL` | `60` | Browser and image-proxy cache duration in seconds |
| `STATUS_LIGHTS_GITHUB_TIMEOUT` | `5` | GitHub request timeout in seconds |

`GITHUB_TOKEN` is accepted as a fallback, but the service-specific variable is preferred. Never put
a token in the URL or commit one to the repository.

## cPanel deployment

1. Select a supported PHP version with the cURL and JSON extensions enabled.
2. Set the `g.statuslights.dev` document root to the repository's `generator/public` directory.
3. Make `generator/var/cache` writable by the PHP process.
4. Add `STATUS_LIGHTS_GITHUB_TOKEN` to the hosting environment if the host supports environment
   variables. The service works without one but shares GitHub's lower unauthenticated API limit.
5. Open `/health`, then request a real `.svg` URL.

Keeping the document root at `generator/public` prevents source files, cache data, and environment
configuration from being served directly.

## URL format

```text
/github/{owner}/{repository}/{workflow}.svg
/github/{owner}/{repository}/{workflow}/{option}/{value}...svg
```

Supported options are `size`, `width`, `font`, `font-size`, `radius`, `text`, `success-color`,
`failure-color`, `running-color`, and `unknown-color`. The `text` value may contain `{status}`.
