<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tetromino Puzzle</title>
  <style>
    body {
      font-family: sans-serif;
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    #board {
      display: grid;
      grid-template-columns: repeat(11, 40px);
      grid-template-rows: repeat(11, 40px);
      gap: 1px;
      background-color: #444;
    }

    .cell {
      width: 40px;
      height: 40px;
      background-color: #eee;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: bold;
    }
    .occupied {
      background: #666;
    }

    .sum {
      background-color: #ddd;
      color: #333;
      font-size: 0.9em;
    }

    .tetromino {
      position: absolute;
      width: 160px;
      height: 160px;
    }

    .block {
      position: absolute;
      width: 40px;
      height: 40px;
      background-color: #0af;
      color: #fff;
      font-weight: bold;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 1px solid black;
    }

    .tetromino.dragging {
      transform: scale(1.05);
      opacity: 0.8;
      cursor: grabbing;
      z-index: 9999;
    }

    .tetromino.invalid .block {
      background-color: #f33 !important;
      transition: background-color 0.5s;
    }

    #tetromino-container {
      width: 440px; /* 10 cells * 40px + 9px of 1px gaps */
      height: 180px;
      background-color: #f8f8f8;
      border: 2px dashed #aaa;
      justify-content: space-around;
      align-items: center;
      margin-top: 1rem;
    }

    .tetromino.returning {
      animation: flashRed 0.5s, returnToPool 0.5s ease-in-out forwards;
    }

    @keyframes flashRed {
      0% { filter: brightness(1); background-color: transparent; }
      50% { filter: brightness(2); background-color: red; }
      100% { filter: brightness(1); background-color: transparent; }
    }

    @keyframes returnToPool {
      100% {
        transform: translate(var(--return-x), var(--return-y));
      }
    }

  </style>
</head>
<body>
  <h1>Tetromino Puzzle Game</h1>
  <div id="board"></div>
  <div id="tetromino-container"></div>


  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script>

// any click that lasts longer than a half second is not a click, it's a drag.
const dragDurationThreshold = 500;
// this is the distance in pixels of mousemove with mousedown that we assume is a drag, not a click
const dragDistanceThreshold = 10;

let grid = [];
let pieces = [];


// game is a serialized representation of the starting state of a game
const game = {
  vert: [0,0,1,14,16,16,19,0,0,0],
  horiz: [0,0,22,0,21,3,11,9,0,0],
  pieces: [
    {
      shape: [[1,1,1,1]],
      numbers: [[4,0,9,9]]
    },
    {
      shape: [[1,0],[1,0],[1,1]],
      numbers: [7,7,7,9]
    },
    {
      shape: [[0,1],[1,1],[1,0]],
      numbers: [1,2,8,9]
    }
  ]
};

// this grid is going to contain the 100 cells, and what is currently in each cell. 
// when a piece is dropped or rotated, this is what we update. We update the data
// and then trigger a render of the DOM based on that data
// starting a drag removes blocks from grid cells
// dropping puts blocks back in
// for example, a cell might contain null, or it could contain
// {
//    pieceId: 1,
//    number: 4
// }
function initGrid() {
  for (let x = 0; x < 10; x++) {
    grid[x] = [];
    for (let y = 0; y < 10; y++) {
      grid[x][y] = null;
    }
  }
}

// pieces is the array of pieces that exist. We will store their rotated matrix and the 
// numbers, and its current position on the board or in the tray
// we could technically regenerate the grid from these pieces
pieces.push(createRandomPiece());

function createRandomPiece() {
  return {
    id: 1,
    shape: [[0, 1], [0, 1], [1, 1]],
    numbers: [[null, 4], [null, 7], [9, 2]],
    position: { // if this is null, it means in the tray
      x: 3,
      y: 6
    }
  };
}


// this looks through the pieces and updates the dom board
// it isn't using the grid. Maybe we don't need the grid?
function updateBoard() {
  for(let piece of pieces) {
    console.log(piece);

    piece.shape.forEach((row, y) => {
      row.forEach((val, x) => {
        if (val) { // says there is a 1 in that position
          console.log('y = '+y+', x = '+x);
          console.log(piece.position);
          xcell = piece.position.x + x;
          ycell = piece.position.y + y;
          $cell = $('#cell-'+xcell+'-'+ycell);
          $cell.addClass('occupied');
          const digit = piece.numbers[y][x];
          $cell.text(digit);
          // at some point we'll style the borders so it looks like a piece, ie remove
          // borders between cells that are of the same piece
        }
      });
    });
  }
}


function setCellHandlers() {
  $el = $('.cell');
  $el.on('mousedown touchstart', function(e) {
    e.preventDefault();
    onStartDragCell(e);
  });

  $el.on('dragstart', function() { // Prevent the browser's default drag behavior
    return false;
  });
}

// this is what happens when a cell is clicked (not a long drag)
function onClickCell(e) {
  e.preventDefault();
  console.log(e.target);

  // if cell is empty, return
  // get the piece that occupies this cell
  // do a rotation of the piece, around the given cell  
  // figure out if the rotation would cause an error:
    // collision with another piece
    // out of bounds
  // if error, play a buzzer and do an animation
  // if all is well, change the data and then re-render
}


// do this when a cell is empty
function disableDragCell() {

}

function isThereAPieceThere(x, y) {
  pieces.forEach((piece) => {
    piece.shape.forEach((row, y) => {
      row.forEach((val, x) => {
        if (val) { // says there is a 1 in that position
          // console.log('y = '+y+', x = '+x);
          // console.log(piece.position);
          xcell = piece.position.x + x;
          ycell = piece.position.y + y;

          if (x == xcell && y == ycell) {
            return true;
          }
        }
      });
    });
  });  
  return false;
}

function onStartDragCell(e) {
  $cell = $(e.target);
  coords = $cell.data('coords');
  console.log(coords);

  const occupied = isThereAPieceThere(coords.x, coords.y);
  // see if there is a piece occupying this cell
  if (!occupied) {
    console.log('no piece occupies this cell');
    return;
  }

  console.log('onStartDragCell');
  console.log(e);

  // here is where we generate the draggable piece, which might end up in the tray

    $(document).addClass('dragging-active'); // Add a class to indicate dragging is active
    // Cancel any previous document-level handlers
    $(document).off('.drag');

    // create a tetromino draggable
    $('<div>booyah</div>').appendTo($('#board'));

    let offsetX, offsetY;
    let dragStartTime = 0, startX = 0, startY = 0;
    const dragThreshold = 10;
    let isDragging = false;

    const pageX = e.type.includes('touch') ? e.touches[0].pageX : e.pageX;
    const pageY = e.type.includes('touch') ? e.touches[0].pageY : e.pageY;
    const offset = el.offset();
    offsetX = pageX - offset.left;
    offsetY = pageY - offset.top;
    startX = pageX;
    startY = pageY;
    dragStartTime = Date.now();
    el.data('dragStartPosition', { x: startX, y: startY }); // Store drag start position

    $(document).on('mousemove.drag touchmove.drag', function(e) {
        const currentX = e.type.includes('touch') ? e.touches[0].pageX : e.pageX;
        const currentY = e.type.includes('touch') ? e.touches[0].pageY : e.pageY;
        const dx = currentX - startX;
        const dy = currentY - startY;

        // is this a true drag, or is it just a click
        if (!isDragging && (Math.abs(dx) > dragThreshold || Math.abs(dy) > dragThreshold)) {
            isDragging = true;
            el.addClass('dragging');
            el.css('position', 'absolute');
        }

        if (isDragging) {
            el.css({
                left: currentX - offsetX,
                top: currentY - offsetY
            });
        }
    });

    $(document).on('mouseup.drag touchend.drag', function(e) {
        $(document).removeClass('dragging-active'); // Remove the dragging active class
        $(document).off('.drag');

        const endX = e.type.includes('touch') ? e.changedTouches[0].pageX : e.pageX;
        const endY = e.type.includes('touch') ? e.changedTouches[0].pageY : e.pageY;
        const startPos = el.data('dragStartPosition');
        const travelDistance = Math.hypot(endX - startPos.x, endY - startPos.y);
        const dragDuration = Date.now() - dragStartTime;

        if (isDragging || travelDistance > dragThreshold || dragDuration > 500) {
            el.removeClass('dragging');
            snapToGrid(el);
        } else if (!isDragging) {
            // If not considered a drag, treat it as a click for rotation
            rotateTetrominoAtClick(el, endX, endY);
        }

        isDragging = false;
        activeTetromino = null;
    });
}

function onDragPiece() {
  // as piece is dragging, figure out where it would drop
  // highlight the cells where it would land
}

function onDropPiece() {
  // animation effect to make it snap into the cell, 
  // update the data,
  // render the board,
  // fade out and destroy the draggable
}

function renderEmptyBoard() {
  for (let y = 0; y < 11; y++) {
    for (let x = 0; x < 11; x++) {
      const cellid = `cell-${ x }-${ y }`;
      const $cell = $('<div class="cell" id="'+cellid+'"></div>');
      $cell.data('coords', {x, y});
      if (x === 10 || y === 10) {
        $cell.addClass('sum');
      }
      $board.append($cell);
    }
  }
}





/* ----------- */


const tetrominos = [
  { name: 'I', shape: [[1, 1, 1, 1]] },
  { name: 'O', shape: [[1, 1], [1, 1]] },
  { name: 'T', shape: [[0, 1, 0], [1, 1, 1]] },
  { name: 'L', shape: [[1, 0], [1, 0], [1, 1]] },
  { name: 'J', shape: [[0, 1], [0, 1], [1, 1]] },
  { name: 'S', shape: [[0, 1, 1], [1, 1, 0]] },
  { name: 'Z', shape: [[1, 1, 0], [0, 1, 1]] }
];

const $board = $('#board');


function createTetromino() {
  const t = tetrominos[Math.floor(Math.random() * tetrominos.length)];
  const shape = t.shape;
  const div = $('<div class="tetromino"></div>').appendTo('#tetromino-container');
  div.data('shape', shape);

  const blocks = [];
  shape.forEach((row, y) => {
    row.forEach((val, x) => {
      if (val) blocks.push({
        x,
        y,
        digit: Math.floor(Math.random() * 10),
        px: x,
        py: y
      });
    });
  });

  div.data('blocks', blocks);
  drawBlocks(div);
  enableDrag(div);
}

function drawBlocks(el) {
  el.empty();
  const blocks = el.data('blocks');
  blocks.forEach(({ x, y, digit }) => {
    const left = x * 40;
    const top = y * 40;
    el.append(`<div class="block" style="left:${left}px; top:${top}px;">${digit}</div>`);
  });
}

function rotateMatrix(matrix) {
  const N = matrix.length;
  const M = matrix[0].length;
  return Array.from({ length: M }, (_, x) =>
    Array.from({ length: N }, (_, y) => matrix[N - 1 - y][x])
  );
}

let activeTetromino = null;


function enableDrag(el) {
    el.on('mousedown touchstart', function(e) {
        e.preventDefault();
        startDrag(el, e);
    });

    el.on('dragstart', function() { // Prevent the browser's default drag behavior
        return false;
    });
}


function startDragCell(el, e) {

}







function rotateTetrominoAtClick(el, pageX, pageY) {
  const rect = el[0].getBoundingClientRect();
  const clickX = pageX - rect.left;
  const clickY = pageY - rect.top;
  const blockX = Math.floor(clickX / 40);
  const blockY = Math.floor(clickY / 40);

  const shape = el.data('shape');
  const blocks = el.data('blocks');
  const newShape = rotateMatrix(shape);

  const newBlocks = blocks.map(({ x, y, digit }) => {
    const relX = x - blockX;
    const relY = y - blockY;
    const rotX = -relY;
    const rotY = relX;
    return {
      x: rotX + blockX,
      y: rotY + blockY,
      digit
    };
  });

  const boardOffset = $board.offset();
  const elOffset = el.offset();
  const cellSize = 41;

  const originX = Math.round((elOffset.left - boardOffset.left) / cellSize);
  const originY = Math.round((elOffset.top - boardOffset.top) / cellSize);

  // Build a set of occupied cells by other tetrominos
  const occupied = new Set();
  $('.tetromino').not(el).each(function () {
    const other = $(this);
    const offset = other.offset();
    const ox = Math.round((offset.left - boardOffset.left) / cellSize);
    const oy = Math.round((offset.top - boardOffset.top) / cellSize);
    const otherBlocks = other.data('blocks');

    otherBlocks.forEach(({ x, y }) => {
      const key = `${ox + x},${oy + y}`;
      occupied.add(key);
    });
  });

  // Check if the new rotated position would collide
  const hasCollision = newBlocks.some(({ x, y }) => {
    const gx = originX + x;
    const gy = originY + y;
    if (gx < 0 || gx >= 10 || gy < 0 || gy >= 10) return true;
    const key = `${gx},${gy}`;
    return occupied.has(key);
  });

  if (hasCollision) {
    // ❌ Flash red and play buzzer
    el.addClass('invalid');
    $('#buzzer')[0].play();
    setTimeout(() => el.removeClass('invalid'), 500);
    return;
  }

  // ✅ Apply rotation
  el.data('shape', newShape);
  el.data('blocks', newBlocks);
  drawBlocks(el);
  computeSums();
}




function startDrag(el, e) {
    $(document).addClass('dragging-active'); // Add a class to indicate dragging is active
    // Cancel any previous document-level handlers
    $(document).off('.drag');

    activeTetromino = el;

    let offsetX, offsetY;
    let dragStartTime = 0, startX = 0, startY = 0;
    const dragThreshold = 10;
    let isDragging = false;

    const pageX = e.type.includes('touch') ? e.touches[0].pageX : e.pageX;
    const pageY = e.type.includes('touch') ? e.touches[0].pageY : e.pageY;
    const offset = el.offset();
    offsetX = pageX - offset.left;
    offsetY = pageY - offset.top;
    startX = pageX;
    startY = pageY;
    dragStartTime = Date.now();
    el.data('dragStartPosition', { x: startX, y: startY }); // Store drag start position

    $(document).on('mousemove.drag touchmove.drag', function(e) {
        const currentX = e.type.includes('touch') ? e.touches[0].pageX : e.pageX;
        const currentY = e.type.includes('touch') ? e.touches[0].pageY : e.pageY;
        const dx = currentX - startX;
        const dy = currentY - startY;

        if (!isDragging && (Math.abs(dx) > dragThreshold || Math.abs(dy) > dragThreshold)) {
            isDragging = true;
            el.addClass('dragging');
            el.css('position', 'absolute');
        }

        if (isDragging) {
            el.css({
                left: currentX - offsetX,
                top: currentY - offsetY
            });
        }
    });

    $(document).on('mouseup.drag touchend.drag', function(e) {
        $(document).removeClass('dragging-active'); // Remove the dragging active class
        $(document).off('.drag');

        const endX = e.type.includes('touch') ? e.changedTouches[0].pageX : e.pageX;
        const endY = e.type.includes('touch') ? e.changedTouches[0].pageY : e.pageY;
        const startPos = el.data('dragStartPosition');
        const travelDistance = Math.hypot(endX - startPos.x, endY - startPos.y);
        const dragDuration = Date.now() - dragStartTime;

        if (isDragging || travelDistance > dragThreshold || dragDuration > 500) {
            el.removeClass('dragging');
            snapToGrid(el);
        } else if (!isDragging) {
            // If not considered a drag, treat it as a click for rotation
            rotateTetrominoAtClick(el, endX, endY);
        }

        isDragging = false;
        activeTetromino = null;
    });
}



function rotateTetromino(el, pageX, pageY) {
  const rect = el[0].getBoundingClientRect();
  const clickX = pageX - rect.left;
  const clickY = pageY - rect.top;
  const blockX = Math.floor(clickX / 40);
  const blockY = Math.floor(clickY / 40);

  const shape = el.data('shape');
  const blocks = el.data('blocks');
  const newShape = rotateMatrix(shape);

  const newBlocks = blocks.map(({ x, y, digit }) => {
    const relX = x - blockX;
    const relY = y - blockY;
    return {
      x: -relY + blockX,
      y: relX + blockY,
      digit
    };
  });

  el.data('shape', newShape);
  el.data('blocks', newBlocks);
  drawBlocks(el);
  computeSums();
}

function snapToGrid(el) {
    const boardOffset = $board.offset();
    const cellSize = 41;
    const x = Math.round((el.offset().left - boardOffset.left) / cellSize);
    const y = Math.round((el.offset().top - boardOffset.top) / cellSize);

    const blocks = el.data('blocks');
    const hasCollision = blocks.some(({ px, py }) => { // Use block-local coordinates
        const gridX = x + px;
        const gridY = y + py;
        if (gridX < 0 || gridX >= 10 || gridY < 0 || gridY >= 10) return false; // Out of bounds is not a collision with another piece

        return $('.tetromino').not(el).toArray().some(otherTetromino => {
            const otherBlocks = $(otherTetromino).data('blocks');
            const otherOffset = $(otherTetromino).offset();
            const otherGridX = Math.round((otherOffset.left - boardOffset.left) / cellSize);
            const otherGridY = Math.round((otherOffset.top - boardOffset.top) / cellSize);
            return otherBlocks.some(({ px: ox, py: oy }) => gridX === otherGridX + ox && gridY === otherGridY + oy);
        });
    });

    if (hasCollision) {
        // Animate back to the original position
        const originalOffset = el.data('originalPosition');
        el.addClass('returning');
        el.css('--return-x', originalOffset.left - el.offset().left + 'px');
        el.css('--return-y', originalOffset.top - el.offset().top + 'px');
        setTimeout(() => {
            el.removeClass('returning');
            el.css({
                left: originalOffset.left,
                top: originalOffset.top,
                '--return-x': '0',
                '--return-y': '0'
            });
        }, 500);
    } else {
        el.css({
            left: boardOffset.left + x * cellSize,
            top: boardOffset.top + y * cellSize
        });
        // here, update the grid
        // hide the draggable element?
        computeSums();
    }
}



function computeSums() {
  const cellMap = Array.from({ length: 10 }, () => Array(10).fill(0));

  $('.tetromino').each(function () {
    const el = $(this);
    const blocks = el.data('blocks');
    const offset = el.offset();
    const boardOffset = $board.offset();

    const x0 = Math.round((offset.left - boardOffset.left) / 41);
    const y0 = Math.round((offset.top - boardOffset.top) / 41);

    blocks.forEach(({ x, y, digit }) => {
      const cx = x0 + x;
      const cy = y0 + y;
      if (cx >= 0 && cx < 10 && cy >= 0 && cy < 10) {
        cellMap[cy][cx] += digit;
      }
    });
  });

  for (let y = 0; y < 10; y++) {
    const rowSum = cellMap[y].reduce((a, b) => a + b, 0);
    $board.children().eq(y * 11 + 10).text(rowSum || '');
  }

  for (let x = 0; x < 10; x++) {
    let colSum = 0;
    for (let y = 0; y < 10; y++) {
      colSum += cellMap[y][x];
    }
    $board.children().eq(10 * 11 + x).text(colSum || '');
  }
}

function initGame() {
    renderEmptyBoard();
    initGrid();

    updateBoard();
    setCellHandlers();


    createTetromino();
    // createTetromino();
    // createTetromino();
}

initGame();

  </script>


<audio id="buzzer" preload="auto">
  <source src="https://actions.google.com/sounds/v1/alarms/beep_short.ogg" type="audio/ogg">
  <source src="https://actions.google.com/sounds/v1/alarms/beep_short.mp3" type="audio/mpeg">
  Your browser does not support the audio element.
</audio>

</body>
</html>