# Status Lights

Status Lights is an open-source, free public service for turning GitHub Actions workflow results into compact, customizable SVG indicators.

This repository is starting with the public website. The status-light service itself will follow in later pull requests.

## Website

The static site lives in [`site/`](site/) and is published with GitHub Pages after changes reach `main`.

To view it locally:

```bash
python3 -m http.server 8000 --directory site
```

Then open <http://localhost:8000>.

## Validate

```bash
python3 scripts/validate-site.py
node --check site/script.js
```

## Project direction

- Free public access
- Open-source implementation
- GitHub Actions workflow status as the first provider
- URL-based configuration
- Custom text, size, font, corner radius, and state colours
- Small SVG output designed for Markdown dashboards

The website labels the service as under construction until a production endpoint is available.

## License

[MIT](LICENSE)

