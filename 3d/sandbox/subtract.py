import trimesh
import numpy as np

def create_subtraction_poc():
    # 1. Create the "Main" shape: A Rectangle (Box)
    # Extents are [width, height, depth]
    box = trimesh.creation.box(extents=[10, 10, 4])
    
    # 2. Create the "Tool" shape: A Cylinder to subtract
    # radius=2, height=10 (tall enough to definitely poke through)
    cylinder = trimesh.creation.cylinder(radius=2, height=10)
    
    # 3. Position the cylinder so it overlaps the box
    # By default, both are centered at (0,0,0). 
    # Let's shift the cylinder slightly off-center to see a partial hole.
    translation = [2, 0, 0]
    cylinder.apply_translation(translation)
    
    # 4. Perform the Subtraction (Box minus Cylinder)
    # Note: boolean_difference returns a new Trimesh object
    result = box.difference(cylinder)
    
    # 5. Export to your lab folder
    result.export('subtraction_test.stl')
    print("subtraction_test.stl generated.")

if __name__ == "__main__":
    create_subtraction_poc()