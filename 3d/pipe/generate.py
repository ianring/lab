#!/usr/bin/env python3
"""
Coffin Pipe Generator  --  SCAFFOLD

Builds a coffin-shaped smoking pipe: a flat-sided hexagonal "coffin" body
standing on end, with a tapered tobacco chamber bored down from the top and a
draught hole bored horizontally through the foot to meet it.

    plan view (XY)            elevation (XZ)
         ____                  ______
        /    \                |      |   <- chamber bored down from the top
       |      |               |  ()  |
       |      |               |  ||  |
        \    /                |  |+--|-- draught hole to the shank face
         \__/                 |______|

The plan silhouette comes from svg/outline.svg if you drop one in (right side
up, any scale -- it gets fitted to LENGTH_MM x WIDTH_MM). With no SVG present
it falls back to a procedural coffin hexagon so the script runs out of the box.

STATUS: this is a starting skeleton, not a finished pipe. The parameters below
are placeholders picked to look right rather than measured, and these are NOT
modelled yet:
    - the shank/tenon joint and the mouthpiece (stem is a plain bore for now)
    - draught-hole entry at the true chamber floor (it currently meets the
      chamber wall, which leaves a small sump)
    - any chamfer or crown treatment on the rim

Usage:
    python generate.py                  # writes output/pipe.stl
    python generate.py my_variant.stl   # writes output/my_variant.stl

Dependencies:
    pip install numpy trimesh shapely svgpathtools
"""

import os
import sys
import math

import numpy as np
import trimesh
from shapely.geometry import Polygon
from svgpathtools import Document

HERE = os.path.dirname(os.path.abspath(__file__))
SVG_DIR = os.path.join(HERE, 'svg')
OUTPUT_DIR = os.path.join(HERE, 'output')
OUTLINE_SVG = os.path.join(SVG_DIR, 'outline.svg')

# ============================================================================
# PARAMETERS  (placeholders -- measure a real pipe and replace these)
# ============================================================================

LENGTH_MM = 55.0            # coffin long axis (heel to rim), in plan
WIDTH_MM = 32.0             # coffin across the shoulders, in plan
THICKNESS_MM = 30.0         # how thick the slab is (the pipe's depth)

SHOULDER_FRACTION = 0.30    # how far up the length the widest point sits
HEAD_FRACTION = 0.55        # head width as a fraction of shoulder width
FOOT_FRACTION = 0.45        # foot width as a fraction of shoulder width

CHAMBER_TOP_DIA = 20.0      # tobacco chamber diameter at the rim
CHAMBER_BOTTOM_DIA = 16.0   # diameter at the chamber floor (slight taper)
CHAMBER_DEPTH = 36.0        # how far down from the rim the chamber goes

DRAUGHT_DIA = 4.0           # draught hole (airway) diameter
DRAUGHT_HEIGHT = 8.0        # height of the airway above the base

PATH_SAMPLE_POINTS = 200    # samples per SVG subpath when tracing the outline
REVOLVE_SECTIONS = 96       # facets on the bored cylinders


# ============================================================================
# OUTLINE
# ============================================================================

def coffin_outline():
    """The default coffin hexagon in plan, as a Shapely polygon.

    Origin at the centre of the foot; +Y runs toward the rim end. Sized
    directly in mm so no fitting step is needed.
    """
    half_w = WIDTH_MM / 2.0
    shoulder_y = LENGTH_MM * SHOULDER_FRACTION
    head_half = half_w * HEAD_FRACTION
    foot_half = half_w * FOOT_FRACTION

    return Polygon([
        (-foot_half, 0.0),
        (-half_w, shoulder_y),
        (-head_half, LENGTH_MM),
        (head_half, LENGTH_MM),
        (half_w, shoulder_y),
        (foot_half, 0.0),
    ])


def outline_from_svg(svg_file):
    """Trace the largest closed path in an SVG into a plan polygon.

    Document() is used rather than svg2paths so that nested <g transform>
    matrices are flattened into the coordinates -- Affinity bakes scales and
    mirrors into those groups, and ignoring them distorts the silhouette.
    """
    paths = Document(svg_file).paths()
    if not paths:
        return None

    best = None
    for path in paths:
        if len(path) == 0:
            continue
        ts = np.linspace(0.0, 1.0, PATH_SAMPLE_POINTS)
        pts = [(path.point(t).real, path.point(t).imag) for t in ts]
        poly = Polygon(pts)
        if not poly.is_valid:
            poly = poly.buffer(0)
        if poly.is_empty:
            continue
        if best is None or poly.area > best.area:
            best = poly

    if best is None:
        return None

    # SVG Y grows downward; flip so the rim end points at +Y.
    best = Polygon([(x, -y) for x, y in best.exterior.coords])

    # Fit to the target footprint, then seat the foot on Y=0, centred on X.
    min_x, min_y, max_x, max_y = best.bounds
    scale = min(WIDTH_MM / (max_x - min_x), LENGTH_MM / (max_y - min_y))
    coords = [((x - (min_x + max_x) / 2.0) * scale, (y - min_y) * scale)
              for x, y in best.exterior.coords]
    return Polygon(coords)


def load_outline():
    if os.path.exists(OUTLINE_SVG):
        poly = outline_from_svg(OUTLINE_SVG)
        if poly is not None:
            print(f"Outline: {os.path.relpath(OUTLINE_SVG, HERE)}")
            return poly
        print(f"Warning: no usable path in {OUTLINE_SVG}, using the default.")
    print("Outline: procedural coffin hexagon")
    return coffin_outline()


# ============================================================================
# BUILD
# ============================================================================

def build_body(outline):
    """Extrude the plan outline into the slab, standing rim-up.

    Extrusion runs along +Z, then the whole thing is rotated so the plan's +Y
    (foot -> rim) becomes world +Z, which is how the pipe actually stands.
    """
    slab = trimesh.creation.extrude_polygon(outline, height=THICKNESS_MM)
    slab.apply_transform(
        trimesh.transformations.rotation_matrix(math.pi / 2, [1, 0, 0]))
    slab.apply_translation([0, 0, -slab.bounds[0][2]])
    return slab


def build_chamber(body):
    """The tapered tobacco chamber, bored down from the rim.

    Built as a cylinder whose vertices are scaled by height, so it narrows from
    CHAMBER_TOP_DIA at the rim to CHAMBER_BOTTOM_DIA at the floor. It runs
    OVERSHOOT above the rim so the boolean never has to cut coplanar faces.
    """
    OVERSHOOT = 1.0
    total_h = CHAMBER_DEPTH + OVERSHOOT

    chamber = trimesh.creation.cylinder(
        radius=CHAMBER_TOP_DIA / 2.0,
        height=total_h,
        sections=REVOLVE_SECTIONS,
    )

    taper = CHAMBER_BOTTOM_DIA / CHAMBER_TOP_DIA
    verts = chamber.vertices.copy()
    z_min = verts[:, 2].min()
    for i, (x, y, z) in enumerate(verts):
        # 0 at the chamber floor, 1 at the rim and above (the overshoot stays
        # at full diameter -- it is waste, cut away outside the body).
        t = min((z - z_min) / CHAMBER_DEPTH, 1.0)
        k = taper + (1.0 - taper) * t
        verts[i] = [x * k, y * k, z]
    chamber = trimesh.Trimesh(vertices=verts, faces=chamber.faces)

    # Put the top of the cylinder OVERSHOOT above the rim.
    rim_z = body.bounds[1][2]
    chamber.apply_translation([0, 0, rim_z + OVERSHOOT - total_h / 2.0])
    return chamber


def build_draught(body):
    """The airway, bored horizontally through the foot along -Y."""
    depth = body.extents[1] * 2.0
    bore = trimesh.creation.cylinder(
        radius=DRAUGHT_DIA / 2.0,
        height=depth,
        sections=REVOLVE_SECTIONS,
    )
    bore.apply_transform(
        trimesh.transformations.rotation_matrix(math.pi / 2, [1, 0, 0]))
    bore.apply_translation([0, 0, DRAUGHT_HEIGHT])
    return bore


def build_pipe():
    outline = load_outline()

    print("Extruding body...")
    body = build_body(outline)

    print("Boring chamber...")
    body = body.difference(build_chamber(body))

    print("Boring draught hole...")
    body = body.difference(build_draught(body))

    return body


def main():
    name = sys.argv[1] if len(sys.argv) > 1 else 'pipe.stl'
    if not name.lower().endswith('.stl'):
        name += '.stl'

    pipe = build_pipe()

    os.makedirs(OUTPUT_DIR, exist_ok=True)
    out_path = os.path.join(OUTPUT_DIR, name)
    pipe.export(out_path)

    w, d, h = pipe.extents
    print(f"--- SUCCESS: {os.path.relpath(out_path, HERE)} ---")
    print(f"width {w:.1f} mm  depth {d:.1f} mm  height {h:.1f} mm")


if __name__ == '__main__':
    main()
