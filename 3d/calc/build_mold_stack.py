import trimesh
import numpy as np
import os
import sys
import glob
from svgpathtools import svg2paths

# --- CONFIGURATION ---
TARGET_MUG_HEIGHT_MM = 150.0  # Overall height of the final positive
WALL_THICKNESS = 15.0         # Plaster wall thickness around the mug
KEY_RADIUS = 8.0              # Registration key radius
KEY_OFFSET_MM = 2.0           # Clearance
MOLD_BLOCK_SIDES_MARGIN = 30.0 # Extra width for the plaster block
GALLERY_WIDTH = 20.0          # Width of the reservoir lip
GALLERY_HEIGHT = 20.0         # Height of the reservoir

def load_slice_svg(filename):
    # Use svgpathtools to load the path reliably
    paths, attributes = svg2paths(filename)
    if not paths:
        return None
        
    # Assuming single path per slice or we combine them
    path = paths[0]
    
    # Sample points from the path
    # We need enough points for a smooth revolve
    NUM_SAMPLES = 200
    ts = np.linspace(0, 1, NUM_SAMPLES)
    points = []
    for t in ts:
        p = path.point(t)
        points.append([p.real, p.imag])
        
    return np.array(points)

def build_stack(project_name, slices_dir, output_dir):
    # 0. Find all slice files
    # Expecting {project_name}_slice_{i}.svg
    slice_files = sorted(glob.glob(os.path.join(slices_dir, f"{project_name}_slice_*.svg")))
    if not slice_files:
        print(f"No slice files found in {slices_dir} matching {project_name}_slice_*.svg")
        return

    print(f"Found {len(slice_files)} slices.")
    
    # 1. Determine Scale Factor
    # We need to load ALL slices to find total height
    slice_data = [] # Stores (points, height_contribution)
    
    min_y_global = float('inf')
    max_y_global = -float('inf')
    
    for f in slice_files:
        pts = load_slice_svg(f)
        if pts is None: continue
        
        ys = pts[:, 1]
        min_y_global = min(min_y_global, np.min(ys))
        max_y_global = max(max_y_global, np.max(ys))
        
        slice_data.append(pts)
        
    total_raw_height = max_y_global - min_y_global
    scale_factor = TARGET_MUG_HEIGHT_MM / total_raw_height
    
    print(f"Total Raw Height: {total_raw_height:.2f}")
    print(f"Scale Factor: {scale_factor:.4f}")
    print(f"Y Range: {min_y_global} to {max_y_global}")
    
    # 2. Process each slice
    
    # User requested to PRESERVE the distance from the origin axis.
    # So we assume X=0 in the SVG is the center of rotation.
    # We do NOT shift X.
    
    # Just find max radius for block sizing
    all_xs = np.concatenate([p[:, 0] for p in slice_data])
    actual_max_radius = 0
    
    # Shift and Scale Data
    scaled_slices = []
    for pts in slice_data:
        # Do NOT shift X. 
        # pts[:, 0] -= x_offset
        
        # Invert/Shift Y
        # We want the Z height to be increasing.
        # Let's use (max_y_global - y) so that the lowest point in SVG (max Y) becomes 0.
        # And the highest point in SVG (min Y) becomes the top.
        
        new_y = (max_y_global - pts[:, 1])
        
        # Reconstruct with scaled values
        # We process column by column to apply scale
        xs = pts[:, 0] * scale_factor
        ys = new_y * scale_factor
        
        pts_scaled = np.column_stack((xs, ys))
        
        scaled_slices.append(pts_scaled)
        actual_max_radius = max(actual_max_radius, np.max(xs))
        
    block_width = (actual_max_radius + WALL_THICKNESS) * 2
    print(f"Mold Block Width: {block_width:.2f} mm")

    # Keep track of all parts for the full assembly
    stack_meshes = []

    # 3. Generate Geometry
    
    num_slices = len(scaled_slices)
    
    for i, pts in enumerate(scaled_slices):
        print(f"Generating Mold Level {i}...")
        
        # Prepare profile for solid revolution
        top_y = pts[-1, 1]
        bottom_y = pts[0, 1]
        
        # GALLERY LOGIC (Top Slice Only)
        if i == num_slices - 1:
            print("Adding Gallery to Top Slice...")
            # The "brim" is the last point in pts (highest Y, since we inverted? or lowest?)
            # We ordered Y so that max_y_global-y makes 0 at bottom and Max at Top.
            # So the last point in 'pts' should be the highest Z (top of mug).
            # Let's verify start/end. 
            # Slice 0 is bottom. Slice N is top.
            # pts inside a slice: usually ordered along curve.
            # If curve went bottom-up, last point is top.
            
            brim_point = pts[-1]
            brim_r, brim_z = brim_point[0], brim_point[1]
            
            # Create extension points matching user sketch:
            # 1. Start at brim
            # 2. Go Horizontal OUT (Shelf)
            # 3. Go UP and OUT (Flared rim)
            
            GALLERY_FLARE = 10.0
            
            p_shelf = [brim_r + GALLERY_WIDTH, brim_z]
            p_top   = [brim_r + GALLERY_WIDTH + GALLERY_FLARE, brim_z + GALLERY_HEIGHT]
            
            # Append gallery points
            pts = np.vstack([pts, p_shelf, p_top])
            
            # Update top_y to be the new highest point
            top_y = p_top[1] 
            
            # Also update block width for this level
            current_max_r = max(np.max(pts[:, 0]), actual_max_radius)
            local_block_width = (current_max_r + WALL_THICKNESS) * 2
            
        else:
            local_block_width = block_width
            
        
        closed_pts = np.vstack([
            pts,
            [0, top_y],
            [0, bottom_y],
            pts[0] # Close back to start
        ])
        
        # Revolve
        mug_part = trimesh.creation.revolve(closed_pts, sections=128)
        mug_part.fix_normals()
        
        # Add to stack
        stack_meshes.append(mug_part)
        
        # Export Positive Shape
        out_pos = os.path.join(output_dir, f"{project_name}_level_{i}_positive.stl")
        mug_part.export(out_pos)
        
        # ... (rest of mold generation) ...
        # ... (inside the loop) ...

        # Bounds of this part
        bounds = mug_part.bounds 
        z_min, z_max = bounds[0][2], bounds[1][2]
        part_height = z_max - z_min
        z_center = (z_max + z_min) / 2
        
        # Create Block
        block_size = [local_block_width, local_block_width, part_height]
        block = trimesh.creation.box(extents=block_size)
        block.apply_translation([0, 0, z_center])
        
        try:
            mold_solid = block.difference(mug_part)
        except Exception as e:
            print(f"Boolean difference failed: {e}")
            continue

        right_half = trimesh.intersections.slice_mesh_plane(mold_solid, plane_normal=[-1, 0, 0], plane_origin=[0, 0, 0], cap=True)
        left_half = trimesh.intersections.slice_mesh_plane(mold_solid, plane_normal=[1, 0, 0], plane_origin=[0, 0, 0], cap=True)

        out_l = os.path.join(output_dir, f"{project_name}_level_{i}_left.stl")
        out_r = os.path.join(output_dir, f"{project_name}_level_{i}_right.stl")
        
        left_half.export(out_l)
        right_half.export(out_r)
        print(f"Exported {out_l}, {out_r}")

    # Export Full Stack
    if stack_meshes:
        print("Exporting Full Stack Assembly...")
        full_stack = trimesh.util.concatenate(stack_meshes)
        out_full = os.path.join(output_dir, f"{project_name}_full_stack.stl")
        full_stack.export(out_full)
        print(f"Exported {out_full}")


if __name__ == "__main__":
    # Usage: python build_mold_stack.py [project_name]
    # Assumes ../builds/[project_name]/slices exists
    
    proj = "profile"
    if len(sys.argv) > 1:
        proj = sys.argv[1]
        
    base_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../builds', proj))
    slices_dir = os.path.join(base_dir, 'slices')
    output_dir = os.path.join(base_dir, 'molds')
    
    if not os.path.exists(output_dir):
        os.makedirs(output_dir)
        
    build_stack(proj, slices_dir, output_dir)
