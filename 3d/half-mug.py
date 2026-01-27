import trimesh
import numpy as np

# --- PARAMETERS ---
input_file = 'svg_vase.stl'
output_file = 'svg_vase_half.stl'

def create_half_master():
    # 1. Load the full solid master
    mesh = trimesh.load(input_file)
    
    if not mesh.is_volume:
        print("Warning: Mesh is not a solid volume. The cut might not be clean.")

    # 2. Define a large "Cutter Box"
    # We make it much larger than the vase to ensure a clean cut
    extents = mesh.extents * 2
    cutter_box = trimesh.creation.box(extents=extents)
    
    # 3. Position the box to cover exactly half the space
    # By default, the box is centered at (0,0,0). 
    # We move it so its edge sits exactly on the Y-Z plane (X=0)
    translation = [extents[0] / 2, 0, 0]
    cutter_box.apply_translation(translation)
    
    # 4. Subtract the box from the vase
    # This leaves you with only the 'left' half of the vase
    half_mesh = mesh.difference(cutter_box)
    
    # 5. Export
    half_mesh.export(output_file)
    print(f"Success! Created {output_file}")
    print(f"The flat back is now aligned with the X=0 plane.")

if __name__ == "__main__":
    create_half_master()