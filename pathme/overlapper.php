<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$a1 = $_POST['a1'];
$b1 = $_POST['b1'];
$a2 = $_POST['a2'];
$b2 = $_POST['b2'];

if (!file_exists('overlaps.json')) {
	$o = [];
} else {
	$o = file_get_contents('overlaps.json');
	$o = json_decode($o, true);
}

if (!is_array($o)) {
	$o = [];
}

if (!array_key_exists($a1, $o)) {
	$o[$a1] = [];
}
if (!array_key_exists($b1, $o[$a1])) {
	$o[$a1][$b1] = [];
}
if (!array_key_exists($a2, $o[$a1][$b1])) {
	$o[$a1][$b1][$a2] = [];
}

if (!in_array($b2, $o[$a1][$b1][$a2])) {
	// add it
	$o[$a1][$b1][$a2][] = $b2;
	echo "added";
} else {
	// remove it
	$o[$a1][$b1][$a2] = removeByValue($o[$a1][$b1][$a2], $b2);
	echo "removed";
}

$o = file_put_contents('overlaps.json', json_encode($o));


function removeByValue($array, $value) {
    return array_filter($array, function($item) use ($value) {
        return $item !== $value;
    });
}