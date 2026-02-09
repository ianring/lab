import trimesh
import numpy as np
from shapely.geometry import LineString

# --- PARAMETERS ---
SVG_FILE = 'profile.svg'
OUTPUT_FILE = 'mug-mold-expanded-just-floor.stl'

MINIMUM_PLASTER_WIDTH = 10
MINIMUM_PRINTED_WIDTH = 5
TARGET_HEIGHT_MM = 150.0 # how tall we want the mug to be, in mm
REVOLVE_SECTIONS = 128     

SHRINKAGE_RATE = 1 / (1 - 0.12)

KEY_RADIUS = 8 # radius of the calibration keys
KEY_MARGIN = 6 # distance between the shape bounding box and the keys

WALL_HEIGHT_MARGIN = 20 # this is used to get the wall height based on the maximum mug radius
WALL_THICKNESS = 5 # thickness of the 3D printed wall, not the plaster wall

MARGIN_MM = 40.0              
FLOOR_THICKNESS = KEY_RADIUS + MINIMUM_PRINTED_WIDTH
BOTTOM_MARGIN = 15.0

# GALLERY PARAMETERS
GALLERY_MARGIN = 20.0
GALLERY_HEIGHT = 20.0        

SKIRT_WIDTH = 25
WALL_SKIRT = 25

def load_and_scale_points(filename, target_h):
    path_data = trimesh.load(filename)
    points = max(path_data.discrete, key=len)[:, :2]
    
    h_current = np.max(points[:, 1]) - np.min(points[:, 1])
    scale_factor = target_h / h_current
    return points * scale_factor * SHRINKAGE_RATE


def get_brim_radius(points):
    """
    Finds the x-coordinate (radius) at the minimum y-coordinate (top of mug).
    """
    # Find the index of the point with the lowest Y value (the top)
    brim_index = np.argmin(points[:, 1])
    
    # Get the X value at that index
    brim_radius = points[brim_index][0]
    
    return brim_radius


def create_gallery(master, points):
    brim_radius = get_brim_radius(points)
    gallery_radius = brim_radius + GALLERY_MARGIN

    min_y = np.min(points[:, 1])

    gallery = trimesh.creation.cylinder(radius=gallery_radius, height=GALLERY_HEIGHT, sections=REVOLVE_SECTIONS)
    gallery.apply_translation([0, 0, min_y - (GALLERY_HEIGHT / 2)])

    sliced_gallery = trimesh.intersections.slice_mesh_plane(
        mesh=gallery, 
        plane_normal=[0, 1, 0], 
        plane_origin=[0, 0, 0], 
        cap=True
    )

    return sliced_gallery


def create_solid_master(points):
    """Generates mug, gallery, and flange, then slices and aligns."""
    # 1. 360 Degree Revolve
    full_mug = trimesh.creation.revolve(points, sections=REVOLVE_SECTIONS)
    
    # 2. Identify Extrema
    min_y = np.min(points[:, 1])
    
    # slice it in half, along the XY plane
    master = trimesh.intersections.slice_mesh_plane(
        mesh=full_mug, 
        plane_normal=[0, 1, 0], 
        plane_origin=[0, 0, 0], 
        cap=True
    )

    master.apply_translation([0,0, -master.bounds[0][2]])
        
    return master


def create_hollow_master_3d(points, shell_thickness=3.0):
    full_mug = trimesh.creation.revolve(points, sections=REVOLVE_SECTIONS)
    
    # Create an interior version by 'offsetting' the surface inward
    # Note: This requires a manifold (water-tight) mesh to work reliably
    inner_void = full_mug.copy()
    inner_void.apply_transform(trimesh.transformations.scale_matrix(0.95)) # Simple scaling
    
    # Or use the more precise offsetting (slower):
    # inner_void = trimesh.creation.offset_mesh(full_mug, -shell_thickness)

    hollow_mug = full_mug.difference(inner_void)
    
    # Slice...
    master = trimesh.intersections.slice_mesh_plane(
        mesh=hollow_mug, 
        plane_normal=[0, 1, 0], 
        plane_origin=[0, 0, 0], 
        cap=True
    )
    return master


def create_hollow_master(points, shell_thickness=3.0):
    """
    Creates a master with uniform wall thickness by offsetting the 2D profile.
    """
    # 1. Create the outer 3D shape
    outer_mug = trimesh.creation.revolve(points, sections=REVOLVE_SECTIONS)
    
    # 2. Offset the 2D line to create the interior profile
    # We offset the line 'inward' (to the left, since points are on the right of X=0)
    line = LineString(points)
    # .parallel_offset creates a new line at a fixed distance
    inner_line = line.parallel_offset(shell_thickness, side='left', join_style=2)
    
    # Convert back to numpy array and ensure it's oriented correctly
    inner_points = np.array(inner_line.coords)
    
    # 3. Create the inner 'void' mesh
    inner_void = trimesh.creation.revolve(inner_points, sections=REVOLVE_SECTIONS)
    
    # 4. Subtract the void from the outer mug
    hollow_mug = outer_mug.difference(inner_void)
    
    # 5. Slice and align as usual
    master = trimesh.intersections.slice_mesh_plane(
        mesh=hollow_mug, 
        plane_normal=[0, 1, 0], 
        plane_origin=[0, 0, 0], 
        cap=True
    )
    
    master.apply_translation([0, 0, -master.bounds[0][2]])
    return master





def create_floor(master, points):
    shape_width = master.extents[0]; # this is the diameter of the round object
    shape_length = master.extents[1]; # this is the radius of the object, since it is sliced in half
    shape_height = master.extents[2]; # this is the height from the base to the brim
    print(f"shape width is {shape_width} mm") # the mug is sideways. width is the diameter of the round object
    print(f"shape length is {shape_length} mm") # length is actually the radius of the round object
    print(f"shape height is {shape_height} mm") # height is the actual height, it's set to 150

    floor_width = shape_width + (4 * MINIMUM_PLASTER_WIDTH) + (4 * KEY_RADIUS) # add margin on the sides to make room for keys
    floor_length = shape_height + GALLERY_HEIGHT + BOTTOM_MARGIN

    floor_size = [floor_width, FLOOR_THICKNESS, floor_length]

    # 2. Floor
    floor = trimesh.creation.box(extents=floor_size)
    # floor.apply_translation([0, (-FLOOR_THICKNESS/2)-1, (shape_height / 2) - GALLERY_HEIGHT])
    floor.apply_translation([0, (-FLOOR_THICKNESS/2), (shape_height / 2) - (GALLERY_HEIGHT / 2) + (BOTTOM_MARGIN / 2)])

    floor = create_keys(master, points, floor)

    # skirt = create_skirt(master, points, floor)
    # floor = floor.union(skirt)

    return floor


def create_keys(master, points, floor):
    sphere = trimesh.creation.icosphere(radius=KEY_RADIUS, subdivisions=3)
    top_sphere = trimesh.intersections.slice_mesh_plane(
        mesh=sphere, 
        plane_normal=[0, 1, 0], # keep the top half
        plane_origin=[0, 0, 0], 
        cap=True
    )

    sphere = trimesh.creation.icosphere(radius=KEY_RADIUS, subdivisions=3)
    bottom_sphere = trimesh.intersections.slice_mesh_plane(
        mesh=sphere, 
        plane_normal=[0, -1, 0], # keep the top half
        plane_origin=[0, 0, 0], 
        cap=True
    )

    shape_width = master.extents[0]; # this is the diameter of the round object
    shape_length = master.extents[1]; # this is the radius of the object, since it is sliced in half
    shape_height = master.extents[2]; # this is the height from the base to the brim

    # consider that the position of the sphere is its center
    y_distance_from_top = KEY_RADIUS + KEY_MARGIN
    y_distance_from_bottom = (shape_height - KEY_RADIUS - KEY_MARGIN)
    x_left = ((-shape_width / 2) - KEY_RADIUS - KEY_MARGIN)
    x_right = ((shape_width / 2) + KEY_RADIUS + KEY_MARGIN)

    # top left - convex
    loc = [x_left, 0, y_distance_from_top]
    key = top_sphere.copy().apply_translation(loc)
    floor = floor.union(key)

    # top right - concave
    loc = [x_right, 0, y_distance_from_top]
    key = bottom_sphere.copy().apply_translation(loc)
    floor = floor.difference(key)

    # bottom left
    loc = [x_left, 0, y_distance_from_bottom]
    key = top_sphere.copy().apply_translation(loc)
    floor = floor.union(key)

    # bottom right - concave
    loc = [x_right, 0, y_distance_from_bottom]
    key = bottom_sphere.copy().apply_translation(loc)
    floor = floor.difference(key)

    return floor


def create_box(master, points, floor):

    brim_radius = get_brim_radius(points)
    gallery_radius = brim_radius + GALLERY_MARGIN

    shape_width = master.extents[0]; # this is the diameter of the round object
    shape_length = master.extents[1]; # this is the radius of the object, since it is sliced in half
    shape_height = master.extents[2]; # this is the height from the base to the brim

    floor_width = floor.extents[0]; # 
    floor_length = floor.extents[1]; # 
    floor_height = floor.extents[2]; # 
    print(f"floor width is {floor_width} mm") #
    print(f"floor length is {floor_length} mm") #
    print(f"floor height is {floor_height} mm") #

    #todo: get the max of the shape height or the gallery height
    widest_girth = max(shape_length, gallery_radius)

    wall_height = widest_girth + FLOOR_THICKNESS + WALL_HEIGHT_MARGIN # todo: base this on the max of gallery and shape radius
    wall_z_middle = (wall_height / 2) - FLOOR_THICKNESS

    # "top" wall, extends beyond the edges on both sides
    wall_size = [floor_width + (2 * WALL_THICKNESS), wall_height, WALL_THICKNESS]
    wall = trimesh.creation.box(extents=wall_size)
    wall.apply_translation([0, wall_z_middle, (-WALL_THICKNESS / 2) - GALLERY_HEIGHT])

    # left side wall
    wall2_size = [WALL_THICKNESS, wall_height, floor_height]
    wall2 = trimesh.creation.box(extents=wall2_size)
    wall2.apply_translation([(-floor_width / 2) - (WALL_THICKNESS / 2), wall_z_middle, (floor_height / 2) - GALLERY_HEIGHT])

    # right side wall
    wall3_size = [WALL_THICKNESS, wall_height, floor_height]
    wall3 = trimesh.creation.box(extents=wall3_size)
    wall3.apply_translation([(floor_width / 2) + (WALL_THICKNESS / 2), wall_z_middle, (floor_height / 2) - GALLERY_HEIGHT])

    # "bottom" wall, extends beyond the edges on both sides
    wall4_size = [floor_width + (2 * WALL_THICKNESS), wall_height, WALL_THICKNESS]
    wall4 = trimesh.creation.box(extents=wall4_size)
    wall4.apply_translation([0, wall_z_middle, (-WALL_THICKNESS / 2) + floor_height - GALLERY_HEIGHT])


    assembly = wall.union([wall2, wall3, wall4])    
    return assembly

def create_skirt(master, points, floor):
    SKIRT_WIDTH = 25

    floor_width = floor.extents[0]; # 
    floor_length = floor.extents[1]; # 
    floor_height = floor.extents[2]; # 

    skirt1_size = [SKIRT_WIDTH, WALL_THICKNESS, floor_height]
    skirt1 = trimesh.creation.box(extents=skirt1_size)
    skirt1.apply_translation([(-floor_width / 2) - (SKIRT_WIDTH / 2) , (-WALL_THICKNESS / 2), (floor_height / 2) - GALLERY_HEIGHT])

    skirt2_size = [SKIRT_WIDTH, WALL_THICKNESS, floor_height]
    skirt2 = trimesh.creation.box(extents=skirt2_size)
    skirt2.apply_translation([(floor_width / 2) + (SKIRT_WIDTH / 2) , (-WALL_THICKNESS / 2), (floor_height / 2) - GALLERY_HEIGHT])

    skirt3_size = [floor_width + (2 * SKIRT_WIDTH), WALL_THICKNESS, SKIRT_WIDTH]
    skirt3 = trimesh.creation.box(extents=skirt3_size)
    skirt3.apply_translation([0 , (-WALL_THICKNESS / 2), (-SKIRT_WIDTH / 2) - GALLERY_HEIGHT])

    skirt4_size = [floor_width + (2 * SKIRT_WIDTH), WALL_THICKNESS, SKIRT_WIDTH]
    skirt4 = trimesh.creation.box(extents=skirt4_size)
    skirt4.apply_translation([0 , (-WALL_THICKNESS / 2), floor_height - (SKIRT_WIDTH / 2)] )

    skirt = skirt1.union([skirt2, skirt3, skirt4])

    return skirt


def create_wall_piece(master, points, floor):
    lift = 35 # this separates the wall from the floor so you can see it separately

    brim_radius = get_brim_radius(points)
    gallery_radius = brim_radius + GALLERY_MARGIN

    shape_width = master.extents[0]; # this is the diameter of the round object
    shape_length = master.extents[1]; # this is the radius of the object, since it is sliced in half
    shape_height = master.extents[2]; # this is the height from the base to the brim

    floor_width = floor.extents[0]; # 
    floor_length = floor.extents[1]; # 
    floor_height = floor.extents[2]; # 

    #todo: get the max of the shape height or the gallery height
    widest_girth = max(shape_length, gallery_radius)

    wall_height = widest_girth + FLOOR_THICKNESS + WALL_HEIGHT_MARGIN # todo: base this on the max of gallery and shape radius
    wall_z_middle = (wall_height / 2) - FLOOR_THICKNESS + lift

    # this is the flat piece parallel to the floor
    wall = trimesh.creation.box(extents=[
        WALL_SKIRT,          # left/right with respect to the mug
        WALL_THICKNESS,     # z-axis
        floor_height        # up/down with respect to the mug
    ])
    wall.apply_translation([
        (-floor_width / 2) - (WALL_SKIRT / 2) + SKIRT_WIDTH,   # left/right with respect to the mug
        wall_z_middle - (wall_height/2),        # z-axis
        (floor_height / 2) - GALLERY_HEIGHT - WALL_SKIRT    # up/down with respect to the mug
    ])

    # this is the tall wide wall piece perpendicular to the mug
    wall2 = trimesh.creation.box(extents=[
        WALL_THICKNESS,
        wall_height,
        floor_height
    ])
    wall2.apply_translation([
        (-floor_width / 2) + SKIRT_WIDTH - (WALL_THICKNESS / 2),
        wall_z_middle + (WALL_THICKNESS / 2),
        (floor_height / 2) - GALLERY_HEIGHT - SKIRT_WIDTH
    ])

    wall3 = trimesh.creation.box(extents=[
        WALL_SKIRT,
        wall_height,
        WALL_THICKNESS
    ])
    wall3.apply_translation([
        (-floor_width / 2) + (WALL_SKIRT / 2),
        wall_z_middle + (WALL_THICKNESS / 2),
        floor_height - GALLERY_HEIGHT + (WALL_THICKNESS / 2) - SKIRT_WIDTH - WALL_THICKNESS
    ])


    walls = wall.union([wall2, wall3])

    return walls


def build_mold():
    print("Scaling SVG Profile...")
    points = load_and_scale_points(SVG_FILE, TARGET_HEIGHT_MM)
    
    print("Creating Master...")
    master = create_solid_master(points)
    # master = create_hollow_master(points, shell_thickness=3.0)

    print("Creating Gallery...")
    gallery = create_gallery(master, points)

    print("Creating Floor...")
    floor = create_floor(master, points)
    print("Creating Keys...")
    # this takes the floor, cuts the keys in it, and returns the floor

    # box = create_box(master, points, floor)

    assembly = master.union([gallery, floor])
    # assembly = master.union([gallery])

    # wall = create_wall_piece(master, points, floor)
    # assembly = assembly.union([wall])

    assembly.export(OUTPUT_FILE)
    print(f"--- SUCCESS: {OUTPUT_FILE} exported ---")

    print(f"extents of the whole print:")
    full_width = assembly.extents[0]; # 
    full_length = assembly.extents[1]; # 
    full_height = assembly.extents[2]; # 
    print(f"full width is {full_width} mm") #
    print(f"full length is {full_length} mm") #
    print(f"full height is {full_height} mm") #

if __name__ == "__main__":
    build_mold()