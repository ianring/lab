#!/usr/bin/env python3
"""
SVG-to-STL Stamp Generator

Reads SVG files from the svgs/ folder, and for each one generates
a single STL file containing stamps in four sizes (30mm, 25mm, 20mm, 15mm)
arranged side by side on the same build plate, design facing up.

Each stamp has:
  - A circular base (cylinder) of the given diameter, 10mm deep
  - The SVG design extruded 3mm and placed on top of the base,
    scaled to fit within the circle with a margin

Usage:
    python generate.py              # Process all SVGs in svgs/
    python generate.py heart.svg    # Process a specific SVG
"""

import os
import sys
import glob
import math
from collections import defaultdict
import numpy as np
import trimesh
from shapely.geometry import Polygon, MultiPolygon, LineString, Point
from shapely.ops import unary_union
from svgpathtools import Document

# --- PARAMETERS ---
SIZES = [40, 30, 20, 15]       # stamp diameters in mm
BASE_DEPTH = 10.0              # height of the cylindrical base
DESIGN_DEPTH = 3.0             # height of the extruded SVG design
# Uniform sizing: every design is scaled by the SAME ratio, derived from the
# reference circle you draw around each design in Affinity. Because all designs
# live in one document at one unit scale, their exported coordinates carry their
# true relative sizes, so a single constant reproduces exactly what you drew.
DOCUMENT_DPI = 300.0          # your Affinity document's resolution (File >
#                               Document Setup). SVG units are pixels at this DPI.
CIRCLE_INCHES = 1.0           # reference-circle diameter you drew (inches)
CIRCLE_UNITS = DOCUMENT_DPI * CIRCLE_INCHES  # circle diameter in SVG units
DESIGN_FILL = 1.0             # global fill factor; 1.0 = a design that fills its
#                               reference circle fills the stamp; 0.9 leaves a
#                               10% margin on every stamp. Preserves proportions.
SECTIONS = 128                 # cylinder smoothness
STAMP_GAP = 5.0                # gap between stamps on the build plate (mm)
PATH_SAMPLE_POINTS = 200       # number of points to sample along each SVG path
STROKE_WIDTH_RATIO = 0.03      # stroke buffer width as fraction of SVG bounding box
OUTPUT_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'output')
SVG_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'svgs')


def sample_svg_path(path, num_points=PATH_SAMPLE_POINTS):
    """Sample discrete points along an svgpathtools Path object."""
    points = []
    for t in np.linspace(0, 1, num_points):
        pt = path.point(t)
        points.append((pt.real, pt.imag))
    return points


def split_into_subpaths(path):
    """
    Split an svgpathtools Path into continuous subpaths at discontinuities.
    A discontinuity occurs where one segment's end doesn't match the next
    segment's start (i.e. a new M command in the SVG path data).
    """
    from svgpathtools import Path as SvgPath
    if len(path) == 0:
        return []

    subpaths = []
    current_segments = [path[0]]

    for i in range(1, len(path)):
        prev_end = path[i - 1].end
        curr_start = path[i].start
        # If there's a jump, start a new subpath
        if abs(prev_end - curr_start) > 1e-6:
            subpaths.append(SvgPath(*current_segments))
            current_segments = [path[i]]
        else:
            current_segments.append(path[i])

    if current_segments:
        subpaths.append(SvgPath(*current_segments))

    return subpaths


def svg_paths_to_polygons(svg_file):
    """
    Parse an SVG file and convert all paths to Shapely polygons.
    Handles:
      - Multiple <path> elements
      - Compound paths (multiple M commands / subpaths within one path)
      - Closed paths (treated as filled polygons)
      - Holes: inner closed rings contained within outer rings (same path element)
      - Open strokes (buffered into solid polygons)
    Returns a merged Shapely geometry, or None if nothing was found.
    """
    # Use Document (not svg2paths2) so nested <g transform="matrix(...)">
    # transforms are flattened into the path coordinates. Affinity bakes
    # non-uniform scales and mirrors into these groups; ignoring them would
    # distort the aspect ratio and flip some designs.
    paths = Document(svg_file).paths()

    if not paths:
        return None

    # First pass: split all paths into subpaths and collect points for bbox
    all_points = []
    # Group subpaths by parent <path> element index
    path_groups = defaultdict(list)  # pi -> [(pts, is_closed), ...]

    for pi, path in enumerate(paths):
        if len(path) == 0:
            continue
        subpaths = split_into_subpaths(path)
        for subpath in subpaths:
            pts = sample_svg_path(subpath)
            all_points.extend(pts)
            path_groups[pi].append(pts)

    if not all_points:
        return None

    all_pts_array = np.array(all_points)
    bbox_width = np.max(all_pts_array[:, 0]) - np.min(all_pts_array[:, 0])
    bbox_height = np.max(all_pts_array[:, 1]) - np.min(all_pts_array[:, 1])
    max_dim = max(bbox_width, bbox_height)
    stroke_width = max(max_dim * STROKE_WIDTH_RATIO, 0.5)
    close_threshold = max_dim * 0.01  # 1% of bbox = "close enough" to be closed

    # Second pass: process each <path> element's subpaths,
    # detecting holes via containment within the same path element
    polygons = []

    for pi, subpath_points_list in path_groups.items():
        closed_rings = []   # valid closed Polygon rings from this path
        open_strokes = []   # open subpaths to buffer as strokes

        for points in subpath_points_list:
            if len(points) < 2:
                continue

            start = points[0]
            end = points[-1]
            dist = math.sqrt((start[0] - end[0])**2 + (start[1] - end[1])**2)
            is_closed = dist <= close_threshold

            if is_closed and len(points) >= 3:
                try:
                    poly = Polygon(points)
                    if not poly.is_valid:
                        # Fix self-intersecting paths (e.g. crescents where
                        # outer arc goes forward and inner arc comes back)
                        poly = poly.buffer(0)
                    if not poly.is_empty and poly.area > 0.1:
                        # buffer(0) may produce a MultiPolygon
                        if isinstance(poly, MultiPolygon):
                            closed_rings.extend(poly.geoms)
                        else:
                            closed_rings.append(poly)
                        continue
                except Exception:
                    pass

            # Open or failed as polygon → treat as stroke
            open_strokes.append(points)

        # Combine the closed rings of this compound path with the even-odd
        # rule: the filled area is the XOR (symmetric difference) of all the
        # subpath rings, so overlapping regions toggle solid/hole. This is the
        # true meaning of fill-rule:evenodd and handles any nesting depth
        # (a simple hole, a hole with a solid island inside it, the yin-yang
        # swirl plus its two dots, etc.), unlike a largest-ring-is-outer guess.
        if closed_rings:
            eo = closed_rings[0]
            for ring in closed_rings[1:]:
                try:
                    eo = eo.symmetric_difference(ring)
                except Exception:
                    # If a XOR step fails, fall back to unioning it in solid
                    eo = eo.union(ring)
            # Keep only the polygonal parts (XOR can emit stray lines/points
            # where rings touch); MultiPolygon/GeometryCollection both iterate.
            if isinstance(eo, Polygon):
                if not eo.is_empty:
                    polygons.append(eo)
            elif hasattr(eo, 'geoms'):
                polygons.extend(g for g in eo.geoms
                                if isinstance(g, Polygon) and not g.is_empty)

        # Process open strokes: buffer into solid polygons
        for points in open_strokes:
            if len(points) < 2:
                continue
            try:
                line = LineString(points)
                buffered = line.buffer(stroke_width, cap_style=2, join_style=2)
                if not buffered.is_empty and buffered.area > 0.1:
                    polygons.append(buffered)
            except Exception:
                continue

    if not polygons:
        return None

    # Merge all polygons into a single geometry
    merged = unary_union(polygons)
    return merged


def design_center(geometry):
    """
    Return the (x, y) point to place at the stamp center.

    We use the center of the design's MINIMUM ENCLOSING CIRCLE, because that is
    how a shape is positioned to fit inside a reference circle. For an upward
    triangle this is the circumcenter (a third up from the base), so all three
    vertices sit equidistant from the edge — unlike the bounding-box center,
    which sits at half height and lets the base corners spill out. For a
    symmetric design it coincides with the bbox center, so nothing changes.

    Falls back to the area centroid, then the bbox center, on older Shapely.
    """
    try:
        import shapely
        circle = shapely.minimum_bounding_circle(geometry)
        c = circle.centroid
        return c.x, c.y
    except Exception:
        try:
            c = geometry.centroid
            return c.x, c.y
        except Exception:
            minx, miny, maxx, maxy = geometry.bounds
            return (minx + maxx) / 2.0, (miny + maxy) / 2.0


def fit_geometry_to_circle(geometry, diameter):
    """
    Scale a Shapely geometry by ONE constant ratio and center it in the stamp.

    The ratio is the stamp diameter divided by the reference-circle diameter
    (CIRCLE_UNITS), so every design is resized by the same amount and its true
    size relative to the circle is preserved exactly as drawn in Affinity.
    Aspect ratio is untouched (x and y scale identically). Placement uses the
    design's enclosing-circle center (see design_center), which matches how you
    fit each design inside its reference circle.
    """
    from shapely.affinity import translate, scale as shapely_scale

    scale_factor = (diameter / CIRCLE_UNITS) * DESIGN_FILL

    cx, cy = design_center(geometry)

    centered = translate(geometry, -cx, -cy)
    fitted = shapely_scale(centered, xfact=scale_factor, yfact=scale_factor, origin=(0, 0))

    return fitted


def create_stamp(geometry, diameter):
    """
    Create a single stamp mesh: circular base + extruded SVG design on top.
    The design faces up (+Z). Base bottom sits at z=0.
    """
    # 1. Fit the design to the stamp circle
    fitted = fit_geometry_to_circle(geometry, diameter)
    if fitted is None:
        print(f"  ERROR: Could not fit geometry at {diameter}mm")
        return None


    # 2. Create the circular base cylinder
    base = trimesh.creation.cylinder(
        radius=diameter / 2.0,
        height=BASE_DEPTH,
        sections=SECTIONS
    )
    # Position so bottom is at z=0
    base.apply_translation([0, 0, BASE_DEPTH / 2.0])

    # 3. Extrude the SVG design using trimesh's built-in extrusion
    #    (produces watertight manifold meshes, unlike manual triangulation)
    #    Handle MultiPolygon by extruding each part separately
    try:
        if isinstance(fitted, MultiPolygon):
            parts = [trimesh.creation.extrude_polygon(p, DESIGN_DEPTH)
                     for p in fitted.geoms]
            design_mesh = trimesh.util.concatenate(parts)
        else:
            design_mesh = trimesh.creation.extrude_polygon(fitted, DESIGN_DEPTH)
    except Exception as e:
        print(f"  ERROR: Extrusion failed at {diameter}mm: {e}")
        return None

    # Position the design on top of the base, overlapping 0.5mm into
    # the cylinder so the boolean union has clean intersecting volume
    design_mesh.apply_translation([0, 0, BASE_DEPTH - 0.5])

    # 4. Union the base and design
    try:
        stamp = base.union(design_mesh)
    except Exception:
        # Fallback: concatenate meshes if boolean fails
        print(f"  Warning: Boolean union failed, concatenating meshes instead")
        stamp = trimesh.util.concatenate([base, design_mesh])

    return stamp


def process_svg(svg_file):
    """
    Process a single SVG file, generating all four stamp sizes
    arranged side by side on the same build plate in one STL.
    Stamps are placed in a row along the X axis, largest to smallest.
    """
    basename = os.path.splitext(os.path.basename(svg_file))[0]
    print(f"\nProcessing: {basename}")

    # Parse SVG once, reuse for all sizes
    geometry = svg_paths_to_polygons(svg_file)
    if geometry is None:
        print(f"  ERROR: No valid paths found in {svg_file}")
        return

    stamps = []
    cursor_x = 0.0  # tracks the X position for placing each stamp

    for diameter in SIZES:
        print(f"  Generating {diameter}mm stamp...")
        stamp = create_stamp(geometry, diameter)
        if stamp is None:
            continue

        # Translate so this stamp's center is at cursor_x + radius
        radius = diameter / 2.0
        stamp.apply_translation([cursor_x + radius, 0, 0])
        stamps.append(stamp)

        # Advance cursor past this stamp + gap
        cursor_x += diameter + STAMP_GAP

    if not stamps:
        print(f"  ERROR: No stamps generated for {basename}")
        return

    # Combine all stamps into one mesh
    combined = trimesh.util.concatenate(stamps)

    output_file = os.path.join(OUTPUT_DIR, f"{basename}.stl")
    combined.export(output_file)
    print(f"  ✓ Saved: {output_file} ({len(combined.faces)} faces)")


def main():
    os.makedirs(OUTPUT_DIR, exist_ok=True)

    if len(sys.argv) > 1:
        # Process specific files
        svg_files = []
        for arg in sys.argv[1:]:
            if os.path.isabs(arg):
                svg_files.append(arg)
            else:
                svg_files.append(os.path.join(SVG_DIR, arg))
    else:
        # Process all SVGs in the svgs/ directory
        svg_files = sorted(glob.glob(os.path.join(SVG_DIR, '*.svg')))

    if not svg_files:
        print("No SVG files found in svgs/ directory.")
        print("Place your SVG files in the svgs/ folder and run again.")
        return

    print(f"Found {len(svg_files)} SVG file(s) to process.")
    print(f"Sizes: {', '.join(str(s) + 'mm' for s in SIZES)}")
    print(f"Base depth: {BASE_DEPTH}mm, Design depth: {DESIGN_DEPTH}mm")

    for svg_file in svg_files:
        if not os.path.exists(svg_file):
            print(f"  File not found: {svg_file}")
            continue
        process_svg(svg_file)

    print(f"\n--- Done. Output files are in: {OUTPUT_DIR} ---")


if __name__ == '__main__':
    main()
