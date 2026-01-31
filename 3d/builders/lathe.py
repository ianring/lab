import numpy as np
from stl import mesh

def create_lathe_mesh(profile_points, segments=64):
    """
    profile_points: List of (radius, height) tuples
    segments: How many slices to divide the circle into
    """
    num_profile = len(profile_points)
    vertices = []
    
    # 1. Generate all vertices
    for r, z in profile_points:
        for i in range(segments):
            theta = (2 * np.pi * i) / segments
            x = r * np.cos(theta)
            y = r * np.sin(theta)
            vertices.append([x, y, z])
            
    vertices = np.array(vertices)
    faces = []

    # 2. Connect vertices with triangles (Faces)
    for p in range(num_profile - 1):
        for s in range(segments):
            # Current row indices
            p1 = p * segments + s
            p2 = p * segments + (s + 1) % segments
            # Next row indices
            p3 = (p + 1) * segments + s
            p4 = (p + 1) * segments + (s + 1) % segments
            
            # Triangle 1
            faces.append([p1, p2, p3])
            # Triangle 2
            faces.append([p2, p4, p3])

    faces = np.array(faces)
    
    # 3. Create the STL mesh
    lathe_mesh = mesh.Mesh(np.zeros(faces.shape[0], dtype=mesh.Mesh.dtype))
    for i, f in enumerate(faces):
        for j in range(3):
            lathe_mesh.vectors[i][j] = vertices[f[j],:]
            
    return lathe_mesh

# Define a curved profile: (radius, height)
# This creates a simple vase shape using a sine wave
z_range = np.linspace(0, 10, 50)
profile = [(2 + np.sin(z * 0.8), z) for z in z_range]

# Generate and save
vase = create_lathe_mesh(profile)
vase.save('lathe_output.stl')
print("lathe_output.stl generated.")