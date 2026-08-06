<?php
$raster_dir = realpath(__DIR__ . '/../rasters');
$output_dir = realpath(__DIR__ . '/../output');
$exts = ['png', 'jpg', 'jpeg', 'bmp', 'gif', 'tif', 'tiff', 'webp'];

$rasters = [];
foreach ($exts as $ext) {
    foreach (glob($raster_dir . '/*.' . $ext) as $f) { $rasters[$f] = true; }
    foreach (glob($raster_dir . '/*.' . strtoupper($ext)) as $f) { $rasters[$f] = true; }
}
$rasters = array_keys($rasters);
sort($rasters);
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #1a1a2e;
            color: #ccc;
            font-family: 'Segoe UI', system-ui, sans-serif;
            padding: 16px;
            overflow-y: auto;
        }
        h2 {
            color: #e0e0ff;
            font-size: 15px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            border-bottom: 2px solid rgba(100, 120, 255, 0.3);
            padding-bottom: 10px;
            margin-bottom: 16px;
        }
        a {
            color: #88aaff;
            text-decoration: none;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 9px 12px;
            border-radius: 4px;
            font-size: 13px;
            transition: all 0.15s ease;
            border-bottom: 1px solid rgba(100, 120, 255, 0.08);
        }
        a:hover {
            background: rgba(100, 120, 255, 0.15);
            color: #aaccff;
        }
        .tag {
            font-size: 10px;
            padding: 2px 7px;
            border-radius: 10px;
            letter-spacing: 0.5px;
        }
        .traced { background: rgba(80, 200, 120, 0.2); color: #7fe0a0; }
        .untraced { background: rgba(200, 120, 80, 0.18); color: #e0a07f; }
        .empty {
            color: #666;
            font-style: italic;
            padding: 20px 10px;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <h2>🖊 Tracer</h2>
    <?php if (empty($rasters)): ?>
        <p class="empty">No raster images found.<br>Drop files in rasters/.</p>
    <?php else: ?>
        <?php foreach ($rasters as $file): ?>
            <?php
                $basename = basename($file);
                $name = pathinfo($file, PATHINFO_FILENAME);
                $has_svg = file_exists($output_dir . '/' . $name . '.svg');
                $viewer_url = 'viewer.php?name=' . urlencode($name);
            ?>
            <a href="<?= $viewer_url ?>" target="viewframe">
                <span><?= htmlspecialchars($basename) ?></span>
                <?php if ($has_svg): ?>
                    <span class="tag traced">traced</span>
                <?php else: ?>
                    <span class="tag untraced">no svg</span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
