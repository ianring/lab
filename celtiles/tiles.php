<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


$tiles = [
	0 => [
		'shapes' => [
			'M 40,0 L 60,20 L 60,40 L 30,10 Z',
			'M 50,30 L 60,40 L 40,60 L 20,60 Z',
			'M 30,50 L 20,60 L 0,40 L 0,20 Z',
			'M 10,30 L 0,20, L 20,0 L 40,0 Z'
		],
		'lines' => [
			'M 40,0 L 60,20',
			'M 60,40 L 40,60',
			'M 20,60 L 0,40',
			'M 0,20 L 20,0',
			'M 30,10 L 60,40',
			'M 50,30 L 20,60',
			'M 30,50 L 0,20',
			'M 10,30 L 40,0'
		]
	],
	1 => [
		'shapes' => [
			'M 0,20 C 0,20 5,5 30,5 C 55,5 60,20 60,20 L 60,40 L 50,30 C 50,30 45,20 30,20 C 15,20 10,30 10,30 Z',
			'M 60,40 L 40,60 L 20,60 L 50,30 Z',
			'M 20,60 L 0,40 L 0,20 L 30,50 Z'
		],
		'lines' => [
			'M 0,20 C 0,20 5,5 30,5 C 55,5 60,20 60,20',
			'M 10,30 C 10,30 15,20 30,20 C 45,20 50,30 50,30 L 60,40',
			'M 20,60 L 50,30 L 60,40 L 40,60',
			'M 0,20 L 30,50 L 20,60 L 0,40'
		]
	],
	2 => [
		'lines' => [
			'M 0,20 C 0,20 10,5 40,5 L 55,5 L 55,20 C 55,50 40,60 40,60',
			'M 10,30 C 10,30 15,20 40,20 C 40,45 30,50 30,50 L 20,60',
			'M 0,20 L 30,50 L 20,60 0,40'
		],
		'shapes' => [
			'M 0,20 C 0,20 10,5 40,5 L 55,5 L 55,20 C 55,50 40,60 40,60 L 20,60 L 30,50 C 30,50 40,45 40,20 C 15,20 10,30 10,30 Z',
			'M 0,20 L 30,50 L 20,60 L 0,40 Z'
		]
	],
	3 => [
		'lines' => [
			'M 20,60 L 30,50 C 35,45 40,40 40,30 C 40,20 35,15 30,10',
			'M 40,60 C 40,60 55,50 55,30 C 55,10 40,0 40,0',
			'M 40,0 L 30,10 C 25,15 20,20 20,30 C 20,40 25,45, 30,50',
			'M 20,0 C 20,0 5,10 5,30 C 5,50 20,60 20,60'
		],
		'shapes' => [
			'M 20,60 L 30,50 C 35,45 40,40 40,30 C 40,20 35,15 30,10 L 40,0 C 40,0 55,10 55,30 C 55,50 40,60 40,60 Z',
			'M 40,0 L 30,10 C 25,15 20,20 20,30 C 20,40 25,45, 30,50 L 20,60 C 20,60 5,50 5,30 C 5,10 20,0 20,0 Z'
		]
	],
	4 => [
		'lines' => [
			'M 20,60 L 30,50 C 30,50 40,45 40,30 C 40,25 35,20 30,20 C 25,20 20,25 20,30 C 20,45 25,45 30,50',
			'M 40,60 C 40,60 55,45 55,30 C 55,15 45,5 30,5 C 15,5 5,15 5,30 C 5,50 20,60 20,60'
		],
		'shapes' => [
			'M 20,60 L 40,60 C 40,60 55,45 55,30 C 55,15 45,5 30,5 C 15,5 5,15 5,30 C 5,50 20,60 20,60
			L 30,50 
			C 25,45 20,45 20,30 C 20,25 25,20 30,20 C 35,20 40,25 40,30 C 40,45 30,50 30,50
			Z'
		]
	],
];

$tiles[5] = getRotatedVersion($tiles[4]);
$tiles[6] = getRotatedVersion($tiles[5]);
$tiles[7] = getRotatedVersion($tiles[6]);

$tiles[8] = getRotatedVersion($tiles[1]);
$tiles[9] = getRotatedVersion($tiles[8]);
$tiles[10] = getRotatedVersion($tiles[9]);

$tiles[11] = getRotatedVersion($tiles[2]);
$tiles[12] = getRotatedVersion($tiles[11]);
$tiles[13] = getRotatedVersion($tiles[12]);


$tiles[14] = ['lines' => [], 'shapes' => []];
$tiles[15] = getRotatedVersion($tiles[3]);

$tilemap = [
	0 => $tiles[14],
	1 => $tiles[6],
	2 => $tiles[5],
	3 => $tiles[12],
	4 => $tiles[4],
	5 => $tiles[3],
	6 => $tiles[11],
	7 => $tiles[8],
	8 => $tiles[7],
	9 => $tiles[13],
	10 => $tiles[15],
	11 => $tiles[9],
	12 => $tiles[2],
	13 => $tiles[10],
	14 => $tiles[1],
	15 => $tiles[0],
];


$sq = 30;

$a = [];
for($x = 0; $x<$sq; $x++) {
	$a[$x] = [];
	for($y = 0; $y<$sq; $y++) {
		$a[$x][$y] = 0;
	}
}


define('TOP', 3);
define('RIGHT', 2);
define('BOTTOM', 1);
define('LEFT', 0);

for($x = 0; $x<$sq; $x++) {
	for($y = 0; $y<$sq; $y++) {

		if ($x < ($sq - 1)) {
			$chance = chance(0.5);
			// $chance = true;
			$a[$x][$y] = setBit($a[$x][$y], RIGHT, $chance);
			$a[$x+1][$y] = setBit($a[$x+1][$y], LEFT, $chance);
		}

		if ($y < ($sq - 1)) {
			$chance = chance(0.5);
			// $chance = true;
			$a[$x][$y] = setBit($a[$x][$y], BOTTOM, $chance);
			$a[$x][$y+1] = setBit($a[$x][$y+1], TOP, $chance);
		}
	}
}




for($x = 0; $x<$sq; $x++) {
	for($y = 0; $y<$sq; $y++) {
		// echo toBinary($a[$x][$y]);
		createTile($tilemap[$a[$x][$y]]);
		// echo ',';
	}
	echo '<br/>';
}


function toBinary($bitmask, $padLength = 4) {
    return str_pad(decbin($bitmask), $padLength, '0', STR_PAD_LEFT);
}

function setBit($bitmask, $position, $value) {
    if ($value) {
        // Set the bit to 1
        return $bitmask | (1 << $position);
    } else {
        // Set the bit to 0
        return $bitmask & ~(1 << $position);
    }
}

function chance($probability) {
    return mt_rand() / mt_getrandmax() < $probability;
}

function getRotatedVersion($tile) {
	$newtile = ['lines' => [], 'shapes' => []];
	foreach($tile['lines'] as $d) {
		$newtile['lines'][] = rotateSvgPath90($d, 60);
	}
	foreach($tile['shapes'] as $d) {
		$newtile['shapes'][] = rotateSvgPath90($d, 60);
	}
	return $newtile;
}

echo '<hr/>';

for($i=0; $i<=15; $i++) {
	createTile($tiles[$i]);
}


function createTile($tile) {
	echo '<svg width="30" height="30" viewBox="0 0 60 60">';
	echo '<rect width="60" height="60" fill="#999" stroke="#666"/>';

	foreach($tile['shapes'] as $path) {
		echo '<path d="'.$path.'" fill="white" />';
	} 

	foreach($tile['lines'] as $path) {
		echo '<path d="'.$path.'" stroke="black" stroke-width="2" fill="none"/>';
	} 

	echo '</svg>';	
}


function rotateSvgPath90($pathD, $size) {
    // Parse the path
    preg_match_all('/([A-Za-z])([^A-Za-z]*)/', $pathD, $matches, PREG_SET_ORDER);

    $newPath = '';

    foreach ($matches as $match) {
        $command = strtoupper($match[1]);
        $params = trim($match[2]);

        if ($command === 'Z') {
            $newPath .= 'Z ';
            continue;
        }

        // Split the parameters into numbers
        $numbers = preg_split('/[\s,]+/', $params, -1, PREG_SPLIT_NO_EMPTY);
        $newNumbers = [];

        for ($i = 0; $i < count($numbers); $i += 2) {
            $x = floatval($numbers[$i]);
            $y = floatval($numbers[$i + 1]);

            // Rotate 90 degrees clockwise around center
            $center = $size / 2;
            $x -= $center;
            $y -= $center;
            $newX = $y;
            $newY = -$x;
            $newX += $center;
            $newY += $center;

            $newNumbers[] = $newX;
            $newNumbers[] = $newY;
        }

        $newPath .= $command . ' ' . implode(' ', $newNumbers) . ' ';
    }

    return trim($newPath);
}