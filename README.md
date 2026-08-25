# Status Lights

Status Lights is an open-source, free public service for turning GitHub Actions workflow results
into compact, customizable SVG indicators.

This repository contains the public website and the PHP generator service.

## Website

The public documentation site is [statuslights.dev](https://statuslights.dev).
The static site lives in [`site/`](site/) and is published with GitHub Pages after changes reach `main`.

The generator source lives in [`generator/`](generator/) and is designed to run separately at
`g.statuslights.dev`. Its canonical GitHub
Actions route will be:

```text
https://g.statuslights.dev/github/{owner}/{repository}/{workflow}.svg
```

The generator resolves the latest public workflow run, caches the state, and renders the result as
an SVG. Until the service is deployed, the website continues to label generated URLs as planned.

To view it locally:

```bash
python3 -m http.server 8000 --directory site
```

Then open <http://localhost:8000>.

## Validate

```bash
python3 scripts/validate-site.py
node --check site/script.js
find generator/public generator/src generator/tests generator/router.php -name '*.php' -print0 | xargs -0 -n1 php -l
php generator/tests/run.php
```

Generator setup, configuration, URL options, and cPanel deployment instructions are documented in
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
