# Status Lights

Status Lights is an open-source, free public service for turning GitHub Actions workflow results
into compact, customizable SVG indicators.

This repository contains the public website and the PHP backend for the installable Status Lights
GitHub App.

## Project status

This table uses Status Lights itself to show the latest `main` result for each repository workflow
and its individual jobs.

| Scope | Status |
| --- | :---: |
| GitHub Pages workflow | [![GitHub Pages workflow status](https://g.statuslights.dev/github/KingBain/status-lights/pages.yml/size/30/radius/5.svg)](https://github.com/KingBain/status-lights/actions/workflows/pages.yml) |
| ↳ Validate site job | [![Validate site job status](https://g.statuslights.dev/github/KingBain/status-lights/pages.yml/job/Validate%20site/size/30/radius/5.svg)](https://github.com/KingBain/status-lights/actions/workflows/pages.yml) |
| ↳ Deploy site job | [![Deploy site job status](https://g.statuslights.dev/github/KingBain/status-lights/pages.yml/job/Deploy%20site/size/30/radius/5.svg)](https://github.com/KingBain/status-lights/actions/workflows/pages.yml) |
| PHP generator workflow | [![PHP generator workflow status](https://g.statuslights.dev/github/KingBain/status-lights/generator.yml/size/30/radius/5.svg)](https://github.com/KingBain/status-lights/actions/workflows/generator.yml) |
| ↳ Test with PHP 8.3 job | [![PHP 8.3 job status](https://g.statuslights.dev/github/KingBain/status-lights/generator.yml/job/Test%20with%20PHP%208.3/size/30/radius/5.svg)](https://github.com/KingBain/status-lights/actions/workflows/generator.yml) |
| ↳ Test with PHP 8.4 job | [![PHP 8.4 job status](https://g.statuslights.dev/github/KingBain/status-lights/generator.yml/job/Test%20with%20PHP%208.4/size/30/radius/5.svg)](https://github.com/KingBain/status-lights/actions/workflows/generator.yml) |
| ↳ Test with PHP 8.5 job | [![PHP 8.5 job status](https://g.statuslights.dev/github/KingBain/status-lights/generator.yml/job/Test%20with%20PHP%208.5/size/30/radius/5.svg)](https://github.com/KingBain/status-lights/actions/workflows/generator.yml) |

## Quick start

1. [Install the Status Lights GitHub App](https://github.com/apps/status-lights) and grant it access
   only to the public repositories you want to use.
2. Run the workflow on the repository's default branch. GitHub will send Status Lights the first
   workflow and job states.
3. Use the builder at [statuslights.dev](https://statuslights.dev/#customize) or write the URL
   directly.
4. Embed the SVG anywhere an image URL works.

```markdown
[![Pages status](https://g.statuslights.dev/github/KingBain/status-lights/pages.yml.svg)](https://github.com/KingBain/status-lights/actions/workflows/pages.yml)
```

The App asks for **Actions: read-only** access plus GitHub's automatic **Metadata: read-only**
permission. It has no repository write permission and never needs your personal access token. A
newly installed repository shows `unknown` until the selected workflow runs once after installation.

Status-light URLs are public and do not require authentication. Install the public service only on
public repositories. See the [getting-started guide](docs/getting-started.md) for workflow and job
examples, permission details, and troubleshooting.

## GitHub App architecture

Status Lights uses an installable GitHub App instead of request-time GitHub API polling. GitHub
sends `workflow_run` and `workflow_job` webhook events to the PHP backend, which stores the latest
state locally. SVG requests then read that local state rather than consuming GitHub REST API quota.

Every webhook is verified with GitHub's HMAC-SHA256 signature, and duplicate delivery IDs are
ignored to prevent replay. The App stores only the repository, workflow, job, run, delivery,
installation, and status metadata needed to serve the lights.

The App needs only read-only GitHub Actions access. It does not need repository write access or a
personal access token. Operators running their own instance can follow
[`docs/github-app-setup.md`](docs/github-app-setup.md) for registration, webhook, hosting, and
security configuration.

## Website

The public documentation site is [statuslights.dev](https://statuslights.dev). The static site lives
in [`site/`](site/) and is published with GitHub Pages after changes reach `main`.

The backend source lives in [`generator/`](generator/) and runs separately at
[`g.statuslights.dev`](https://g.statuslights.dev). Its canonical GitHub Actions routes remain:

```text
https://g.statuslights.dev/github/{owner}/{repository}/{workflow}.svg
https://g.statuslights.dev/github/{owner}/{repository}/{workflow}/job/{job-name}.svg
```

Installing the GitHub App does not change existing Status Lights URLs.

To view the documentation site locally:

```bash
python3 -m http.server 8000 --directory site
```

Then open <http://localhost:8000>.

## Validate

```bash
python3 scripts/validate-site.py
node --check site/script.js
find generator scripts -name '*.php' -print0 | xargs -0 -n1 php -l
php generator/tests/run.php
```

The PHP backend remains dependency-free. GitHub App runtime setup, environment-variable
configuration, maintenance, and URL options are documented in
[`generator/README.md`](generator/README.md).

## Project direction

- Free public access
- Installable GitHub App
- Webhook-driven GitHub Actions workflow and job state
- Open-source implementation
- URL-based configuration
- Custom text, size, font, corner radius, and state colours
- Small SVG output designed for Markdown dashboards

The public site and backend use separate hosts so the documentation can remain on GitHub Pages while
the PHP GitHub App backend runs on its application host.

## License

[MIT](LICENSE)
