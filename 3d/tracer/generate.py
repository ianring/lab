#!/usr/bin/env python3
"""
Raster-to-SVG Tracer

Reads raster images (PNG/JPG/etc.) from the rasters/ folder and traces each
one into a vector SVG in the output/ folder, using potrace.

The pipeline for each image is:
    1. Load and convert to grayscale (compositing transparency over BACKGROUND)
    2. Optional Gaussian blur to tame noise before thresholding
    3. Threshold at THRESHOLD (0-255) into a black/white bitmap
    4. Trace the bitmap into smooth Bezier curves with potrace
    5. Emit an SVG whose viewBox matches the original pixel dimensions

Almost everything is configurable, either by editing the constants below or
via command-line flags (flags win). Run with -h to see them all.

Dependencies:
    pip install pillow numpy potracer
    (the C-based 'pypotrace' uses the same import name and API, but fills the
    opposite cells — if you switch to it and traces come out inverted, drop the
    np.invert() in trace_to_paths.)

Usage:
    python generate.py                          # trace every raster
    python generate.py zodiac-woodcut.png       # trace one file
    python generate.py --threshold 150          # override the grey cutoff
    python generate.py --invert --turdsize 5    # trace light-on-dark, despeckle
"""

import os
import sys
import glob
import argparse

import numpy as np
from PIL import Image, ImageFilter

try:
    import potrace
except ImportError:
    sys.exit(
        "ERROR: the 'potrace' module is not installed.\n"
        "Install a tracer backend with:  pip install potracer\n"
        "(or the C-based 'pypotrace' — same import name and API)"
    )

# --- TRACING PARAMETERS ---
THRESHOLD = 128         # grey cutoff 0-255; pixels darker than this are filled
INVERT = False          # True traces light shapes on a dark background instead
BLUR_RADIUS = 0.0       # Gaussian blur radius (px) applied before thresholding
BACKGROUND = 255        # grey level (0-255) that transparent pixels sit on
UPSCALE = 1.0           # resize the raster by this factor before tracing; >1
#                         captures finer detail and smoother curves from
#                         small/low-res sources (e.g. 3 or 4 for line art)

# --- POTRACE CURVE PARAMETERS ---
TURDSIZE = 2            # suppress speckles smaller than this many pixels
TURNPOLICY = 'minority'  # how to resolve ambiguous turns: black/white/left/
#                          right/minority/majority/random
ALPHAMAX = 1.0          # corner threshold; higher = smoother/rounder corners
OPTICURVE = True        # optimize/merge Bezier segments where possible
OPTTOLERANCE = 0.2      # curve optimization tolerance (higher = simpler curves)

# --- OUTPUT PARAMETERS ---
SCALE = 1.0             # multiply the SVG's declared width/height by this
FLIP_Y = False          # flip vertically if the trace comes out upside down
FILL_COLOR = '#000000'  # fill color of the traced shape
DRAW_BACKGROUND = False  # if True, draw a BACKGROUND_COLOR rectangle behind it
BACKGROUND_COLOR = '#ffffff'

# --- FILE LOCATIONS ---
RASTER_EXTS = ('png', 'jpg', 'jpeg', 'bmp', 'gif', 'tif', 'tiff', 'webp')
OUTPUT_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'output')
RASTER_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'rasters')


def resolve_turnpolicy(name):
    """Map a turn-policy name to whatever constant the installed backend uses,
    tolerating the slightly different naming in potracer vs pypotrace."""
    key = name.upper()
    for attr in (f"TURNPOLICY_{key}", f"POTRACE_TURNPOLICY_{key}"):
        if hasattr(potrace, attr):
            return getattr(potrace, attr)
    return None  # fall back to the backend default


def point_xy(p):
    """Extract (x, y) from a potrace point, which may be a numpy array,
    a tuple, or an object with .x / .y depending on the backend."""
    try:
        return float(p[0]), float(p[1])
    except (TypeError, KeyError, IndexError):
        return float(p.x), float(p.y)


def load_bitmap(image_path, cfg):
    """Load a raster and return (bitmap, width, height) where bitmap is a 2D
    numpy bool array with True = foreground (the area potrace should fill)."""
    img = Image.open(image_path)

    # Composite any transparency onto the configured background grey, so that
    # transparent regions don't get traced as solid shapes.
    if img.mode in ('RGBA', 'LA') or (img.mode == 'P' and 'transparency' in img.info):
        rgba = img.convert('RGBA')
        bg = Image.new('RGBA', rgba.size, (cfg.background,) * 3 + (255,))
        img = Image.alpha_composite(bg, rgba)

    gray = img.convert('L')

    # Upscale before tracing so potrace has more pixels to follow, capturing
    # finer detail and placing edges more precisely (LANCZOS keeps it crisp).
    if cfg.upscale != 1.0:
        new_size = (max(1, round(gray.width * cfg.upscale)),
                    max(1, round(gray.height * cfg.upscale)))
        gray = gray.resize(new_size, Image.LANCZOS)

    if cfg.blur_radius > 0:
        gray = gray.filter(ImageFilter.GaussianBlur(cfg.blur_radius))

    width, height = gray.size
    data = np.asarray(gray)

    foreground = data < cfg.threshold       # dark pixels are the shape
    if cfg.invert:
        foreground = ~foreground

    return foreground, width, height


def trace_to_paths(bitmap, cfg):
    """Run potrace on a boolean bitmap and return a list of SVG path 'd'
    strings (one per traced curve)."""
    # 'bitmap' is True where the foreground (the shape) is. The pure-Python
    # 'potracer' backend fills the False cells, so hand it the inverse — that
    # way the foreground gets traced as the solid shape, not the background.
    bmp = potrace.Bitmap(np.invert(bitmap))

    trace_kwargs = dict(
        turdsize=cfg.turdsize,
        alphamax=cfg.alphamax,
        opticurve=bool(cfg.opticurve),
        opttolerance=cfg.opttolerance,
    )
    turnpolicy = resolve_turnpolicy(cfg.turnpolicy)
    if turnpolicy is not None:
        trace_kwargs['turnpolicy'] = turnpolicy

    path = bmp.trace(**trace_kwargs)

    d_strings = []
    for curve in path:
        sx, sy = point_xy(curve.start_point)
        parts = [f"M {sx:.3f} {sy:.3f}"]
        for segment in curve:
            ex, ey = point_xy(segment.end_point)
            if segment.is_corner:
                cx, cy = point_xy(segment.c)
                parts.append(f"L {cx:.3f} {cy:.3f} L {ex:.3f} {ey:.3f}")
            else:
                c1x, c1y = point_xy(segment.c1)
                c2x, c2y = point_xy(segment.c2)
                parts.append(
                    f"C {c1x:.3f} {c1y:.3f} {c2x:.3f} {c2y:.3f} {ex:.3f} {ey:.3f}"
                )
        parts.append("Z")
        d_strings.append(" ".join(parts))

    return d_strings


def build_svg(d_strings, width, height, cfg):
    """Assemble the final SVG document string.

    width/height are at trace resolution (already upscaled); the viewBox uses
    them so the curve coordinates line up, while the declared display size is
    divided back down by UPSCALE so the SVG stays its original physical size."""
    out_w = width / cfg.upscale * cfg.scale
    out_h = height / cfg.upscale * cfg.scale

    # potrace returns curves in image space (y increasing downward), which
    # already matches SVG. FLIP_Y is a safety valve if a backend disagrees.
    transform = (
        f' transform="translate(0,{height}) scale(1,-1)"' if cfg.flip_y else ''
    )

    combined = " ".join(d_strings)
    lines = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        f'<svg xmlns="http://www.w3.org/2000/svg" '
        f'width="{out_w:.3f}" height="{out_h:.3f}" '
        f'viewBox="0 0 {width} {height}">',
    ]
    if cfg.draw_background:
        lines.append(
            f'  <rect width="{width}" height="{height}" '
            f'fill="{cfg.background_color}"/>'
        )
    lines.append(
        f'  <path d="{combined}" fill="{cfg.fill_color}" '
        f'fill-rule="evenodd"{transform}/>'
    )
    lines.append('</svg>')
    return "\n".join(lines)


def process_raster(image_path, cfg):
    """Trace a single raster image into output/<name>.svg."""
    basename = os.path.splitext(os.path.basename(image_path))[0]
    print(f"\nProcessing: {os.path.basename(image_path)}")

    try:
        bitmap, width, height = load_bitmap(image_path, cfg)
    except Exception as e:
        print(f"  ERROR: could not load image: {e}")
        return

    filled = int(np.count_nonzero(bitmap))
    total = bitmap.size
    print(f"  {width}x{height}px, threshold {cfg.threshold}"
          f"{' (inverted)' if cfg.invert else ''}, "
          f"{100.0 * filled / total:.1f}% filled")

    try:
        d_strings = trace_to_paths(bitmap, cfg)
    except Exception as e:
        print(f"  ERROR: tracing failed: {e}")
        return

    if not d_strings:
        print("  WARNING: nothing traced — try adjusting --threshold or --invert")
        return

    svg = build_svg(d_strings, width, height, cfg)
    output_file = os.path.join(OUTPUT_DIR, f"{basename}.svg")
    with open(output_file, 'w') as f:
        f.write(svg)
    print(f"  ✓ Saved: {output_file} ({len(d_strings)} path(s))")


def parse_args():
    """CLI flags default to the constants above; anything passed overrides."""
    p = argparse.ArgumentParser(
        description="Trace raster images in rasters/ into SVGs in output/.")
    p.add_argument('files', nargs='*',
                   help="specific raster filenames (default: all in rasters/)")
    p.add_argument('--threshold', type=int, default=THRESHOLD,
                   help=f"grey cutoff 0-255 (default {THRESHOLD})")
    p.add_argument('--invert', action='store_true', default=INVERT,
                   help="trace light shapes on a dark background")
    p.add_argument('--blur-radius', type=float, default=BLUR_RADIUS,
                   help=f"pre-threshold blur in px (default {BLUR_RADIUS})")
    p.add_argument('--background', type=int, default=BACKGROUND,
                   help=f"grey level under transparency (default {BACKGROUND})")
    p.add_argument('--upscale', type=float, default=UPSCALE,
                   help=f"resize factor before tracing for more detail "
                        f"(default {UPSCALE})")
    p.add_argument('--turdsize', type=int, default=TURDSIZE,
                   help=f"despeckle threshold in px (default {TURDSIZE})")
    p.add_argument('--turnpolicy', default=TURNPOLICY,
                   help=f"turn policy (default {TURNPOLICY})")
    p.add_argument('--alphamax', type=float, default=ALPHAMAX,
                   help=f"corner smoothness (default {ALPHAMAX})")
    p.add_argument('--no-opticurve', dest='opticurve', action='store_false',
                   default=OPTICURVE, help="disable curve optimization")
    p.add_argument('--opttolerance', type=float, default=OPTTOLERANCE,
                   help=f"curve optimization tolerance (default {OPTTOLERANCE})")
    p.add_argument('--scale', type=float, default=SCALE,
                   help=f"output width/height multiplier (default {SCALE})")
    p.add_argument('--flip-y', action='store_true', default=FLIP_Y,
                   help="flip vertically if the trace is upside down")
    p.add_argument('--fill-color', default=FILL_COLOR,
                   help=f"traced fill color (default {FILL_COLOR})")
    p.add_argument('--draw-background', action='store_true', default=DRAW_BACKGROUND,
                   help="draw a solid background rectangle")
    p.add_argument('--background-color', default=BACKGROUND_COLOR,
                   help=f"background rectangle color (default {BACKGROUND_COLOR})")
    return p.parse_args()


def main():
    cfg = parse_args()
    os.makedirs(OUTPUT_DIR, exist_ok=True)

    if cfg.files:
        image_files = [
            f if os.path.isabs(f) else os.path.join(RASTER_DIR, f)
            for f in cfg.files
        ]
    else:
        image_files = []
        for ext in RASTER_EXTS:
            image_files.extend(glob.glob(os.path.join(RASTER_DIR, f'*.{ext}')))
            image_files.extend(glob.glob(os.path.join(RASTER_DIR, f'*.{ext.upper()}')))
        image_files = sorted(set(image_files))

    if not image_files:
        print("No raster images found in rasters/ directory.")
        print(f"Supported: {', '.join(RASTER_EXTS)}")
        return

    print(f"Found {len(image_files)} image(s) to trace.")

    for image_path in image_files:
        if not os.path.exists(image_path):
            print(f"  File not found: {image_path}")
            continue
        process_raster(image_path, cfg)

    print(f"\n--- Done. SVGs are in: {OUTPUT_DIR} ---")


if __name__ == '__main__':
    main()
