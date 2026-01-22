<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
header('Content-Type: image/svg+xml');


define('SVGHEIGHT', 1200);
define('SVGWIDTH', 1200);
define('CENTERX', 600);
define('CENTERY', 600);
define('OPACITY', 1);
define('LEFT_FILL', 'white');
define('TOP_FILL', '#eeeeee');
define('RIGHT_FILL', '#dddddd');
define('LEFT_STROKE', 'black');
define('TOP_STROKE', 'black');
define('RIGHT_STROKE', 'black');
define('INNER_RADIUS', 60);
define('RADIUS_GRADIENT', 10);
define('LAYERS', 8);
define('OUTERMOST_LAYER_RADIUS', 30);
define('SHOW_GRID', false);
define('SPOKES', 24);
define('CUBE_DEPTH', 3);
define('MOON_DISTANCE', 400);
define('MOON_RADIUS', 30);
define('MOON_DARK', '#888');
define('MOON_LIGHT', '#FFF');


// --- 2. Main SVG Output ---

echo '<svg xmlns="http://www.w3.org/2000/svg" width="'.SVGWIDTH.'" height="'.SVGHEIGHT.'" viewBox="0 0 '.SVGWIDTH.' '.SVGHEIGHT.'">';

if (SHOW_GRID) {
	for($i = 0; $i < 72; $i++) {
		$p2 = plot(400, $i * 5);
		echo '<line stroke="green" stroke-width="0.5" x1="'.CENTERX.'" y1="'.CENTERY.'" x2="'.$p2[0].'" y2="'.$p2[1].'" />';
	}

	for($i = 0; $i < 50; $i++) {
		$r = $i * 10;
		echo '<circle stroke="green" stroke-width="0.5"  fill="none" cx="'.CENTERX.'" cy="'.CENTERY.'" r="'.$r.'" />';
	}	
}


for($j=0; $j < LAYERS; $j++) {

	$r = OUTERMOST_LAYER_RADIUS - ($j * 3);
	$o = ($j % 2) == 0 ? 3 : -3;

	for($i = 0; $i < SPOKES; $i++) {
		echo getCubePoints($r, ($i * 6), CUBE_DEPTH);
	}
	for($i = 0; $i < (SPOKES / 2); $i++) {
	 	echo getCubePoints(($r - 1.5), ($i * 12) + $o, CUBE_DEPTH);
	}	
}


// draw the moons
$num_days = (int) date('t'); // 't' is the format char for "days in month"
$num_pos = $num_days + 3;
$angle_increment = 360 / $num_pos;

$month = date('m');
$year = date('Y');
for ($day = 1; $day <= $num_days; $day++) {
    $angle = 90 - ($day * $angle_increment) + 180 - ($angle_increment * 1);
    $date = new DateTime("$year-$month-$day", new DateTimeZone("UTC"));
    $phase = getMoonPhase($date);
    $coords = plot(MOON_DISTANCE, $angle);
    echo drawMoonPhase($phase, $coords[0], $coords[1], MOON_RADIUS);
}
// for ($day = 1; $day <= $num_days; $day++) {
//     $angle = 90 - ($day * $angle_increment);    
//     $coords = plot(MOON_DISTANCE, $angle);
//     echo '<text x="'.$coords[0].'" y="'.$coords[1].'">'.$day.'</text>';
// }

// draw the days

echo "</svg>\n";



function getMoonPhase(DateTime $date): float {
    // The synodic period of the moon (in days)
    $synodicPeriod = 29.530588853;

    // A known New Moon epoch (January 6, 2000, 18:14 UTC)
    $knownNewMoon = new DateTime("2000-01-06 18:14:00", new DateTimeZone("UTC"));

    // We set the time to noon (12:00) UTC on the target day.
    // This provides a stable average for the entire day.
    $targetDate = clone $date;
    $targetDate->setTime(12, 0, 0);
    $targetDate->setTimezone(new DateTimeZone("UTC"));

    // Get the difference in seconds between the target date and the known New Moon
    $diffSeconds = $targetDate->getTimestamp() - $knownNewMoon->getTimestamp();
    
    // Convert the difference from seconds to days
    $diffDays = $diffSeconds / (60 * 60 * 24);

    // Calculate the phase
    // We use fmod (floating-point modulus) to get the remainder.
    $phase = fmod($diffDays / $synodicPeriod, 1.0);

    // fmod can return a negative value for dates before the epoch
    if ($phase < 0) {
        $phase += 1;
    }

    return $phase;
}





function drawMoonPhase($percentage, $cx, $cy, $r) {
    error_log($percentage);


    $p = round($percentage, 2);
    $svg = '';

    // 2. Handle Special Cases: New Moon and Full Moon
    if (abs($p - 0.0) < 0.01 || abs($p - 1.0) < 0.01) { // new
        return '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$r.'" fill="'.MOON_DARK.'" stroke="black" stroke-width="1" data-phase="'.$percentage.'" />';
    }
    if (abs($p - 0.5) < 0.01) { // new
        return '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$r.'" fill="'.MOON_LIGHT.'" stroke="black" stroke-width="1" data-phase="'.$percentage.'" />';
    }


    // 1. Draw the base white circle (always present)
    $svg .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$r.'" fill="'.MOON_LIGHT.'" stroke="black" stroke-width="1" data-phase="'.$percentage.'"  />';

    
    // 3. Calculate path for Crescent/Gibbous phases
    
    $phaseAngle = $p * 2 * M_PI;
    $rx = $r * abs(cos($phaseAngle));

    $top_y = $cy - $r;
    $bottom_y = $cy + $r;

    $d = "M $cx,$top_y "; // Move to the top of the moon

    if ($p < 0.5) {
        // 1. Draw the right (lit) edge (a circular arc)
        $d .= "A $r,$r 0 0 1 $cx,$bottom_y ";
        
        // 2. Draw the terminator (an elliptical arc)
        $sweep = ($p < 0.25) ? 1 : 0;
        $d .= "A $rx,$r 0 0 $sweep $cx,$top_y";
        
    } else {
        // WANING (0.5 to 1.0) - Light on the left
        
        // 1. Draw the left (lit) edge (a circular arc)
        $d .= "A $r,$r 0 0 0 $cx,$bottom_y ";
        
        // 2. Draw the terminator (an elliptical arc)
        $sweep = ($p < 0.75) ? 1 : 0;
        $d .= "A $rx,$r 0 0 $sweep $cx,$top_y";
    }

    $d .= " Z"; // Close the path
    
    // Add the final path to the SVG string
    $svg .= '<path d="'.$d.'" fill="'.MOON_DARK.'" stroke="black" stroke-width="1" />';

    return $svg;
}



/**
 * Calculates the point-strings for the 3 faces of a single "cube".
 */
function getCubePoints($radii, $angle, $depth) {
    
    // given the outer center point as grid plots, not coordinates

    // top of cube
    $points = [];
    $points[] = implode(',', gridplot($radii, $angle));
    $points[] = implode(',', gridplot($radii - 1, $angle - 2));
    $points[] = implode(',', gridplot($radii - 2, $angle));
    $points[] = implode(',', gridplot($radii - 1, $angle + 2));
    $top = implode(' ', $points);

    // left of cube
    $points = [];
    $points[] = implode(',', gridplot($radii - 1, $angle - 2));
    $points[] = implode(',', gridplot($radii - 2, $angle));
    $points[] = implode(',', gridplot($radii - ($depth + 1), $angle));
    $points[] = implode(',', gridplot($radii - $depth, $angle - 2));
    $left = implode(' ', $points);

    // top of cube
    $points = [];
    $points[] = implode(',', gridplot($radii - 1, $angle + 2));
    $points[] = implode(',', gridplot($radii - 2, $angle));
    $points[] = implode(',', gridplot($radii - ($depth + 1), $angle));
    $points[] = implode(',', gridplot($radii - $depth, $angle + 2));
    $right = implode(' ', $points);


    return '<polygon stroke="'.TOP_STROKE.'" points="'. $top .'" fill="'.TOP_FILL.'" opacity="'.OPACITY.'" /><polygon stroke="'.TOP_STROKE.'" points="'. $left .'" fill="'.LEFT_FILL.'" opacity="'.OPACITY.'" /><polygon stroke="'.TOP_STROKE.'" points="'. $right .'" fill="'.RIGHT_FILL.'" opacity="'.OPACITY.'" />';
}



function plot($distance, $degrees) {
    $radians = $degrees * M_PI / 180;
    
    $x = CENTERX + (cos($radians) * $distance);
    $y = CENTERY - (sin($radians) * $distance); 
    
    // Return the coordinate pair as a string
    return [$x, $y];
}

function gridplot($r, $a) {
    $radius = INNER_RADIUS + ($r * RADIUS_GRADIENT);

    // each cube takes up 4 grid widths, with 2 between each one
    $gridWidth = 360 / (SPOKES * (4 + 2));

    $angle = $a * $gridWidth; 
    return plot($radius, $angle);
}