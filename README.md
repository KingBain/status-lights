# Status Lights

Status Lights is an open-source, free public service for turning GitHub Actions workflow results
into compact, customizable SVG indicators.

This repository contains the public website and the PHP generator service.

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

## Website

The public documentation site is [statuslights.dev](https://statuslights.dev). The static site lives
in [`site/`](site/) and is published with GitHub Pages after changes reach `main`.

The generator source lives in [`generator/`](generator/) and runs separately at
[`g.statuslights.dev`](https://g.statuslights.dev). Its canonical GitHub Actions route is:

```text
https://g.statuslights.dev/github/{owner}/{repository}/{workflow}.svg
https://g.statuslights.dev/github/{owner}/{repository}/{workflow}/job/{job-name}.svg
```

The generator resolves the latest public workflow run on the repository's default branch. It can
render the whole workflow state or select an individual job by its display name.

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

The PHP generator is a single-file, dependency-free application. Generator setup, configuration,
URL options, and the cPanel pull deployment are documented in
[`generator/README.md`](generator/README.md).

## Project direction

- Free public access
- Open-source implementation
- GitHub Actions workflow status as the first provider
- URL-based configuration
- Custom text, size, font, corner radius, and state colours
- Small SVG output designed for Markdown dashboards

The public site and generator use separate hosts so the documentation can remain on GitHub Pages
while the PHP service runs on its application host.

## License

[MIT](LICENSE)
