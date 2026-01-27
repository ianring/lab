import trimesh
import numpy as np

# --- PARAMETERS ---
target_height_mm = 200.0  # Set your desired height here
sections = 128

# 1. Load the SVG
path = trimesh.load('curvedprofile.svg')
points = path.discrete[0]

# 2. Calculate current height and scale factor
current_height = np.max(points[:, 1]) - np.min(points[:, 1])
scale_factor = target_height_mm / current_height

# 3. Apply the scale
points *= scale_factor

print(f"Original height: {current_height:.1f} units")
print(f"Scaling by {scale_factor:.4f} to reach {target_height_mm}mm")

# 4. Revolve and Export
mesh = trimesh.creation.revolve(points, sections=sections)
mesh.export('svg_vase.stl')