import trimesh
import numpy as np

# Load base
base = trimesh.load('cylinder.stl')
cutter = trimesh.creation.icosphere(radius=1.5)
cutter.apply_translation([4, 0, 5])

if not base.is_volume:
    print("Mesh is a shell. Converting to volume...")
    
    # This is a robust way to turn a thin surface into a solid 'sandwich'
    # We extrude the entire mesh along its normals
    base = base.extrude(2.0) 

# Final check for manifold status
if base.is_volume or base.is_watertight:
    print("Success: Proceeding with subtraction.")
    variation = base.difference(cutter)
    variation.export('variation_v1.stl')
else:
    # If the above fails, it's likely the original STL has 'naked edges'
    # we can try to force it into a solid via a convex hull (if shape allows)
    print("Mesh still not a volume. Trying a watertight wrap...")
    base = trimesh.copy.deepcopy(base).convex_hull
    variation = base.difference(cutter)
    variation.export('variation_v1.stl')