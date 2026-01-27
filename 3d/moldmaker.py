import trimesh
import numpy as np

# --- PARAMETERS ---
SVG_FILE = 'profile-straight.svg'
OUTPUT_FILE = 'mugmold.stl'

MINIMUM_PLASTER_WIDTH = 10
TARGET_HEIGHT_MM = 150.0 # how tall we want the mug to be, in mm
REVOLVE_SECTIONS = 128     

KEY_RADIUS = 10 # radius of the calibration keys
KEY_MARGIN = 10 # distance between the shape bounding box and the keys

WALL_HEIGHT_MARGIN = 20 # this is used to get the wal height based on the maximum mug radius
WALL_THICKNESS = 10 # thickness of the 3D printed wall, not the plaster wall

MARGIN_MM = 40.0              
FLOOR_THICKNESS = KEY_RADIUS + MINIMUM_PLASTER_WIDTH
BOTTOM_MARGIN = 30.0

# GALLERY PARAMETERS
GALLERY_MARGIN = 20.0
GALLERY_HEIGHT = 20.0        

def load_and_scale_points(filename, target_h):
    path_data = trimesh.load(filename)
    points = max(path_data.discrete, key=len)[:, :2]
    
    h_current = np.max(points[:, 1]) - np.min(points[:, 1])
    scale_factor = target_h / h_current
    return points * scale_factor


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
    shape_radius = full_mug.extents[0] / 2
    
    # slice it in half, along the XY plane
    master = trimesh.intersections.slice_mesh_plane(
        mesh=full_mug, 
        plane_normal=[0, 1, 0], 
        plane_origin=[0, 0, 0], 
        cap=True
    )

    master.apply_translation([0,0, -master.bounds[0][2]])
        
    return master, shape_radius

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

    return floor


def create_keys(master, points, floor):
    sphere = trimesh.creation.icosphere(radius=KEY_RADIUS, subdivisions=3)

    shape_width = master.extents[0]; # this is the diameter of the round object
    shape_length = master.extents[1]; # this is the radius of the object, since it is sliced in half
    shape_height = master.extents[2]; # this is the height from the base to the brim

    # consider that the position of the sphere is its center
    y_distance_from_top = KEY_RADIUS + KEY_MARGIN
    y_distance_from_bottom = (shape_height - KEY_RADIUS - KEY_MARGIN)

    loc = [((-shape_width / 2) - KEY_RADIUS - KEY_MARGIN), 0, y_distance_from_top]
    key = sphere.copy().apply_translation(loc)
    floor = floor.union(key)

    loc = [((shape_width / 2) + KEY_RADIUS + KEY_MARGIN), 0, y_distance_from_top]
    key = sphere.copy().apply_translation(loc)
    floor = floor.difference(key)

    loc = [((shape_width / 2) + KEY_RADIUS + KEY_MARGIN), 0, y_distance_from_bottom]
    key = sphere.copy().apply_translation(loc)
    floor = floor.union(key)

    loc = [((-shape_width / 2) - KEY_RADIUS - KEY_MARGIN), 0, y_distance_from_bottom]
    key = sphere.copy().apply_translation(loc)
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

    wall_height = (shape_height / 2) + FLOOR_THICKNESS + 5 # todo: base this on the max of gallery and shape radius
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


def build_mold():
    print("Scaling SVG Profile...")
    points = load_and_scale_points(SVG_FILE, TARGET_HEIGHT_MM)
    
    print("Creating Master...")
    master, shape_radius = create_solid_master(points)

    print("Creating Gallery...")
    gallery = create_gallery(master, points)

    print("Creating Floor...")
    floor = create_floor(master, points)

    print("Creating Keys...")
    # this takes the floor, cuts the keys in it, and returns the floor
    floor = create_keys(master, points, floor)

    box = create_box(master, points, floor)

    assembly = master.union([gallery, floor, box])
    
    assembly.export(OUTPUT_FILE)
    print(f"--- SUCCESS: {OUTPUT_FILE} exported ---")



if __name__ == "__main__":
    build_mold()