#!/usr/bin/env python3
"""
SVG-to-STL Cookie Cutter Generator

Reads SVG files from the svgs/ folder and, for each one, generates an STL
cookie cutter that traces the SVG outline.

Each cutter is a vertical wall whose cross-section changes with height:

    z = CUTTER_DEPTH  ___________   <- top (non-cutting) edge
                     |   RIDGE   |     thick reinforcing ridge (RIDGE_THICKNESS)
                     |___________|
                       |       |
                       |  WALL |        structural outline wall (WALL_THICKNESS)
                       |       |
                        \     /
                         \   /         taper section (TAPER_HEIGHT tall)
                          \ /
    z = 0                  V           <- sharp cutting edge (CUTTING_EDGE_THICKNESS)

The whole thing is CUTTER_DEPTH tall (1 inch by default). The cutting edge
faces down (-Z) so it sits ready to print and ready to cut.

Each SVG produces one STL containing a cutter in each of SIZES (3cm / 5cm /
7cm longest-dimension by default), laid out side by side on the build plate.

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
from shapely.geometry import Polygon, MultiPolygon, LineString
from shapely.affinity import translate, scale as shapely_scale
from shapely.ops import unary_union
from svgpathtools import svg2paths2

# --- GEOMETRY PARAMETERS (all in mm) ---
CUTTER_DEPTH = 25.4            # total height of the cutter (1 inch)
WALL_THICKNESS = 2.0          # thickness of the structural outline wall
CUTTING_EDGE_THICKNESS = 0.4  # thickness of the sharp cutting edge at the blade
TAPER_HEIGHT = 8.0            # height over which the edge tapers up to the wall
RIDGE_THICKNESS = 5.0         # thickness of the reinforcing top (non-cutting) ridge
RIDGE_HEIGHT = 4.0            # height of the top ridge band
SIZES = [30.0, 50.0, 70.0]   # longest-dimension of each cutter (3cm / 5cm / 7cm)
CUTTER_GAP = 8.0             # gap between cutters on the build plate

# --- PROCESSING PARAMETERS ---
SAMPLE_SPACING = 0.75         # outline resample spacing (smaller = smoother, slower)
PATH_SAMPLE_POINTS = 400      # points sampled along each raw SVG path
STROKE_WIDTH_RATIO = 0.03     # stroke buffer width as fraction of SVG bounding box
OUTPUT_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'output')
SVG_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'svgs')


# --------------------------------------------------------------------------
# SVG parsing (shared approach with ../stamps/generate.py)
# --------------------------------------------------------------------------

def sample_svg_path(path, num_points=PATH_SAMPLE_POINTS):
    """Sample discrete points along an svgpathtools Path object."""
    points = []
    for t in np.linspace(0, 1, num_points):
        pt = path.point(t)
        points.append((pt.real, pt.imag))
    return points


def split_into_subpaths(path):
    """
    Split an svgpathtools Path into continuous subpaths at discontinuities
    (i.e. wherever a new M command starts a disconnected subpath).
    """
    from svgpathtools import Path as SvgPath
    if len(path) == 0:
        return []

    subpaths = []
    current_segments = [path[0]]

    for i in range(1, len(path)):
        prev_end = path[i - 1].end
        curr_start = path[i].start
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
    Parse an SVG file and convert all paths into a single merged Shapely
    geometry. Handles multiple paths, compound paths, holes, and open
    strokes (which are buffered into thin solids). Returns a Shapely
    Polygon/MultiPolygon, or None if nothing usable was found.
    """
    paths, attributes, svg_attributes = svg2paths2(svg_file)

    if not paths:
        return None

    all_points = []
    path_groups = defaultdict(list)  # parent <path> index -> [subpath points, ...]

    for pi, path in enumerate(paths):
        if len(path) == 0:
            continue
        for subpath in split_into_subpaths(path):
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
    close_threshold = max_dim * 0.01  # within 1% of bbox = "closed"

    polygons = []

    for pi, subpath_points_list in path_groups.items():
        closed_rings = []
        open_strokes = []

        for points in subpath_points_list:
            if len(points) < 2:
                continue

            start = points[0]
            end = points[-1]
            dist = math.hypot(start[0] - end[0], start[1] - end[1])
            is_closed = dist <= close_threshold

            if is_closed and len(points) >= 3:
                try:
                    poly = Polygon(points)
                    if not poly.is_valid:
                        poly = poly.buffer(0)
                    if not poly.is_empty and poly.area > 0.1:
                        if isinstance(poly, MultiPolygon):
                            closed_rings.extend(poly.geoms)
                        else:
                            closed_rings.append(poly)
                        continue
                except Exception:
                    pass

            open_strokes.append(points)

        # Detect holes: smaller closed rings contained inside larger ones.
        if closed_rings:
            closed_rings.sort(key=lambda p: p.area, reverse=True)
            used_as_hole = set()

            for i, outer in enumerate(closed_rings):
                if i in used_as_hole:
                    continue

                hole_coords = []
                for j in range(i + 1, len(closed_rings)):
                    if j in used_as_hole:
                        continue
                    inner = closed_rings[j]
                    if outer.contains(inner):
                        hole_coords.append(list(inner.exterior.coords))
                        used_as_hole.add(j)

                if hole_coords:
                    try:
                        poly_with_holes = Polygon(outer.exterior.coords, hole_coords)
                        if poly_with_holes.is_valid:
                            polygons.append(poly_with_holes)
                        else:
                            polygons.append(outer)
                    except Exception:
                        polygons.append(outer)
                else:
                    polygons.append(outer)

        # Open strokes become thin buffered solids.
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

    return unary_union(polygons)


# --------------------------------------------------------------------------
# Cutter construction
# --------------------------------------------------------------------------

def fit_geometry(geometry, target_size):
    """Center a geometry on the origin and scale so its largest bounding-box
    dimension equals target_size."""
    minx, miny, maxx, maxy = geometry.bounds
    width = maxx - minx
    height = maxy - miny
    max_dim = max(width, height)
    if max_dim == 0:
        return None

    centered = translate(geometry, -(minx + maxx) / 2.0, -(miny + maxy) / 2.0)
    factor = target_size / max_dim
    return shapely_scale(centered, xfact=factor, yfact=factor, origin=(0, 0))


def geometry_rings(geometry):
    """Return every closed ring (exteriors and holes) in a Polygon/MultiPolygon
    as a list of Shapely LinearRing objects. Each ring becomes one cutter wall."""
    if isinstance(geometry, MultiPolygon):
        polys = list(geometry.geoms)
    elif isinstance(geometry, Polygon):
        polys = [geometry]
    else:
        return []

    rings = []
    for poly in polys:
        if poly.is_empty or poly.exterior is None:
            continue
        rings.append(poly.exterior)
        rings.extend(poly.interiors)
    return rings


def resample_ring(ring, spacing=SAMPLE_SPACING):
    """Resample a closed ring to evenly spaced points (by arc length).
    Returns an (N, 2) array of distinct points (the closing point is omitted)."""
    line = LineString(ring.coords)
    length = line.length
    if length == 0:
        return None
    n = max(int(round(length / spacing)), 12)
    pts = [line.interpolate(k / n, normalized=True) for k in range(n)]
    return np.array([[p.x, p.y] for p in pts])


def make_cross_section():
    """
    Build the vertical wall cross-section in the (offset, height) plane.
    'offset' is the signed distance from the outline centerline; the profile
    is symmetric about offset = 0, so it stands centered on the outline.

    Going counterclockwise, bottom-up the right side and back down the left:
      sharp cutting edge -> taper out -> wall -> ridge step out -> ridge top
    """
    e = CUTTING_EDGE_THICKNESS / 2.0
    w = WALL_THICKNESS / 2.0
    r = RIDGE_THICKNESS / 2.0
    D = CUTTER_DEPTH
    th = TAPER_HEIGHT
    rh = RIDGE_HEIGHT

    return np.array([
        [-e, 0.0],      # cutting edge, left
        [e, 0.0],       # cutting edge, right
        [w, th],        # taper up to full wall, right
        [w, D - rh],    # wall up to ridge, right
        [r, D - rh],    # step out to ridge, right
        [r, D],         # ridge top, right
        [-r, D],        # ridge top, left
        [-r, D - rh],   # step in from ridge, left
        [-w, D - rh],   # wall down, left
        [-w, th],       # taper back down to edge, left
    ])


def sweep_ring(points, profile):
    """
    Sweep a closed 2D cross-section 'profile' (M points of (offset, z)) along a
    closed outline 'points' (N points of (x, y)), producing a watertight tube.

    At each outline point the cross-section is placed in the plane spanned by
    the outline's local normal and the Z axis. Because the profile is symmetric
    about offset = 0, the normal's sign does not matter.
    """
    pts = np.asarray(points, dtype=float)
    prof = np.asarray(profile, dtype=float)
    N = len(pts)
    M = len(prof)

    # Outward (or inward; symmetric so it doesn't matter) normal at each point,
    # from the perpendicular of the local tangent.
    nxt = np.roll(pts, -1, axis=0)
    prv = np.roll(pts, 1, axis=0)
    tangents = nxt - prv
    lengths = np.hypot(tangents[:, 0], tangents[:, 1])
    lengths[lengths == 0] = 1.0
    tangents /= lengths[:, None]
    normals = np.column_stack([tangents[:, 1], -tangents[:, 0]])

    # Vertices: for each outline point i and each profile point j.
    verts = np.empty((N * M, 3), dtype=float)
    for i in range(N):
        px, py = pts[i]
        nx, ny = normals[i]
        base = i * M
        verts[base:base + M, 0] = px + nx * prof[:, 0]
        verts[base:base + M, 1] = py + ny * prof[:, 0]
        verts[base:base + M, 2] = prof[:, 1]

    # Faces: quads between consecutive outline points and profile points,
    # both wrapping around (closed loops), split into two triangles each.
    faces = []
    for i in range(N):
        i2 = (i + 1) % N
        for j in range(M):
            j2 = (j + 1) % M
            a = i * M + j
            b = i2 * M + j
            c = i2 * M + j2
            d = i * M + j2
            faces.append([a, b, c])
            faces.append([a, c, d])

    mesh = trimesh.Trimesh(vertices=verts, faces=np.array(faces), process=True)
    mesh.fix_normals()
    return mesh


def create_cutter(geometry, target_size):
    """Build the full cookie-cutter mesh for one merged SVG geometry, scaled so
    its longest dimension equals target_size. Centered on the origin in XY."""
    fitted = fit_geometry(geometry, target_size)
    if fitted is None:
        return None

    rings = geometry_rings(fitted)
    if not rings:
        return None

    profile = make_cross_section()
    walls = []
    for ring in rings:
        outline = resample_ring(ring)
        if outline is None or len(outline) < 3:
            continue
        walls.append(sweep_ring(outline, profile))

    if not walls:
        return None

    # Each ring is an independent watertight tube; concatenate into one STL.
    return trimesh.util.concatenate(walls)


def process_svg(svg_file):
    """Process a single SVG file into one STL containing a cutter in each of
    SIZES, arranged side by side along X (smallest to largest)."""
    basename = os.path.splitext(os.path.basename(svg_file))[0]
    print(f"\nProcessing: {basename}")

    geometry = svg_paths_to_polygons(svg_file)
    if geometry is None:
        print(f"  ERROR: No valid paths found in {svg_file}")
        return

    cutters = []
    cursor_x = 0.0  # left edge of the next cutter on the build plate
    for target_size in SIZES:
        print(f"  Generating {target_size:.0f}mm cutter...")
        cutter = create_cutter(geometry, target_size)
        if cutter is None:
            print(f"    ERROR: Could not build {target_size:.0f}mm cutter")
            continue

        # Place this cutter so its left edge sits at cursor_x.
        minx, miny, minz, maxx, maxy, maxz = cutter.bounds.flatten()
        cutter.apply_translation([cursor_x - minx, 0, 0])
        cutters.append(cutter)
        cursor_x += (maxx - minx) + CUTTER_GAP

    if not cutters:
        print(f"  ERROR: No cutters generated for {basename}")
        return

    combined = trimesh.util.concatenate(cutters)
    output_file = os.path.join(OUTPUT_DIR, f"{basename}.stl")
    combined.export(output_file)
    print(f"  ✓ Saved: {output_file} ({len(combined.faces)} faces)")


def main():
    os.makedirs(OUTPUT_DIR, exist_ok=True)

    if len(sys.argv) > 1:
        svg_files = []
        for arg in sys.argv[1:]:
            svg_files.append(arg if os.path.isabs(arg) else os.path.join(SVG_DIR, arg))
    else:
        svg_files = sorted(glob.glob(os.path.join(SVG_DIR, '*.svg')))

    if not svg_files:
        print("No SVG files found in svgs/ directory.")
        print("Place your SVG files in the svgs/ folder and run again.")
        return

    print(f"Found {len(svg_files)} SVG file(s) to process.")
    print(f"Sizes: {', '.join(f'{s:.0f}mm' for s in SIZES)}")
    print(f"Depth: {CUTTER_DEPTH}mm  Wall: {WALL_THICKNESS}mm  "
          f"Edge: {CUTTING_EDGE_THICKNESS}mm  Ridge: {RIDGE_THICKNESS}mm")

    for svg_file in svg_files:
        if not os.path.exists(svg_file):
            print(f"  File not found: {svg_file}")
            continue
        process_svg(svg_file)

    print(f"\n--- Done. Output files are in: {OUTPUT_DIR} ---")


if __name__ == '__main__':
    main()
