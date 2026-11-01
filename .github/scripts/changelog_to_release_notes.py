#!/usr/bin/env python3
"""
changelog_to_release_notes.py

Reads a git-log style CHANGELOG.md and writes a customer-facing RELEASE_NOTES.md
containing only the last N versions, grouped under New / Improved / Fixed,
with internal/noise lines removed.

Supported input line formats (one entry per line, prefix "-" or "•" optional):
    - [2026-09-04] fix: carry the calculator's fields into 1.6's add-to-cart request (6.6.27)
    - [2026-10-21] 6.6.57: show the entered size on every step of the order
    - [2026-08-27] Fix fatal error on install/load ...            <- no version: inherits current section
Version section headers are also recognised:
    ## 6.6.27 — 2026-09-04      |  **6.6.27** 2026-09-04   |  ## [6.6.27] - 2026-09-04   |  v6.6.27

Usage:
    python3 changelog_to_release_notes.py CHANGELOG.md RELEASE_NOTES.md [--versions 3] [--title "Price by Surface"]

Exit code 0 on success, 1 on unreadable input, 2 when no versions were detected.
"""

from __future__ import annotations

import argparse
import re
import sys
from collections import OrderedDict
from dataclasses import dataclass, field
from pathlib import Path

# --------------------------------------------------------------------------- regexes

# semantic version: 6.6.27 / 6.6.27-beta.1 / v6.6.27
VERSION_RE = re.compile(r"(?<![\d.])v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.]+)?)(?![\d.])")
# date in [YYYY-MM-DD] or bare YYYY-MM-DD
DATE_RE = re.compile(r"(\d{4}-\d{2}-\d{2})")
# an entry line: optional bullet, optional [date], message
ENTRY_RE = re.compile(r"^\s*(?:[-*•]\s*)?(?:\[(\d{4}-\d{2}-\d{2})\]\s*)?(.+?)\s*$")
# version header lines (markdown heading, bold, or bare "6.6.27 — date")
HEADER_RE = re.compile(
    r"^\s*(?:#{1,6}\s*|\*\*)?\[?v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.]+)?)\]?\*{0,2}"
    r"\s*(?:[—–\-:]\s*|\(|\s)?\s*(\d{4}-\d{2}-\d{2})?\)?\s*$"
)
# trailing "(6.6.27)" or leading "6.6.57:" version tag inside a message
TRAIL_VER_RE = re.compile(r"\s*\(v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.]+)?)\)\s*$")
LEAD_VER_RE = re.compile(r"^v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.]+)?)\s*[:\-—]\s*")
# conventional-commit prefix: fix(scope)!: / feat: / chore:
CC_PREFIX_RE = re.compile(r"^(?P<type>[a-zA-Z]+)(?:\([^)]*\))?!?:\s*(?P<rest>.+)$")

# --------------------------------------------------------------------------- noise filter

# Any line matching one of these (case-insensitive) is dropped from customer notes.
NOISE_PATTERNS = [
    r"^chore\b",                # chore: ...
    r"^test\b",                 # test: ...
    r"^docs?\b",                # docs: ... (internal docs; README bumps)
    r"^ci\b", r"^build\b", r"^style\b", r"^refactor\b",
    r"\breadme\b",
    r"\bdebug\b",
    r"\bclaude\b", r"\bcursor\b", r"\bcopilot\b", r"\bchatgpt\b",
    r"\bcurl\b",
    r"\bstop tracking\b",
    r"^temp\b", r"\btemp:\b", r"\btemporary\b", r"\bwip\b",
    r"^revert\b", r"\brevert:\b",
    r"\bversion number\b", r"\bbump version\b", r"\bupdate (module )?version\b",
    r"\bmerge (branch|pull request)\b",
    r"\bworking files\b", r"\bgitignore\b", r"\bp\d+\.html\b",
    r"\bchangelog\b",            # "update changelog" lines
    r"\broot cause\b",           # post-mortem notes
    r"\blint\b", r"\bphpstan\b", r"\bphp-cs\b", r"\bphpcs\b", r"\bdocblocks?\b",
    # own cross-promotion and developer-only lines (A-icerik/03)
    r"\bpromo\b", r"cross-promo", r"\bads widget\b", r"\breview nudge\b",
    r"\bexception\b", r"console\.log", r"\bdd\(",
]
NOISE_RE = re.compile("|".join(f"(?:{p})" for p in NOISE_PATTERNS), re.IGNORECASE)

# Internal jargon that should not reach a customer even in otherwise valid lines.
# If a line contains one of these, it is dropped (conservative).
INTERNAL_JARGON_RE = re.compile(
    r"(500'd|\b500s\b|\bfatals?\b|\bstack ?trace\b|\bvar_dump\b|\bshim\b|\bredeclaration\b|"
    r"\$[\w.(]|::|->|\bsprintf\b|\bfmod\b|\bDI preference\b|\bdata-form-part\b|\bWHERE clauses?\b|PS ?1\.[67]-only|\b1\.[67]-only|\bhook(Display|Action)\w+|"
    r"\.tpl\b|\.php\b|\.js\b|\.scss\b|\bclass\b|\bmethod\b|\bnullable\b|\barray\b|"
    r"\bupgrade script\b|\boverrides?\b|\bcontroller\b|\bwidget class\b)",
    re.IGNORECASE,
)

# --------------------------------------------------------------------------- classification

NEW_RE = re.compile(r"^(feat|feature|add|added|new|introduce|introduced|implement|implemented|support|first release|initial release)\b", re.I)
FIXED_RE = re.compile(r"^(fix|fixed|fixes|bugfix|hotfix|resolve|resolved|correct|corrected|repair|guard|prevent)\b", re.I)
IMPROVED_RE = re.compile(r"^(improve|improved|perf|enhance|enhanced|update|updated|refresh|rename|renamed|polish|redesign|show|keep|bill|hide|remove|removed|replace|replaced|optimi[sz]e|speed)\b", re.I)


def classify(message: str, cc_type: str | None) -> str:
    """Return 'New', 'Improved' or 'Fixed'."""
    if cc_type:
        t = cc_type.lower()
        if t in ("feat", "feature"):
            return "New"
        if t in ("fix", "bugfix", "hotfix"):
            return "Fixed"
        if t in ("perf", "improve", "enhance"):
            return "Improved"
    if FIXED_RE.match(message):
        return "Fixed"
    if NEW_RE.match(message):
        return "New"
    if IMPROVED_RE.match(message):
        return "Improved"
    # fallback: anything mentioning error/crash/broken is a fix
    if re.search(r"\b(error|crash|broken|fatal|bug|wrong|incorrect|missing)\b", message, re.I):
        return "Fixed"
    return "Improved"


def humanize(message: str) -> str:
    """Strip prefixes/tags and make the first letter upper-case."""
    msg = message.strip()
    msg = TRAIL_VER_RE.sub("", msg)
    msg = LEAD_VER_RE.sub("", msg)
    cc_type = None
    m = CC_PREFIX_RE.match(msg)
    if m:
        cc_type = m.group("type")
        msg = m.group("rest")
    msg = re.sub(r"^(Fix|Fixed|Add|Added|Update|Updated)\s*[:\-]\s*", lambda mm: mm.group(1) + " ", msg)
    msg = msg.strip().rstrip(".")
    if msg:
        msg = msg[0].upper() + msg[1:]
    return msg, cc_type


# --------------------------------------------------------------------------- data model

@dataclass
class Version:
    number: str
    date: str | None = None
    entries: "OrderedDict[str, list[str]]" = field(default_factory=lambda: OrderedDict((k, []) for k in ("New", "Improved", "Fixed")))
    dropped: list[str] = field(default_factory=list)

    def key(self) -> tuple:
        core = self.number.split("-")[0].split("+")[0]
        parts = tuple(int(p) for p in core.split("."))
        pre = self.number[len(core):]
        # pre-release sorts before the final release
        return parts + ((0,) if pre else (1,))

    def is_empty(self) -> bool:
        return not any(self.entries.values())


# --------------------------------------------------------------------------- parsing

def has_headers(text: str) -> bool:
    """True when the changelog has version header lines (## 6.6.27 / **6.6.27** date)."""
    for raw in text.splitlines():
        h = HEADER_RE.match(raw.rstrip())
        if h and not ENTRY_RE.match(raw.rstrip()).group(2).lower().startswith(("fix", "feat", "chore")):
            return True
    return False


def parse_changelog(text: str, inline_versions: bool | None = None) -> "OrderedDict[str, Version]":
    """
    inline_versions=None  -> auto: use "(x.y.z)" tags for grouping only when the file has no headers
    inline_versions=True  -> every "(x.y.z)" / "x.y.z:" tag is its own version (patch-level notes)
    inline_versions=False -> tags are stripped, entries belong to the section header (release-level notes)
    """
    if inline_versions is None:
        inline_versions = not has_headers(text)
    versions: "OrderedDict[str, Version]" = OrderedDict()
    current: Version | None = None

    def get(num: str, date: str | None) -> Version:
        v = versions.get(num)
        if v is None:
            v = Version(num, date)
            versions[num] = v
        elif date and not v.date:
            v.date = date
        return v

    for raw in text.splitlines():
        line = raw.rstrip()
        if not line.strip():
            continue

        h = HEADER_RE.match(line)
        if h and not ENTRY_RE.match(line).group(2).lower().startswith(("fix", "feat", "chore")):
            current = get(h.group(1), h.group(2))
            continue

        e = ENTRY_RE.match(line)
        if not e:
            continue
        date, message = e.group(1), e.group(2)
        if not message or message.startswith("#"):
            continue

        # explicit version inside the message wins over the section
        ver = None
        if inline_versions:
            tm = TRAIL_VER_RE.search(message)
            lm = LEAD_VER_RE.match(message)
            if tm:
                ver = tm.group(1)
            elif lm:
                ver = lm.group(1)

        target = get(ver, date) if ver else current
        if target is None:
            # entries before any header or version tag: park under "unreleased"
            target = get("unreleased", date)
        if date and not target.date:
            target.date = date

        clean, cc_type = humanize(message)
        if not clean:
            continue
        if NOISE_RE.search(message) or NOISE_RE.search(clean) or INTERNAL_JARGON_RE.search(clean):
            target.dropped.append(message)
            continue
        bucket = classify(clean, cc_type)
        if clean not in target.entries[bucket]:
            target.entries[bucket].append(clean)

    return versions


# --------------------------------------------------------------------------- rendering

def render(versions: list[Version], title: str | None) -> str:
    out: list[str] = []
    out.append(f"# {title} — Release notes" if title else "# Release notes")
    out.append("")
    out.append("_Only customer-relevant changes are listed. Internal refactoring, tests and housekeeping are omitted._")
    out.append("")
    for v in versions:
        head = f"## {v.number}"
        if v.date:
            head += f" — {v.date}"
        out.append(head)
        out.append("")
        for section in ("New", "Improved", "Fixed"):
            items = v.entries[section]
            if not items:
                continue
            out.append(f"### {section}")
            for it in items:
                out.append(f"- {it}")
            out.append("")
    return "\n".join(out).rstrip() + "\n"


# --------------------------------------------------------------------------- Keep-a-Changelog input

# Keep-a-Changelog sections that reach the customer -> conventional-commit type that classify()
# already maps. Any other "###" section (Notes, Quality, Tests, Known issues ...) is developer
# material: in a version block that has "###" sections, only entries under these are kept.
KAC_SECTION_TYPES = {
    "added": "feat",
    "changed": "improve", "deprecated": "improve", "removed": "improve",
    "fixed": "fix", "security": "fix",
}
# "### Fixed", "### Added (1.0.1)", "### Fixed — security"; not "### Fixed during testing"
KAC_SECTION_RE = re.compile(r"^\s*###\s*(\w+)\s*(?:[(\[—–\-/:,].*)?$")
SUBHEADING_RE = re.compile(r"^\s*###(?!#)")
BLOCK_HEADING_RE = re.compile(r"^\s*#{1,2}(?!#)")
BULLET_START_RE = re.compile(r"^\s*[-*•]\s+")


def is_block_start(line: str) -> bool:
    """A version header or a top-level (# / ##) heading starts a new version block."""
    return bool(HEADER_RE.match(line) or BLOCK_HEADING_RE.match(line))


def strip_emphasis(line: str) -> str:
    """Remove **bold**, __bold__ and *italic* markers; ENTRY_RE would eat a lone leading '*'."""
    line = line.replace("**", "").replace("__", "")
    return re.sub(r"(?<![\w*])\*(?=\S)([^*\n]+?)(?<=\S)\*(?![\w*])", r"\1", line)


def flatten_keep_a_changelog(text: str, skipped: list | None = None) -> str:
    """
    Pre-pass for hand-written Keep-a-Changelog files, so the line-based parser above sees one
    entry per line: a wrapped bullet is joined back onto its first line, a paragraph becomes
    one entry, tables and fenced code are dropped, and entries under "### Added / Fixed /
    Changed ..." get the matching "feat: / fix: / improve:" prefix so classify() files them
    under the heading the author chose. In a version block that has "###" sections, entries
    outside the KAC_SECTION_TYPES sections are left out and appended to `skipped` as
    (version, entry). Git-log style input passes through unchanged.
    """
    lines = text.splitlines()
    # version blocks that use "###" sections at all; only those are filtered by section
    sectioned: set[int] = set()
    block = 0
    in_fence = False
    for raw in lines:
        # same fence handling as below, so both passes number the blocks alike
        if raw.lstrip().startswith("```"):
            in_fence = not in_fence
            continue
        if in_fence or not raw.strip():
            continue
        if is_block_start(raw.rstrip()):
            block += 1
        elif SUBHEADING_RE.match(raw):
            sectioned.add(block)

    out: list[str] = []
    section_type: str | None = None
    open_entry = False      # last line in `out` (or `skipped`) is an entry that may continue
    in_fence = False
    skipping = False        # inside a section whose entries are left out
    block = 0
    version = "unreleased"
    for raw in lines:
        line = raw.rstrip()
        if line.lstrip().startswith("```"):
            in_fence = not in_fence
            open_entry = False
            continue
        if in_fence or line.lstrip().startswith("|"):
            open_entry = False
            continue
        if not line.strip():
            open_entry = False
            out.append("")
            continue
        if is_block_start(line):
            block += 1
            h = HEADER_RE.match(line)
            version = h.group(1) if h else "unreleased"
            section_type = None
            # text before the block's first "###" is in no kept section
            skipping = block in sectioned
            open_entry = False
            out.append(line)
            continue
        if line.lstrip().startswith("#"):
            # any heading closes the previous section; only a known one opens a new type
            m = KAC_SECTION_RE.match(line)
            section_type = KAC_SECTION_TYPES.get(m.group(1).lower()) if m else None
            skipping = block in sectioned and section_type is None
            open_entry = False
            out.append(line)
            continue
        is_bullet = bool(BULLET_START_RE.match(line))
        body = BULLET_START_RE.sub("", line, count=1).strip()
        if skipping:
            if skipped is not None:
                if open_entry and not is_bullet:
                    skipped[-1] = (version, f"{skipped[-1][1]} {body}")
                else:
                    skipped.append((version, body))
            open_entry = True
            continue
        if open_entry and not is_bullet:
            out[-1] = f"{out[-1]} {body}"
            continue
        if section_type:
            # the bucket comes from the heading, whatever the entry itself starts with
            body = f"{section_type}: {body}"
        out.append(f"- {body}")
        open_entry = True
    # after joining, so emphasis that spans a wrapped line is removed too
    return "\n".join(strip_emphasis(line) for line in out)


# --------------------------------------------------------------------------- main

def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description="Convert git-log CHANGELOG.md to customer RELEASE_NOTES.md")
    ap.add_argument("changelog", type=Path)
    ap.add_argument("output", type=Path)
    ap.add_argument("--versions", type=int, default=3, help="how many latest versions to keep (default 3)")
    ap.add_argument("--title", default=None, help="product name for the H1")
    ap.add_argument("--keep-empty", action="store_true", help="keep versions whose entries were all filtered out")
    grp = ap.add_mutually_exclusive_group()
    grp.add_argument("--inline-versions", dest="inline", action="store_true", default=None,
                     help="treat every '(x.y.z)' tag as its own version (patch-level notes)")
    grp.add_argument("--header-versions", dest="inline", action="store_false",
                     help="group entries by section header only (release-level notes)")
    ap.add_argument("--verbose", action="store_true", help="print dropped lines to stderr")
    args = ap.parse_args(argv)

    try:
        text = args.changelog.read_text(encoding="utf-8", errors="replace")
    except OSError as exc:
        print(f"error: cannot read {args.changelog}: {exc}", file=sys.stderr)
        return 1

    skipped: list[tuple[str, str]] = []
    versions = parse_changelog(flatten_keep_a_changelog(text, skipped), inline_versions=args.inline)
    for num, entry in skipped:
        if num in versions:
            versions[num].dropped.append(strip_emphasis(entry))
    versions.pop("unreleased", None)
    if not versions:
        print("error: no version numbers detected (expected x.y.z)", file=sys.stderr)
        return 2

    ordered = sorted(versions.values(), key=Version.key, reverse=True)
    if not args.keep_empty:
        ordered = [v for v in ordered if not v.is_empty()]
    latest = ordered[: args.versions]

    if args.verbose:
        for v in latest:
            for d in v.dropped:
                print(f"[dropped {v.number}] {d}", file=sys.stderr)

    args.output.write_text(render(latest, args.title), encoding="utf-8")
    kept = sum(len(x) for v in latest for x in v.entries.values())
    dropped = sum(len(v.dropped) for v in latest)
    print(f"wrote {args.output}: {len(latest)} version(s), {kept} line(s) kept, {dropped} dropped")
    return 0


if __name__ == "__main__":
    sys.exit(main())
