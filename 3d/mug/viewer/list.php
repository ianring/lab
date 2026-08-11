<?php
$output_dir = realpath(__DIR__ . '/../output');
$files = $output_dir ? glob($output_dir . '/*.stl') : [];
sort($files);
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        body { background: #222; color: #ccc; font-family: sans-serif; padding: 10px; }
        a { color: #0af; text-decoration: none; display: block; padding: 8px; border-bottom: 1px solid #333; }
        a:hover { background: #333; }
        h3 { color: #fff; font-size: 14px; text-transform: uppercase; border-bottom: 2px solid #444; }
        .note { color: #666; font-size: 11px; padding: 4px 8px; }
        .empty { color: #666; font-style: italic; padding: 20px 8px; font-size: 13px; }
    </style>
</head>
<body>
    <h3>Mug Moulds</h3>
    <?php if (empty($files)): ?>
        <p class="empty">No STL files found.<br>Run generate.py first.</p>
    <?php else: ?>
        <p class="note">Master &middot; cottle walls &middot; registration keys</p>
        <?php foreach ($files as $file): ?>
            <?php
                $basename = basename($file);
                $file_url = '../output/' . $basename;
            ?>
            <a href="viewer.php?file=<?= urlencode($file_url) ?>" target="viewframe"><?= htmlspecialchars($basename) ?></a>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
