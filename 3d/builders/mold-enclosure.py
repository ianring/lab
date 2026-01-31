import trimesh
import numpy as np

# --- PARAMETERS ---
box_size = [180, 250, 5]    # [Width, Length, Floor Thickness]
wall_height = 80             # Height of the outer walls to hold plaster
wall_thickness = 3
key_radius = 10
master_file = 'svg_vase_half.stl' # Your half-vase from the previous step

def generate_mold_assembly():
    # 1. Create the Floor (Base Plate)
    floor = trimesh.creation.box(extents=box_size)
    # Move so the top surface is at Z=0
    floor.apply_translation([0, 0, -box_size[2]/2])



    # 2. Load the Master (Half-Vase)
    master = trimesh.load(master_file)

    # --- THE FIX: FLIP THE MASTER FACE-DOWN ---
    # We assume the cut face is currently on the XZ or YZ plane.
    # Rotate 90 degrees around the Y-axis (adjust axis if needed based on your cut)
    flip_matrix = trimesh.transformations.rotation_matrix(np.pi/2, [0, 1, 0])
    master.apply_transform(flip_matrix)

    # 3. SNAP TO FLOOR
    # Move the master so its lowest point (the flat face) is exactly at Z=0
    min_z = master.bounds[0][2]
    master.apply_translation([0, 0, -min_z])

    # Center it on the plate (X and Y)
    center_offset = master.centroid[:2]
    master.apply_translation([-center_offset[0], -center_offset[1], 0])

    
    # 3. Create the Registration Keys
    sphere = trimesh.creation.icosphere(radius=key_radius)
    # Positions for 4 keys
    key_locs = [
        [50, 100, 0],   # Positive 1
        [-50, 100, 0],  # Positive 2
        [50, -100, 0],  # Negative 1
        [-50, -100, 0]  # Negative 2
    ]
    
    # 4. Assembly Process
    assembly = trimesh.util.concatenate([floor, master])
    
    # Add Positive Keys (Union)
    for loc in key_locs[:2]:
        key = sphere.copy().apply_translation(loc)
        assembly = assembly.union(key)
        
    # Subtract Negative Keys (Difference)
    for loc in key_locs[2:]:
        key = sphere.copy().apply_translation(loc)
        assembly = assembly.difference(key)

    # 5. Add the Funnel (The Pouring Channel)
    # A cylinder connecting the top of the vase to the box edge
    # You'll need to adjust 'y' position based on your vase height
    funnel = trimesh.creation.cylinder(radius=15, height=50)

    # 5. Add the Funnel (The Pouring Channel)
    funnel = trimesh.creation.cylinder(radius=15, height=50)

    # Create a 4x4 rotation matrix around the X-axis (90 degrees)
    rotation_matrix = trimesh.transformations.rotation_matrix(np.pi/2, [1, 0, 0])
    funnel.apply_transform(rotation_matrix)

    funnel.apply_translation([0, 110, 0]) # Move to the edge of the vase/box


    funnel.apply_translation([0, 125, 0]) # Move to box edge
    
    assembly = assembly.union(funnel)

    # 6. Export
    assembly.export('mold_enclosure_positive.stl')
    print("Mold enclosure generated. Check your viewer!")

if __name__ == "__main__":
    generate_mold_assembly()