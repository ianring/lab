<?php
$dir = "."; // Current directory
$files = glob("*.stl");
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        body { background: #222; color: #ccc; font-family: sans-serif; padding: 10px; }
        a { color: #0af; text-decoration: none; display: block; padding: 8px; border-bottom: 1px solid #333; }
        a:hover { background: #333; }
        h3 { color: #fff; font-size: 14px; text-transform: uppercase; border-bottom: 2px solid #444; }
    </style>
</head>
<body>
    <h3>STL Lab Files</h3>
    <?php foreach ($files as $file): ?>
        <a href="viewer.php?file=<?= urlencode($file) ?>" target="viewframe"><?= htmlspecialchars($file) ?></a>
    <?php endforeach; ?>
</body>
</html>