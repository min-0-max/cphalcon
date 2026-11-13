#!/usr/bin/env python3
"""
gen_arginfo.py - Generate PHP 8 arginfo for the Phalcon 3.4 amalgamated build.

Old Zephir (0.10/0.12) registers most methods with NULL arginfo. PHP 8 emits
"Missing arginfo" warnings for every such method and Reflection cannot see
their parameters. This tool:

  1. Parses the authoritative Zephir sources (phalcon/**/*.zep) to extract
     each class method's parameter names and required/optional counts.
     A parameter with a `= default` is optional.
  2. Rewrites NULL arginfo references in build/php7/64bits/phalcon.zep.h to
     freshly generated arginfo names.
  3. Emits build/php7/64bits/phalcon_arginfo.h with one
     ZEND_BEGIN_ARG_INFO_EX(...) block per method, all by-value
     (Zephir sources contain no by-reference parameters).

The parameter data comes from the same zep sources the amalgamated C was
generated from (upstream 3.4.x HEAD), so names and arities match what
runtime zephir_fetch_params() calls enforce.

Idempotent: re-running on an already-rewritten header is a no-op.
Usage: python3 tools/gen_arginfo.py   (from the repository root)
"""

import os
import shutil
import re
import sys

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ZEP_DIR = os.path.join(REPO, "phalcon")
ZEPH_H = os.path.join(REPO, "build", "php7", "64bits", "phalcon.zep.h")
OUT_H = os.path.join(REPO, "build", "php7", "64bits", "phalcon_arginfo.h")

FUNC_RE = re.compile(
    r"^\s*(?:(?:public|protected|private|static|final|abstract|internal|deprecated)\s+)+"
    r"function\s+\$?([A-Za-z0-9_]+)\s*\((.*)\)\s*(?:->.*)?;?\s*$"
)
PROP_RE = re.compile(
    r"^\s*(?:public|protected|private)\s+(_?[A-Za-z0-9_]+)\s*"
    r"(?:=\s*[^{;]+)?\{\s*([A-Za-z_,\s]+)\}\s*;?\s*$"
)
CLASS_RE = re.compile(
    r"^\s*(?:abstract\s+|final\s+)*(class|interface)\s+([A-Za-z0-9_]+)"
    r"(?:\s+extends\s+([A-Za-z0-9_\\\\]+))?"
    r"(?:\s+implements\s+([A-Za-z0-9_\\\\,\s]+))?\s*\{?\s*$"
)
IDENT_TAIL_RE = re.compile(r"([A-Za-z_][A-Za-z0-9_]*)\s*$")
ME_RE = re.compile(
    r"((?:PHP_ME|PHP_ABSTRACT_ME)\(\s*(\w+)\s*,\s*(\w+)\s*,\s*)NULL(\s*[,)])"
)


class ZepClass(object):
    def __init__(self, cname, namespace):
        self.cname = cname            # Phalcon_Cache_Backend
        self.namespace = namespace    # Phalcon\Cache
        self.methods = {}             # method_lower -> [(name, optional)]
        self.extends = None           # reference as written
        self.interfaces = []          # references as written


def split_top_level(s, sep=","):
    """Split s on sep at bracket depth 0. <> treated as brackets (zephir types)."""
    parts, depth, cur = [], 0, []
    for ch in s:
        if ch in "({[<":
            depth += 1
        elif ch in ")}]>":
            depth -= 1
        if ch == sep and depth == 0:
            parts.append("".join(cur))
            cur = []
        else:
            cur.append(ch)
    if cur:
        parts.append("".join(cur))
    return parts


def parse_param(part):
    """Return (name, optional) or None if the parameter cannot be parsed."""
    part = part.strip()
    if not part:
        return None
    # separate default value at top level '='
    depth = 0
    for i, ch in enumerate(part):
        if ch in "({[<":
            depth += 1
        elif ch in ")}]>":
            depth -= 1
        elif ch == "=" and depth == 0:
            part, has_default = part[:i], True
            break
    else:
        has_default = False
    part = part.strip()
    m = IDENT_TAIL_RE.search(part)
    if not m:
        return None
    # "..."-style variadic marker or missing name -> bail out
    if part.endswith("...") or part == m.group(1) and part in (
        "var", "string", "int", "bool", "char", "float", "double",
        "array", "object", "callable", "resource", "null", "long",
    ):
        return None
    return m.group(1), has_default


def _add_shortcut_methods(zc, prop, shorts_src):
    """Register zephir { get, set, __toString } property accessors."""
    shorts = [s.strip() for s in shorts_src.split(",") if s.strip()]
    base = prop.lstrip("_")
    param = prop if base == prop else base
    if "get" in shorts:
        zc.methods["get" + base.lower()] = []
    if "set" in shorts:
        zc.methods["set" + base.lower()] = [(param, False)]
    if "__toString" in shorts:
        zc.methods["__tostring"] = []


def parse_zep_class_map():
    """cname_lower -> ZepClass (methods + inheritance graph)"""
    classes = {}
    for root, _, files in os.walk(ZEP_DIR):
        for fn in files:
            if not fn.endswith(".zep"):
                continue
            path = os.path.join(root, fn)
            rel = os.path.splitext(os.path.relpath(path, ZEP_DIR))[0]
            parts = rel.split("/")
            cname = "Phalcon_" + "_".join(parts)
            namespace = "\\".join(parts[:-1])
            zc = ZepClass(cname, namespace)
            with open(path, "r", encoding="utf-8", errors="replace") as fh:
                lines = fh.readlines()
            prev_open = False   # multiline "{ get ... };" property pending
            pending_prop = None
            pending_shorts = ""
            for line in lines:
                if prev_open:
                    pending_shorts += " " + line.strip()
                    if "}" in pending_shorts:
                        inner = pending_shorts[
                            pending_shorts.index("{") + 1: pending_shorts.index("}")
                        ]
                        _add_shortcut_methods(zc, pending_prop, inner)
                        prev_open = False
                    continue
                # multiline property shortcut opener: protected _definition {
                om = re.match(
                    r"^\s*(?:public|protected|private)\s+(_?[A-Za-z0-9_]+)\s*"
                    r"(?:=\s*[^{;]+)?\{\s*$", line)
                if om:
                    prev_open, pending_prop, pending_shorts = True, om.group(1), "{"
                    continue
                cm = CLASS_RE.match(line)
                if cm and cm.group(2).lower() == parts[-1].lower():
                    if cm.group(3):
                        zc.extends = cm.group(3).strip()
                    if cm.group(4):
                        zc.interfaces = [
                            i.strip() for i in cm.group(4).split(",") if i.strip()
                        ]
                m = FUNC_RE.match(line)
                if not m:
                    # single-line property shortcut: protected _type { get, set };
                    pm = PROP_RE.match(line)
                    if pm:
                        _add_shortcut_methods(zc, pm.group(1), pm.group(2))
                    continue
                meth, params_src = m.group(1), m.group(2).strip()
                if params_src == "":
                    zc.methods[meth.lower()] = []
                    continue
                params = []
                ok = True
                for piece in split_top_level(params_src):
                    piece = piece.strip()
                    if piece.startswith("..."):
                        ok = False
                        break
                    p = parse_param(piece)
                    if p is None:
                        ok = False
                        break
                    params.append(p)
                if ok:
                    zc.methods[meth.lower()] = params
            classes[cname.lower()] = zc
    return classes


def resolve_class(classes, namespace, ref):
    """Resolve a zep class reference (possibly namespace-relative) to a
    ZepClass in the map, or None."""
    if not ref:
        return None
    ref = ref.strip().strip("\\")
    candidates = []
    if "\\" in ref:
        candidates.append(ref)
    else:
        if namespace:
            candidates.append(namespace + "\\" + ref)
        candidates.append(ref)
        candidates.append("Phalcon\\" + ref)
    for cand in candidates:
        key = ("Phalcon_" + cand.replace("\\", "_")).lower() \
            if not cand.startswith("Phalcon\\") else cand.replace("\\", "_").lower()
        key = key.replace("phalcon_phalcon", "phalcon")
        if key in classes:
            return classes[key]
    return None


def find_method_params(classes, cls_lower, meth_lower):
    """Own methods first, then the extends chain, then interfaces (BFS)."""
    start = classes.get(cls_lower)
    if start is None:
        return None
    seen = set()
    queue = [start]
    while queue:
        zc = queue.pop(0)
        key = zc.cname.lower()
        if key in seen:
            continue
        seen.add(key)
        if meth_lower in zc.methods:
            return zc.methods[meth_lower]
        parent = resolve_class(classes, zc.namespace, zc.extends)
        if parent is not None:
            queue.append(parent)
        for iref in zc.interfaces:
            iface = resolve_class(classes, zc.namespace, iref)
            if iface is not None:
                queue.append(iface)
    return None


def arginfo_name(cls_cname, method):
    return "arginfo_%s_%s" % (cls_cname.lower(), method.lower())


def main():
    classes = parse_zep_class_map()
    # Always regenerate from the pristine upstream header so the tool is a
    # deterministic full-rebuild (no incremental state to corrupt).
    pristine = ZEPH_H + ".pristine"
    if not os.path.exists(pristine):
        shutil.copyfile(ZEPH_H, pristine)
    with open(pristine, "r", encoding="utf-8") as fh:
        text = fh.read()

    blocks = {}
    unresolved = []
    order = []

    def repl(m):
        prefix, cls, meth, tail = m.group(1), m.group(2), m.group(3), m.group(4)
        params = find_method_params(classes, cls.lower(), meth.lower())
        if params is None:
            unresolved.append("%s::%s" % (cls, meth))
            return m.group(0)
        name = arginfo_name(cls, meth)
        if name not in blocks:
            order.append(name)
            required = sum(1 for _, opt in params if not opt)
            lines = [
                "ZEND_BEGIN_ARG_INFO_EX(%s, 0, %d, %d)" % (name, required, len(params))
            ]
            for pname, _ in params:
                lines.append("\tZEND_ARG_INFO(0, %s)" % pname)
            lines.append("ZEND_END_ARG_INFO()")
            blocks[name] = "\n".join(lines)
        return "%s%s%s" % (prefix, name, tail)

    # Zephir also emits a few static abstract interface methods as
    # ZEND_FENTRY(name, NULL, NULL, flags); the class must be taken from the
    # enclosing method table (last PHP_ME/PHP_ABSTRACT_ME in the same table).
    FENTRY_RE = re.compile(
        r"(ZEND_FENTRY\(\s*(\w+)\s*,\s*NULL\s*,\s*)NULL(\s*,)"
    )

    def fentry_repl_factory(state):
        def frepl(m):
            cls = state.get("class")
            if not cls:
                return m.group(0)
            params = find_method_params(classes, cls.lower(), m.group(2).lower())
            if params is None:
                unresolved.append("%s::%s" % (cls, m.group(2)))
                return m.group(0)
            name = arginfo_name(cls, m.group(2))
            if name not in blocks:
                order.append(name)
                required = sum(1 for _, o in params if not o)
                lines = [
                    "ZEND_BEGIN_ARG_INFO_EX(%s, 0, %d, %d)" % (
                        name, required, len(params))
                ]
                for pname, _ in params:
                    lines.append("\tZEND_ARG_INFO(0, %s)" % pname)
                lines.append("ZEND_END_ARG_INFO()")
                blocks[name] = "\n".join(lines)
            return "%s%s%s" % (m.group(1), name, m.group(3))
        return frepl

    state = {"class": None}
    out_lines = []
    frepl = fentry_repl_factory(state)
    for line in text.splitlines(keepends=True):
        stripped = line.strip()
        tm = re.match(r"ZEPHIR_INIT_FUNCS\((\w+)_method_entry\)", stripped)
        if tm:
            # table name (lowercase) doubles as the lowercase class key
            key = tm.group(1)
            state["class"] = "_".join(
                p[:1].upper() + p[1:] for p in key.split("_"))
        else:
            cm = re.match(r"(?:PHP_ME|PHP_ABSTRACT_ME)\(\s*(\w+)\s*,", stripped)
            if cm:
                state["class"] = cm.group(1)
        out_lines.append(FENTRY_RE.sub(frepl, line))
    text = "".join(out_lines)

    new_text, n = ME_RE.subn(repl, text)
    print("rewrote %d NULL arginfo references; %d unresolved" % (
        n - len(unresolved), len(unresolved)))
    for u in unresolved[:40]:
        print("  UNRESOLVED:", u)
    if len(unresolved) > 40:
        print("  ... and %d more" % (len(unresolved) - 40))

    if n - len(unresolved) == 0:
        print("nothing generated; leaving files untouched")
        return 1

    out = [
        "/*",
        " * phalcon_arginfo.h - arginfo for methods Zephir 0.10/0.12 registered",
        " * with NULL (it only emitted arginfo for parameters with declared",
        " * types). Parameter names and arities are extracted from the same",
        " * Zephir sources (phalcon directory) the amalgamated C was built from.",
        " * Generated by tools/gen_arginfo.py - do not edit by hand.",
        " */",
        "#ifndef PHALCON_ARGINFO_GENERATED_H",
        "#define PHALCON_ARGINFO_GENERATED_H",
        "",
    ]
    for nm in order:
        out.append(blocks[nm])
        out.append("")
    out.append("#endif /* PHALCON_ARGINFO_GENERATED_H */")
    out.append("")

    with open(OUT_H, "w", encoding="utf-8") as fh:
        fh.write("\n".join(out))

    include_line = '#include "phalcon_arginfo.h"\n'
    if include_line not in new_text:
        anchor = "#define ZEPHIR_CLASS_ENTRIES_H\n"
        if anchor not in new_text:
            print("FATAL: include anchor not found in phalcon.zep.h", file=sys.stderr)
            return 1
        new_text = new_text.replace(anchor, anchor + "\n" + include_line, 1)

    with open(ZEPH_H, "w", encoding="utf-8") as fh:
        fh.write(new_text)

    print("wrote %s (%d arginfo blocks)" % (os.path.relpath(OUT_H, REPO), len(blocks)))
    return 0


if __name__ == "__main__":
    sys.exit(main())
