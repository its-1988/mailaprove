#!/usr/bin/env python3
"""
Compile gettext .po files into .mo binary catalogs.

Usage:
    python3 compile_mo.py file1.po file2.po ...
    python3 compile_mo.py *.po

GLPI 11 loads .mo files at runtime. After updating a .po file, run this
script to refresh the matching .mo (no msgfmt required).
"""
from __future__ import annotations

import os
import struct
import sys
from pathlib import Path


def parse_po(path: Path) -> dict[str, str | list[str]]:
    """Parse a .po file into a dict {msgid: msgstr} (handles plurals)."""
    entries: dict[tuple[str, str], str | list[str]] = {}
    msgid: list[str] = []
    msgid_plural: list[str] = []
    msgstr: list[str] = []
    msgstr_plurals: dict[int, list[str]] = {}
    state = None  # 'id', 'id_plural', 'str', 'str_plural'
    current_plural_index = 0

    def flush() -> None:
        nonlocal msgid, msgid_plural, msgstr, msgstr_plurals
        if msgid or msgid_plural:
            mid = "".join(msgid)
            if msgid_plural:
                plurals = [
                    "".join(msgstr_plurals.get(i, []))
                    for i in range(max(msgstr_plurals.keys(), default=-1) + 1)
                ]
                entries[(mid, "".join(msgid_plural))] = plurals
            else:
                entries[(mid, "")] = "".join(msgstr)
        msgid = []
        msgid_plural = []
        msgstr = []
        msgstr_plurals = {}

    def unquote(s: str) -> str:
        # Strip surrounding quotes and decode escape sequences.
        s = s.strip()
        if s.startswith('"') and s.endswith('"'):
            s = s[1:-1]
        return (
            s.replace(r"\\", "\x00")
             .replace(r"\n", "\n")
             .replace(r"\t", "\t")
             .replace(r"\r", "\r")
             .replace(r"\"", '"')
             .replace("\x00", "\\")
        )

    with open(path, encoding="utf-8") as f:
        for raw_line in f:
            line = raw_line.rstrip("\n")
            stripped = line.strip()
            if not stripped or stripped.startswith("#"):
                if not stripped:
                    flush()
                    state = None
                continue
            if stripped.startswith("msgid_plural"):
                state = "id_plural"
                msgid_plural = [unquote(stripped[len("msgid_plural"):])]
                continue
            if stripped.startswith("msgid"):
                # Start of a new entry: flush the previous one.
                flush()
                state = "id"
                msgid = [unquote(stripped[len("msgid"):])]
                continue
            if stripped.startswith("msgstr["):
                end = stripped.index("]")
                current_plural_index = int(stripped[len("msgstr["):end])
                state = "str_plural"
                rest = stripped[end + 1:]
                msgstr_plurals.setdefault(current_plural_index, []).append(unquote(rest))
                continue
            if stripped.startswith("msgstr"):
                state = "str"
                msgstr = [unquote(stripped[len("msgstr"):])]
                continue
            if stripped.startswith('"'):
                piece = unquote(stripped)
                if state == "id":
                    msgid.append(piece)
                elif state == "id_plural":
                    msgid_plural.append(piece)
                elif state == "str":
                    msgstr.append(piece)
                elif state == "str_plural":
                    msgstr_plurals.setdefault(current_plural_index, []).append(piece)
    flush()
    return entries


def write_mo(entries: dict, out_path: Path) -> None:
    """Write the entries dict to a .mo file in little-endian gettext format."""
    keys = []
    values = []
    # The empty msgid is the header — it must come first.
    sorted_items = sorted(entries.items(), key=lambda kv: (kv[0][0] != "", kv[0][0]))
    for (mid, plural), translation in sorted_items:
        if plural:
            key = mid + "\x00" + plural
            value = "\x00".join(translation if isinstance(translation, list) else [translation])
        else:
            key = mid
            value = translation if isinstance(translation, str) else "\x00".join(translation)
        keys.append(key.encode("utf-8"))
        values.append(value.encode("utf-8"))

    n = len(keys)
    header_size = 28
    table_size = n * 8 * 2  # two tables: original and translation offsets

    key_offsets = []
    value_offsets = []
    key_section_start = header_size + table_size
    cursor = key_section_start
    for k in keys:
        key_offsets.append((len(k), cursor))
        cursor += len(k) + 1  # +1 for trailing NUL
    for v in values:
        value_offsets.append((len(v), cursor))
        cursor += len(v) + 1

    output = bytearray()
    # Magic, version, count, offsets of tables, hash table size+offset.
    output += struct.pack(
        "<Iiiiiii",
        0x950412DE,                    # magic, little-endian
        0,                              # version
        n,                              # number of strings
        header_size,                    # offset of original strings table
        header_size + n * 8,            # offset of translated strings table
        0,                              # hash table size
        0,                              # hash table offset
    )
    # Original strings table
    for length, offset in key_offsets:
        output += struct.pack("<ii", length, offset)
    # Translated strings table
    for length, offset in value_offsets:
        output += struct.pack("<ii", length, offset)
    # Strings themselves
    for k in keys:
        output += k + b"\x00"
    for v in values:
        output += v + b"\x00"

    out_path.write_bytes(bytes(output))


def main(argv: list[str]) -> int:
    if len(argv) < 2:
        print(__doc__)
        return 1

    args = []
    for pattern in argv[1:]:
        # Cheap glob expansion for *.po so Windows shells work.
        if "*" in pattern or "?" in pattern:
            args.extend(str(p) for p in Path(".").glob(pattern))
        else:
            args.append(pattern)

    rc = 0
    for arg in args:
        src = Path(arg)
        if not src.is_file():
            print(f"skip: {src} not found", file=sys.stderr)
            rc = 2
            continue
        dst = src.with_suffix(".mo")
        try:
            entries = parse_po(src)
            write_mo(entries, dst)
        except Exception as e:
            print(f"error compiling {src}: {e}", file=sys.stderr)
            rc = 1
            continue
        print(f"{src} -> {dst}  ({len(entries)} entries)")
    return rc


if __name__ == "__main__":
    sys.exit(main(sys.argv))
