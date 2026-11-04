"""DS-4 page migration: Tailwind palette classes -> Foundation v3 tokens and
component classes (ADR 0082 §9, docs/design/foundation-v3/COMPONENTS.md).

Usage: python3 -I ds_migrate.py FILE.vue [FILE.vue ...]
Rewrites files in place; prints a per-file summary. Presentation only:
never touches attributes other than class / :class, script class strings,
text content, props or logic.
"""
import re
import sys

PALETTE = "slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose"
TOKEN_RE = re.compile(
    r"(?<![\w:/\[-])((?:[a-z0-9-]+:)*)(bg|text|border|divide|ring|outline|placeholder|decoration|accent|fill|stroke|from|to|via)-("
    + PALETTE
    + r")-(\d{2,3})(/\d+)?(?![\w-])"
)
ROUNDED_RE = re.compile(r"(?<![\w:-])((?:[a-z0-9-]+:)*)rounded(?:-(?:sm|md|lg))?(?![\w-])")

SUCCESS = {"green", "emerald", "teal", "lime"}
INFO = {"sky", "blue", "indigo", "cyan", "violet", "purple"}
CAUTION = {"amber", "yellow", "orange"}
DANGER = {"red", "rose", "pink", "fuchsia"}
NEUTRAL = {"slate", "gray", "zinc", "neutral", "stone"}


def map_token(prefix: str, kind: str, hue: str, shade: int, alpha: str | None) -> str:
    hover = "hover:" in prefix or "active:" in prefix
    out = None
    if hue in NEUTRAL:
        if kind == "text" or kind == "placeholder" or kind == "decoration":
            out = "ink" if shade >= 700 else ("ink-muted" if shade >= 400 else "graphite-400")
            if shade <= 200:
                out = "ground"  # light text on a dark fill
        elif kind in ("border", "divide", "ring", "outline"):
            if kind == "outline":
                out = "accent-strong"
            elif shade >= 700:
                out = "accent-strong"
            elif shade >= 300:
                out = "control-line"
            else:
                out = "line"
        elif kind == "bg":
            if shade >= 600:
                out = "accent-800" if hover else "accent-strong"
            elif shade == 50:
                out = "graphite-100"
            elif shade <= 200:
                out = "graphite-200"
            else:
                out = "graphite-300"
        elif kind == "accent":
            out = "accent-strong"
    elif hue in DANGER:
        if kind == "bg":
            if shade <= 100:
                out = "danger-tint"
            else:
                return prefix + "brightness-90" if hover else prefix + "bg-danger"
        elif kind == "text":
            out = "danger"
        elif kind in ("border", "divide", "ring"):
            out = "danger/40" if shade <= 300 and not alpha else "danger"
            alpha = None
        elif kind == "outline":
            out = "danger"
    elif hue in CAUTION:
        if kind == "bg":
            out = "accent-100" if shade <= 100 else "accent-strong"
        elif kind == "text":
            out = "ink"
        elif kind in ("border", "divide", "ring"):
            out = "accent-300"
        elif kind == "outline":
            out = "accent-strong"
    elif hue in SUCCESS:
        if kind == "bg":
            out = "graphite-100" if shade <= 100 else "accent-strong"
        elif kind == "text":
            out = "ink"
        elif kind in ("border", "divide", "ring"):
            out = "graphite-300"
        elif kind == "outline":
            out = "accent-strong"
    elif hue in INFO:
        if kind == "bg":
            out = "accent-100" if shade <= 100 else "accent-strong"
        elif kind == "text":
            out = "accent-strong"
        elif kind in ("border", "divide", "ring"):
            out = "accent-300"
        elif kind == "outline":
            out = "accent-strong"
    if out is None:
        raise SystemExit(f"unmapped token {prefix}{kind}-{hue}-{shade}")
    return f"{prefix}{kind}-{out}{alpha or ''}"


def map_tokens(text: str) -> str:
    text = TOKEN_RE.sub(lambda m: map_token(m.group(1), m.group(2), m.group(3), int(m.group(4)), m.group(5)), text)
    return ROUNDED_RE.sub(lambda m: m.group(1) + "rounded-xs", text)


SPACING = re.compile(r"^(px|py)-[\d.]+$")
OPACITY = re.compile(r"^disabled:opacity-\d+$")


def restructure(tag: str, classes: str) -> str:
    toks = classes.split()
    t = tag.lower()
    # Status pills are square tags in the design (DS-4c).
    if "rounded-full" in toks and "text-xs" in toks and "py-0.5" in toks:
        toks = ["rounded-xs" if x == "rounded-full" else x for x in toks]
        classes = " ".join(toks)
    buttonish = t in ("button", "a", "link", "inertialink")
    if t in ("input", "select", "textarea") and "border-slate-300" in toks:
        drop = {"rounded", "border", "border-slate-300", "text-sm", "bg-white", "w-full"}
        keep = [x for x in toks if x not in drop and not SPACING.match(x)]
        if "w-full" not in toks and not any(re.match(r"^(sm:|md:|lg:)?w-", x) for x in keep):
            keep.append("w-auto")
        return " ".join(["input"] + keep)
    if buttonish and "bg-slate-900" in toks and "text-white" in toks:
        drop = {"rounded", "bg-slate-900", "text-sm", "font-medium", "text-white", "hover:bg-slate-800", "hover:bg-slate-700"}
        return " ".join(["btn", "btn-primary"] + [x for x in toks if x not in drop and not SPACING.match(x) and not OPACITY.match(x)])
    if buttonish and any(x in toks for x in ("bg-red-700", "bg-red-800", "bg-red-600")) and "text-white" in toks:
        drop = {"rounded", "text-sm", "font-medium", "text-white"}
        keep = [x for x in toks if x not in drop and not SPACING.match(x) and not OPACITY.match(x) and not re.match(r"^(hover:)?bg-red-\d+$", x)]
        return " ".join(["btn", "btn-danger"] + keep)
    if buttonish and "border-slate-300" in toks and "border" in toks:
        drop = {"rounded", "border", "border-slate-300", "bg-white", "text-sm", "font-medium", "text-slate-700", "text-slate-900", "hover:bg-slate-50", "hover:bg-slate-100"}
        return " ".join(["btn", "btn-secondary"] + [x for x in toks if x not in drop and not SPACING.match(x) and not OPACITY.match(x)])
    return classes


TAG_RE = re.compile(r"<([A-Za-z][\w.-]*)((?:[^>\"']|\"[^\"]*\"|'[^']*')*)>")
CLASS_ATTR = re.compile(r'(\s)(class|:class)="([^"]*)"')


def migrate(src: str) -> str:
    def on_tag(m: re.Match) -> str:
        tag, attrs = m.group(1), m.group(2)

        def on_attr(a: re.Match) -> str:
            value = a.group(3)
            if a.group(2) == "class":
                value = restructure(tag, value)
            return f'{a.group(1)}{a.group(2)}="{map_tokens(value)}"'

        return f"<{tag}{CLASS_ATTR.sub(on_attr, attrs)}>"

    tpl_start = src.find("<template>")
    head, body = (src[:tpl_start], src[tpl_start:]) if tpl_start >= 0 else (src, "")
    # Script: only quoted strings that look like class lists.
    head = re.sub(
        r"(['`])((?:(?!\1).)*?)\1",
        lambda m: m.group(1) + (map_tokens(m.group(2)) if re.search(r"\b(bg|text|border|px|py|rounded|divide|hover:)", m.group(2)) else m.group(2)) + m.group(1),
        head,
    )
    body = TAG_RE.sub(on_tag, body)
    return head + body


if __name__ == "__main__":
    for path in sys.argv[1:]:
        before = open(path, encoding="utf-8").read()
        after = migrate(before)
        left = TOKEN_RE.findall(after)
        if after != before:
            open(path, "w", encoding="utf-8").write(after)
        print(f"{path}: {'changed' if after != before else 'unchanged'}; palette left: {len(left)}")
