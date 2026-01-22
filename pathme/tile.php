<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once('pieces.php');

$fj = file_get_contents('overlaps.json');
$overlaps = json_decode($fj, true);

// print_r($overlaps);

for($i=0;$i<800;$i++) {
	$tile = getTile();
	renderTile($tile);
}



function getTile() {

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
	$nodesAvailable = [0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15];
	$nodesConnected = [];
	$tile = [];

	for ($i = 0; $i<15; $i++) {

		if (count($nodesAvailable) == 0) {
			break;
		}

		// pick a random node
		$firstNode = getRandomFromArray($nodesAvailable);
		// echo '<br><br> random node: '.$firstNode;

		// get all the things it can connect to
		$c = $connections[$firstNode];

		$candidates = $c;

		// remove the connection to self, because that should be a last resort
		removeByValue($candidates, $firstNode);

		// remove any that overlap paths already on the tile
		foreach($c as $candidate) {

			$candpath = canonicalPath($firstNode, $candidate);
			$a1 = $candpath[0];
			$b1 = $candpath[1];
			foreach($tile as $path) {
				$a2 = $path[0];
				$b2 = $path[1];
				// echo '<br/>testing candidate '.$candidate;
				$thisOverlaps = isOverlap($a1, $b1, $a2, $b2);
				if ($thisOverlaps) {
					// echo '<br/>overlap found, node ' . $a1 . '-' . $b1 . ' overlaps ' . $a2 . '-' . $b2;
					// remove candidate
					removeByValue($candidates, $candidate);
					// echo '<br/>'.implode(',',$candidates);
				}
			}
		}

		// print_r($candidates);
		if (count($candidates) > 0) {
			// echo "<br>node $firstNode has ".count($candidates)." candidates";
			$randomCandidate = getRandomFromArray($candidates);

			$canonicalRandomCandidate = canonicalPath($firstNode, $randomCandidate);
			// echo '<br/> adding path '.$canonicalRandomCandidate[0].'-'.$canonicalRandomCandidate[1];
			$tile[] = $canonicalRandomCandidate;
			removeByValue($nodesAvailable, $firstNode);
			removeByValue($nodesAvailable, $randomCandidate);		
		} else {
			$tile[] = canonicalPath($firstNode, $firstNode);
			removeByValue($nodesAvailable, $firstNode);
		}

		// continue until all nodes have been connected to something, or themselves.
	}
	return $tile;
}
// echo '<pre>';
// echo json_encode($tile);



function canonicalPath($a1, $b1) {
	if ($a1 < $b1){
		return [$a1,$b1];
	} else {
		return [$b1,$a1];
	}
}

function removeByValue(array &$array, $value): void {
    foreach ($array as $key => $item) {
        if ($item === $value) {
            unset($array[$key]);
        }
    }
    // Optionally reindex if it's a numerically indexed array:
    $array = array_values($array);
}

function renderTile($tile) {
	global $pieces;
	// $a1 = $tile[0][0];
	// $b1 = $tile[0][1];
	// $a2 = $tile[1][0];
	// $b2 = $tile[1][1];

	echo '<svg width="60" height="60" viewBox="0 0 60 60">';
	echo '<rect width="60" height="60" fill="#333" />';
	$snake = 'lightblue';

	foreach($tile as $fromto) {
		$from = strval($fromto[0]);
		$to = strval($fromto[1]);
		if ($from <= $to) {
			$piece = $pieces[$from][$to];
		} else {
			$piece = $pieces[$to][$from];			
		}
		echo '<path d="'.$piece.'" fill="'.$snake.'" stroke="black" stroke-width="2"/>';

		echo '<rect x="11" y="0" width="8" height="1" fill="'.$snake.'" />';
		echo '<rect x="21" y="0" width="8" height="1" fill="'.$snake.'" />';
		echo '<rect x="31" y="0" width="8" height="1" fill="'.$snake.'" />';
		echo '<rect x="41" y="0" width="8" height="1" fill="'.$snake.'" />';

		echo '<rect x="11" y="59" width="8" height="1" fill="'.$snake.'" />';
		echo '<rect x="21" y="59" width="8" height="1" fill="'.$snake.'" />';
		echo '<rect x="31" y="59" width="8" height="1" fill="'.$snake.'" />';
		echo '<rect x="41" y="59" width="8" height="1" fill="'.$snake.'" />';

		echo '<rect x="59" y="11" width="1" height="8" fill="'.$snake.'" />';
		echo '<rect x="59" y="21" width="1" height="8" fill="'.$snake.'" />';
		echo '<rect x="59" y="31" width="1" height="8" fill="'.$snake.'" />';
		echo '<rect x="59" y="41" width="1" height="8" fill="'.$snake.'" />';

		echo '<rect x="0" y="11" width="1" height="8" fill="'.$snake.'" />';
		echo '<rect x="0" y="21" width="1" height="8" fill="'.$snake.'" />';
		echo '<rect x="0" y="31" width="1" height="8" fill="'.$snake.'" />';
		echo '<rect x="0" y="41" width="1" height="8" fill="'.$snake.'" />';

	}
	echo '</svg>';

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