import trimesh
import numpy as np

# --- PARAMETERS ---
SVG_FILE = 'profile.svg'
TARGET_HEIGHT_MM = 150.0   
REVOLVE_SECTIONS = 128     

WALL_HEIGHT = 120
WALL_THICKNESS = 3
MARGIN_MM = 40.0              # Buffer space around the mug
KEY_RADIUS = 10
FLOOR_THICKNESS = KEY_RADIUS + 30.0  # Ensures registration holes are 'divots'

def build_mold():
    # 1. LOAD AND SCALE SVG
    path_data = trimesh.load(SVG_FILE)
    points = max(path_data.discrete, key=len)[:, :2]
    
    h_current = np.max(points[:, 1]) - np.min(points[:, 1])
    scale_factor = TARGET_HEIGHT_MM / h_current
    points *= scale_factor

    # The first point's X value is the radius at the top
    brim_radius = points[0][0] 

    # The last point's X value is the radius at the bottom
    base_radius = points[-1][0]


    # 2. GENERATE FULL 360 DEGREE MUG
    full_mug = trimesh.creation.revolve(points, sections=REVOLVE_SECTIONS)
    
    # 3. SLICE IN HALF 
    master = trimesh.intersections.slice_mesh_plane(
        mesh=full_mug, 
        plane_normal=[1, 0, 0], 
        plane_origin=[0, 0, 0], 
        cap=True
    )

    # 4. ORIENTATION (FLIPPING FACE DOWN)
    master.apply_transform(trimesh.transformations.rotation_matrix(np.pi/2, [0, 1, 0]))
    master.apply_transform(trimesh.transformations.rotation_matrix(np.pi, [0, 1, 0]))

    # Snap the flat face exactly to Z=0
    master.apply_translation([0, 0, -master.bounds[0][2]])
    master.apply_translation([-master.centroid[0], -master.centroid[1], 0])

    # 5. DYNAMIC BOX SIZING
    # Calculate box size based on the mug dimensions + margin
    master_extents = master.extents
    box_width = master_extents[0] + (MARGIN_MM * 2)
    box_length = master_extents[1] + (MARGIN_MM * 2)
    box_size = [box_width, box_length, FLOOR_THICKNESS]

    # 6. ASSEMBLY
    # Create the floor plate
    floor = trimesh.creation.box(extents=box_size)
    # Move floor so its TOP surface is at Z=0
    floor.apply_translation([0, 0, -FLOOR_THICKNESS/2])

    sphere = trimesh.creation.icosphere(radius=KEY_RADIUS, subdivisions=2)
    
    # Position keys relative to the dynamic box size
    key_offset_x = (box_width / 2) - (MARGIN_MM / 2)
    key_offset_y = (box_length / 2) - (MARGIN_MM / 2)
    
    key_pos = [
        [key_offset_x, key_offset_y, 0],   # Positive
        [-key_offset_x, key_offset_y, 0],  # Positive
        [key_offset_x, -key_offset_y, 0],  # Negative
        [-key_offset_x, -key_offset_y, 0]  # Negative
    ]

    # Fuse master to floor
    assembly = floor.union(master)

    # Add Registration Keys (Divots and Studs)
    for i, loc in enumerate(key_pos):
        key = sphere.copy().apply_translation(loc)
        if i < 2:
            assembly = assembly.union(key)
        else:
            assembly = assembly.difference(key)

    # 7. ENCLOSURE WALLS
    outer = trimesh.creation.box(extents=[box_size[0], box_size[1], WALL_HEIGHT])
    inner = trimesh.creation.box(extents=[box_size[0] - WALL_THICKNESS*2, 
                                           box_size[1] - WALL_THICKNESS*2, 
                                           WALL_HEIGHT + 2])
    walls = outer.difference(inner)
    # Walls sit on top of the bottom plane of the floor
    walls.apply_translation([0, 0, (WALL_HEIGHT/2) - FLOOR_THICKNESS])
    
    final_mold = assembly.union(walls)

    # 8. EXPORT
    final_mold.export('mold_master_complete.stl')
    print(f"Export Complete. Floor thickness: {FLOOR_THICKNESS}mm, Margin: {MARGIN_MM}mm")

if __name__ == "__main__":
    build_mold()