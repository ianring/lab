<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once('pieces.php');

?>
<style>
	.overlap{
		background: #F66;
		fill: #f66;
	}
	.normal {
		background: #666;
		fill: #666;
	}
</style>

<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>

<script>
function addOverlap(a1,b1,a2,b2) {
	
  $.ajax({
    type: "POST",
    url: 'overlapper.php',
    data: {
    	a1,b1,a2,b2
    },
  });	

}

</script>

<?php

$fj = file_get_contents('overlaps.json');
$overlaps = json_decode($fj, true);

$paths = [
	0 => 0,
	1 => 0,
	2 => 0,
	3 => 0,
	4 => 0,
	5 => 0,
	6 => 0,
	7 => 0,
	8 => 0,
	9 => 0,
	10 => 0,
	11 => 0,
	12 => 0,
	13 => 0,
	14 => 0,
	15 => 0,
];

$connections = [
	0 => [0,1,2,3,4,5,6,7,11,12,13,14,15],
	1 => [0,1,2,3,4,5,6,7,10,12,13,14,15],
	2 => [0,1,2,3,4,5,6,7,9,12,13,14,15],
	3 => [0,1,2,3,4,5,6,7,8,12,13,14,15],
	4 => [0,1,2,3,4,5,6,7,8,9,10,11,15],
	5 => [0,1,2,3,4,5,6,7,8,9,10,11,14],
	6 => [0,1,2,3,4,5,6,7,8,9,10,11,13],
	7 => [0,1,2,3,4,5,6,7,8,9,10,11,12],
	8 => [3,4,5,6,7,8,9,10,11,12,13,14,15],
	9 => [2,4,5,6,7,8,9,10,11,12,13,14,15],
	10 => [1,4,5,6,7,8,9,10,11,12,13,14,15],
	11 => [0,4,5,6,7,8,9,10,11,12,13,14,15],
	12 => [0,1,2,3,7,8,9,10,11,12,13,14,15],
	13 => [0,1,2,3,6,8,9,10,11,12,13,14,15],
	14 => [0,1,2,3,5,8,9,10,11,12,13,14,15],
	15 => [0,1,2,3,4,8,9,10,11,12,13,14,15],
];






// by default, all nodes are connected to themselves
$tile = [
	[0,0],
	[1,1],
	[2,2],
	[3,3],
	[4,4],
	[5,5],
	[6,6],
	[7,7],
	[8,8],
	[9,9],
	[10,10],
	[11,11],
	[12,12],
	[13,13],
	[14,14],
	[15,15]
];

$tile = []; // start with an empty tile

// one at a time, try connecting each node with another one, not itself

$nodesAvailable = [0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15];

// check if the two can be connected

// show all the overlaps
$count = 0;
// for($i = 0; $i<16; $i++) {
for($a1 = 0; $a1<16; $a1++) {
	for($b1 = $a1; $b1<16; $b1++) {
		for($a2 = 0; $a2<16; $a2++) {
			for($b2 = $a2; $b2<16; $b2++) {

				$slug1 = $a1 . '-' . $b1;
				$slug2 = $a2 . '-' . $b2;
				$slug = $slug1 . '-' . $slug2;

				if ($slug1 == $slug2) {
					// they're the same thing
					continue;
				}

				if ($a1 == $a2 || $a1 == $b2 || $b1 == $a2 || $b1 == $b2) {
					// the two paths have a shared node
					continue;
				}

				// detect nodes that don't connect
				if (!in_array($b1, $connections[$a1])) {
					continue;
				}
				if (!in_array($b2, $connections[$a2])) {
					continue;
				}

				// if (isOverlap($i, $j, $k, $l)) {
				// 	continue;
				// }

				$piece1 = $pieces[$a1][$b1];
				$piece2 = $pieces[$a2][$b2];

				$tile = [
					[$a1,$b1],
					[$a2,$b2]
				];
				renderTile($tile);

				if ($count > 10000) {
					die();
				}
				$count++;

			}
		}
	}
}

function isOverlap($a1, $b1, $a2, $b2) {
	global $overlaps;

	if ($a1 == $a2 || $a1 == $b2 || $b1 == $a2 || $b1 == $b2) {
		// the two paths have a shared node
		return true;
	}

	$slug1 = $a1 . '-' . $b1;
	$slug2 = $a2 . '-' . $b2;
	$slug = $slug1 . '-' . $slug2;

	$o = $overlaps;

	if (array_key_exists($a1, $o)) {
		if (array_key_exists($b1, $o[$a1])) {
			if (array_key_exists($a2, $o[$a1][$b1])) {
				if (in_array($b2, $o[$a1][$b1][$a2])) {
					return true;
				}
			}
		}

	}
}



die();

for ($i = 0; $i<15; $i++) {
	// pick a random node
	$firstNode = getRandomFromArray($nodesDisconnected);

	// get all the things it can connect to

	// remove any that overlap paths already on the tile

	// if there are any left
		// add it to the tile and remove the two nodes
	// else
		// connect that node with itself, remove it

	// continue until all nodes have been connected to something, or themselves.
}
echo json_encode($tile);

renderTile($tile);


function renderTile($tile) {
	global $pieces;
	$a1 = $tile[0][0];
	$b1 = $tile[0][1];
	$a2 = $tile[1][0];
	$b2 = $tile[1][1];

	if (isOverlap($a1, $b1, $a2, $b2)) {
		$class="overlap";
	} else {
		$class="normal";
	}

	echo '<svg width="100" height="100" viewBox="0 0 60 60">';
	echo '<rect width="60" height="60" class="'.$class.'" stroke="black" stroke-width="0.5" onClick="addOverlap('.$a1.','.$b1.','.$a2.','.$b2.')" />';

	$label = '';
	foreach($tile as $fromto) {
		$from = strval($fromto[0]);
		$to = strval($fromto[1]);
		if ($from <= $to) {
			$piece = $pieces[$from][$to];
		} else {
			$piece = $pieces[$to][$from];			
		}
		echo '<path d="'.$piece.'" fill="white" stroke="black" stroke-width="0.5"/>';

		$label .= '-' . $from . '-' . $to;
	}

	echo '<text x="2" y="12" class="small" font-size="8">'.$label.'</text>';
	echo '</svg>';

}
function removeTileByNumber(array &$tiles, int $number): bool {
    foreach ($tiles as $index => $pair) {
        if (in_array($number, $pair, true)) {
            unset($tiles[$index]);
            // Optional: reindex the array to keep numeric keys sequential
            $tiles = array_values($tiles);
            return true; // success
        }
    }
    return false; // not found
}





$piece = getRandomFromArray($pieces);
$piece = $pieces['15']['15'];



function getRandomFromArray($array) {
    // Check if the array is not empty
    if (empty($array)) {
        return null;  // or handle the empty case as needed
    }
    
    // Get a random index
    $randomIndex = array_rand($array);

    // Return the random value
    return $array[$randomIndex];
}

function popRandomElement(array &$array) {
    if (empty($array)) {
        return null; // or throw an exception if you prefer
    }

    $keys = array_keys($array);
    $randomKey = $keys[array_rand($keys)];
    $element = $array[$randomKey];
    unset($array[$randomKey]);

    return $element;
}

