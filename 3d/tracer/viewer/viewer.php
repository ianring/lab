<?php
$name = $_GET['name'] ?? '';
$raster_dir = realpath(__DIR__ . '/../rasters');
$output_dir = realpath(__DIR__ . '/../output');
$exts = ['png', 'jpg', 'jpeg', 'bmp', 'gif', 'tif', 'tiff', 'webp'];

$raster_url = '';
if ($name !== '') {
    foreach ($exts as $ext) {
        foreach ([$ext, strtoupper($ext)] as $e) {
            $candidate = $raster_dir . '/' . $name . '.' . $e;
            if (file_exists($candidate)) {
                $raster_url = '../rasters/' . $name . '.' . $e;
                break 2;
            }
        }
    }
}
$svg_path = $output_dir . '/' . $name . '.svg';
$svg_url = ($name !== '' && file_exists($svg_path)) ? '../output/' . $name . '.svg' : '';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #111122;
            color: #ccc;
            font-family: 'Segoe UI', system-ui, sans-serif;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        #toolbar {
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 10px 16px;
            background: rgba(10, 10, 30, 0.9);
            border-bottom: 1px solid rgba(100, 120, 255, 0.15);
            font-size: 13px;
            flex: 0 0 auto;
        }
        #toolbar .title { color: #aaccff; font-weight: 600; }
        #toolbar label { color: #8888cc; cursor: pointer; user-select: none; }
        #toolbar input[type=range] { vertical-align: middle; }
        .stage {
            flex: 1 1 auto;
            display: flex;
            min-height: 0;
        }
        .pane {
            flex: 1 1 50%;
            display: flex;
            flex-direction: column;
            min-width: 0;
            border-right: 1px solid rgba(100, 120, 255, 0.12);
        }
        .pane:last-child { border-right: none; }
        .pane h3 {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #6677aa;
            padding: 8px 14px;
            flex: 0 0 auto;
        }
        .canvas {
            flex: 1 1 auto;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            /* white so the black vector reads clearly */
            background-color: #ffffff;
        }
        .canvas img, .canvas object, .canvas svg {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        /* overlay mode: stack raster + svg in a single pane */
        #overlayStage { display: none; position: relative; }
        #overlayStage .layer {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        #overlayStage .layer img,
        #overlayStage .layer object { max-width: 100%; max-height: 100%; object-fit: contain; }
        .missing {
            color: #aa6677;
            font-style: italic;
            font-size: 13px;
            padding: 20px;
            text-align: center;
        }
        .hint {
            color: #555;
            font-style: italic;
            margin: auto;
            font-size: 15px;
            letter-spacing: 1px;
        }
    </style>
</head>
<body>
<?php if ($name === ''): ?>
    <div class="hint">Select an image from the list</div>
<?php else: ?>
    <div id="toolbar">
        <span class="title"><?= htmlspecialchars($name) ?></span>
        <label><input type="checkbox" id="overlayToggle"> Overlay</label>
        <label id="opacityWrap" style="display:none">
            Vector opacity
            <input type="range" id="opacity" min="0" max="100" value="55">
        </label>
    </div>

    <!-- side-by-side -->
    <div class="stage" id="sideStage">
        <div class="pane">
            <h3>Raster</h3>
            <div class="canvas">
                <?php if ($raster_url): ?>
                    <img src="<?= htmlspecialchars($raster_url) ?>" alt="raster">
                <?php else: ?>
                    <div class="missing">raster not found</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="pane">
            <h3>Vector (traced SVG)</h3>
            <div class="canvas">
                <?php if ($svg_url): ?>
                    <object type="image/svg+xml" data="<?= htmlspecialchars($svg_url) ?>"></object>
                <?php else: ?>
                    <div class="missing">no SVG yet — run generate.py</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- overlay -->
    <div class="stage" id="overlayStage">
        <div class="canvas" style="flex:1">
            <?php if ($raster_url): ?>
                <div class="layer"><img src="<?= htmlspecialchars($raster_url) ?>" alt="raster"></div>
            <?php endif; ?>
            <?php if ($svg_url): ?>
                <div class="layer" id="svgLayer">
                    <object type="image/svg+xml" data="<?= htmlspecialchars($svg_url) ?>"></object>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const overlayToggle = document.getElementById('overlayToggle');
        const sideStage = document.getElementById('sideStage');
        const overlayStage = document.getElementById('overlayStage');
        const opacityWrap = document.getElementById('opacityWrap');
        const opacity = document.getElementById('opacity');
        const svgLayer = document.getElementById('svgLayer');

        function applyOpacity() {
            if (svgLayer) svgLayer.style.opacity = opacity.value / 100;
        }
        overlayToggle.addEventListener('change', function () {
            const on = overlayToggle.checked;
            sideStage.style.display = on ? 'none' : 'flex';
            overlayStage.style.display = on ? 'flex' : 'none';
            opacityWrap.style.display = on ? 'inline' : 'none';
            applyOpacity();
        });
        opacity.addEventListener('input', applyOpacity);
        applyOpacity();
    </script>
<?php endif; ?>
</body>
</html>
