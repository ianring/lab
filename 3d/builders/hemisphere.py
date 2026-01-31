import trimesh
import numpy as np

# --- PARAMETERS ---
radius = 10.0  # 10mm radius
subdivisions = 3 # Higher = smoother surface
output_file = 'key_hemisphere.stl'

def generate_hemisphere():
    # 1. Create a full sphere
    # icosphere is better for 3D printing because the triangles are more uniform
    sphere = trimesh.creation.icosphere(subdivisions=subdivisions, radius=radius)
    
    # 2. Define a "Cutter Box" to slice it in half
    # We make it twice the size of the sphere
    box_size = radius * 4
    cutter_box = trimesh.creation.box(extents=[box_size, box_size, box_size])
    
    # 3. Position the box so its bottom face sits at Z=0
    # This will slice the sphere right through the "equator"
    cutter_box.apply_translation([0, 0, -box_size / 2])
    
    # 4. Subtract the box from the sphere
    hemisphere = sphere.difference(cutter_box)
    
    # 5. Export to your lab
    hemisphere.export(output_file)
    print(f"Success! {output_file} generated.")
    print(f"The flat base is now sitting on the Z=0 plane.")

if __name__ == "__main__":
    generate_hemisphere()