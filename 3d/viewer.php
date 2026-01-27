<?php $file = $_GET['file'] ?? ''; ?><html>
<head>
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script src="https://cdn.jsdelivr.net/gh/mrdoob/three.js@r128/examples/js/loaders/STLLoader.js"></script>
<script src="https://cdn.jsdelivr.net/gh/mrdoob/three.js@r128/examples/js/controls/OrbitControls.js"></script>
</head>

<body>

<div id="viewer" style="width: 100%; height: 100%; background: #1a1a1a;"></div>

<script>
window.onload = function() {
    const fileToLoad = "<?= htmlspecialchars($file) ?>";
    const container = document.getElementById('viewer');
    if (!container) return;

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(45, container.clientWidth / container.clientHeight, 1, 5000);
    
    // Initial camera position (will be overridden by auto-zoom)
    camera.position.set(300, 300, 300);

    const renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setSize(container.clientWidth, container.clientHeight);
    container.appendChild(renderer.domElement);

    const controls = new THREE.OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;

    // --- GRID FIX ---
    // Size: 500mm, Divisions: 50. This makes each major grid square 10mm.
    const grid = new THREE.GridHelper(500, 50, 0x888888, 0x444444);
    scene.add(grid);

    scene.add(new THREE.AmbientLight(0xffffff, 0.5));
    const pointLight = new THREE.PointLight(0xffffff, 1);
    pointLight.position.set(200, 500, 200);
    scene.add(pointLight);

    const loader = new THREE.STLLoader();
    loader.load(fileToLoad, function (geometry) {
        const material = new THREE.MeshStandardMaterial({ 
            color: 0x999999, 
            roughness: 0.5, 
            metalness: 0.1,
            side: THREE.DoubleSide 
        });
        const mesh = new THREE.Mesh(geometry, material);
        
        // geometry.computeBoundingBox();
        // const box = geometry.boundingBox;
        // const size = new THREE.Vector3();
        // box.getSize(size);
        // const center = new THREE.Vector3();
        // box.getCenter(center);

        // Center mesh and sit it on the grid
        // mesh.position.x = -center.x;
        // mesh.position.y = -box.min.y;
        // mesh.position.z = -center.z;

        scene.add(mesh);

        // --- AUTO-ZOOM LOGIC ---
        // Calculate the distance needed to fit the object in view
        const maxDim = Math.max(size.x, size.y, size.z);
        const fov = camera.fov * (Math.PI / 180);
        let cameraZ = Math.abs(maxDim / 2 / Math.tan(fov / 2));
        
        cameraZ *= 2.5; // Multiplier to give some "breathing room" around the piece
        camera.position.set(cameraZ, cameraZ, cameraZ);
        
        // Point camera at the center of the now-placed mesh
        controls.target.set(0, size.y / 2, 0);
        controls.update();
    });

    function animate() {
        requestAnimationFrame(animate);
        controls.update();
        renderer.render(scene, camera);
    }
    animate();
};
</script>

</body>

</html>


