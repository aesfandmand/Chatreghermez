#!/usr/bin/env python3
"""Exact-route audit against the locked v4.2 route contract.

Implements the two checks the package performs on the home master
(V42-04 unknown exact routes, V42-06 trailing slash) so that any other
public page can be held to the same rule before it is published.

Scope freeze, "استثنای مجاز برای سلامت قرارداد Route":
    ناسازگاری میان لینک‌های عمومی، Architecture Model و Route Contract
    یک قابلیت جدید نیست؛ Blocker انتشار است.

Usage:
    tools/route_audit.py pages/services-urban-investment-v15.html
    tools/route_audit.py --json pages/*.html
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
SPEC = REPO / "spec" / "abs-v4.2"
ROUTE_MAP = SPEC / "abs_06_final_route_map_v4_2.json"
ARCH_MODEL = SPEC / "abs_05_final_architecture_model_v4_2.json"

HREF_RE = re.compile(r"""\shref\s*=\s*["']([^"']+)["']""", re.I)

# A path segment the contract writes as {family}, {product}, {city}… matches
# any single concrete segment.
PLACEHOLDER_RE = re.compile(r"\{[^/}]+\}")

# Routes carrying a parenthetical note, e.g. "/wp-json/…/rental/* (registered
# only by future module)" — the note is prose, not part of the path.
NOTE_RE = re.compile(r"\s*\(.*\)\s*$")


def _clean(route: str) -> str:
    return NOTE_RE.sub("", route.strip())


def _as_route(item) -> str | None:
    """Route entries are either bare strings or dicts with a 'route' key."""
    if isinstance(item, str):
        return _clean(item)
    if isinstance(item, dict):
        for key in ("route", "logical_url", "url"):
            if isinstance(item.get(key), str):
                return _clean(item[key])
    return None


def load_contract() -> tuple[set[str], list[re.Pattern]]:
    """Return (exact routes, patterns) declared by contract + architecture."""
    exact: set[str] = set()
    patterns: list[re.Pattern] = []

    def absorb(routes) -> None:
        for item in routes or []:
            route = _as_route(item)
            if not route or not route.startswith("/"):
                continue
            if "*" in route or PLACEHOLDER_RE.search(route):
                regex = "".join(
                    r"[^/]+" if PLACEHOLDER_RE.fullmatch(seg) else
                    r".*" if seg == "*" else re.escape(seg)
                    for seg in re.split(r"(\{[^/}]+\}|\*)", route) if seg
                )
                patterns.append(re.compile(rf"^{regex}$"))
            else:
                exact.add(route)

    route_map = json.loads(ROUTE_MAP.read_text(encoding="utf-8"))
    for key, value in route_map.items():
        if isinstance(value, list):
            absorb(value)

    arch = json.loads(ARCH_MODEL.read_text(encoding="utf-8"))
    absorb(arch.get("sitemap_pages"))

    return exact, patterns


def extract_hrefs(html: str) -> list[str]:
    return HREF_RE.findall(html)


def is_internal(href: str) -> bool:
    if href.startswith("/"):
        return True
    return not re.match(r"^(?:[a-z][a-z0-9+.-]*:|//|#)", href, re.I)


def normalise(href: str) -> str:
    """Strip query and fragment; keep the path exactly as authored."""
    return re.split(r"[?#]", href, maxsplit=1)[0]


def needs_trailing_slash(path: str) -> bool:
    """Content URLs end in '/'. Files with an extension are exempt."""
    if path in ("", "/"):
        return False
    last = path.rsplit("/", 1)[-1]
    return "." not in last


def audit(path: Path, exact: set[str], patterns: list[re.Pattern]) -> dict:
    html = path.read_text(encoding="utf-8", errors="replace")
    hrefs = extract_hrefs(html)

    internal, external, unknown, slash_violations = [], [], [], []
    for href in hrefs:
        if not is_internal(href):
            external.append(href)
            continue
        route = normalise(href)
        if not route:
            continue
        internal.append(route)
        if not route.startswith("/"):
            unknown.append(route)  # relative link: unresolvable against contract
            continue
        if needs_trailing_slash(route) and not route.endswith("/"):
            slash_violations.append(route)
        registered = route in exact or any(p.match(route) for p in patterns)
        if not registered:
            unknown.append(route)

    return {
        "file": str(path.relative_to(REPO)) if path.is_relative_to(REPO) else str(path),
        "total_hrefs": len(hrefs),
        "unique_internal_paths": len(set(internal)),
        "external_links": len(set(external)),
        "unknown_exact_routes": sorted(set(unknown)),
        "unknown_exact_route_count": len(set(unknown)),
        "trailing_slash_violations": sorted(set(slash_violations)),
        "trailing_slash_violation_count": len(set(slash_violations)),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("files", nargs="+", type=Path)
    parser.add_argument("--json", action="store_true", help="emit machine-readable output")
    parser.add_argument(
        "--limit", type=int, default=25,
        help="max offending routes to print per category (text mode)",
    )
    args = parser.parse_args()

    exact, patterns = load_contract()
    results = [audit(f, exact, patterns) for f in args.files]

    if args.json:
        print(json.dumps({"contract_exact_routes": len(exact),
                          "contract_patterns": len(patterns),
                          "results": results}, ensure_ascii=False, indent=2))
    else:
        print(f"contract: {len(exact)} exact routes, {len(patterns)} patterned\n")
        for r in results:
            verdict = "PASS" if not (r["unknown_exact_routes"] or
                                     r["trailing_slash_violations"]) else "BLOCKER"
            print(f"[{verdict}] {r['file']}")
            print(f"  {r['total_hrefs']} hrefs, {r['unique_internal_paths']} unique internal paths, "
                  f"{r['external_links']} external")
            for label, key in (("unregistered routes", "unknown_exact_routes"),
                               ("trailing-slash violations", "trailing_slash_violations")):
                items = r[key]
                if items:
                    print(f"  {len(items)} {label}:")
                    for item in items[:args.limit]:
                        print(f"    - {item}")
                    if len(items) > args.limit:
                        print(f"    … +{len(items) - args.limit} more")
            print()

    blocked = any(r["unknown_exact_routes"] or r["trailing_slash_violations"] for r in results)
    return 1 if blocked else 0


if __name__ == "__main__":
    sys.exit(main())
