import trimesh
import numpy as np

# 1. Define your profile as a 2D line
# (Radius, Height)
points = np.array([
    [0, 0], [3, 0], [4, 2], [3, 5], [5, 8], [4, 10], [0, 10]
])

# 2. Revolve it into a solid
# 'sections' is the number of slices (higher = smoother)
vase = trimesh.creation.revolve(points, sections=64)

# 3. Save it
vase.export('procedural_vase.stl')