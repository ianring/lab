<?php

// $text1 = 'You can\'t';
// $text2 = 'touch this';

function overlayWarpedText($imagePath, $text1, $text2, $outputPath, $warpPoints) {
    // Escape shell arguments
    $escapedImagePath = escapeshellarg($imagePath);
    $escapedText1 = escapeshellarg($text1);
    $escapedText2 = escapeshellarg($text2);
    $escapedOutputPath = escapeshellarg($outputPath);
    $escapedWarpPoints = escapeshellarg($warpPoints);

    // ImageMagick command:
    // 1. Create a transparent layer with the text
    // 2. Warp the transparent text layer
    // 3. Composite the warped text onto the original image.
    $command = "convert " . $escapedImagePath . " 
                 -size $(identify -format '%wx%h' " . $escapedImagePath . " xc:transparent 
                   -gravity center 
                   -pointsize 120 
                   -fill black 
                   -annotate +0+120 '" . $escapedText1 . "' 
                   -annotate +0+160 '" . $escapedText2 . "' 
                   -virtual-pixel transparent 
                   -distort Perspective '" . $escapedWarpPoints . "' 
                -composite " . $escapedOutputPath;

    // Execute the ImageMagick command
    exec($command, $output, $return_var);
    echo "<hr>";
    echo $command;
    echo "<hr>";

    if ($return_var !== 0) {
        return "Error: ImageMagick command failed. Output: " . implode("\n", $output);
    }

    return "Warped text overlay successful. Output file: " . $outputPath;
}

// Example usage:
$imagePath = 'shirts/shirt002.png'; // Replace with your image path
$text1 = "Warped Text";
$text2 = "Example";
$outputPath = 'output/output.png'; // Replace with your desired output path

// Example warp points: (You MUST adjust these!)
// sourceX1,sourceY1 destX1,destY1 ...
// Example for a very slight bend, you will need to find your own.
$warpPoints = "0,0 20,0 0,100 0,100 100,0 80,0 100,100 100,100";

$result = overlayWarpedText($imagePath, $text1, $text2, $outputPath, $warpPoints);
echo $result;

echo "done!";

?>