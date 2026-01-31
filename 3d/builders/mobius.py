import numpy as np
from stl import mesh
import math

def create_cylinder(radius=1, height=2, segments=32):
    # Create vertices for top and bottom rings
    vertices = []
    for i in range(segments):
        angle = 2 * math.pi * i / segments
        x = radius * math.cos(angle)
        y = radius * math.sin(angle)
        vertices.append([x, y, 0])      # Bottom ring
        vertices.append([x, y, height]) # Top ring

    vertices = np.array(vertices)
    faces = []

    # Create the side panels (two triangles per segment)
    for i in range(segments):
        b1 = 2 * i
        t1 = 2 * i + 1
        b2 = 2 * ((i + 1) % segments)
        t2 = 2 * ((i + 1) % segments) + 1
        
        faces.append([b1, b2, t1])
        faces.append([t1, b2, t2])

    faces = np.array(faces)
    
    # Create the mesh
    cyl_mesh = mesh.Mesh(np.zeros(faces.shape[0], dtype=mesh.Mesh.dtype))
    for i, f in enumerate(faces):
        for j in range(3):
            cyl_mesh.vectors[i][j] = vertices[f[j],:]
            
    return cyl_mesh

# Generate and save
my_cyl = create_cylinder(radius=1.5, height=5, segments=64)
my_cyl.save('cylinder.stl')