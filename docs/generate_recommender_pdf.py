#!/usr/bin/env python3
"""Render the Mizo recommender guide to a styled, dependency-free PDF."""

from __future__ import annotations

import re
import sys
import textwrap
from pathlib import Path


PAGE_W, PAGE_H = 595.28, 841.89
LEFT, RIGHT, TOP, BOTTOM = 54.0, 54.0, 62.0, 52.0
CONTENT_W = PAGE_W - LEFT - RIGHT


def ascii_text(value: str) -> str:
    replacements = {
        "’": "'", "‘": "'", "“": '"', "”": '"', "—": "-", "–": "-",
        "×": "x", "≥": ">=", "≤": "<=", "→": "->", "•": "*",
        "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━": "",
    }
    for old, new in replacements.items():
        value = value.replace(old, new)
    value = re.sub(r"`([^`]*)`", r"\1", value)
    value = value.replace("**", "")
    return value.encode("cp1252", "replace").decode("cp1252")


def pdf_escape(value: str) -> str:
    raw = ascii_text(value).encode("cp1252", "replace")
    out = []
    for byte in raw:
        if byte in (40, 41, 92):
            out.append("\\" + chr(byte))
        elif byte < 32 or byte > 126:
            out.append(f"\\{byte:03o}")
        else:
            out.append(chr(byte))
    return "".join(out)


class Renderer:
    def __init__(self) -> None:
        self.pages: list[list[str]] = []
        self.commands: list[str] = []
        self.y = PAGE_H - TOP
        self.page_number = 0
        self.new_page()

    def new_page(self) -> None:
        if self.commands:
            self.pages.append(self.commands)
        self.page_number += 1
        self.commands = [
            "1 1 1 rg 0 0 595.28 841.89 re f",
            "0.04 0.36 0.62 rg 0 829.89 595.28 12 re f",
            self.text_command(LEFT, PAGE_H - 35, "ZO STREAM  |  AI RECOMMENDATION SYSTEM", "F1", 7.5, (0.42, 0.42, 0.42)),
            self.text_command(PAGE_W - RIGHT - 142, 28, f"Internal technical guide  |  Page {self.page_number}", "F1", 7.5, (0.42, 0.42, 0.42)),
        ]
        self.y = PAGE_H - TOP

    @staticmethod
    def text_command(x: float, y: float, text: str, font: str, size: float,
                     color: tuple[float, float, float]) -> str:
        r, g, b = color
        return f"BT /{font} {size:.2f} Tf {r:.3f} {g:.3f} {b:.3f} rg 1 0 0 1 {x:.2f} {y:.2f} Tm ({pdf_escape(text)}) Tj ET"

    def ensure(self, height: float) -> None:
        if self.y - height < BOTTOM:
            self.new_page()

    def line(self, text: str, *, font: str = "F1", size: float = 9.4,
             color: tuple[float, float, float] = (0.25, 0.25, 0.25),
             indent: float = 0, leading: float | None = None) -> None:
        leading = leading or size * 1.42
        self.ensure(leading)
        self.commands.append(self.text_command(LEFT + indent, self.y, text, font, size, color))
        self.y -= leading

    def paragraph(self, text: str, *, font: str = "F1", size: float = 9.4,
                  color: tuple[float, float, float] = (0.25, 0.25, 0.25),
                  indent: float = 0, first_prefix: str = "", gap: float = 5) -> None:
        usable = CONTENT_W - indent
        chars = max(24, int(usable / (size * (0.55 if font != "F3" else 0.60))))
        wrapped = textwrap.wrap(ascii_text(text), width=chars, break_long_words=False,
                                break_on_hyphens=False) or [""]
        for index, part in enumerate(wrapped):
            prefix = first_prefix if index == 0 else " " * len(first_prefix)
            self.line(prefix + part, font=font, size=size, color=color, indent=indent)
        self.y -= gap

    def heading(self, text: str, level: int) -> None:
        if level == 1:
            self.ensure(52)
            self.y -= 7
            self.line(text, font="F2", size=23, color=(0.055, 0.145, 0.25), leading=29)
            self.y -= 6
        elif level == 2:
            self.ensure(42)
            self.y -= 9
            self.line(text, font="F2", size=15.5, color=(0.04, 0.36, 0.62), leading=20)
            self.y -= 3
        else:
            self.ensure(32)
            self.y -= 6
            self.line(text, font="F2", size=11.2, color=(0.055, 0.145, 0.25), leading=15)
            self.y -= 2

    def rule(self) -> None:
        self.ensure(14)
        self.y -= 3
        self.commands.append(f"0.78 0.82 0.86 RG 0.6 w {LEFT:.2f} {self.y:.2f} m {PAGE_W-RIGHT:.2f} {self.y:.2f} l S")
        self.y -= 9

    def code(self, lines: list[str]) -> None:
        normalized: list[str] = []
        for line in lines:
            normalized.extend(textwrap.wrap(ascii_text(line), width=91, replace_whitespace=False,
                                            drop_whitespace=False) or [""])
        leading = 10.6
        index = 0
        while index < len(normalized):
            available = int((self.y - BOTTOM - 18) / leading)
            if available < 2:
                self.new_page()
                available = int((self.y - BOTTOM - 18) / leading)
            chunk = normalized[index:index + available]
            height = len(chunk) * leading + 14
            self.commands.append(f"0.95 0.96 0.97 rg {LEFT:.2f} {self.y-height+5:.2f} {CONTENT_W:.2f} {height:.2f} re f")
            self.y -= 8
            for item in chunk:
                self.line(item, font="F3", size=7.7, color=(0.055, 0.145, 0.25), indent=8, leading=leading)
            self.y -= 7
            index += len(chunk)

    def finish(self) -> list[list[str]]:
        if self.commands:
            self.pages.append(self.commands)
            self.commands = []
        return self.pages


def render(markdown: str) -> list[list[str]]:
    renderer = Renderer()
    in_code = False
    code_lines: list[str] = []
    for raw in markdown.splitlines():
        line = raw.strip()
        if line.startswith("```"):
            if in_code:
                renderer.code(code_lines)
                code_lines = []
            in_code = not in_code
            continue
        if in_code:
            code_lines.append(raw.rstrip())
        elif line == "---":
            renderer.rule()
        elif line.startswith("# "):
            renderer.heading(line[2:], 1)
        elif line.startswith("## "):
            renderer.heading(line[3:], 2)
        elif line.startswith("### "):
            renderer.heading(line[4:], 3)
        elif line.startswith("- "):
            renderer.paragraph(line[2:], indent=12, first_prefix="- ", gap=1.5)
        elif re.match(r"^\d+\.\s", line):
            renderer.paragraph(line, indent=12, gap=2)
        elif line.startswith("> "):
            renderer.paragraph('"' + line[2:] + '"', font="F4", size=9.8,
                               color=(0.04, 0.36, 0.62), indent=13, gap=7)
        elif not line:
            renderer.y -= 3
        else:
            renderer.paragraph(line)
    if code_lines:
        renderer.code(code_lines)
    return renderer.finish()


def make_pdf(pages: list[list[str]], output: Path) -> None:
    objects: list[bytes | None] = [None]

    def reserve() -> int:
        objects.append(None)
        return len(objects) - 1

    def add(data: bytes) -> int:
        objects.append(data)
        return len(objects) - 1

    catalog_id = reserve()
    pages_id = reserve()
    font_regular = add(b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>")
    font_bold = add(b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>")
    font_mono = add(b"<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>")
    font_oblique = add(b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>")
    page_ids: list[int] = []
    for commands in pages:
        stream = ("\n".join(commands) + "\n").encode("latin1")
        content_id = add(f"<< /Length {len(stream)} >>\nstream\n".encode() + stream + b"endstream")
        page_data = (
            f"<< /Type /Page /Parent {pages_id} 0 R /MediaBox [0 0 {PAGE_W:.2f} {PAGE_H:.2f}] "
            f"/Resources << /Font << /F1 {font_regular} 0 R /F2 {font_bold} 0 R "
            f"/F3 {font_mono} 0 R /F4 {font_oblique} 0 R >> >> /Contents {content_id} 0 R >>"
        ).encode()
        page_ids.append(add(page_data))
    objects[pages_id] = f"<< /Type /Pages /Count {len(page_ids)} /Kids [{' '.join(f'{i} 0 R' for i in page_ids)}] >>".encode()
    objects[catalog_id] = f"<< /Type /Catalog /Pages {pages_id} 0 R >>".encode()

    info_id = add(
        b"<< /Title (Zo Stream AI Recommendation System - Mizo) "
        b"/Author (Zo Stream Engineering) /Subject (Recommendation architecture and workflow) >>"
    )
    payload = bytearray(b"%PDF-1.4\n%\xe2\xe3\xcf\xd3\n")
    offsets = [0]
    for number, obj in enumerate(objects[1:], start=1):
        assert obj is not None
        offsets.append(len(payload))
        payload.extend(f"{number} 0 obj\n".encode())
        payload.extend(obj)
        payload.extend(b"\nendobj\n")
    xref = len(payload)
    payload.extend(f"xref\n0 {len(objects)}\n".encode())
    payload.extend(b"0000000000 65535 f \n")
    for offset in offsets[1:]:
        payload.extend(f"{offset:010d} 00000 n \n".encode())
    payload.extend(
        f"trailer\n<< /Size {len(objects)} /Root {catalog_id} 0 R /Info {info_id} 0 R >>\n"
        f"startxref\n{xref}\n%%EOF\n".encode()
    )
    output.write_bytes(payload)


def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit("Usage: generate_recommender_pdf.py input.md output.pdf")
    source = Path(sys.argv[1])
    output = Path(sys.argv[2])
    pages = render(source.read_text(encoding="utf-8"))
    make_pdf(pages, output)
    print(f"Generated {output} with {len(pages)} pages")


if __name__ == "__main__":
    main()
