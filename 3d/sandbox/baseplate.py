import trimesh
import numpy as np

# --- ADJUSTABLE PARAMETERS ---
disk_radius = 75.0      # 150mm diameter
disk_thickness = 5.0    # 5mm thick base
ridge_width = 5.0      # Equilateral triangle base
ridge_height = 5.0     #
# Inner edges of the 6 ridges
ridge_positions = [25, 30, 35, 50, 55, 60] 
sections = 128          # Smoothness of the circle

def generate_shape():
    # 1. Start the profile at the center bottom (0, 0)
    profile = [[0, 0]]
    
    # 2. Draw the bottom edge to the outer radius
    profile.append([disk_radius, 0])
    
    # 3. Draw the outer vertical edge of the disk
    profile.append([disk_radius, disk_thickness])
    
    # 4. Draw the top surface, moving inward and adding ridges
    # We sort positions in reverse to draw from the outside-in
    for pos in sorted(ridge_positions, reverse=True):
        # Edge of disk before the ridge
        profile.append([pos + ridge_width, disk_thickness])
        # Peak of the triangular ridge (center of the 10mm width)
        profile.append([pos + (ridge_width / 2), disk_thickness + ridge_height])
        # Inner edge of the ridge
        profile.append([pos, disk_thickness])

    # 5. Finish at the center top
    profile.append([0, disk_thickness])
    # Close the loop back to the start is handled by trimesh revolve
    
    # 6. Revolve the 2D profile into a 3D solid
    # This creates a perfectly watertight (manifold) STL
    mesh = trimesh.creation.revolve(profile, sections=sections)
    
    mesh.export('ridged_disk.stl')
    print(f"Success! Generated 'ridged_disk.stl' with {len(mesh.faces)} faces.")

if __name__ == "__main__":
    generate_shape()