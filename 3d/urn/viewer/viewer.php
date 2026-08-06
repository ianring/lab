<?php $file = $_GET['file'] ?? ''; ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/gh/mrdoob/three.js@r128/examples/js/loaders/STLLoader.js"></script>
    <script src="https://cdn.jsdelivr.net/gh/mrdoob/three.js@r128/examples/js/controls/OrbitControls.js"></script>
    <style>
        * { margin: 0; padding: 0; }
        body { overflow: hidden; background: #111122; }
        #viewer { width: 100vw; height: 100vh; }
        #info {
            position: absolute;
            top: 12px;
            left: 12px;
            color: #8888cc;
            font-family: 'Segoe UI', system-ui, monospace;
            font-size: 12px;
            background: rgba(10, 10, 30, 0.8);
            padding: 8px 14px;
            border-radius: 6px;
            border: 1px solid rgba(100, 120, 255, 0.15);
            pointer-events: none;
        }
        #loading {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #6666aa;
            font-family: 'Segoe UI', system-ui, sans-serif;
            font-size: 16px;
            letter-spacing: 2px;
        }
    </style>
</head>
<body>

    <div id="viewer"></div>
    <div id="info"></div>
    <div id="loading"><?= $file ? 'Loading STL...' : 'Select an urn from the list' ?></div>

    <script>
        window.onload = function () {
            const fileToLoad = "<?= htmlspecialchars($file) ?>";
            const container = document.getElementById('viewer');
            const infoEl = document.getElementById('info');
            const loadingEl = document.getElementById('loading');

            if (!container) return;

            // --- Scene Setup ---
            const scene = new THREE.Scene();
            scene.background = new THREE.Color(0x111122);

            const camera = new THREE.PerspectiveCamera(
                45,
                container.clientWidth / container.clientHeight,
                0.1,
                5000
            );
            camera.position.set(40, 40, 40);

            const renderer = new THREE.WebGLRenderer({ antialias: true });
            renderer.setSize(container.clientWidth, container.clientHeight);
            renderer.setPixelRatio(window.devicePixelRatio);
            renderer.shadowMap.enabled = true;
            container.appendChild(renderer.domElement);

            const controls = new THREE.OrbitControls(camera, renderer.domElement);
            controls.enableDamping = true;
            controls.dampingFactor = 0.08;

            // --- Grid: 10mm spacing ---
            const grid = new THREE.GridHelper(200, 20, 0x334466, 0x222244);
            scene.add(grid);

            // --- Lights ---
            scene.add(new THREE.AmbientLight(0x6666aa, 0.4));

            const dirLight = new THREE.DirectionalLight(0xffffff, 0.8);
            dirLight.position.set(50, 100, 50);
            dirLight.castShadow = true;
            scene.add(dirLight);

            const pointLight = new THREE.PointLight(0x8888ff, 0.4);
            pointLight.position.set(-30, 60, -30);
            scene.add(pointLight);

            // --- Load STL ---
            if (fileToLoad) {
                const loader = new THREE.STLLoader();
                loader.load(fileToLoad, function (geometry) {
                    const material = new THREE.MeshNormalMaterial({
                        side: THREE.DoubleSide
                    });
                    const mesh = new THREE.Mesh(geometry, material);

                    // Center mesh on XZ plane
                    geometry.computeBoundingBox();
                    const box = geometry.boundingBox;
                    const size = new THREE.Vector3();
                    box.getSize(size);
                    const center = new THREE.Vector3();
                    box.getCenter(center);

                    mesh.position.x = -center.x;
                    mesh.position.y = -box.min.y;
                    mesh.position.z = -center.z;

                    scene.add(mesh);

                    // --- Auto-zoom ---
                    const maxDim = Math.max(size.x, size.y, size.z);
                    const fov = camera.fov * (Math.PI / 180);
                    let cameraZ = Math.abs(maxDim / 2 / Math.tan(fov / 2));
                    cameraZ *= 2.5;

                    camera.position.set(cameraZ, cameraZ * 0.8, cameraZ);
                    controls.target.set(0, size.y / 2, 0);
                    controls.update();

                    // Update info
                    infoEl.textContent =
                        fileToLoad.split('/').pop() +
                        '  •  ' + size.x.toFixed(1) + ' × ' +
                        size.y.toFixed(1) + ' × ' +
                        size.z.toFixed(1) + ' mm';

                    loadingEl.style.display = 'none';
                },
                undefined,
                function(err) {
                    loadingEl.textContent = 'Error loading file';
                    console.error(err);
                });
            }

            // --- Resize handler ---
            window.addEventListener('resize', function () {
                camera.aspect = container.clientWidth / container.clientHeight;
                camera.updateProjectionMatrix();
                renderer.setSize(container.clientWidth, container.clientHeight);
            });

            // --- Render loop ---
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
