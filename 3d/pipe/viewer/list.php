<?php
$output_dir = realpath(__DIR__ . '/../output');
$files = glob($output_dir . '/*.stl');
sort($files);
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
            display: block;
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
        .note {
            color: #666;
            font-size: 11px;
            padding: 4px 12px;
            margin-top: -4px;
            margin-bottom: 6px;
        }
        .empty {
            color: #666;
            font-style: italic;
            padding: 20px 10px;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <h2>⚱️ Coffin Pipes</h2>
    <?php if (empty($files)): ?>
        <p class="empty">No STL files found.<br>Run generate.py first.</p>
    <?php else: ?>
        <p class="note">Coffin bowl &middot; bored stem</p>
        <?php foreach ($files as $file): ?>
            <?php
                $basename = basename($file);
                $file_url = '../output/' . $basename;
                $viewer_url = 'viewer.php?file=' . urlencode($file_url);
            ?>
            <a href="<?= $viewer_url ?>" target="viewframe">
                <?= htmlspecialchars($basename) ?>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
