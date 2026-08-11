# 3d

Generators that turn 2D artwork into printable STLs for pottery work. Each
project is self-contained and follows the same layout:

```
<project>/
  generate.py     the generator; run it from inside the project folder
  <inputs>/       source artwork (svg/, svgs/, or rasters/)
  output/         generated STLs -- everything here is disposable
  viewer/         view.php frameset over list.php + viewer.php (three.js)
```

Run a generator from its own folder, e.g. `cd stamps && python generate.py`.
Paths are resolved relative to the script, so output always lands in that
project's `output/`.

## Projects

| Folder | What it makes |
|---|---|
| `stamps/` | Round clay stamps from SVG designs, four sizes per plate |
| `tracer/` | Raster images (PNG/JPG) traced into vector SVGs via potrace |
| `mug/` | Mug master plus its plaster-mould stack: cottle walls, gallery, floor, registration keys |
| `urn/` | Fluted classical urn -- the top section, as a solid mould master |
| `pipe/` | Coffin-shaped smoking pipe (**scaffold** -- geometry still to be worked out) |
| `cutters/` | Cookie/clay cutters traced from SVG outlines, three sizes per plate |

### mug/

The one project with more than a single entry point:

- `generate.py` -- the full mould: master, gallery, floor, cottle wall
- `generate-floor.py` -- same build with the wall piece disabled, for printing the floor alone
- `moldmaker2.py` -- an alternate take that cuts the gallery as a cavity
- `calc/` -- analysis helpers: draft-angle plots, profile slicing, multi-level stack builder

Profiles live in `mug/svg/`; `profile.svg` is the one the generators read.

## sandbox/

One-off experiments and superseded scripts, kept for reference: the early
manual mould pipeline (`svg-lathe.py` → `half-mug.py` → `mold-enclosure.py`),
revolve and boolean proofs-of-concept, and a standalone STL viewer. Nothing
here is part of a project, and several scripts reference files that no longer
exist.

## Dependencies

```
pip install numpy trimesh shapely svgpathtools pillow potracer matplotlib
```

`trimesh` needs a boolean backend (`manifold3d` or `blender`) for the mould and
pipe generators.
