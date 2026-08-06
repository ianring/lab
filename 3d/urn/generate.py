#!/usr/bin/env python3
"""
Fluted Urn Generator

Builds the upper section of a fluted (reeded) cement-style urn as a solid STL,
suitable as a master pattern for a plaster slip-casting mould. The plinth is a
separate piece and is NOT modelled here.

The silhouette is taken from a hand-traced SVG (svg/profile.svg): the right-half
cross-section of the urn, with its straight edge on the central axis. That curve
is revolved into the smooth body. A second SVG (svg/flutes.svg) marks the height
band of the belly that carries the reeds.

The reeds are convex half-round strips with rounded ends. They are seated so that
their CRESTS lie exactly on the traced silhouette and the valleys between them
dip inward by REED_HEIGHT -- so your traced outline is honoured exactly while the
reeds stand proud and meet the body orthogonally, like applique strips.

Both SVGs come from the same Affinity document, so their raw path coordinates
share one coordinate system. We read the raw `d` data and ignore the per-file
<g transform> that Affinity adds when cropping the export, which keeps the two
files aligned (flattening the transforms would move each to its own crop origin).

Usage:
    python generate.py                 # writes output/urn.stl
    python generate.py my_variant.stl  # writes output/my_variant.stl

Dependencies:
    pip install numpy trimesh svgpathtools
"""

import os
import sys
import numpy as np
import trimesh
import xml.etree.ElementTree as ET
from svgpathtools import parse_path

# ============================================================================
# OVERALL SIZE
# ============================================================================
MODEL_HEIGHT_MM = 250.0       # total height of the model; the traced profile is
#                               scaled uniformly to this (print smaller in slicer)
EXTRA_SCALE     = 1.0         # extra uniform scale on top, if handy

# ============================================================================
# REEDS  (the vertical fluted strips on the belly)
# ============================================================================
N_REEDS       = 22            # number of reeds around the circumference
REED_HEIGHT   = 4.0           # depth of the valleys between reeds (mm); reed
#                               crests sit on the traced silhouette
REED_RADIUS   = 7.0           # half-width of a reed AND the radius of its
#                               rounded end caps (its footprint, mm)
# The reed height band is read from svg/flutes.svg. To override it manually,
# set either of these to a number (mm from the bottom); leave as None to use
# the traced flutes region.
REED_Z_BOTTOM_OVERRIDE = None
REED_Z_TOP_OVERRIDE    = None
# The reeds must end before the belly curves in toward the foot, otherwise their
# rounded caps splay out over the tuck-under into a "flower". The reed band's
# bottom is raised to at least this fraction of the way up from the belly's
# lower waist (min radius) to the belly's widest point. 0 = allow reeds down to
# the waist; 1 = pull them all the way up to the belly max. Raise it if the
# reeds still flare; lower it to let them run further down the belly.
REED_BOTTOM_CLEARANCE = 0.35

# ============================================================================
# MESH RESOLUTION / FILES
# ============================================================================
ANGULAR_SEGMENTS       = 512  # slices around the axis (higher = smoother reeds)
PROFILE_SAMPLES_PER_SEG = 24  # points sampled per traced path segment
AXIS_TOLERANCE          = 1.0  # raw units; how close to the axis edge counts

HERE = os.path.dirname(os.path.abspath(__file__))
SVG_DIR = os.path.join(HERE, 'svg')
PROFILE_SVG = os.path.join(SVG_DIR, 'profile.svg')
FLUTES_SVG = os.path.join(SVG_DIR, 'flutes.svg')
OUTPUT_DIR = os.path.join(HERE, 'output')

SVG_NS = '{http://www.w3.org/2000/svg}'


def first_path_d(svg_file):
    """Return the raw `d` string of the first <path> in an SVG, ignoring any
    group transforms (we want the untransformed document-space coordinates)."""
    root = ET.parse(svg_file).getroot()
    for el in root.iter(f'{SVG_NS}path'):
        return el.get('d')
    raise ValueError(f"No <path> found in {svg_file}")


def path_y_extent(svg_file, samples=600):
    """Return (min_y, max_y) of a path's raw coordinates (SVG y, increasing
    downward)."""
    path = parse_path(first_path_d(svg_file))
    ys = [path.point(t).imag for t in np.linspace(0.0, 1.0, samples)]
    return min(ys), max(ys)


def load_profile():
    """
    Parse svg/profile.svg into a bottom-to-top profile.

    Returns (r, z, nr, nz, unit, datum_y):
      r, z   : radius / height of the silhouette in mm (z=0 at the bottom)
      nr, nz : outward 2D surface normal at each point
      unit   : mm per raw SVG unit (so other files can be converted to match)
      datum_y: the raw SVG y used as the z=0 datum (the profile's max y)
    """
    path = parse_path(first_path_d(PROFILE_SVG))

    # Bounding extent in raw units; the axis is the straight edge at min x.
    dense = [path.point(t) for t in np.linspace(0.0, 1.0, 2000)]
    axis_x = min(p.real for p in dense)
    min_y = min(p.imag for p in dense)
    max_y = max(p.imag for p in dense)
    raw_height = max_y - min_y
    unit = EXTRA_SCALE * MODEL_HEIGHT_MM / raw_height

    # Walk the segments, skipping the single axis edge (both endpoints on the
    # axis). What remains is the open silhouette from one pole to the other.
    pts = []
    for seg in path:
        on_axis = (abs(seg.start.real - axis_x) < AXIS_TOLERANCE and
                   abs(seg.end.real - axis_x) < AXIS_TOLERANCE)
        if on_axis:
            continue
        for t in np.linspace(0.0, 1.0, PROFILE_SAMPLES_PER_SEG):
            p = seg.point(t)
            pts.append((p.real, p.imag))

    pts = np.array(pts)

    # Drop consecutive duplicate points.
    keep = [0]
    for i in range(1, len(pts)):
        if np.hypot(*(pts[i] - pts[keep[-1]])) > 1e-6:
            keep.append(i)
    pts = pts[keep]

    # Convert to mm: radius from the axis, height measured up from the bottom.
    r = (pts[:, 0] - axis_x) * unit
    z = (max_y - pts[:, 1]) * unit

    # The retained chain runs top -> bottom; flip so it goes bottom -> top.
    r = r[::-1].copy()
    z = z[::-1].copy()

    # Outward 2D normal = tangent rotated -90 deg: (t_r, t_z) -> (t_z, -t_r).
    tangent = np.gradient(np.column_stack([r, z]), axis=0)
    tr, tz = tangent[:, 0], tangent[:, 1]
    length = np.hypot(tr, tz)
    length[length == 0] = 1.0
    nr = tz / length
    nz = -tr / length
    return r, z, nr, nz, unit, max_y


def reed_band(r, z, unit, datum_y):
    """Return (z_bottom, z_top) of the reeded band in mm, from svg/flutes.svg
    (or the manual overrides), with the bottom raised so the reeds finish above
    the point where the belly changes direction into the foot."""
    if REED_Z_BOTTOM_OVERRIDE is not None and REED_Z_TOP_OVERRIDE is not None:
        return REED_Z_BOTTOM_OVERRIDE, REED_Z_TOP_OVERRIDE

    fmin_y, fmax_y = path_y_extent(FLUTES_SVG)
    # Same datum and scale as the profile keeps the band aligned to the body.
    z_top = (datum_y - fmin_y) * unit
    z_bottom = (datum_y - fmax_y) * unit

    # Find the belly (widest point) and the waist below it (narrowest point
    # before the foot flares back out). The profile changes direction between
    # them; keep the reed bottom a fraction of the way up from that waist so the
    # reeds end on the convex belly, not over the tuck-under.
    i_belly = int(np.argmax(r))
    belly_z = z[i_belly]
    lower = np.where(z < belly_z)[0]
    if len(lower):
        waist_z = z[lower[int(np.argmin(r[lower]))]]
        floor = waist_z + REED_BOTTOM_CLEARANCE * (belly_z - waist_z)
        z_bottom = max(z_bottom, floor)

    if REED_Z_BOTTOM_OVERRIDE is not None:
        z_bottom = REED_Z_BOTTOM_OVERRIDE
    if REED_Z_TOP_OVERRIDE is not None:
        z_top = REED_Z_TOP_OVERRIDE
    return z_bottom, z_top


def reed_displacement(z, r_ref, z_bottom, z_top):
    """
    Radial displacement (mm) for every (profile-row, angle), shape (P, M).

    Each reed is a half-round rod lying vertically on the surface: within
    REED_RADIUS of a reed's centreline (measured in arc-length/height space) the
    reed rises by a half-round profile with hemispherical ends. The whole reeded
    band is then lowered by REED_HEIGHT, so reed crests return to the traced
    silhouette (displacement 0) while the gaps between reeds sit REED_HEIGHT in.
    """
    theta = np.linspace(0.0, 2.0 * np.pi, ANGULAR_SEGMENTS, endpoint=False)

    spacing = 2.0 * np.pi / N_REEDS
    d_theta = theta - np.round(theta / spacing) * spacing
    arc = d_theta * r_ref                                    # (M,)

    dz = np.where(z < z_bottom, z_bottom - z,
                  np.where(z > z_top, z - z_top, 0.0))       # (P,)

    dist = np.sqrt(arc[None, :] ** 2 + dz[:, None] ** 2)     # (P, M)
    reed = np.zeros_like(dist)
    inside = dist < REED_RADIUS
    reed[inside] = REED_HEIGHT * np.sqrt(1.0 - (dist[inside] / REED_RADIUS) ** 2)

    # Band mask: 1 within the band, fading to 0 over REED_RADIUS past its ends,
    # matching how the reed caps fade, so the surface returns cleanly to the
    # silhouette at the top and bottom of the fluting.
    band = np.clip(1.0 - dz / REED_RADIUS, 0.0, 1.0)[:, None]

    return reed - REED_HEIGHT * band


def build_mesh():
    """Assemble the watertight solid urn mesh from the traced profile + reeds."""
    r, z, nr, nz, unit, datum_y = load_profile()
    z_bottom, z_top = reed_band(r, z, unit, datum_y)
    r_ref = float(r.max())      # belly radius, for angle<->arc conversion

    P = len(r)
    M = ANGULAR_SEGMENTS
    theta = np.linspace(0.0, 2.0 * np.pi, M, endpoint=False)
    cos_t, sin_t = np.cos(theta), np.sin(theta)

    disp = reed_displacement(z, r_ref, z_bottom, z_top)      # (P, M)

    rr = r[:, None] + disp * nr[:, None]                     # (P, M)
    zz = z[:, None] + disp * nz[:, None]
    X = rr * cos_t[None, :]
    Y = rr * sin_t[None, :]
    grid = np.stack([X, Y, zz], axis=-1)                     # (P, M, 3)

    # Rows 0 and P-1 sit on the axis (r=0) -> pole vertices; the rest are rings.
    K = P - 2
    ring_verts = grid[1:P - 1].reshape(K * M, 3)
    bottom_pole = np.array([0.0, 0.0, z[0]])
    top_pole = np.array([0.0, 0.0, z[-1]])
    verts = np.vstack([ring_verts, bottom_pole, top_pole])
    bottom_idx = K * M
    top_idx = K * M + 1

    a = np.arange(K - 1)[:, None]
    j = np.arange(M)[None, :]
    jn = (j + 1) % M
    v00 = a * M + j
    v01 = a * M + jn
    v10 = (a + 1) * M + j
    v11 = (a + 1) * M + jn
    t1 = np.stack([v00, v10, v11], axis=-1).reshape(-1, 3)
    t2 = np.stack([v00, v11, v01], axis=-1).reshape(-1, 3)

    j = np.arange(M)
    jn = (j + 1) % M
    bottom_fan = np.stack([np.full(M, bottom_idx), jn, j], axis=-1)
    last = (K - 1) * M
    top_fan = np.stack([np.full(M, top_idx), last + j, last + jn], axis=-1)

    faces = np.vstack([t1, t2, bottom_fan, top_fan])

    mesh = trimesh.Trimesh(vertices=verts, faces=faces, process=True)
    mesh.fix_normals()

    info = dict(z_bottom=z_bottom, z_top=z_top, r_ref=r_ref,
                height=float(z[-1] - z[0]))
    return mesh, info


def main():
    os.makedirs(OUTPUT_DIR, exist_ok=True)
    name = sys.argv[1] if len(sys.argv) > 1 else 'urn.stl'
    if not name.lower().endswith('.stl'):
        name += '.stl'
    output_file = os.path.join(OUTPUT_DIR, name)

    print("Building fluted urn from traced profile...")
    mesh, info = build_mesh()
    ext = mesh.bounds[1] - mesh.bounds[0]
    print(f"  Size: {ext[0]:.1f} x {ext[1]:.1f} x {ext[2]:.1f} mm")
    print(f"  Belly radius: {info['r_ref']:.1f} mm   Reeds: {N_REEDS}")
    print(f"  Reed band z: {info['z_bottom']:.1f} -> {info['z_top']:.1f} mm")
    print(f"  {len(mesh.vertices)} vertices, {len(mesh.faces)} faces")
    print(f"  Watertight: {mesh.is_watertight}")

    mesh.export(output_file)
    print(f"  Saved: {output_file}")


if __name__ == '__main__':
    main()
