#!/usr/bin/env python3

"""Small, dependency-free validation for the Status Lights static site."""

from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import unquote, urlparse


ROOT = Path(__file__).resolve().parents[1]
SITE = ROOT / "site"


class SiteParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.title_count = 0
        self.h1_count = 0
        self.description_count = 0
        self.in_head = False
        self.ids: set[str] = set()
        self.references: list[tuple[str, str]] = []

    def handle_starttag(
        self, tag: str, attrs: list[tuple[str, str | None]]
    ) -> None:
        values = dict(attrs)

        if tag == "head":
            self.in_head = True
        elif tag == "title" and self.in_head:
            self.title_count += 1
        elif tag == "h1":
            self.h1_count += 1
        elif tag == "meta" and values.get("name") == "description":
            self.description_count += 1

        if values.get("id"):
            self.ids.add(values["id"] or "")

        if tag in {"a", "link"} and values.get("href"):
            self.references.append((tag, values["href"] or ""))
        elif tag in {"img", "script", "source"} and values.get("src"):
            self.references.append((tag, values["src"] or ""))

    def handle_endtag(self, tag: str) -> None:
        if tag == "head":
            self.in_head = False


def local_path(reference: str, source: Path) -> Path | None:
    parsed = urlparse(reference)

    if parsed.scheme or parsed.netloc or reference.startswith(("#", "mailto:")):
        return None

    clean = unquote(parsed.path)

    if not clean:
        return None

    if clean.startswith("/"):
        path = SITE / clean.lstrip("/")
    else:
        path = source.parent / clean

    if path.is_dir():
        path = path / "index.html"

    return path


def validate_page(page: Path, required_ids: set[str] | None = None) -> list[str]:
    parser = SiteParser()
    parser.feed(page.read_text(encoding="utf-8"))
    name = str(page.relative_to(ROOT))
    problems: list[str] = []

    if parser.title_count != 1:
        problems.append(f"{name} must contain exactly one title")
    if parser.h1_count != 1:
        problems.append(f"{name} must contain exactly one h1")
    if parser.description_count != 1:
        problems.append(f"{name} must contain exactly one meta description")

    if required_ids:
        missing_ids = sorted(required_ids - parser.ids)
        if missing_ids:
            problems.append(f"{name} is missing required section IDs: {', '.join(missing_ids)}")

    for tag, reference in parser.references:
        path = local_path(reference, page)
        if path is not None and not path.exists():
            problems.append(f"{name}: {tag} references missing file: {reference}")

    return problems


def main() -> None:
    required_files = [
        SITE / "index.html",
        SITE / "docs" / "index.html",
        SITE / "styles.css",
        SITE / "script.js",
        SITE / "favicon.svg",
        SITE / "404.html",
    ]

    missing = [str(path.relative_to(ROOT)) for path in required_files if not path.is_file()]

    if missing:
        raise SystemExit("Missing required site files: " + ", ".join(missing))

    problems = validate_page(
        SITE / "index.html",
        {"top", "install", "how-it-works", "customize", "principles", "roadmap"},
    )
    problems.extend(
        validate_page(
            SITE / "docs" / "index.html",
            {
                "top",
                "getting-started",
                "install",
                "build-a-url",
                "embed",
                "url-reference",
                "troubleshooting",
                "self-hosting",
                "security",
            },
        )
    )

    if problems:
        raise SystemExit("Site validation failed:\n- " + "\n- ".join(problems))

    print("Status Lights site validation passed.")


if __name__ == "__main__":
    main()
