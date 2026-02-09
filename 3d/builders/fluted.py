import trimesh
import numpy as np

# 1. Load your SVG-generated solid master
# (Assuming you already ran the revolve script)
master = trimesh.load('svg_vase.stl')

# 2. Create a "Carving Tool" (a tall thin cylinder)
tool_radius = 5
tool_height = 1000 # Make it tall enough to cover the whole vase
cutter = trimesh.creation.cylinder(radius=tool_radius, height=tool_height)

# 3. Array the cutter around the vase
num_cuts = 12
for i in range(num_cuts):
    angle = (2 * np.pi * i) / num_cuts
    # Move cutter to the edge of the vase (adjust 'radius_offset' as needed)
    radius_offset = 80 
    x = radius_offset * np.cos(angle)
    y = radius_offset * np.sin(angle)
    
    # Position the cutter
    instance = cutter.copy()
    instance.apply_translation([x, y, 400]) # Center it vertically
    
    # Subtract from the master
    master = master.difference(instance)

# 4. Export the carved master for 3D printing
master.export('darkware_master_carved.stl')