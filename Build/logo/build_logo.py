#!/usr/bin/env python3
"""Builds Resources/Public/Images/logo-dtranslate.svg.

The wordmark follows the geometry of logo-dai.svg: cap height 200, stem width
42, outer corner radius 34, counters rounded with 16 — so "dTRANSLATE" sits next
to "dAi" as the same family, not as a lookalike.
"""

import pathlib

H = 200      # cap height
S = 42       # stem width
R = 34       # outer radius
GAP = 15     # letter spacing
BAR = 79     # top edge of the middle bar (E, S)
BAR2 = 121   # bottom edge of the middle bar
FOOT = 158   # top edge of the foot bar (L, E, S)

ORANGE = '#ff5c1c'
GREY = '#626f69'

# The "d" of logo-dai.svg, unchanged.
D_PATH = ('M172,0 H214 V200 H34 A34,34 0 0 1 0,166 V88 A34,34 0 0 1 34,54 H172 Z '
          'M58,96 A16,16 0 0 0 42,112 V142 A16,16 0 0 0 58,158 H156 A16,16 0 0 0 172,142 '
          'V112 A16,16 0 0 0 156,96 Z')
D_WIDTH = 214


def t(w=150):
    """T — top bar plus a centred stem."""
    x = (w - S) / 2
    return w, f'M0,0 H{w} V{S} H{x + S:g} V{H} H{x:g} V{S} H0 Z'


def r(w=155):
    """R — a "P" bowl on a full-height stem, plus a straight diagonal leg."""
    bowl = BAR2  # bottom edge of the bowl
    return w, (
        # outline: stem + bowl
        f'M0,0 H{w - R} A{R},{R} 0 0 1 {w},{R} V{bowl - R} A{R},{R} 0 0 1 {w - R},{bowl} '
        f'H{S} V{H} H0 Z '
        # counter, rounded like the one in the "d"
        f'M{S},{S} V{bowl - S} H{w - S - 16} A16,16 0 0 0 {w - S},{bowl - S - 16} '
        f'V{S + 16} A16,16 0 0 0 {w - S - 16},{S} Z '
        # leg
        f'M{w - 2 * S},{bowl} H{w - S} L{w},{H} H{w - S} Z'
    )


def a(w=170):
    """A — the shape from logo-dai.svg, normalised to x = 0."""
    return w, (
        f'M0,{H} V{R} A{R},{R} 0 0 1 {R},0 H{w - R} A{R},{R} 0 0 1 {w},{R} V{H} H{w - S} '
        f'V{S + 16} A16,16 0 0 0 {w - S - 16},{S} H{S + 16} A16,16 0 0 0 {S},{S + 16} V{H} Z '
        f'M{S},{BAR + 43} H{w - S} V{FOOT + 6} H{S} Z'
    )


def n(w=150):
    """N — two stems joined by a diagonal."""
    return w, f'M0,{H} V0 H{S} L{w - S},{H - 68} V0 H{w} V{H} H{w - S} L{S},68 V{H} Z'


def s(w=150):
    """S — top bar, left shoulder, middle bar, right shoulder, foot bar."""
    return w, (
        f'M{R},0 H{w - R} A{R},{R} 0 0 1 {w},{R} V{S} H{S} V{BAR} H{w} V{H - R} '
        f'A{R},{R} 0 0 1 {w - R},{H} H{R} A{R},{R} 0 0 1 0,{H - R} V{FOOT} H{w - S} '
        f'V{BAR2} H0 V{R} A{R},{R} 0 0 1 {R},0 Z'
    )


def l(w=130):
    """L — stem plus foot."""
    return w, f'M0,0 H{S} V{FOOT} H{w} V{H} H0 Z'


def e(w=140):
    """E — stem with three bars."""
    return w, (
        f'M0,0 H{w} V{S} H{S} V{BAR} H{w - 14} V{BAR2} H{S} V{FOOT} H{w} V{H} H0 Z'
    )


GLYPHS = {'T': t, 'R': r, 'A': a, 'N': n, 'S': s, 'L': l, 'E': e}

parts = [f'<path d="{D_PATH}" fill="{ORANGE}" class="dtranslate-d" fill-rule="nonzero"/>']
x = D_WIDTH + GAP + 18
for char in 'TRANSLATE':
    width, path = GLYPHS[char]()
    parts.append(
        f'<path transform="translate({x:g} 0)" d="{path}" fill="{GREY}" '
        f'class="dtranslate-word" fill-rule="nonzero"/>'
    )
    x += width + GAP

total = round(x - GAP)
svg = (
    f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {total} {H}" '
    f'width="{total}" height="{H}">\n  ' + '\n  '.join(parts) + '\n</svg>\n'
)
target = pathlib.Path(__file__).resolve().parents[2] / 'Resources' / 'Public' / 'Images' / 'logo-dtranslate.svg'
target.parent.mkdir(parents=True, exist_ok=True)
target.write_text(svg, encoding='utf-8')
print(f'{target} — {total}×{H}')
