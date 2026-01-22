<style>
	.board td {
		padding:0;
		madgin:0;
	}
</style>
<?php
define('CANVAS_SIZE', 120); // leave this alone
define('SPOKE_WIDTH', 3);
define('TILE_SIZE', 60);

define('BG', '#333333');
define('BORDER', '#666666');
define('TRACK', '#EEEEEE');
define('TIE', '#CCCCCC');
define('BETWEEN', '#555555');
define('HANGAR_WIDTH', 90);
define('HANGAR_WALL_WIDTH', 3);
define('HANGAR_WALLS', TRACK);


echo '<table class="board">';
echo '<tr>';
echo '<td>'.renderTerminal('start', ['R'], ['E']).'</td>';
echo '<td>'.renderTrack('horiz').'</td>';
echo '<td>'.renderTrack('horiz').'</td>';
echo '<td>'.renderTrack('horiz').'</td>';
echo '<td>'.renderTrack('BL').'</td>';
echo '</tr>';

echo '<tr>';
echo '<td>'.renderTrack('BR').'</td>';
echo '<td>'.renderTrack('horiz').'</td>';
echo '<td>'.renderTrack('horiz').'</td>';
echo '<td>'.renderTrack('horiz').'</td>';
echo '<td>'.renderTrack('TL').'</td>';
echo '</tr>';

echo '<tr>';
echo '<td>'.renderTrack('TR').'</td>';
echo '<td>'.renderTrack('horiz').'</td>';
echo '<td>'.renderTrack('horiz').'</td>';
echo '<td>'.renderTrack('horiz').'</td>';
echo '<td>'.renderTerminal('start', ['R'], ['W']).'</td>';
echo '</tr>';


echo '</table>';

echo '<br/><br/><br/>';

echo renderTrack('horiz');
echo renderTrack('vert');
echo renderTrack('vert-horiz');
echo renderTrack('horiz-vert');

echo renderTrack('TL');
echo renderTrack('TR');
echo renderTrack('BL');
echo renderTrack('BR');

echo renderTrack('TL-BL');
echo renderTrack('BL-TL');

echo renderTrack('TL-TR');
echo renderTrack('TR-TL');

echo renderTrack('TR-BR');
echo renderTrack('BR-TR');

echo renderTrack('BR-BL');
echo renderTrack('BL-BR');

echo renderTrack('vert-TL');
echo renderTrack('TL-vert');
echo renderTrack('horiz-TL');
echo renderTrack('TL-horiz');

echo renderTrack('vert-TR');
echo renderTrack('TR-vert');
echo renderTrack('horiz-TR');
echo renderTrack('TR-horiz');

echo renderTrack('vert-BR');
echo renderTrack('BR-vert');
echo renderTrack('horiz-BR');
echo renderTrack('BR-horiz');

echo renderTrack('vert-BL');
echo renderTrack('BL-vert');
echo renderTrack('horiz-BL');
echo renderTrack('BL-horiz');

echo renderTerminal('start', ['R'], ['W']);
echo renderTerminal('start', ['R','Y'], ['W']);
echo renderTerminal('start', ['R','Y','B'], ['W']);
echo renderTerminal('start', ['R','G','O','V'], ['N','E','S','W']);



function renderTerminal($end, $trains = [], $openings = []) {
	$s = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>';
	$s .= '<svg width="'.TILE_SIZE.'" height="'.TILE_SIZE.'" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">';
	$s .= '<rect x="0" y="0" width="120" height="120" fill="'.BG.'"></rect>';
    $s .= borders();
    $s .= hangar($trains);
    $s .= entrance($openings);
    $s .= '</svg>';
	return $s;	
}

function entrance($openings) {
	$s = '';

	if (in_array('W', $openings)) {
		$w = ((CANVAS_SIZE - HANGAR_WIDTH) / 2);
		$s .= '<rect x="0" y="40" width="'.$w.'" height="4" fill="'.TRACK.'"></rect>';
		$s .= '<rect x="0" y="44" width="'.($w + HANGAR_WALL_WIDTH + 1).'" height="32" fill="'.BETWEEN.'"></rect>';
		$s .= '<rect x="0" y="76" width="'.$w.'" height="4" fill="'.TRACK.'"></rect>';
		$s .= '<rect x="0" y="44" width="'.$w.'" height="5" fill="url(#grad-south)"></rect>';
		$s .= '<rect x="0" y="71" width="'.$w.'" height="5" fill="url(#grad-north)"></rect>';
		$s .= '<rect x="0" y="80" width="'.$w.'" height="10" fill="url(#grad-south)"></rect>';
		$s .= '<rect x="0" y="30" width="'.$w.'" height="10" fill="url(#grad-north)"></rect>';
		$s .= '<polygon points="15 60 5 50 5 70" fill="'.TRACK.'"></polygon>';
	}
	if (in_array('N', $openings)) {
		$w = ((CANVAS_SIZE - HANGAR_WIDTH) / 2);
		$s .= '<rect x="40" y="0" width="4" height="'.$w.'" fill="'.TRACK.'"></rect>';
		$s .= '<rect x="44" y="0" width="32" height="'.($w + HANGAR_WALL_WIDTH + 1).'" fill="'.BETWEEN.'"></rect>';
		$s .= '<rect x="76" y="0" width="4" height="'.$w.'" fill="'.TRACK.'"></rect>';
		$s .= '<rect x="44" y="0" width="5" height="'.$w.'" fill="url(#grad-east)"></rect>';
		$s .= '<rect x="71" y="0" width="5" height="'.$w.'" fill="url(#grad-west)"></rect>';
		$s .= '<rect x="80" y="0" width="10" height="'.$w.'" fill="url(#grad-east)"></rect>';
		$s .= '<rect x="30" y="0" width="10" height="'.$w.'" fill="url(#grad-west)"></rect>';
		$s .= '<polygon points="60 15 50 5 70 5" fill="'.TRACK.'"></polygon>';
	}
	if (in_array('E', $openings)) {
		$w = ((CANVAS_SIZE - HANGAR_WIDTH) / 2);
		$pos = HANGAR_WIDTH + $w;
		$s .= '<rect x="'.$pos.'" y="40" width="'.$w.'" height="4" fill="'.TRACK.'"></rect>';
		$s .= '<rect x="'.($pos - HANGAR_WALL_WIDTH - 1).'" y="44" width="'.($w + HANGAR_WALL_WIDTH + 1).'" height="32" fill="'.BETWEEN.'"></rect>';
		$s .= '<rect x="'.$pos.'" y="76" width="'.$w.'" height="4" fill="'.TRACK.'"></rect>';
		$s .= '<rect x="'.$pos.'" y="44" width="'.$w.'" height="5" fill="url(#grad-south)"></rect>';
		$s .= '<rect x="'.$pos.'" y="71" width="'.$w.'" height="5" fill="url(#grad-north)"></rect>';		
		$s .= '<rect x="'.$pos.'" y="80" width="'.$w.'" height="10" fill="url(#grad-south)"></rect>';
		$s .= '<rect x="'.$pos.'" y="30" width="'.$w.'" height="10" fill="url(#grad-north)"></rect>';
		$s .= '<polygon points="105 60 115 50 115 70" fill="'.TRACK.'"></polygon>';
	}
	if (in_array('S', $openings)) {
		$w = ((CANVAS_SIZE - HANGAR_WIDTH) / 2);
		$pos = HANGAR_WIDTH + $w;
		$s .= '<rect x="40" y="'.$pos.'" width="4" height="'.$w.'" fill="'.TRACK.'"></rect>';
		$s .= '<rect x="44" y="'.($pos - HANGAR_WALL_WIDTH - 1).'" width="32" height="'.($w + HANGAR_WALL_WIDTH + 1).'" fill="'.BETWEEN.'"></rect>';
		$s .= '<rect x="76" y="'.$pos.'" width="4" height="'.$w.'" fill="'.TRACK.'"></rect>';
		$s .= '<rect x="44" y="'.$pos.'" width="5" height="'.$w.'" fill="url(#grad-east)"></rect>';
		$s .= '<rect x="71" y="'.$pos.'" width="5" height="'.$w.'" fill="url(#grad-west)"></rect>';		
		$s .= '<rect x="80" y="'.$pos.'" width="10" height="'.$w.'" fill="url(#grad-east)"></rect>';
		$s .= '<rect x="30" y="'.$pos.'" width="10" height="'.$w.'" fill="url(#grad-west)"></rect>';
		$s .= '<polygon points="60 105 50 115 70 115" fill="'.TRACK.'"></polygon>';
	}

	return $s;
}

function outrance() {
	$s = '';
	$s .= '<polygon points="5 60 15 50 15 70" fill="'.TRACK.'"></polygon>';
	return $s;
}

function hangar($trains) {
	$s = '';
	$pos = ((CANVAS_SIZE - HANGAR_WIDTH) / 2);
	$s .= '<rect width="'.HANGAR_WIDTH.'" height="'.HANGAR_WIDTH.'" x="'.$pos.'" y="'.$pos.'" rx="10" ry="10" fill="' . HANGAR_WALLS . '"></rect>';
	$inner = (HANGAR_WIDTH - (HANGAR_WALL_WIDTH * 2));
	$pos = ((CANVAS_SIZE - HANGAR_WIDTH) / 2) + HANGAR_WALL_WIDTH;
	$s .= '<rect width="'.$inner.'" height="'.$inner.'" x="'.$pos.'" y="'.$pos.'" rx="8" ry="8" fill="' . BETWEEN . '"></rect>';

	$c = [
		'R' => 'red',
		'O' => 'orange',
		'Y' => 'yellow',
		'G' => 'green',
		'B' => 'blue',
		'V' => 'indigo',
	];

	if (count($trains) == 1) {
		$s .= '<circle cx="60" cy="60" r="30" fill="'.$c[$trains[0]].'"></circle>';
	} elseif (count($trains) == 2) {
		$s .= '<circle cx="40" cy="60" r="16" fill="'.$c[$trains[0]].'"></circle>';
		$s .= '<circle cx="80" cy="60" r="16" fill="'.$c[$trains[1]].'"></circle>';
	} elseif (count($trains) == 3) {
		$s .= '<circle cx="40" cy="43" r="16" fill="'.$c[$trains[0]].'"></circle>';
		$s .= '<circle cx="80" cy="43" r="16" fill="'.$c[$trains[1]].'"></circle>';
		$s .= '<circle cx="60" cy="77" r="16" fill="'.$c[$trains[2]].'"></circle>';
	} elseif (count($trains) == 4) {
		$s .= '<circle cx="40" cy="40" r="16" fill="'.$c[$trains[0]].'"></circle>';
		$s .= '<circle cx="80" cy="40" r="16" fill="'.$c[$trains[1]].'"></circle>';
		$s .= '<circle cx="40" cy="80" r="16" fill="'.$c[$trains[2]].'"></circle>';
		$s .= '<circle cx="80" cy="80" r="16" fill="'.$c[$trains[3]].'"></circle>';
	}

	return $s;
}


function renderTrack($type = 'horiz') {	
	$s = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>';
	$s .= '<svg width="'.TILE_SIZE.'" height="'.TILE_SIZE.'" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">';

	$s .= '  <defs>';
	$s .= '    <linearGradient id="grad-east" x1="0%" x2="100%" y1="0%" y2="0%">';
	$s .= '      <stop offset="0%" stop-color="black" stop-opacity="1" />';
	$s .= '      <stop offset="100%" stop-color="black" stop-opacity="0" />';
	$s .= '    </linearGradient>';
	$s .= '    <linearGradient id="grad-south" x1="0%" x2="0%" y1="0%" y2="100%">';
	$s .= '      <stop offset="0%" stop-color="black" stop-opacity="1" />';
	$s .= '      <stop offset="100%" stop-color="black" stop-opacity="0" />';
	$s .= '    </linearGradient>';
	$s .= '    <linearGradient id="grad-west" x1="100%" x2="0%" y1="0%" y2="0%">';
	$s .= '      <stop offset="0%" stop-color="black" stop-opacity="1" />';
	$s .= '      <stop offset="100%" stop-color="black" stop-opacity="0" />';
	$s .= '    </linearGradient>';
	$s .= '    <linearGradient id="grad-north" x1="0%" x2="0%" y1="100%" y2="0%">';
	$s .= '      <stop offset="0%" stop-color="black" stop-opacity="1" />';
	$s .= '      <stop offset="100%" stop-color="black" stop-opacity="0" />';
	$s .= '    </linearGradient>';

	$s .= '	   <radialGradient id="outerCurveOuterBL" cx="0" cy="100%" r="100%">';
	$s .= '      <stop offset="90%" stop-opacity="1" stop-color="black"></stop>';
	$s .= '      <stop offset="100%" stop-opacity="0" stop-color="black"></stop>';
	$s .= '    </radialGradient>';
	$s .= '    <radialGradient id="outerCurveOuterTL" cx="0" cy="0" r="100%">';
	$s .= '      <stop offset="90%" stop-opacity="1" stop-color="black"></stop>';
	$s .= '      <stop offset="100%" stop-opacity="0" stop-color="black"></stop>';
	$s .= '    </radialGradient>';
	$s .= '    <radialGradient id="outerCurveOuterTR" cx="100%" cy="0" r="100%">';
	$s .= '      <stop offset="90%" stop-opacity="1" stop-color="black"></stop>';
	$s .= '      <stop offset="100%" stop-opacity="0" stop-color="black"></stop>';
	$s .= '    </radialGradient>';
	$s .= '    <radialGradient id="outerCurveOuterBR" cx="100%" cy="100%" r="100%">';
	$s .= '      <stop offset="90%" stop-opacity="1" stop-color="black"></stop>';
	$s .= '      <stop offset="100%" stop-opacity="0" stop-color="black"></stop>';
	$s .= '    </radialGradient>';

	$s .= '    <radialGradient id="innerCurveOuterBL" cx="0" cy="100%" r="100%">';
	$s .= '      <stop offset="75%" stop-opacity="0" stop-color="black"></stop>';
	$s .= '      <stop offset="100%" stop-opacity="1" stop-color="black"></stop>';
	$s .= '    </radialGradient>';
	$s .= '    <radialGradient id="innerCurveOuterTL" cx="0" cy="0" r="100%">';
	$s .= '      <stop offset="75%" stop-opacity="0" stop-color="black"></stop>';
	$s .= '      <stop offset="100%" stop-opacity="1" stop-color="black"></stop>';
	$s .= '    </radialGradient>';
	$s .= '    <radialGradient id="innerCurveOuterTR" cx="100%" cy="0" r="100%">';
	$s .= '      <stop offset="75%" stop-opacity="0" stop-color="black"></stop>';
	$s .= '      <stop offset="100%" stop-opacity="1" stop-color="black"></stop>';
	$s .= '    </radialGradient>';
	$s .= '    <radialGradient id="innerCurveOuterBR" cx="100%" cy="100%" r="100%">';
	$s .= '      <stop offset="75%" stop-opacity="0" stop-color="black"></stop>';
	$s .= '      <stop offset="100%" stop-opacity="1" stop-color="black"></stop>';
	$s .= '    </radialGradient>';

	$s .= '	   <radialGradient id="outerCurveInnerBL" cx="0" cy="100%" r="100%">';
	$s .= '      <stop offset="94%" stop-opacity="0" stop-color="black"></stop>';
	$s .= '      <stop offset="100%" stop-opacity="1" stop-color="black"></stop>';
	$s .= '    </radialGradient>';
	$s .= '    <radialGradient id="outerCurveInnerTL" cx="0" cy="0" r="100%">';
	$s .= '      <stop offset="94%" stop-opacity="0" stop-color="black"></stop>';
	$s .= '      <stop offset="100%" stop-opacity="1" stop-color="black"></stop>';
	$s .= '    </radialGradient>';
	$s .= '    <radialGradient id="outerCurveInnerTR" cx="100%" cy="0" r="100%">';
	$s .= '      <stop offset="94%" stop-opacity="0" stop-color="black"></stop>';
	$s .= '      <stop offset="100%" stop-opacity="1" stop-color="black"></stop>';
	$s .= '    </radialGradient>';
	$s .= '    <radialGradient id="outerCurveInnerBR" cx="100%" cy="100%" r="100%">';
	$s .= '      <stop offset="94%" stop-opacity="0" stop-color="black"></stop>';
	$s .= '      <stop offset="100%" stop-opacity="1" stop-color="black"></stop>';
	$s .= '    </radialGradient>';

	$s .= '    <radialGradient id="innerCurveInnerBL" cx="0" cy="100%" r="100%">';
	$s .= '      <stop offset="88%" stop-opacity="1" stop-color="black"></stop>';
	$s .= '      <stop offset="100%" stop-opacity="0" stop-color="black"></stop>';
	$s .= '    </radialGradient>';
	$s .= '    <radialGradient id="innerCurveInnerTL" cx="0" cy="0" r="100%">';
	$s .= '      <stop offset="88%" stop-opacity="1" stop-color="black"></stop>';
	$s .= '      <stop offset="100%" stop-opacity="0" stop-color="black"></stop>';
	$s .= '    </radialGradient>';
	$s .= '    <radialGradient id="innerCurveInnerTR" cx="100%" cy="0" r="100%">';
	$s .= '      <stop offset="88%" stop-opacity="1" stop-color="black"></stop>';
	$s .= '      <stop offset="100%" stop-opacity="0" stop-color="black"></stop>';
	$s .= '    </radialGradient>';
	$s .= '    <radialGradient id="innerCurveInnerBR" cx="100%" cy="100%" r="100%">';
	$s .= '      <stop offset="88%" stop-opacity="1" stop-color="black"></stop>';
	$s .= '      <stop offset="100%" stop-opacity="0" stop-color="black"></stop>';
	$s .= '    </radialGradient>';



	$s .= '  </defs>';

	$s .= '<rect x="0" y="0" width="120" height="120" fill="'.BG.'"></rect>';
    $s .= borders();

    switch($type) {
    	case 'horiz':
    		$s .= horiz();
    		break;
    	case 'vert':
    		$s .= vert();
    		break;
    	case 'vert-horiz':
    		$s .= vert();
    		$s .= horiz();
    		break;
    	case 'horiz-vert':
    		$s .= horiz();
    		$s .= vert();
    		break;
    	case 'TL':
    		$s .= curve('TL');
    		break;
    	case 'TR':
    		$s .= curve('TR');
    		break;
    	case 'BL':
    		$s .= curve('BL');
    		break;
    	case 'BR':
    		$s .= curve('BR');
    		break;

    	case 'TL-BL':
    		$s .= curve('TL');
    		$s .= curve('BL');
    		break;
    	case 'BL-TL':
    		$s .= curve('BL');
    		$s .= curve('TL');
    		break;

    	case 'TL-TR':
    		$s .= curve('TL');
    		$s .= curve('TR');
    		break;
    	case 'TR-TL':
    		$s .= curve('TR');
    		$s .= curve('TL');
    		break;

    	case 'TR-BR':
    		$s .= curve('TR');
    		$s .= curve('BR');
    		break;
    	case 'BR-TR':
    		$s .= curve('BR');
    		$s .= curve('TR');
    		break;

    	case 'BL-BR':
    		$s .= curve('BL');
    		$s .= curve('BR');
    		break;
    	case 'BR-BL':
    		$s .= curve('BR');
    		$s .= curve('BL');
    		break;


    	case 'vert-TL':
    		$s .= vert();
    		$s .= curve('TL');
    		break;
    	case 'TL-vert':
    		$s .= curve('TL');
    		$s .= vert();
    		break;
    	case 'horiz-TL':
    		$s .= horiz();
    		$s .= curve('TL');
    		break;
    	case 'TL-horiz':
    		$s .= curve('TL');
    		$s .= horiz();
    		break;

    	case 'vert-TR':
    		$s .= vert();
    		$s .= curve('TR');
    		break;
    	case 'TR-vert':
    		$s .= curve('TR');
    		$s .= vert();
    		break;
    	case 'horiz-TR':
    		$s .= horiz();
    		$s .= curve('TR');
    		break;
    	case 'TR-horiz':
    		$s .= curve('TR');
    		$s .= horiz();
    		break;

    	case 'vert-BR':
    		$s .= vert();
    		$s .= curve('BR');
    		break;
    	case 'BR-vert':
    		$s .= curve('BR');
    		$s .= vert();
    		break;
    	case 'horiz-BR':
    		$s .= horiz();
    		$s .= curve('BR');
    		break;
    	case 'BR-horiz':
    		$s .= curve('BR');
    		$s .= horiz();
    		break;

    	case 'vert-BL':
    		$s .= vert();
    		$s .= curve('BL');
    		break;
    	case 'BL-vert':
    		$s .= curve('BL');
    		$s .= vert();
    		break;
    	case 'horiz-BL':
    		$s .= horiz();
    		$s .= curve('BL');
    		break;
    	case 'BL-horiz':
    		$s .= curve('BL');
    		$s .= horiz();
    		break;





    	default:
    		$s .= vert();
    		break;
    }
    $s .= '</svg>';
    return $s;
}

function borders() {
	$s = '<rect x="0" y="0" width="120" height="1" fill="'.BORDER.'"></rect>';
	$s .= '<rect x="0" y="0" width="1" height="120" fill="'.BORDER.'"></rect>';
	$s .= '<rect x="120" y="0" width="1" height="120" fill="'.BORDER.'"></rect>';
	$s .= '<rect x="0" y="119" width="120" height="1" fill="'.BORDER.'"></rect>';
	return $s;
}


function horiz() {
	// two tracks
	$s = '<rect x="0" y="40" width="120" height="4" fill="'.TRACK.'"></rect>';
	$s .= '<rect x="0" y="76" width="120" height="4" fill="'.TRACK.'"></rect>';
	// between tracks
	$s .= '<rect x="0" y="44" width="120" height="32" fill="'.BETWEEN.'"></rect>';

	// outer shadows
	$s .= '<rect x="0" y="80" width="120" height="10" fill="url(#grad-south)"></rect>';
	$s .= '<rect x="0" y="30" width="120" height="10" fill="url(#grad-north)"></rect>';
	// ties
	for($i = 0; $i < 10; $i++) {
		$s .= '<rect x="'.(($i * 12) + 6).'" y="44" width="'.SPOKE_WIDTH.'" height="32" fill="'.TIE.'"></rect>';
	}
	// inner shadows
	$s .= '<rect x="0" y="44" width="120" height="5" fill="url(#grad-south)"></rect>';
	$s .= '<rect x="0" y="71" width="120" height="5" fill="url(#grad-north)"></rect>';
	return $s;
}
    
function vert() {
	// two tracks
	$s = '<rect x="40" y="0" width="4" height="120" fill="'.TRACK.'"></rect>';
	$s .= '<rect x="76" y="0" width="4" height="120" fill="'.TRACK.'"></rect>';
	// between tracks
	$s .= '<rect x="44" y="0" width="32" height="120" fill="'.BETWEEN.'"></rect>';

	// outer shadows
	$s .= '<rect x="80" y="0" width="10" height="120" fill="url(#grad-east)"></rect>';
	$s .= '<rect x="30" y="0" width="10" height="120" fill="url(#grad-west)"></rect>';
	// ties
	for($i = 0; $i < 10; $i++) {
		$s .= '<rect y="'.(($i * 12) + 6).'" x="44" width="32" height="'.SPOKE_WIDTH.'" fill="'.TIE.'"></rect>';
	}
	// inner shadows
	$s .= '<rect x="44" y="0" width="5" height="120" fill="url(#grad-east)"></rect>';
	$s .= '<rect x="71" y="0" width="5" height="120" fill="url(#grad-west)"></rect>';
	return $s;
}

function curve($corner) {
	$s = '';
	$s .= drawAnnulusSector(44, 76, $corner, BETWEEN);
	$s .= spokes($corner);
	$s .= drawAnnulusSector(76, 80, $corner);
	$s .= drawAnnulusSector(40, 44, $corner);
	$s .= curveShadows($corner);
	return $s;
}



function curveShadows($corner) {
	$s = '';
	$s .= drawAnnulusShadowOuterCurveOuter($corner);
	$s .= drawAnnulusShadowInnerCurveOuter($corner);
	$s .= drawAnnulusShadowOuterCurveInner($corner);
	$s .= drawAnnulusShadowInnerCurveInner($corner);
	return $s;
}


function drawAnnulusShadowOuterCurveOuter($corner) {
	$color = 'url(#outerCurveOuter'.$corner.')';
	// $color = 'red';
	$s .= drawAnnulusSector(80, 90, $corner, $color);
	return $s;
}

function drawAnnulusShadowInnerCurveOuter($corner) {
	$color = 'url(#innerCurveOuter'.$corner.')';
	// $color = 'yellow';
	$s .= drawAnnulusSector(30, 40, $corner, $color);
	return $s;
}

function drawAnnulusShadowInnerCurveInner($corner) {
	$color = 'url(#innerCurveInner'.$corner.')';
	// $color = 'blue';
	$s .= drawAnnulusSector(49, 44, $corner, $color);
	return $s;
}

function drawAnnulusShadowOuterCurveInner($corner) {
	$color = 'url(#outerCurveInner'.$corner.')';
	// $color = 'green';
	$s .= drawAnnulusSector(71, 76, $corner, $color);
	return $s;
}


function spokes($corner) {

	switch($corner) {
		case 'TL':
		    $centerX = 0;
		    $centerY = 0;
		    $startAngle = 5;
		    $endAngle = 90;
		    break;
		case 'TR':
		    $centerX = 120;
		    $centerY = 0;
		    $startAngle = 95;
		    $endAngle = 180;
		    break;
		case 'BL':
		    $centerX = 0;
		    $centerY = 120;
		    $startAngle = 185;
		    $endAngle = 360;
		    break;
		case 'BR':
		    $centerX = 120;
		    $centerY = 120;
		    $startAngle = 185;
		    $endAngle = 360;
		    break;
		default:
		    $centerX = 0;
		    $centerY = 0;
		    $startAngle = 5;
		    $endAngle = 90;
		    break;
	}

	return drawSpokes(
	    $smallRadius = 40,
	    $largeRadius = 80,
	    $centerX,
	    $centerY,
	    $startAngle,
	    $endAngle,
	    $spokeWidth = SPOKE_WIDTH,
	    $spacingAngle = 9,
	    $fillColor = TIE
	);

}


function drawSpokes(
    $smallRadius,
    $largeRadius,
    $centerX,
    $centerY,
    $startAngle,
    $endAngle,
    $spokeWidth,
    $spacingAngle,
    $fillColor = '#333333'
){
    $spokesSvg = '';
    $degToRad = M_PI / 180;
    
    if ($endAngle < $startAngle) {
        $endAngle += 360; 
    }

    // Determine the equivalent angular width needed for the spacing and iteration
    // We'll use the outer radius to calculate the angular step between spokes.
    $halfWidth = $spokeWidth / 2;
    $halfWidthAngle = rad2deg(asin($halfWidth / $largeRadius));
    $stepAngle = (2 * $halfWidthAngle) + $spacingAngle;

    // --- Loop through the angular range ---
    for ($currentAngle = $startAngle; $currentAngle <= $endAngle; $currentAngle += $stepAngle) {
        
        // --- 1. Define the angular boundaries for the current spoke ---
        // The side edges of the rectangle are perpendicular to the radius at this angle.
        $centerAngle = $currentAngle + $halfWidthAngle; // Center of the spoke's angle
        $perpAngle = $centerAngle + 90; // Angle perpendicular to the center angle (for side shifting)
        
        $angleRad = $centerAngle * $degToRad;
        $perpRad = $perpAngle * $degToRad;

        // --- 2. Calculate the four corner points (P1, P2, P3, P4) ---
        
        // P1 & P4 are on the Inner Arc (radius $smallRadius)
        // P2 & P3 are on the Outer Arc (radius $largeRadius)
        
        // Offset for the parallel side edges
        $offsetX = $halfWidth * cos($perpRad);
        $offsetY = $halfWidth * sin($perpRad);

        // P1: Inner Arc, Left Side
        $P1_x = $centerX + $smallRadius * cos($angleRad) + $offsetX;
        $P1_y = $centerY + $smallRadius * sin($angleRad) + $offsetY;

        // P2: Outer Arc, Left Side
        $P2_x = $centerX + $largeRadius * cos($angleRad) + $offsetX;
        $P2_y = $centerY + $largeRadius * sin($angleRad) + $offsetY;

        // P3: Outer Arc, Right Side (shift in the opposite direction)
        $P3_x = $centerX + $largeRadius * cos($angleRad) - $offsetX;
        $P3_y = $centerY + $largeRadius * sin($angleRad) - $offsetY;

        // P4: Inner Arc, Right Side (shift in the opposite direction)
        $P4_x = $centerX + $smallRadius * cos($angleRad) - $offsetX;
        $P4_y = $centerY + $smallRadius * sin($angleRad) - $offsetY;

        // --- 3. Assemble the polygon string ---
        // Polygon tracing order: P1 -> P2 -> P3 -> P4 -> P1
        $points = sprintf(
            "%f,%f %f,%f %f,%f %f,%f", 
            $P1_x, $P1_y, 
            $P2_x, $P2_y, 
            $P3_x, $P3_y, 
            $P4_x, $P4_y
        );

        $spokesSvg .= sprintf('<polygon points="%s" fill="%s" />', $points, $fillColor);
    }

    return $spokesSvg;
}





function drawAnnulusSector(int $innerRadius, int $outerRadius, string $corner, $color = TRACK) {
    // Access constants directly
    $r_in = $innerRadius;
    $r_out = $outerRadius;

    // Center coordinates and point calculations change based on the corner
    $P1_x = 0; $P1_y = 0; 
    $P2_x = 0; $P2_y = 0; 
    $P3_x = 0; $P3_y = 0; 
    $P4_x = 0; $P4_y = 0;

    // 1. Determine Center and Points
    switch (strtoupper($corner)) {
        case 'BR': // Bottom Right (Center: W, W)
            // BR tracing CW: Bottom -> Right -> Right -> Bottom
            $P1_x = CANVAS_SIZE - $r_out; $P1_y = CANVAS_SIZE;      // Outer Arc Start (Bottom Edge)
            $P2_x = CANVAS_SIZE;          $P2_y = CANVAS_SIZE - $r_out; // Outer Arc End (Right Edge)
            $P3_x = CANVAS_SIZE;          $P3_y = CANVAS_SIZE - $r_in;  // Inner Arc End (Right Edge)
            $P4_x = CANVAS_SIZE - $r_in;  $P4_y = CANVAS_SIZE;      // Inner Arc Start (Bottom Edge)
            break;
            
        case 'TR': // Top Right (Center: W, 0)
            // TR tracing CW: Right -> Top -> Top -> Right
            $P1_x = CANVAS_SIZE;          $P1_y = $r_out;  // Outer Arc Start (Right Edge)
            $P2_x = CANVAS_SIZE - $r_out; $P2_y = 0;      // Outer Arc End (Top Edge)
            $P3_x = CANVAS_SIZE - $r_in;  $P3_y = 0;      // Inner Arc End (Top Edge)
            $P4_x = CANVAS_SIZE;          $P4_y = $r_in;   // Inner Arc Start (Right Edge)
            break;

        case 'TL': // Top Left (Center: 0, 0)
            // TL tracing CW: Top -> Left -> Left -> Top
            $P1_x = $r_out;  $P1_y = 0;      // Outer Arc Start (Top Edge)
            $P2_x = 0;      $P2_y = $r_out;  // Outer Arc End (Left Edge)
            $P3_x = 0;      $P3_y = $r_in;   // Inner Arc End (Left Edge)
            $P4_x = $r_in;   $P4_y = 0;      // Inner Arc Start (Top Edge)
            break;
            
        case 'BL': // Bottom Left (Center: 0, W)
            // BL tracing CW: Left -> Bottom -> Bottom -> Left
            $P1_x = 0;      $P1_y = CANVAS_SIZE - $r_out; // Outer Arc Start (Left Edge)
            $P2_x = $r_out;  $P2_y = CANVAS_SIZE;      // Outer Arc End (Bottom Edge)
            $P3_x = $r_in;   $P3_y = CANVAS_SIZE;      // Inner Arc End (Bottom Edge)
            $P4_x = 0;      $P4_y = CANVAS_SIZE - $r_in;  // Inner Arc Start (Left Edge)
            break;

        default:
            // Use BR logic for default/invalid input
            $P1_x = CANVAS_SIZE - $r_out; $P1_y = CANVAS_SIZE;
            $P2_x = CANVAS_SIZE;          $P2_y = CANVAS_SIZE - $r_out;
            $P3_x = CANVAS_SIZE;          $P3_y = CANVAS_SIZE - $r_in;
            $P4_x = CANVAS_SIZE - $r_in;  $P4_y = CANVAS_SIZE;
            break;
    }

    // 2. Construct the Path 
    
    // M P1: Move to start point of the outer arc
    $d  = "M {$P1_x},{$P1_y} "; 
    
    // A (Outer Arc P1 -> P2): Sweep Flag 1 (Clockwise)
    $d .= "A {$r_out},{$r_out} 0 0 1 {$P2_x},{$P2_y} ";
    
    // L P3: Straight line segment
    $d .= "L {$P3_x},{$P3_y} ";
    
    // A (Inner Arc P3 -> P4): Sweep Flag 0 (Counter-Clockwise)
    $d .= "A {$r_in},{$r_in} 0 0 0 {$P4_x},{$P4_y} ";
    
    // Z: Close the path with a straight line segment
    $d .= "Z";

    return '<path d="' . $d . '" fill="' . $color . '" stroke="none" />';
}


