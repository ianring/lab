// establish some global constants and objects
let pieces = [];
let cellSize = 51; // in pixels. this will be responsive to screen size
const $board = $('#board');
const $tray = $('#tray');
// any click that lasts longer than a half second is not a click, it's a drag.
const dragDurationThreshold = 750;
// this is the distance in pixels of mousemove with mousedown that we assume is a drag, not a click
const dragDistanceThreshold = 10;
let games = [];
let moves = 0;
let currentLevel = 0;
const totalLevels = 9;
let currentSolve = []; // keeps the user's moves
let game = {};

loadGame(0);

window.addEventListener('resize', onResize);

/* --------- */

function loadGame() {
  $.ajax({
    url: "levels.php",
    dataType: 'json',
    method: 'GET',
    data: { 
      level: currentLevel
    }
  }).done(function(data) {
    console.log(data);
    game = data;
    initGame();
  });
}

function initGame() {
  moves = 0;
  updateMoves();
  renderEmptyBoard();
  currentSolve = [];

  pieces = game.pieces;

  renderBoard();
  updateSums();
  setCellHandlers();
  onResize();
  updateStats();
  drawPaginator();
}

// init at the beginning of the game, this makes an empty board of cells in the DOM with
// classes and ids that are useful for manipulating the UI. Each cell also has a data
// attachment with the cell's x and y coordinates.
function renderEmptyBoard() {
  $board.empty();
  $('.gameover').remove();
  $board.append($(`<div class="cell empty"></div>`));
  for (let x = 0; x < 10; x++) {
      const $cell = $(`<div class="cell goal top" id="goal-col-${ x }"></div>`);
      $board.append($cell);
  }  
  $board.append($(`<div class="cell empty"></div>`));

  for (let y = 0; y < 10; y++) {
    $board.append($(`<div class="cell goal left" id="goal-row-${ y }"></div>`));
    for (let x = 0; x < 10; x++) {
      $cell = $(`<div class="cell board" id="cell-${ x }-${ y }"></div>`).data('coords', {x,y});
      $board.append($cell);
    }
    $board.append($(`<div class="cell sum right" id="sum-row-${ y }"></div>`));
  }

  $board.append($(`<div class="cell empty"></div>`));
  for (let x = 0; x < 10; x++) {
      $board.append($(`<div class="cell sum bottom" id="sum-col-${ x }"></div>`));
  }  
  $board.append($(`<div class="cell empty"></div>`));

}


// this looks through the pieces and updates the DOM so the board looks like what the data describes, with
// pieces placed on the board.
// this only covers the state of cells in the board grid. The tray and other elements are rendered elsewhere
function renderBoard() {
  $('.board').removeClass('occupied');
  $('.board').text('');

  for(let piece of pieces) {
    // clear out any existing ones

    $('.cell').removeClass('color-'+piece.colorCode);
    if (piece.position !== null && !piece.isDragging) {
      piece.shape.forEach((block) => {
        // console.log('y = '+y+', x = '+x);
        // console.log(piece.position);
        xcell = piece.position.x + block.x;
        ycell = piece.position.y + block.y;
        $cell = $('#cell-'+xcell+'-'+ycell);
        $cell.addClass('occupied');
        $cell.text(block.number);
        // at some point we'll style the borders so it looks like a piece, ie remove
        // borders between cells that are of the same piece

        $cell.addClass('color-'+piece.colorCode);
      });
    }
  }

  for(let forbid of game.forbidden) {
    xcell = forbid.x;
    ycell = forbid.y;
    $cell = $('#cell-'+xcell+'-'+ycell);
    $cell.addClass('forbidden');
    $cell.html($(`<img src="lock.svg" />`));
  }

  updateSums();
  // future enhancement: show restricted cells

}


// this function adds up the rows and columns, and updates the DOM with those sums.
function updateSums() {
  const {rows, cols} = getSums();

  for(i=0;i<10;i++) {
    if (rows[i] == 0) {
      $('#sum-row-'+i).text('');
    } else {
      $('#sum-row-'+i).text(rows[i]);
    }
    if (cols[i] == 0) {
      $('#sum-col-'+i).text('');
    } else {
      $('#sum-col-'+i).text(cols[i]);
    }
  }

  const checkmark = '<img src="checkmark.svg" />';
  // update the goals too
  for(i=0;i<10;i++) {
    if (game.goal.rows[i] === rows[i]) {
      $(`#goal-row-${ i }`).html(checkmark);
    } else {
      $(`#goal-row-${ i }`).text(game.goal.rows[i]);
    }

    if (game.goal.cols[i] === cols[i]) {
      $(`#goal-col-${ i }`).html(checkmark);
    } else {
      $(`#goal-col-${ i }`).text(game.goal.cols[i]);
    }

  }
  testForWin();
}


function testForWin() {
  // const {rows, cols} = getSums();
  const actual = getSums();
  const goal = game.goal;

  const actualJSON = JSON.stringify(actual);
  const goalJSON = JSON.stringify(goal);
  if (actualJSON == goalJSON) {
    showGameOver();
  } else {
    hideGameOver();
  }
}


function getSums() {
  rows = [0,0,0,0,0,0,0,0,0,0];
  cols = [0,0,0,0,0,0,0,0,0,0];
  pieces.forEach((piece) => {
    piece.shape.forEach((block) => {
      // get the global coords
      posx = block.x + piece.position.x;
      posy = block.y + piece.position.y;
      cols[posx] = cols[posx] + block.number;
      rows[posy] = rows[posy] + block.number;
    });
  });
  return {
    rows, cols
  };
}


function showGameOver() {
    $('.gameover').remove();
    // get position and dimensions of board
    const pos = $('#cell-0-0').offset();
    const left = pos.left;
    const top = pos.top;
    const width = cellSize * 10;
    const height = cellSize * 10;
    $gameover = $(`<div></div>`)
        .addClass('gameover')
        .css({
          left: left,
          top: top,
          width: width,
          height: height,

        })
        .appendTo($('body'));

    $content = $('<div class="gameovercontent"></div>');
    $(`<div><img src="solved.svg" width="40%" /></div>`).appendTo($content);
    $(`<div class="movecount">You finished in ${ moves } ${ moves == 1 ? 'move' : 'moves' }</div>`).appendTo($content);
    $(`<div class="sharetitle"><img src="share.svg" width="40%" /></div>`).appendTo($content);
    $(`<div id="nextbutton"><img id="continue-button" src="continue-button.svg" width="30%" /></div>`)
      .on('mouseover', () => {
        $('#continue-button').attr('src', 'continue-button-hover.svg');
      })
      .on('mouseout', () => {
        $('#continue-button').attr('src', 'continue-button.svg');
      })
      .on('click', () => {
        currentLevel++;
        loadGame(currentLevel);
      })
      .appendTo($content);

    $content.appendTo($gameover);

    registerSolve();
    currentSolve = [];
}

function registerSolve() {
  if (currentSolve.length == 0) {
    return;
  }

  $.ajax({
    url: "registerSolve.php",
    dataType: 'json',
    method: 'POST',
    data: {
      solve: {
        moves: currentSolve,
        seconds: 30,
        datetime: '2025-10-10 10:10:10',
        levelId: game.id,
      }
    }
  });
}


function hideGameOver() {
  $('#gameover').hide();
}


// set up the essential handlers on the board cells, so they detect the start of a click or drag.
function setCellHandlers() {
  $el = $('.board');
  $el.on('mousedown touchstart', function(e) {
    e.preventDefault();
    onCellMouseDown(e);
  });

  $el.on('dragstart', function() { // Prevent the browser's default drag behavior
    return false;
  });
}


// triggered by the mousedown on a cell; it does not yet know if this is the beginning of a drag or
// the start of a click. We figure that out by watching for a mouseup within a time limit of dragDurationThreshold,
// or a movement bigger than the dragDistanceThreshold. 
// We need to be careful here, because pieces in the tray might have overlapping containers
function onCellMouseDown(e) {
  $cell = $(e.target);
  coords = $cell.data('coords');
  if (!coords) {
    // this is likely a forbidden cell
    return;
  }
  // console.log(coords);

  const piece = whichPieceIsThere(coords.x, coords.y);
  // see if there is a piece occupying this cell
  if (piece === null) {
    console.log('no piece occupies this cell');
    return;
  }

  // top left of the board, relative to page
  const boardOrigin = $('#cell-0-0').offset();
  // console.log('boardOrigin');
  // console.log(boardOrigin);

  // top left of the piece, relative to the page
  const pieceOrigin = {
    x: (piece.position.x * cellSize) + boardOrigin.left,
    y: (piece.position.y * cellSize) + boardOrigin.top,
  }
  // console.log('pieceOrigin');
  // console.log(pieceOrigin);

  // coords of the mouse position relative to the page when the mousedown event happened
  const pageX = e.type.includes('touch') ? e.touches[0].pageX : e.pageX;
  const pageY = e.type.includes('touch') ? e.touches[0].pageY : e.pageY;
  const dragStartPosition = {
    x: pageX,
    y: pageY,
  };
  // console.log('dragStartPosition');
  // console.log(dragStartPosition);

  // offset of the mousedown relative to the piece origin point
  // this is what we need to keep the piece positioned relative to the pointer
  const handleOffsetFromPiece = {
    x: (dragStartPosition.x - pieceOrigin.x),
    y: (dragStartPosition.y - pieceOrigin.y),
  }
  // console.log('handleOffsetFromPiece');
  // console.log(handleOffsetFromPiece);

  let offsetX, offsetY;
  let dragStartTime = 0, startX = 0, startY = 0;
  let isDragging = false;

  dragStartTime = Date.now();

  renderBoard();

  // at this point in the code we don't know if this is a drag or a click. But we can still
  // create the draggable thing, and not attach it to the DOM
  const $draggableThing = createDraggableThing(piece);
  $draggableThing.data('dragStartPosition', dragStartPosition); // Store drag start position

  // todo: set a timer, and if the mouseup doesn't happen within a half second, make it a drag?



  $(document).on('mousemove.drag touchmove.drag', function(e) {

    const currentX = e.type.includes('touch') ? e.touches[0].pageX : e.pageX;
    const currentY = e.type.includes('touch') ? e.touches[0].pageY : e.pageY;

    // get the distance of the drag
    const dx = currentX - dragStartPosition.x;
    const dy = currentY - dragStartPosition.y;

    // is this a true drag, or is it just a click
    if (!isDragging && (Math.abs(dx) > dragDistanceThreshold || Math.abs(dy) > dragDistanceThreshold)) {

      $draggableThing.appendTo($('#board'));
      $draggableThing.data('dragStartPosition', dragStartPosition);

      isDragging = true;
      $draggableThing.addClass('dragging');
      $draggableThing.css('position', 'absolute');
    }

    if (isDragging) {
      let cx = currentX - handleOffsetFromPiece.x;
      let cy = currentY - handleOffsetFromPiece.y;
      $draggableThing.css({
        left: cx,
        top: cy
      });
      // extra things to do when dragging: show a droppable preview
      onDragPiece($draggableThing, cx,cy);
    }

  });

  // the event is on the entire document, not on the piece
  $(document).on('mouseup.drag touchend.drag', function(e) {
    $(document).off('.drag');

    const endX = e.type.includes('touch') ? e.changedTouches[0].pageX : e.pageX;
    const endY = e.type.includes('touch') ? e.changedTouches[0].pageY : e.pageY;
    const startPos = $draggableThing.data('dragStartPosition');
    const travelDistance = Math.hypot(endX - startPos.x, endY - startPos.y);
    const dragDuration = Date.now() - dragStartTime;

    if (isDragging || travelDistance > dragDistanceThreshold || dragDuration > dragDurationThreshold) {
      // $draggableThing.removeClass('dragging');
      onDropPiece($draggableThing);
    } else if (!isDragging) {
      // If not considered a drag, treat it as a click for rotation
      // console.log('this was a click!');
      piece.isDragging = false;
      onClickCell(coords.x, coords.y);
    }

    isDragging = false;
  });
}


// merely checks if there is a piece covering a board cell
function isThereAPieceThere(xpos, ypos) {
  return whichPieceIsThere(xpos, ypos) == null;
}


// checks the x and y coordinates of the grid, and tells what piece is there
// if there is no piece in that cell, returns null.
function whichPieceIsThere(xpos, ypos) {
  let o = null;
  pieces.forEach((piece) => {
    // if (piece.position == null || piece.isDragging) {
    //   return null;
    // }
    piece.shape.forEach((block) => {
      xcell = piece.position.x + block.x;
      ycell = piece.position.y + block.y;
      if (xpos == xcell && ypos == ycell) {
        o = piece;
      }
    });
  });  
  return o;
}


// renders the DOM element of a draggable piece with its blocks.
function createDraggableThing(piece) {
  const $div = $('<div class="draggable piece"></div>');
  $div.data('pieceId', piece.id)
  piece.shape.forEach((block) => {
    const left = (block.x * cellSize);
    const top = (block.y * cellSize);
    const width = cellSize;
    const height = cellSize;
    $div.append(`<div class="block noselect" style="left:${left}px; top:${top}px; width:${width}px; height:${height}px;">${block.number}</div>`);
  });
  return $div;
}


// triggered by the movement of a dragged piece. There are other drag things going on
// back in the onCellMouseDown function, but we can encapsulate some things here
function onDragPiece(el, posx, posy) {
}


function onDropPiece($draggableThing) {
  const pieceId = $draggableThing.data('pieceId');
  const piece = findById(pieces, pieceId); // this is the actual data object by reference
  const pieceStartPos = piece.position;

  const boardOffset = $(`#cell-0-0`).offset();
  const draggableOffset = $draggableThing.offset();

  // const gridX = Math.floor((draggableOffset.left - boardOffset.left) / cellSize);
  // const gridY = Math.floor((draggableOffset.top - boardOffset.top) / cellSize);

  const posx = draggableOffset.left;
  const posy = draggableOffset.top;

  // figure out the position it should go to, snapping to the nearest cell
  // note for refactoring: since we are passing in the element, we don't need to also pass in its offset position
  const dropPosition = getClosestDroppablePosition($draggableThing, posx, posy);

  // validate that the position is okay, like not out of bounds or colliding with another piece    
  tempPiece = {
    ...piece,
    position: dropPosition
  };
  if (!isValidPosition(tempPiece)) {
    playBuzzer();
    $draggableThing.remove(); // not cool, we need to put it back
    // todo: animate the piece back to where it was when the drag began, based on the piece data
    renderBoard(); // reset
    return;
  }

  // update the data, meaning we update the value of position in the piece object
  // console.log('finding piece from id '+pieceId);
  // console.log(pieces);
  piece.position = dropPosition;
  piece.isDragging = false;

  currentSolve.push({
    drag: {
      from: pieceStartPos,
      to: dropPosition
    }
  });

  // when the animation is finished, render the board
  renderBoard();
  onAfterMove();

  // then fade out and destroy the draggable, showing the stylized cells below it
  $draggableThing.fadeOut(100, function() {
    this.remove();
  });
  
}


// given the actual mouse pixel position of the draggableThing, get the origin point grid coords
// where the draggableThing will drop
// notable that this doesn't apply any max or min, it can go out of bounds
function getClosestDroppablePosition(el, posx, posy) {
    const boardOffset = $(`#cell-0-0`).offset();
    const x = Math.round((el.offset().left - boardOffset.left) / cellSize);
    const y = Math.round((el.offset().top - boardOffset.top) / cellSize);
    return {x, y};
}


function findById(array, targetId) {
  return array.find(item => item.id === targetId);
}


// todo: checks if a piece is in a valid position on the board. This is part of 
// validating a rotation or a drop. If the piece would overlap any other piece, 
// return false. If the piece would sit out of bounds of the board, that is also
// false. Return true if the piece, with its current position and call matrix, can
// be placed on the board.
function isValidPosition(piece) {
  // console.log(piece);
  if (piece.isDragging) {
    // the piece is being dragged.
    return false;
  }
  if (piece.position == null) {
    // the piece is in the tray
    return false;
  }

  // get the x,y coordinates from piece.position
  const x = piece.position.x;
  const y = piece.position.y;

  // validate that x and y are both on the board for all blocks
  isWithin = true;
  if (x < 0 || y < 0) {
    isWithin = false;
  }
  piece.shape.forEach((block) => {
    if (block.x + x > 9) {
      isWithin = false;
    }
    if (block.y + y > 9) {
      isWithin = false;
    }
  });
  if (!isWithin) { return false; }

  // check for collision with any pieces on the board (other than itself!)
  let isOverlapping = false;
  const pieceX = piece.position.x;
  const pieceY = piece.position.y;

  piece.shape.forEach((block) => {
    const blockX = block.x + pieceX;
    const blockY = block.y + pieceY;

    pieces.forEach((pieceToTest) => {
      if (pieceToTest.id == piece.id) {
        return;
      }
      const pieceToTestX = pieceToTest.position.x;
      const pieceToTestY = pieceToTest.position.y;
      pieceToTest.shape.forEach((blockToTest) => {
        const blockToTestX = blockToTest.x + pieceToTestX;
        const blockToTestY = blockToTest.y + pieceToTestY;
        if (blockX == blockToTestX && blockY == blockToTestY) {
          // console.log('overlap detected');
          // console.log(blockX, blockToTestX, blockY, blockToTestY);
          isOverlapping = true;
        }
      });
    });
  });
  if (isOverlapping) { return false; }

  // check for collision with any forbidden cells
  piece.shape.forEach((block) => {
    const blockX = block.x + pieceX;
    const blockY = block.y + pieceY;

    game.forbidden.forEach((forbiddenToTest) => {
      const forbiddenToTestX = forbiddenToTest.x;
      const forbiddenToTestY = forbiddenToTest.y;

      if (blockX == forbiddenToTestX && blockY == forbiddenToTestY) {
        isOverlapping = true;
      };
    });
  });
  if (isOverlapping) { return false; }

  return true;
}


function onAfterMove() {
  incrementMoves();
  testForWin();
  // todo: move the recalculation stuff to here
}


function incrementMoves() {
  moves++;
  updateMoves();
}


function resetMoves() {
  moves = 0;
  updateMoves()
}


function updateStats() {
  let stats = '<ul>';
  stats += `<li>Moves: ${moves} </li>`;
  stats += `<li>log: ${ formatSolve(currentSolve) }</li>`
  stats += `</ul>`;
  $('#stats').html(stats);
}

function formatSolve() {
  return JSON.stringify(currentSolve);
}

function updateMoves() {
  // $('#stats').text(`Moves: ${moves}`);
  updateStats();
}


function onResize() {
    const height = $('#cell-0-0').height();
    const width = $('#cell-0-0').width();
    cellSize = width;
}


// this is what happens when a cell is clicked (not a long drag)
function onClickCell(x, y) {
  // console.log('click on cell '+x+','+y);

  // get the piece that occupies this cell
  const piece = whichPieceIsThere(x, y);
  // if cell is empty, return null.
  if (!piece) return;
  if (piece.isDragging) {
    return null;
  }

  // do a rotation of the piece, around the given cell  
  tempPiece = rotatePieceAroundCell(piece, x, y);

  // figure out if the rotation would cause an error:
    // collision with another piece
    // out of bounds
  if (!isValidPosition(tempPiece)) {
    // if error, play a buzzer and do an animation
    playBuzzer();
    return;
  }

  // if all is well, change the data and then re-render
  piece.shape = tempPiece.shape;
  piece.position = tempPiece.position;
  renderBoard();

  currentSolve.push({
    click: {x, y}
  })

  onAfterMove();
}


/**
 * Rotates a piece around a clicked cell, treating the clicked cell as the origin (0, 0).
 *
 * @param {object} piece The piece object, containing 'shape' (array of blocks)
 * and optionally other properties.  Each block in 'shape' is expected to have
 * x, y, and number properties.
 * @param {number} clickedX The x-coordinate of the cell to rotate around (in board coordinates).
 * @param {number} clickedY The y-coordinate of the cell to rotate around (in board coordinates).
 * @returns {object} A new piece object with the rotated 'shape'.  The original
 * piece object is not modified.  Returns the original piece if the input
 * is invalid.
 */
function rotatePieceAroundCell(piece, clickedX, clickedY) {
  if (!piece || !piece.shape || !Array.isArray(piece.shape)) {
    return piece; // Return original piece if input is invalid
  }

  // Create a deep copy of the piece to avoid modifying the original
  const rotatedPiece = { ...piece, shape: piece.shape.map(block => ({ ...block })) };

  // translate all the block coordinates into global board coordinates relative to the board's origin
  const translatedShape = rotatedPiece.shape.map(block => ({
    x: block.x + piece.position.x,
    y: block.y + piece.position.y,
    number: block.number,
    colorCode: block.colorCode
  }));  

  // now translate again so the clicked cell is at (0,0)
  const shiftedShape = translatedShape.map(block => ({
    x: block.x - clickedX,
    y: block.y - clickedY,
    number: block.number,
    colorCode: block.colorCode
  }));

  // 2. Rotate the piece 90 degrees clockwise around (0, 0)
  const rotatedShape = shiftedShape.map(block => ({
    x: block.y * -1,
    y: block.x,
    number: block.number,
    colorCode: block.colorCode
  }));

  // 3. Translate the piece back to its original position relative to the board
  restoredShape = rotatedShape.map(block => ({
    x: block.x + clickedX,
    y: block.y + clickedY,
    number: block.number,
    colorCode: block.colorCode
  }));

  // now we have the piece rotated and expressed in terms of global board position.
  // find the top left cell of the piece, that's the new position
  let posx = 999;
  let posy = 999;
  restoredShape.forEach((block) => {
    posx = Math.min(posx, block.x);
    posy = Math.min(posy, block.y);
  })

  // and last, transform the piece so its blocks are relative to that top left origin
  adjustedRestoredShape = restoredShape.map(block => ({
    x: block.x - posx,
    y: block.y - posy,
    number: block.number,
    colorCode: block.colorCode
  }));

  const newRotatedPiece = {
    ... rotatedPiece,
    shape: adjustedRestoredShape,
    position: {
      x: posx,
      y: posy
    }
  }

  return newRotatedPiece;
}


function playBuzzer() {
  $sound = $('#buzzer')[0];
  $sound.pause();
  $sound.currentTime = 0;
  $sound.play();
}


function drawPaginator() {
    const $navContainer = $('#nav');
    $navContainer.empty(); // Clear existing content

    const $nav = $('<ul></ul>').appendTo($navContainer);

    const range = 5;
    let start = Math.max(0, currentLevel - range);
    let end = Math.min(totalLevels - 1, currentLevel + range);

    // Adjust range to always show 11 pages if possible
    while (end - start < range * 2 && start > 0) start--;
    while (end - start < range * 2 && end < totalLevels - 1) end++;

    // "<<" button
    if (start > 0) {
        $('<li>&laquo;</li>')
            .addClass('nav-shift-left')
            .appendTo($nav)
            .click(() => {
                currentLevel = Math.max(0, currentLevel - range);
                drawPaginator();
            });
    }

    // Page buttons
    for (let i = start; i <= end; i++) {
        $('<li></li>')
            .text(i)
            .addClass(i === currentLevel ? 'current' : '')
            .appendTo($nav)
            .click(() => {
              currentLevel = i;
              onClickPaginator();
              drawPaginator();
            });
    }

    // ">>" button
    if (end < totalLevels - 1) {
        $('<li>&raquo;</li>')
            .addClass('nav-shift-right')
            .appendTo($nav)
            .click(() => {
                currentLevel = Math.min(totalLevels - 1, currentLevel + range);
                drawPaginator();
            });
    }
}


function onClickPaginator() {
  // console.log(e);
  loadGame();
}
