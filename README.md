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

## GitHub App architecture

Status Lights is moving from request-time GitHub API polling to an installable GitHub App. GitHub
sends `workflow_run` and `workflow_job` webhook events to the PHP backend, which stores the latest
state locally. SVG requests then read that local state rather than consuming GitHub REST API quota.

The App needs only read-only GitHub Actions access. It does not need repository write access or a
personal access token. See [`docs/github-app-setup.md`](docs/github-app-setup.md) for registration,
permissions, webhook, hosting, and installation instructions.

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
find generator -name '*.php' -print0 | xargs -0 -n1 php -l
php generator/tests/run.php
bash -n scripts/cpanel-pull-deploy.sh
```

The PHP backend remains dependency-free. Generator setup, configuration, URL options, and the cPanel
pull deployment are documented in [`generator/README.md`](generator/README.md).

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
