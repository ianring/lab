<!DOCTYPE html>
<html>

<head>
    <title>Mold Project Report</title>
    <style>
        body {
            font-family: sans-serif;
            background: #222;
            color: #eee;
            padding: 20px;
        }

        h1,
        h2 {
            border-bottom: 1px solid #444;
            padding-bottom: 10px;
        }

        .section {
            margin-bottom: 40px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .card {
            background: #333;
            padding: 10px;
            border-radius: 5px;
        }

        .card h3 {
            margin-top: 0;
            font-size: 14px;
            color: #aaa;
        }

        img {
            max-width: 100%;
            background: white;
        }

        iframe {
            width: 100%;
            height: 300px;
            border: none;
            background: #000;
        }

        .row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .half {
            flex: 1;
        }
    </style>
</head>

<body>

    <?php
    $project = $_GET['project'] ?? '';
    if (!$project) {
        echo "<h1>No Project Selected</h1><p>Please provide ?project=name</p>";
        exit;
    }

    $base_dir = realpath(__DIR__ . '/../../builds/' . $project);
    $slices_dir = $base_dir . '/slices';
    $molds_dir = $base_dir . '/molds';

    if (!file_exists($base_dir)) {
        echo "<h1>Project Not Found</h1><p>Searching in $base_dir</p>";
        exit;
    }
    ?>

    <h1>Project:
        <?= htmlspecialchars($project) ?>
    </h1>

    <div class="section">
        <h2>1. Profile Analysis & Slicing</h2>
        <p>The mug profile is analyzed for undercuts and sliced into optimal sections.</p>

        <div class="row">
            <div class="half">
                <h3>Overall Analysis</h3>
                <?php
                $viz = "../../builds/$project/slices/sliced_mug_viz.png";
                if (file_exists($slices_dir . '/sliced_mug_viz.png')) {
                    echo "<img src='$viz' style='max-height: 600px; width: auto;'>";
                } else {
                    echo "<p>Analysis image not found.</p>";
                }
                ?>
            </div>
            <div class="half">
                <h3>Generated Slices</h3>
                <div class="grid">
                    <?php
                    $slices = glob($slices_dir . "/*_slice_*.svg");
                    sort($slices);
                    foreach ($slices as $slice) {
                        $name = basename($slice);
                        $url = "../../builds/$project/slices/$name";
                        echo "<div class='card'>";
                        echo "<h3>$name</h3>";
                        echo "<img src='$url' height='250'>";
                        echo "</div>";
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>2. Revolved Shapes (Positives)</h2>
        <p>The 2D slices are revolved to create the positive 3D sections of the mug.</p>
        <div class="grid">
            <?php
            $positives = glob($molds_dir . "/*_positive.stl");
            sort($positives);
            foreach ($positives as $p) {
                $name = basename($p);
                $file_url = "../../builds/$project/molds/$name";
                $viewer_url = "viewer.php?file=" . urlencode($file_url);
                echo "<div class='card'>";
                echo "<h3>$name</h3>";
                echo "<iframe src='$viewer_url'></iframe>";
                echo "</div>";
            }
            ?>
        </div>

        <h3>Full Assembly</h3>
        <div class="card">
            <?php
            // Assume single full stack file
            $full_stack = glob($molds_dir . "/*_full_stack.stl");
            if ($full_stack) {
                $f = $full_stack[0];
                $name = basename($f);
                $file_url = "../../builds/$project/molds/$name";
                $viewer_url = "viewer.php?file=" . urlencode($file_url);
                echo "<h3>$name</h3>";
                echo "<iframe src='$viewer_url' style='height: 500px;'></iframe>";
            }
            ?>
        </div>
    </div>

    <div class="section">
        <h2>3. 3D Mold Generation</h2>
        <p>Each slice is revolved and converted into a 2-part block mold.</p>

        <?php
        // Find levels
        // Expected format: {project}_level_{i}_{side}.stl
        $levels = [];
        $molds = glob($molds_dir . "/*.stl");
        foreach ($molds as $m) {
            if (preg_match('/level_(\d+)_/', basename($m), $matches)) {
                $levels[$matches[1]][] = $m;
            }
        }
        ksort($levels);

        foreach ($levels as $lvl => $files) {
            echo "<h3>Level $lvl</h3>";
            echo "<div class='grid'>";
            sort($files);
            foreach ($files as $f) {
                $name = basename($f);
                // Viewer URL needs relative path to the file itself
                $file_url = "../../builds/$project/molds/$name";
                $viewer_url = "viewer.php?file=" . urlencode($file_url);

                echo "<div class='card'>";
                echo "<h3>$name</h3>";
                echo "<iframe src='$viewer_url'></iframe>";
                echo "<p><a href='$file_url' style='color:#88f; font-size:12px;'>Download STL</a></p>";
                echo "</div>";
            }
            echo "</div>";
        }
        ?>
    </div>

</body>

</html>