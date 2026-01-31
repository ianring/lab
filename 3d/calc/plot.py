import numpy as np
import matplotlib.pyplot as plt
from svgpathtools import svg2paths

def analyze_mug_contour(svg_file):
    # 1. Load the SVG
    paths, attributes = svg2paths(svg_file)
    path = paths[0] # Assuming your mug is the first path
    
    # 2. Sample points along the curve
    # We sample 500 points to get a smooth analysis
    ts = np.linspace(0, 1, 500)
    points = np.array([path.point(t) for t in ts])
    
    # Extract X and Y (svgpathtools uses complex numbers: x + iy)
    x = points.real
    y = points.imag
    
    # 3. Calculate the "Draft Logic" (The Derivative)
    # We want to see how X changes relative to the path progress
    dx = np.gradient(x)
    
    # Find where the slope is zero (the "turning points")
    # These are where the mold would get stuck
    critical_indices = np.where(np.diff(np.sign(dx)))[0]
    
    # 4. Visualization
    plt.figure(figsize=(6, 10))
    plt.plot(x, y, 'b-', label='Mug Contour')
    
    if len(critical_indices) > 0:
        plt.scatter(x[critical_indices], y[critical_indices], color='red', s=100, label='Potential Undercut/Slice')
        print(f"Found {len(critical_indices)} potential slice points.")
    else:
        print("No undercuts detected! This shape is a clean 2-piece mold candidate.")

    plt.gca().invert_yaxis() # SVGs have Y-down, this flips it to 'normal'
    plt.title("Mug Draft Analysis")
    plt.legend()
    plt.axis('equal')
    plt.savefig('mug_analysis.png')
    print("Analysis saved to mug_analysis.png")

# To use this, just save your SVG as 'mug.svg' in the same folder
analyze_mug_contour('../shapes/profile.svg')