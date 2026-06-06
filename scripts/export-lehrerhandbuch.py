#!/usr/bin/env python3
from __future__ import annotations

import argparse
import datetime as dt
import json
import re
import sys
import textwrap
import zipfile
from pathlib import Path
from typing import Iterable
from xml.etree import ElementTree as ET


NS_W = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
NS_R = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
NS_RELS = "http://schemas.openxmlformats.org/package/2006/relationships"
NS_WP = "http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
NS_A = "http://schemas.openxmlformats.org/drawingml/2006/main"
NS_PIC = "http://schemas.openxmlformats.org/drawingml/2006/picture"
NS_CP = "http://schemas.openxmlformats.org/package/2006/metadata/core-properties"
NS_DC = "http://purl.org/dc/elements/1.1/"
NS_DCTERMS = "http://purl.org/dc/terms/"
NS_DCMITYPE = "http://purl.org/dc/dcmitype/"
NS_XSI = "http://www.w3.org/2001/XMLSchema-instance"
NS_VT = "http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"

for prefix, uri in {
    "w": NS_W,
    "r": NS_R,
    "wp": NS_WP,
    "a": NS_A,
    "pic": NS_PIC,
    "cp": NS_CP,
    "dc": NS_DC,
    "dcterms": NS_DCTERMS,
    "dcmitype": NS_DCMITYPE,
    "xsi": NS_XSI,
    "vt": NS_VT,
}.items():
    ET.register_namespace(prefix, uri)


def w(tag: str) -> str:
    return f"{{{NS_W}}}{tag}"


def r(tag: str) -> str:
    return f"{{{NS_R}}}{tag}"


def xml_to_bytes(element: ET.Element) -> bytes:
    return ET.tostring(element, encoding="utf-8", xml_declaration=True)


def read_version(project_root: Path) -> str:
    version_file = project_root / "version.json"
    if not version_file.exists():
        return "1.00"
    data = json.loads(version_file.read_text(encoding="utf-8"))
    if "version" in data:
        return str(data["version"])
    return f"{int(data.get('a', 1))}.{int(data.get('b', 0)):02d}"


def parse_inline(text: str) -> list[tuple[str, bool]]:
    parts: list[tuple[str, bool]] = []
    cursor = 0
    for match in re.finditer(r"\*\*(.+?)\*\*", text):
        if match.start() > cursor:
            parts.append((text[cursor:match.start()], False))
        parts.append((match.group(1), True))
        cursor = match.end()
    if cursor < len(text):
        parts.append((text[cursor:], False))
    if not parts:
        parts.append((text, False))
    return parts


def strip_md(text: str) -> str:
    text = re.sub(r"\*\*(.+?)\*\*", r"\1", text)
    text = re.sub(r"\[(.+?)\]\((.+?)\)", r"\1", text)
    return text.strip()


class Block(dict):
    pass


def parse_markdown(md_text: str, base_dir: Path) -> list[Block]:
    lines = md_text.splitlines()
    blocks: list[Block] = []
    i = 0

    heading_re = re.compile(r"^(#{1,6})\s+(.*)$")
    unordered_re = re.compile(r"^(\s*)-\s+(.*)$")
    ordered_re = re.compile(r"^(\s*)(\d+)\.\s+(.*)$")
    screenshot_re = re.compile(r"^\[Screenshot:\s*(.+?)\]\s*$")
    image_re = re.compile(r"^!\[(.*?)\]\((.+?)\)\s*$")

    while i < len(lines):
        line = lines[i]
        stripped = line.strip()

        if not stripped:
            i += 1
            continue

        if stripped == "---":
            blocks.append(Block(type="separator"))
            i += 1
            continue

        heading = heading_re.match(line)
        if heading:
            level = len(heading.group(1))
            text = heading.group(2).strip()
            blocks.append(Block(type="heading", level=level, text=text))
            i += 1
            continue

        screenshot = screenshot_re.match(stripped)
        if screenshot:
            blocks.append(Block(type="screenshot", text=screenshot.group(1).strip()))
            i += 1
            continue

        image = image_re.match(stripped)
        if image:
            alt_text = image.group(1).strip() or "Screenshot"
            raw_target = image.group(2).strip()
            image_path = (base_dir / raw_target).resolve()
            blocks.append(Block(type="image", alt=alt_text, path=str(image_path)))
            i += 1
            continue

        unordered = unordered_re.match(line)
        ordered = ordered_re.match(line)
        if unordered or ordered:
            list_type = "bullet" if unordered else "number"
            items: list[Block] = []
            current: Block | None = None
            while i < len(lines):
                raw = lines[i]
                if not raw.strip():
                    next_line = lines[i + 1] if i + 1 < len(lines) else ""
                    next_is_same_list_item = bool(
                        (list_type == "bullet" and unordered_re.match(next_line))
                        or (list_type == "number" and ordered_re.match(next_line))
                    )
                    next_is_continuation = bool(
                        next_line
                        and next_line[:1].isspace()
                        and not heading_re.match(next_line)
                        and not unordered_re.match(next_line)
                        and not ordered_re.match(next_line)
                    )
                    if next_is_same_list_item:
                        i += 1
                        continue
                    if heading_re.match(next_line) or not next_is_continuation:
                        i += 1
                        break
                    if current is not None:
                        current["text"] += "\n"
                    i += 1
                    continue
                heading = heading_re.match(raw)
                if heading:
                    break
                ul = unordered_re.match(raw)
                ol = ordered_re.match(raw)
                if ul or ol:
                    marker_type = "bullet" if ul else "number"
                    if marker_type != list_type and current is None:
                        list_type = marker_type
                    indent = len((ul or ol).group(1).replace("\t", "    "))
                    level = indent // 2
                    text = (ul or ol).group(2 if ul else 3).strip()
                    current = Block(type=marker_type, level=level, text=text)
                    items.append(current)
                    i += 1
                    continue
                if current is not None:
                    leading_ws = len(raw) - len(raw.lstrip(" \t"))
                    if leading_ws == 0:
                        break
                    addition = raw.strip()
                    if addition:
                        separator = " – " if "\n" not in current["text"] and not current["text"].rstrip().endswith((".", ":", ";", "?", "!")) else " "
                        current["text"] = current["text"].rstrip() + separator + addition
                    i += 1
                    continue
                break
            blocks.append(Block(type="list", list_type=list_type, items=items))
            continue

        paragraph_lines = [stripped]
        i += 1
        while i < len(lines):
            nxt = lines[i]
            nxt_stripped = nxt.strip()
            if not nxt_stripped:
                i += 1
                break
            if nxt_stripped == "---" or heading_re.match(nxt) or unordered_re.match(nxt) or ordered_re.match(nxt) or screenshot_re.match(nxt_stripped):
                break
            paragraph_lines.append(nxt_stripped)
            i += 1
        paragraph_text = " ".join(paragraph_lines).strip()
        style = "paragraph"
        if re.fullmatch(r"\*\*.+?\*\*", paragraph_text):
            style = "label"
        elif paragraph_text.startswith("Wichtig:") or paragraph_text.startswith("Wichtiger Hinweis"):
            style = "warning"
        elif paragraph_text.startswith("Tipp:") or paragraph_text.startswith("Hinweis:"):
            style = "tip"
        blocks.append(Block(type="paragraph", text=paragraph_text, style=style))

    return blocks


def png_dimensions(image_path: Path) -> tuple[int, int]:
    with image_path.open("rb") as fh:
        header = fh.read(24)
    if len(header) < 24 or header[:8] != b"\x89PNG\r\n\x1a\n":
        raise ValueError(f"Nur PNG-Bilder werden aktuell unterstützt: {image_path}")
    width = int.from_bytes(header[16:20], "big")
    height = int.from_bytes(header[20:24], "big")
    return width, height


def add_text_run(paragraph: ET.Element, text: str, *, bold: bool = False, italic: bool = False, color: str | None = None, size_half_points: int | None = None) -> None:
    if text == "":
        return
    run = ET.SubElement(paragraph, w("r"))
    rpr = ET.SubElement(run, w("rPr"))
    if bold:
        ET.SubElement(rpr, w("b"))
    if italic:
        ET.SubElement(rpr, w("i"))
    if color:
        ET.SubElement(rpr, w("color"), {w("val"): color})
    if size_half_points:
        ET.SubElement(rpr, w("sz"), {w("val"): str(size_half_points)})
        ET.SubElement(rpr, w("szCs"), {w("val"): str(size_half_points)})
    for idx, piece in enumerate(text.split("\n")):
        if idx:
            ET.SubElement(run, w("br"))
        t = ET.SubElement(run, w("t"))
        if piece.startswith(" ") or piece.endswith(" ") or "  " in piece:
            t.set("{http://www.w3.org/XML/1998/namespace}space", "preserve")
        t.text = piece


def make_paragraph(
    text: str = "",
    *,
    style: str | None = None,
    page_break_before: bool = False,
    keep_next: bool = False,
    outline_level: int | None = None,
    num_id: int | None = None,
    ilvl: int = 0,
    align: str | None = None,
) -> ET.Element:
    p = ET.Element(w("p"))
    ppr = ET.SubElement(p, w("pPr"))
    if style:
        ET.SubElement(ppr, w("pStyle"), {w("val"): style})
    if page_break_before:
        ET.SubElement(ppr, w("pageBreakBefore"))
    if keep_next:
        ET.SubElement(ppr, w("keepNext"))
    if outline_level is not None:
        ET.SubElement(ppr, w("outlineLvl"), {w("val"): str(outline_level)})
    if align:
        ET.SubElement(ppr, w("jc"), {w("val"): align})
    if num_id is not None:
        num_pr = ET.SubElement(ppr, w("numPr"))
        ET.SubElement(num_pr, w("ilvl"), {w("val"): str(ilvl)})
        ET.SubElement(num_pr, w("numId"), {w("val"): str(num_id)})

    if text:
        for fragment, is_bold in parse_inline(text):
            add_text_run(p, fragment, bold=is_bold)
    return p


def make_field_paragraph(instruction: str, placeholder: str, *, style: str | None = None) -> ET.Element:
    p = ET.Element(w("p"))
    ppr = ET.SubElement(p, w("pPr"))
    if style:
        ET.SubElement(ppr, w("pStyle"), {w("val"): style})

    begin_run = ET.SubElement(p, w("r"))
    ET.SubElement(begin_run, w("fldChar"), {w("fldCharType"): "begin"})

    instr_run = ET.SubElement(p, w("r"))
    instr_text = ET.SubElement(instr_run, w("instrText"))
    instr_text.set("{http://www.w3.org/XML/1998/namespace}space", "preserve")
    instr_text.text = instruction

    sep_run = ET.SubElement(p, w("r"))
    ET.SubElement(sep_run, w("fldChar"), {w("fldCharType"): "separate"})

    add_text_run(p, placeholder)

    end_run = ET.SubElement(p, w("r"))
    ET.SubElement(end_run, w("fldChar"), {w("fldCharType"): "end"})
    return p


def make_page_break_paragraph() -> ET.Element:
    p = ET.Element(w("p"))
    run = ET.SubElement(p, w("r"))
    ET.SubElement(run, w("br"), {w("type"): "page"})
    return p


def make_image_paragraph(rel_id: str, cx: int, cy: int, alt_text: str, image_id: int) -> ET.Element:
    p = ET.Element(w("p"))
    ppr = ET.SubElement(p, w("pPr"))
    ET.SubElement(ppr, w("jc"), {w("val"): "center"})

    run = ET.SubElement(p, w("r"))
    drawing = ET.SubElement(run, w("drawing"))
    inline = ET.SubElement(drawing, f"{{{NS_WP}}}inline")
    ET.SubElement(inline, f"{{{NS_WP}}}extent", {"cx": str(cx), "cy": str(cy)})
    ET.SubElement(inline, f"{{{NS_WP}}}effectExtent", {"l": "0", "t": "0", "r": "0", "b": "0"})
    ET.SubElement(inline, f"{{{NS_WP}}}docPr", {"id": str(image_id), "name": f"Screenshot {image_id}", "descr": alt_text})
    c_nv = ET.SubElement(inline, f"{{{NS_WP}}}cNvGraphicFramePr")
    ET.SubElement(c_nv, f"{{{NS_A}}}graphicFrameLocks", {"noChangeAspect": "1"})

    graphic = ET.SubElement(inline, f"{{{NS_A}}}graphic")
    graphic_data = ET.SubElement(graphic, f"{{{NS_A}}}graphicData", {"uri": NS_PIC})
    pic = ET.SubElement(graphic_data, f"{{{NS_PIC}}}pic")

    nv_pic_pr = ET.SubElement(pic, f"{{{NS_PIC}}}nvPicPr")
    ET.SubElement(nv_pic_pr, f"{{{NS_PIC}}}cNvPr", {"id": "0", "name": alt_text, "descr": alt_text})
    ET.SubElement(nv_pic_pr, f"{{{NS_PIC}}}cNvPicPr")

    blip_fill = ET.SubElement(pic, f"{{{NS_PIC}}}blipFill")
    ET.SubElement(blip_fill, f"{{{NS_A}}}blip", {f"{{{NS_R}}}embed": rel_id})
    stretch = ET.SubElement(blip_fill, f"{{{NS_A}}}stretch")
    ET.SubElement(stretch, f"{{{NS_A}}}fillRect")

    sp_pr = ET.SubElement(pic, f"{{{NS_PIC}}}spPr")
    xfrm = ET.SubElement(sp_pr, f"{{{NS_A}}}xfrm")
    ET.SubElement(xfrm, f"{{{NS_A}}}off", {"x": "0", "y": "0"})
    ET.SubElement(xfrm, f"{{{NS_A}}}ext", {"cx": str(cx), "cy": str(cy)})
    preset = ET.SubElement(sp_pr, f"{{{NS_A}}}prstGeom", {"prst": "rect"})
    ET.SubElement(preset, f"{{{NS_A}}}avLst")

    return p


def build_document(blocks: list[Block], version: str, stand: str, project_name: str) -> bytes:
    document = ET.Element(w("document"))
    body = ET.SubElement(document, w("body"))

    # Title page
    body.append(make_paragraph("Lehrer:innen-Handbuch", style="Title", keep_next=True, align="center"))
    body.append(make_paragraph("Software zur Mitarbeitsbewertung", style="Subtitle", keep_next=True, align="center"))
    body.append(make_paragraph(project_name, style="FrontMeta", keep_next=True, align="center"))
    body.append(make_paragraph(f"Zielgruppe: Lehrkräfte", style="FrontMeta", keep_next=True, align="center"))
    body.append(make_paragraph(f"Version {version} · Stand {stand}", style="FrontMeta", align="center"))
    body.append(make_page_break_paragraph())

    # Document information
    body.append(make_paragraph("Dokumentinformationen", style="FrontHeading", keep_next=True))
    body.append(make_paragraph("Zweck: Dieses Handbuch unterstützt Lehrkräfte bei der täglichen Arbeit mit COOL-Grades.", style="BodyText"))
    body.append(make_paragraph("Zielgruppe: Lehrer:innen, die Mitarbeit, besondere Leistungen und Auswertungen in der App dokumentieren.", style="BodyText"))
    body.append(make_paragraph("Geltungsbereich: Beschrieben werden nur Funktionen der Rolle Lehrer:in.", style="BodyText"))
    body.append(make_paragraph("Hinweis: Admin-Funktionen werden bewusst nicht behandelt und gehören in ein eigenes Handbuch.", style="WarningBox"))
    body.append(make_page_break_paragraph())

    # TOC
    body.append(make_paragraph("Inhaltsverzeichnis", style="FrontHeading", keep_next=True))
    body.append(make_field_paragraph('TOC \\o "1-3" \\h \\z \\u', "Inhaltsverzeichnis wird beim Öffnen in Word aktualisiert.", style="TOCParagraph"))
    body.append(make_paragraph("Falls das Verzeichnis nach dem Öffnen noch nicht sichtbar ist: Rechtsklick auf das Inhaltsverzeichnis → Felder aktualisieren → Gesamtes Verzeichnis aktualisieren.", style="TipBox"))
    body.append(make_page_break_paragraph())

    first_body_heading = True
    for block in blocks:
        btype = block["type"]
        if btype == "separator":
            continue
        if btype == "heading":
            level = int(block["level"])
            text = str(block["text"])
            if level == 1:
                style = "Heading1"
                page_break = False
                outline = 0
            elif level == 2:
                style = "Heading2"
                page_break = not first_body_heading
                outline = 1
            elif level == 3:
                style = "Heading3"
                page_break = False
                outline = 2
            else:
                style = "Heading4"
                page_break = False
                outline = 3
            body.append(make_paragraph(text, style=style, page_break_before=page_break, keep_next=True, outline_level=outline))
            if level <= 2:
                first_body_heading = False
            continue

        if btype == "paragraph":
            style_map = {
                "paragraph": "BodyText",
                "tip": "TipBox",
                "warning": "WarningBox",
                "label": "Heading4",
            }
            body.append(make_paragraph(str(block["text"]), style=style_map.get(str(block["style"]), "BodyText")))
            continue

        if btype == "screenshot":
            body.append(make_paragraph(f"[Screenshot: {block['text']}]", style="ScreenshotPlaceholder", align="center"))
            continue

        if btype == "image":
            body.append(
                make_image_paragraph(
                    str(block["rel_id"]),
                    int(block["cx"]),
                    int(block["cy"]),
                    str(block["alt"]),
                    int(block["image_id"]),
                )
            )
            body.append(make_paragraph(str(block["alt"]), style="Caption", align="center"))
            continue

        if btype == "list":
            for item in block["items"]:
                body.append(make_paragraph(str(item["text"]), style="ListParagraph", num_id=int(block["num_id"]), ilvl=int(item.get("level", 0))))
            continue

    sect_pr = ET.SubElement(body, w("sectPr"))
    ET.SubElement(sect_pr, w("headerReference"), {w("type"): "default", r("id"): "rIdHeader1"})
    ET.SubElement(sect_pr, w("footerReference"), {w("type"): "default", r("id"): "rIdFooter1"})
    ET.SubElement(sect_pr, w("pgSz"), {w("w"): "11907", w("h"): "16840"})
    ET.SubElement(sect_pr, w("pgMar"), {
        w("top"): "1440",
        w("right"): "1260",
        w("bottom"): "1440",
        w("left"): "1260",
        w("header"): "720",
        w("footer"): "720",
        w("gutter"): "0",
    })
    ET.SubElement(sect_pr, w("pgNumType"), {w("start"): "1"})
    return xml_to_bytes(document)


def build_styles() -> bytes:
    xml = f"""<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="{NS_W}">
  <w:docDefaults>
    <w:rPrDefault>
      <w:rPr>
        <w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:eastAsia="Arial" w:cs="Arial"/>
        <w:lang w:val="de-DE"/>
        <w:sz w:val="22"/>
        <w:szCs w:val="22"/>
      </w:rPr>
    </w:rPrDefault>
    <w:pPrDefault>
      <w:pPr>
        <w:spacing w:before="0" w:after="140" w:line="276" w:lineRule="auto"/>
      </w:pPr>
    </w:pPrDefault>
  </w:docDefaults>
  <w:latentStyles w:defLockedState="0" w:defUIPriority="99" w:defSemiHidden="0" w:defUnhideWhenUsed="0" w:defQFormat="0" w:count="276"/>
  <w:style w:type="paragraph" w:default="1" w:styleId="Normal">
    <w:name w:val="Normal"/>
    <w:qFormat/>
    <w:pPr>
      <w:spacing w:after="140" w:line="276" w:lineRule="auto"/>
    </w:pPr>
    <w:rPr>
      <w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:eastAsia="Arial" w:cs="Arial"/>
      <w:color w:val="1F2937"/>
      <w:sz w:val="22"/>
      <w:szCs w:val="22"/>
    </w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="BodyText">
    <w:name w:val="Body Text"/>
    <w:basedOn w:val="Normal"/>
    <w:qFormat/>
    <w:pPr>
      <w:spacing w:after="140" w:line="276" w:lineRule="auto"/>
    </w:pPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Title">
    <w:name w:val="Title"/>
    <w:basedOn w:val="Normal"/>
    <w:qFormat/>
    <w:pPr>
      <w:spacing w:before="0" w:after="220" w:line="320" w:lineRule="auto"/>
      <w:jc w:val="center"/>
    </w:pPr>
    <w:rPr>
      <w:b/>
      <w:color w:val="17324D"/>
      <w:sz w:val="56"/>
      <w:szCs w:val="56"/>
    </w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Subtitle">
    <w:name w:val="Subtitle"/>
    <w:basedOn w:val="Normal"/>
    <w:qFormat/>
    <w:pPr>
      <w:spacing w:after="200" w:line="300" w:lineRule="auto"/>
      <w:jc w:val="center"/>
    </w:pPr>
    <w:rPr>
      <w:b/>
      <w:color w:val="4B5563"/>
      <w:sz w:val="30"/>
      <w:szCs w:val="30"/>
    </w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="FrontMeta">
    <w:name w:val="Front Meta"/>
    <w:basedOn w:val="Normal"/>
    <w:pPr>
      <w:spacing w:after="120" w:line="276" w:lineRule="auto"/>
      <w:jc w:val="center"/>
    </w:pPr>
    <w:rPr>
      <w:color w:val="334155"/>
      <w:sz w:val="24"/>
      <w:szCs w:val="24"/>
    </w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="FrontHeading">
    <w:name w:val="Front Heading"/>
    <w:basedOn w:val="Normal"/>
    <w:qFormat/>
    <w:pPr>
      <w:spacing w:before="0" w:after="220" w:line="300" w:lineRule="auto"/>
    </w:pPr>
    <w:rPr>
      <w:b/>
      <w:color w:val="17324D"/>
      <w:sz w:val="34"/>
      <w:szCs w:val="34"/>
    </w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Heading1">
    <w:name w:val="heading 1"/>
    <w:basedOn w:val="Normal"/>
    <w:next w:val="BodyText"/>
    <w:uiPriority w:val="9"/>
    <w:qFormat/>
    <w:pPr>
      <w:keepNext/>
      <w:spacing w:before="360" w:after="180" w:line="300" w:lineRule="auto"/>
      <w:outlineLvl w:val="0"/>
    </w:pPr>
    <w:rPr>
      <w:b/>
      <w:color w:val="17324D"/>
      <w:sz w:val="40"/>
      <w:szCs w:val="40"/>
    </w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Heading2">
    <w:name w:val="heading 2"/>
    <w:basedOn w:val="Normal"/>
    <w:next w:val="BodyText"/>
    <w:uiPriority w:val="9"/>
    <w:qFormat/>
    <w:pPr>
      <w:keepNext/>
      <w:spacing w:before="320" w:after="160" w:line="290" w:lineRule="auto"/>
      <w:outlineLvl w:val="1"/>
    </w:pPr>
    <w:rPr>
      <w:b/>
      <w:color w:val="1F4E79"/>
      <w:sz w:val="32"/>
      <w:szCs w:val="32"/>
    </w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Heading3">
    <w:name w:val="heading 3"/>
    <w:basedOn w:val="Normal"/>
    <w:next w:val="BodyText"/>
    <w:uiPriority w:val="9"/>
    <w:qFormat/>
    <w:pPr>
      <w:keepNext/>
      <w:spacing w:before="240" w:after="120" w:line="280" w:lineRule="auto"/>
      <w:outlineLvl w:val="2"/>
    </w:pPr>
    <w:rPr>
      <w:b/>
      <w:color w:val="1F4E79"/>
      <w:sz w:val="26"/>
      <w:szCs w:val="26"/>
    </w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Heading4">
    <w:name w:val="heading 4"/>
    <w:basedOn w:val="Normal"/>
    <w:next w:val="BodyText"/>
    <w:pPr>
      <w:keepNext/>
      <w:spacing w:before="180" w:after="100" w:line="276" w:lineRule="auto"/>
      <w:outlineLvl w:val="3"/>
    </w:pPr>
    <w:rPr>
      <w:b/>
      <w:color w:val="334155"/>
      <w:sz w:val="24"/>
      <w:szCs w:val="24"/>
    </w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="ListParagraph">
    <w:name w:val="List Paragraph"/>
    <w:basedOn w:val="Normal"/>
    <w:pPr>
      <w:spacing w:after="80" w:line="276" w:lineRule="auto"/>
    </w:pPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="TOCParagraph">
    <w:name w:val="TOC Paragraph"/>
    <w:basedOn w:val="Normal"/>
    <w:pPr>
      <w:spacing w:after="120" w:line="276" w:lineRule="auto"/>
    </w:pPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="TipBox">
    <w:name w:val="Tip Box"/>
    <w:basedOn w:val="Normal"/>
    <w:pPr>
      <w:spacing w:before="120" w:after="160" w:line="276" w:lineRule="auto"/>
      <w:ind w:left="120" w:right="120"/>
      <w:shd w:val="clear" w:fill="EEF6FF"/>
      <w:pBdr>
        <w:left w:val="single" w:sz="8" w:space="6" w:color="3B82F6"/>
      </w:pBdr>
    </w:pPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="WarningBox">
    <w:name w:val="Warning Box"/>
    <w:basedOn w:val="Normal"/>
    <w:pPr>
      <w:spacing w:before="120" w:after="160" w:line="276" w:lineRule="auto"/>
      <w:ind w:left="120" w:right="120"/>
      <w:shd w:val="clear" w:fill="FFF6E6"/>
      <w:pBdr>
        <w:left w:val="single" w:sz="8" w:space="6" w:color="D97706"/>
      </w:pBdr>
    </w:pPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="ScreenshotPlaceholder">
    <w:name w:val="Screenshot Placeholder"/>
    <w:basedOn w:val="Normal"/>
    <w:pPr>
      <w:spacing w:before="120" w:after="160" w:line="276" w:lineRule="auto"/>
      <w:jc w:val="center"/>
      <w:shd w:val="clear" w:fill="F8FAFC"/>
      <w:pBdr>
        <w:top w:val="single" w:sz="8" w:space="6" w:color="CBD5E1"/>
        <w:left w:val="single" w:sz="8" w:space="6" w:color="CBD5E1"/>
        <w:bottom w:val="single" w:sz="8" w:space="6" w:color="CBD5E1"/>
        <w:right w:val="single" w:sz="8" w:space="6" w:color="CBD5E1"/>
      </w:pBdr>
    </w:pPr>
    <w:rPr>
      <w:i/>
      <w:color w:val="475569"/>
    </w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Caption">
    <w:name w:val="Caption"/>
    <w:basedOn w:val="Normal"/>
    <w:pPr>
      <w:spacing w:before="40" w:after="160" w:line="240" w:lineRule="auto"/>
      <w:jc w:val="center"/>
    </w:pPr>
    <w:rPr>
      <w:i/>
      <w:color w:val="475569"/>
      <w:sz w:val="19"/>
      <w:szCs w:val="19"/>
    </w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Header">
    <w:name w:val="Header"/>
    <w:basedOn w:val="Normal"/>
    <w:pPr><w:jc w:val="center"/></w:pPr>
    <w:rPr><w:sz w:val="18"/><w:szCs w:val="18"/><w:color w:val="64748B"/></w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Footer">
    <w:name w:val="Footer"/>
    <w:basedOn w:val="Normal"/>
    <w:pPr><w:jc w:val="center"/></w:pPr>
    <w:rPr><w:sz w:val="18"/><w:szCs w:val="18"/><w:color w:val="475569"/></w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="TOC1">
    <w:name w:val="toc 1"/>
    <w:basedOn w:val="Normal"/>
    <w:pPr><w:spacing w:after="60"/></w:pPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="TOC2">
    <w:name w:val="toc 2"/>
    <w:basedOn w:val="Normal"/>
    <w:pPr><w:ind w:left="240"/><w:spacing w:after="40"/></w:pPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="TOC3">
    <w:name w:val="toc 3"/>
    <w:basedOn w:val="Normal"/>
    <w:pPr><w:ind w:left="480"/><w:spacing w:after="30"/></w:pPr>
  </w:style>
</w:styles>
"""
    return xml.encode("utf-8")


def build_numbering(numbering_defs: list[tuple[int, int]]) -> bytes:
    xml = f"""<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:numbering xmlns:w="{NS_W}">
  <w:abstractNum w:abstractNumId="0">
    <w:lvl w:ilvl="0">
      <w:start w:val="1"/>
      <w:numFmt w:val="decimal"/>
      <w:lvlText w:val="%1."/>
      <w:lvlJc w:val="left"/>
      <w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr>
    </w:lvl>
    <w:lvl w:ilvl="1">
      <w:start w:val="1"/>
      <w:numFmt w:val="decimal"/>
      <w:lvlText w:val="%2."/>
      <w:lvlJc w:val="left"/>
      <w:pPr><w:ind w:left="1080" w:hanging="360"/></w:pPr>
    </w:lvl>
    <w:lvl w:ilvl="2">
      <w:start w:val="1"/>
      <w:numFmt w:val="decimal"/>
      <w:lvlText w:val="%3."/>
      <w:lvlJc w:val="left"/>
      <w:pPr><w:ind w:left="1440" w:hanging="360"/></w:pPr>
    </w:lvl>
  </w:abstractNum>
  <w:abstractNum w:abstractNumId="1">
    <w:lvl w:ilvl="0">
      <w:start w:val="1"/>
      <w:numFmt w:val="bullet"/>
      <w:lvlText w:val="•"/>
      <w:lvlJc w:val="left"/>
      <w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/></w:rPr>
      <w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr>
    </w:lvl>
    <w:lvl w:ilvl="1">
      <w:start w:val="1"/>
      <w:numFmt w:val="bullet"/>
      <w:lvlText w:val="◦"/>
      <w:lvlJc w:val="left"/>
      <w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/></w:rPr>
      <w:pPr><w:ind w:left="1080" w:hanging="360"/></w:pPr>
    </w:lvl>
    <w:lvl w:ilvl="2">
      <w:start w:val="1"/>
      <w:numFmt w:val="bullet"/>
      <w:lvlText w:val="▪"/>
      <w:lvlJc w:val="left"/>
      <w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/></w:rPr>
      <w:pPr><w:ind w:left="1440" w:hanging="360"/></w:pPr>
    </w:lvl>
  </w:abstractNum>
"""
    for num_id, abstract_num_id in numbering_defs:
        xml += f'  <w:num w:numId="{num_id}"><w:abstractNumId w:val="{abstract_num_id}"/></w:num>\n'
    xml += """\
</w:numbering>
"""
    return xml.encode("utf-8")


def build_settings() -> bytes:
    xml = f"""<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:settings xmlns:w="{NS_W}">
  <w:zoom w:percent="100"/>
  <w:defaultTabStop w:val="720"/>
  <w:updateFields w:val="true"/>
  <w:themeFontLang w:val="de-DE"/>
  <w:displayBackgroundShape/>
</w:settings>
"""
    return xml.encode("utf-8")


def build_font_table() -> bytes:
    xml = f"""<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:fonts xmlns:w="{NS_W}">
  <w:font w:name="Arial">
    <w:panose1 w:val="020B0604020202020204"/>
    <w:charset w:val="00"/>
    <w:family w:val="swiss"/>
    <w:pitch w:val="variable"/>
  </w:font>
</w:fonts>
"""
    return xml.encode("utf-8")


def build_theme() -> bytes:
    return textwrap.dedent(
        """\
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Handbuch">
          <a:themeElements>
            <a:clrScheme name="Handbuch">
              <a:dk1><a:srgbClr val="111827"/></a:dk1>
              <a:lt1><a:srgbClr val="FFFFFF"/></a:lt1>
              <a:dk2><a:srgbClr val="17324D"/></a:dk2>
              <a:lt2><a:srgbClr val="F8FAFC"/></a:lt2>
              <a:accent1><a:srgbClr val="1F4E79"/></a:accent1>
              <a:accent2><a:srgbClr val="3B82F6"/></a:accent2>
              <a:accent3><a:srgbClr val="D97706"/></a:accent3>
              <a:accent4><a:srgbClr val="475569"/></a:accent4>
              <a:accent5><a:srgbClr val="10B981"/></a:accent5>
              <a:accent6><a:srgbClr val="0F766E"/></a:accent6>
              <a:hlink><a:srgbClr val="1F4E79"/></a:hlink>
              <a:folHlink><a:srgbClr val="6B7280"/></a:folHlink>
            </a:clrScheme>
            <a:fontScheme name="Handbuch">
              <a:majorFont><a:latin typeface="Arial"/></a:majorFont>
              <a:minorFont><a:latin typeface="Arial"/></a:minorFont>
            </a:fontScheme>
            <a:fmtScheme name="Handbuch"/>
          </a:themeElements>
        </a:theme>
        """
    ).encode("utf-8")


def build_header(title: str) -> bytes:
    root = ET.Element(w("hdr"))
    p = ET.SubElement(root, w("p"))
    ppr = ET.SubElement(p, w("pPr"))
    ET.SubElement(ppr, w("pStyle"), {w("val"): "Header"})
    ET.SubElement(ppr, w("jc"), {w("val"): "center"})
    add_text_run(p, title)
    return xml_to_bytes(root)


def build_footer(title: str) -> bytes:
    root = ET.Element(w("ftr"))
    p = ET.SubElement(root, w("p"))
    ppr = ET.SubElement(p, w("pPr"))
    ET.SubElement(ppr, w("pStyle"), {w("val"): "Footer"})
    ET.SubElement(ppr, w("jc"), {w("val"): "center"})
    add_text_run(p, f"{title} · Seite ")

    begin_run = ET.SubElement(p, w("r"))
    ET.SubElement(begin_run, w("fldChar"), {w("fldCharType"): "begin"})
    instr_run = ET.SubElement(p, w("r"))
    instr_text = ET.SubElement(instr_run, w("instrText"))
    instr_text.set("{http://www.w3.org/XML/1998/namespace}space", "preserve")
    instr_text.text = " PAGE "
    sep_run = ET.SubElement(p, w("r"))
    ET.SubElement(sep_run, w("fldChar"), {w("fldCharType"): "separate"})
    add_text_run(p, "1")
    end_run = ET.SubElement(p, w("r"))
    ET.SubElement(end_run, w("fldChar"), {w("fldCharType"): "end"})
    return xml_to_bytes(root)


def build_document_rels(image_rels: list[tuple[str, str]]) -> bytes:
    root = ET.Element("Relationships", xmlns=NS_RELS)
    ET.SubElement(root, "Relationship", {
        "Id": "rIdHeader1",
        "Type": "http://schemas.openxmlformats.org/officeDocument/2006/relationships/header",
        "Target": "header1.xml",
    })
    ET.SubElement(root, "Relationship", {
        "Id": "rIdFooter1",
        "Type": "http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer",
        "Target": "footer1.xml",
    })
    ET.SubElement(root, "Relationship", {
        "Id": "rIdStyles",
        "Type": "http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles",
        "Target": "styles.xml",
    })
    ET.SubElement(root, "Relationship", {
        "Id": "rIdNumbering",
        "Type": "http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering",
        "Target": "numbering.xml",
    })
    ET.SubElement(root, "Relationship", {
        "Id": "rIdSettings",
        "Type": "http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings",
        "Target": "settings.xml",
    })
    ET.SubElement(root, "Relationship", {
        "Id": "rIdTheme",
        "Type": "http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme",
        "Target": "theme/theme1.xml",
    })
    ET.SubElement(root, "Relationship", {
        "Id": "rIdFontTable",
        "Type": "http://schemas.openxmlformats.org/officeDocument/2006/relationships/fontTable",
        "Target": "fontTable.xml",
    })
    for rel_id, target in image_rels:
        ET.SubElement(root, "Relationship", {
            "Id": rel_id,
            "Type": "http://schemas.openxmlformats.org/officeDocument/2006/relationships/image",
            "Target": target,
        })
    return xml_to_bytes(root)


def build_root_rels() -> bytes:
    root = ET.Element("Relationships", xmlns=NS_RELS)
    ET.SubElement(root, "Relationship", {
        "Id": "rId1",
        "Type": "http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument",
        "Target": "word/document.xml",
    })
    ET.SubElement(root, "Relationship", {
        "Id": "rId2",
        "Type": "http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties",
        "Target": "docProps/core.xml",
    })
    ET.SubElement(root, "Relationship", {
        "Id": "rId3",
        "Type": "http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties",
        "Target": "docProps/app.xml",
    })
    return xml_to_bytes(root)


def build_content_types() -> bytes:
    xml = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="png" ContentType="image/png"/>
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
  <Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>
  <Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>
  <Override PartName="/word/fontTable.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.fontTable+xml"/>
  <Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>
  <Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>
  <Override PartName="/word/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>
"""
    return xml.encode("utf-8")


def build_core_props(title: str, subject: str, description: str, stand_iso: str) -> bytes:
    root = ET.Element(f"{{{NS_CP}}}coreProperties")
    ET.SubElement(root, f"{{{NS_DC}}}title").text = title
    ET.SubElement(root, f"{{{NS_DC}}}subject").text = subject
    ET.SubElement(root, f"{{{NS_DC}}}creator").text = "OpenAI Codex"
    ET.SubElement(root, f"{{{NS_CP}}}keywords").text = "Lehrer:innen-Handbuch, COOL-Grades, Mitarbeitsbewertung"
    ET.SubElement(root, f"{{{NS_DC}}}description").text = description
    ET.SubElement(root, f"{{{NS_CP}}}lastModifiedBy").text = "OpenAI Codex"
    created = ET.SubElement(root, f"{{{NS_DCTERMS}}}created", {f"{{{NS_XSI}}}type": "dcterms:W3CDTF"})
    created.text = stand_iso
    modified = ET.SubElement(root, f"{{{NS_DCTERMS}}}modified", {f"{{{NS_XSI}}}type": "dcterms:W3CDTF"})
    modified.text = stand_iso
    return xml_to_bytes(root)


def build_app_props() -> bytes:
    xml = f"""<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="{NS_VT}">
  <Application>OpenAI Codex</Application>
  <DocSecurity>0</DocSecurity>
  <ScaleCrop>false</ScaleCrop>
  <HeadingPairs>
    <vt:vector size="2" baseType="variant">
      <vt:variant><vt:lpstr>Title</vt:lpstr></vt:variant>
      <vt:variant><vt:i4>1</vt:i4></vt:variant>
    </vt:vector>
  </HeadingPairs>
  <TitlesOfParts>
    <vt:vector size="1" baseType="lpstr">
      <vt:lpstr>Lehrer:innen-Handbuch</vt:lpstr>
    </vt:vector>
  </TitlesOfParts>
  <Company>COOL-Grades</Company>
  <LinksUpToDate>false</LinksUpToDate>
  <SharedDoc>false</SharedDoc>
  <HyperlinksChanged>false</HyperlinksChanged>
  <AppVersion>1.0</AppVersion>
</Properties>
"""
    return xml.encode("utf-8")


def create_docx(markdown_path: Path, output_path: Path, version: str, stand: dt.date) -> None:
    project_name = "COOL-Grades"
    stand_text = stand.strftime("%d.%m.%Y")
    stand_iso = dt.datetime.combine(stand, dt.time(12, 0)).isoformat() + "Z"
    blocks = parse_markdown(markdown_path.read_text(encoding="utf-8"), markdown_path.parent)
    numbering_defs: list[tuple[int, int]] = []
    next_num_id = 1
    image_rels: list[tuple[str, str]] = []
    image_files: list[tuple[Path, str]] = []
    next_image_rel = 100
    next_image_id = 1
    for block in blocks:
        if block.get("type") == "list":
            block["num_id"] = next_num_id
            numbering_defs.append((next_num_id, 0 if block.get("list_type") == "number" else 1))
            next_num_id += 1
        if block.get("type") == "image":
            image_path = Path(str(block["path"]))
            if not image_path.exists():
                raise FileNotFoundError(f"Bild nicht gefunden: {image_path}")
            width_px, height_px = png_dimensions(image_path)
            max_width_emu = 5_800_000
            width_emu = width_px * 9525
            height_emu = height_px * 9525
            if width_emu > max_width_emu:
                scale = max_width_emu / width_emu
                width_emu = int(width_emu * scale)
                height_emu = int(height_emu * scale)
            rel_id = f"rIdImage{next_image_rel}"
            target = f"media/image{next_image_id}.png"
            block["rel_id"] = rel_id
            block["cx"] = width_emu
            block["cy"] = height_emu
            block["image_id"] = next_image_id
            image_rels.append((rel_id, target))
            image_files.append((image_path, f"word/{target}"))
            next_image_rel += 1
            next_image_id += 1

    output_path.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output_path, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        zf.writestr("[Content_Types].xml", build_content_types())
        zf.writestr("_rels/.rels", build_root_rels())
        zf.writestr("docProps/core.xml", build_core_props(
            "Lehrer:innen-Handbuch",
            "Software zur Mitarbeitsbewertung",
            "Lehrer:innen-Handbuch für COOL-Grades",
            stand_iso,
        ))
        zf.writestr("docProps/app.xml", build_app_props())
        zf.writestr("word/document.xml", build_document(blocks, version, stand_text, project_name))
        zf.writestr("word/_rels/document.xml.rels", build_document_rels(image_rels))
        zf.writestr("word/styles.xml", build_styles())
        zf.writestr("word/numbering.xml", build_numbering(numbering_defs))
        zf.writestr("word/settings.xml", build_settings())
        zf.writestr("word/fontTable.xml", build_font_table())
        zf.writestr("word/theme/theme1.xml", build_theme())
        zf.writestr("word/header1.xml", build_header("Lehrer:innen-Handbuch"))
        zf.writestr("word/footer1.xml", build_footer("Lehrer:innen-Handbuch"))
        for source_path, target_path in image_files:
            zf.writestr(target_path, source_path.read_bytes())


def main(argv: list[str]) -> int:
    script_path = Path(__file__).resolve()
    project_root = script_path.parent.parent

    parser = argparse.ArgumentParser(description="Erzeugt ein DOCX-Lehrer:innen-Handbuch aus dem Markdown-Quelltext.")
    parser.add_argument(
        "--input",
        default=str(project_root / "docs" / "lehrerhandbuch.md"),
        help="Pfad zur Markdown-Eingabedatei",
    )
    parser.add_argument(
        "--output",
        default=str(project_root / "docs" / "lehrerhandbuch.docx"),
        help="Pfad zur DOCX-Ausgabedatei",
    )
    parser.add_argument(
        "--stand",
        default=dt.date.today().isoformat(),
        help="Dokumentenstand im Format YYYY-MM-DD (Standard: heute)",
    )
    args = parser.parse_args(argv)

    input_path = Path(args.input).resolve()
    output_path = Path(args.output).resolve()
    if not input_path.exists():
        print(f"Fehler: Markdown-Datei nicht gefunden: {input_path}", file=sys.stderr)
        return 1

    try:
        stand = dt.date.fromisoformat(args.stand)
    except ValueError:
        print("Fehler: --stand muss im Format YYYY-MM-DD angegeben werden.", file=sys.stderr)
        return 1

    version = read_version(project_root)
    create_docx(input_path, output_path, version, stand)
    print(f"DOCX erzeugt: {output_path}")
    print(f"Version: {version}")
    print(f"Stand: {stand.strftime('%d.%m.%Y')}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
