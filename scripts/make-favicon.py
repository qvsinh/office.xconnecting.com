#!/usr/bin/env python3
"""Regenerate public/favicon.ico from the same geometry as public/favicon.svg.

Why redraw instead of rasterising the SVG: this box has no SVG rasteriser
(no ImageMagick, rsvg or Inkscape), only Pillow. The logo is three rounded bars
on a rounded square, so the shapes are reproduced from the SVG's own numbers
below — keep the two in step by hand if the logo changes.

A multi-size .ico matters because different surfaces pick different frames:
16px in a browser tab, 32px in a bookmark bar, 48px in Windows shortcuts, and
128/256 for high-DPI and OS-level previews. Shipping only 32×32 makes every
other surface scale it badly.

Everything is drawn at 8x and downsampled, which is what keeps the 16px frame
from turning into mush.

    python3 scripts/make-favicon.py
"""

from pathlib import Path

from PIL import Image, ImageDraw

# --- geometry, straight from favicon.svg (viewBox 0 0 32 32) ----------------

VIEWBOX = 32
BACKGROUND = "#465FFF"
FOREGROUND = "#FFFFFF"
CORNER_RADIUS = 7

# x, y, width, height, radius — the three bars
BARS = [
    (7, 9, 4, 14, 1.5),
    (14, 9, 4, 9, 1.5),
    (21, 9, 4, 12, 1.5),
]

SIZES = [16, 32, 48, 64, 128, 256]
SUPERSAMPLE = 8

OUTPUT = Path(__file__).resolve().parent.parent / "public" / "favicon.ico"


def render(size: int) -> Image.Image:
    canvas = size * SUPERSAMPLE
    scale = canvas / VIEWBOX

    image = Image.new("RGBA", (canvas, canvas), (0, 0, 0, 0))
    draw = ImageDraw.Draw(image)

    draw.rounded_rectangle(
        [0, 0, canvas - 1, canvas - 1],
        radius=CORNER_RADIUS * scale,
        fill=BACKGROUND,
    )

    for x, y, width, height, radius in BARS:
        draw.rounded_rectangle(
            [x * scale, y * scale, (x + width) * scale, (y + height) * scale],
            radius=radius * scale,
            fill=FOREGROUND,
        )

    return image.resize((size, size), Image.LANCZOS)


def main() -> None:
    frames = [render(size) for size in SIZES]

    # Pillow writes the extra frames from `append_images`; the largest goes first
    # so viewers that read only the first frame get the best one.
    frames[-1].save(
        OUTPUT,
        format="ICO",
        sizes=[(size, size) for size in SIZES],
    )

    print(f"wrote {OUTPUT} with sizes: {', '.join(f'{s}x{s}' for s in SIZES)}")


if __name__ == "__main__":
    main()
