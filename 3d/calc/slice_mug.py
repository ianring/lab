import numpy as np
import matplotlib.pyplot as plt
from svgpathtools import svg2paths, CubicBezier, Line, QuadraticBezier
import os

def solve_bezier_x_extrema(bezier_segment):
    """
    Finds t values (0 < t < 1) where the x-derivative of the bezier segment is 0.
    """
    # cubic bezier x-coordinates: x(t) = a*t^3 + b*t^2 + c*t + d
    # derivative x'(t) is a quadratic: 3at^2 + 2bt + c
    # We can use the poly roots.
    
    # svgpathtools poly() returns coefficients for the complex polynomial P(t) = x(t) + i*y(t)
    # We just want the real part coefficients.
    
    poly = bezier_segment.poly()
    # poly is a numpy.poly1d object
    # coefficients are [a, b, c, d] highest power first
    
    # Extract real parts of coefficients for x(t)
    x_coeffs = [c.real for c in poly.coeffs]
    
    # Create polynomial for x(t)
    x_poly = np.poly1d(x_coeffs)
    
    # Derivative x'(t)
    dx_poly = x_poly.deriv()
    
    # Find roots
    roots = dx_poly.roots
    
    # Filter valid roots in (0, 1) range
    # tolerance for floating point issues?
    valid_ts = []
    for r in roots:
        if np.isreal(r):
            t = r.real
            if 0.001 < t < 0.999:
                 valid_ts.append(t)
                 
    return sorted(valid_ts)

def slice_mug_profile(input_svg, output_dir):
    paths, attributes = svg2paths(input_svg)
    if not paths:
        print("No paths found!")
        return
    
    path = paths[0] # Assuming single path
    
    # We need to flatten the path into a continuous list of segments and split them
    # Actually, svgpathtools paths are lists of segments.
    # We will build a new list of segments for the 'current' slice, and start a new list when we hit a split point.
    
    slices = [] # List of paths (each path is a list of segments)
    current_slice_segments = []
    
    # Absolute time tracker (just for potential debugging or continuity)
    # But checking each segment is enough.
    
    # We need to handle splitting a segment in the middle.
    
    total_segments = len(path)
    
    start_point = path.start
    
    # Filter out straight lines (centerline, top/bottom caps)
    # Heuristic: Ignore segments that are perfectly vertical or horizontal, or very short.
    profile_segments = []
    for seg in path:
        # Check if it is a line
        if isinstance(seg, Line):
            # Check for vertical or horizontal
            p1, p2 = seg.start, seg.end
            if abs(p1.real - p2.real) < 1e-4 or abs(p1.imag - p2.imag) < 1e-4:
                continue
        profile_segments.append(seg)
        
    print(f"Filtered down to {len(profile_segments)} profile segments from {len(path)} total segments.")

    # Iterate over ONLY the profile segments
    for i, seg in enumerate(profile_segments):
        # Check for extrema within this segment
        ts = []
        if isinstance(seg, (CubicBezier, QuadraticBezier, Line)):
             ts = solve_bezier_x_extrema(seg)
        
        # If no extrema, just add segment to current slice
        if not ts:
            current_slice_segments.append(seg)
        else:
            # We have split points!
            ts.sort()
            
            remaining_seg = seg
            t_consumed = 0.0 
            
            for t_orig in ts:
                # Calculate t in the frame of the currently remaining segment
                if abs(1 - t_consumed) < 1e-6:
                     break
                     
                t_local = (t_orig - t_consumed) / (1.0 - t_consumed)
                
                # Safety clamp
                if t_local <= 0.001 or t_local >= 0.999:
                    continue 

                s1, s2 = remaining_seg.split(t_local)
                
                # Add first part to current slice
                current_slice_segments.append(s1)
                
                # "Close" this slice (save it)
                from svgpathtools import Path
                slices.append(Path(*current_slice_segments))
                current_slice_segments = []
                
                # Update remainder
                remaining_seg = s2
                t_consumed = t_orig
                
            # Add the final piece of this segment to the NEW current slice
            current_slice_segments.append(remaining_seg)
            
    # Add the final accumulated slice
    if current_slice_segments:
        from svgpathtools import Path
        slices.append(Path(*current_slice_segments))

    # Output results
    if not os.path.exists(output_dir):
        os.makedirs(output_dir)
        
    print(f"Found {len(slices)} slices.")
    
    # Visualization setup
    plt.figure(figsize=(6, 10))
    colors = ['r', 'g', 'b', 'c', 'm', 'y', 'k']
    
    global_y_max = -float('inf')
    global_y_min = float('inf')
    
    # Project prefix from input filename
    project_prefix = os.path.splitext(os.path.basename(input_svg))[0]

    for i, s_path in enumerate(slices):
        print(f"Slice {i}: {len(s_path)} segments")
        
        # Save SVG
        from svgpathtools import wsvg
        out_name = os.path.join(output_dir, f'{project_prefix}_slice_{i}.svg')
        wsvg(s_path, filename=out_name)
        
        # Plotting
        # Sample points
        for seg in s_path:
            # seg is a Bezier/Line
            # sample
            ts_vals = np.linspace(0, 1, 50)
            pts = [seg.point(t) for t in ts_vals]
            xs = [p.real for p in pts]
            ys = [p.imag for p in pts]
            
            plt.plot(xs, ys, color=colors[i % len(colors)], linewidth=2, label=f'Section {i}' if seg == s_path[0] else "")
            
            # Find min/max for scaling
            # (Crude check)
            global_y_max = max(global_y_max, max(ys))
            global_y_min = min(global_y_min, min(ys))

    plt.gca().invert_yaxis()
    plt.axis('equal')
    plt.legend()
    plt.title(f"Mug Contour Sliced: {len(slices)} Sections")
    viz_path = os.path.join(output_dir, 'sliced_mug_viz.png')
    plt.savefig(viz_path)
    print(f"Saved {viz_path}")

if __name__ == "__main__":
    import sys
    import os
    
    # Default file or arg
    input_file = '../shapes/profile.svg'
    if len(sys.argv) > 1:
        input_file = sys.argv[1]
        
    project_name = os.path.splitext(os.path.basename(input_file))[0]
    
    # New folder structure: ../build/{project_name}/slices
    # Assuming we are in lab/3d/calc, we go up one to 3d, then up to lab? 
    # Or just keep it inside 3d/build?
    # Let's put it in lab/builds/{project_name}/slices
    base_build_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../builds'))
    output_dir = os.path.join(base_build_dir, project_name, 'slices')
    
    print(f"Processing project: {project_name}")
    print(f"Output directory: {output_dir}")
    
    slice_mug_profile(input_file, output_dir)
