<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tetromino Number Puzzle</title>
    <link href="https://fonts.googleapis.com/css2?family=Press+Start+2P&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body {
            font-family: 'Press Start 2P', monospace;
            background-color: #f0f0f0;
            color: #333;
            margin: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
            overflow-x: hidden;
        }
        #game-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-top: 2rem;
        }
        #board {
            display: grid;
            grid-template-columns: repeat(11, 40px);
            grid-template-rows: repeat(11, 40px);
            border: 4px solid #333;
            background-color: #4a5568;
            margin-bottom: 2rem;
            box-shadow: 5px 5px 10px rgba(0, 0, 0, 0.3);
        }
        .cell {
            width: 40px;
            height: 40px;
            border: 1px solid #718096;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            cursor: pointer;
            user-select: none;
            position: relative;
            color: #fff;
        }
        .sum {
            background-color: #2d3748;
            border-color: #a0aec0;
            font-size: 1rem;
        }
        #tetromino-container {
            position: relative;
            margin-bottom: 2rem;
            /* overflow: visible; */ /* Important! */
            width: 100%; /* Ensure container has width */
        }
        .block {
            width: 40px;
            height: 40px;
            border: 2px solid #2d3748;
            background-color: #f56565;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            cursor: grab;
            user-select: none;
            position: absolute;
            box-shadow: 2px 2px 5px rgba(0, 0, 0, 0.2);
            border-radius: 5px;
            color: #fff;
        }
        .block:active {
            cursor: grabbing;
            box-shadow: 1px 1px 2px rgba(0, 0, 0, 0.2);
        }
        #tray {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 200px; /* Adjust as needed */
            height: 80px; /* Adjust as needed */
            background-color: #a0aec0;
            border: 2px solid #718096;
            border-radius: 0.375rem;
            margin-top: 1rem;
            position: relative; /* Needed for absolute positioning of pieces */
        }
        .tray-full {
            background-color: #f56565;
        }
        .tray-empty {
            background-color: #a0aec0;
        }

    </style>
</head>
<body class="bg-gray-200 p-4">
    <div id="game-container" class="bg-white rounded-lg shadow-md p-6">
        <h1 class="text-2xl font-bold mb-4 text-gray-800 text-center">Tetromino Number Puzzle</h1>
        <div id="board"></div>
        <div id="tetromino-container">
            <div id="tray" class="tray-empty"></div>
        </div>
        <div id="message-box" class="hidden"></div>
    </div>
    <script>
        const board = $('#board');
        const tetrominos = [
            { shape: [[1, 1, 1, 1]] },      // I-shape
            { shape: [[1, 1], [1, 1]] },    // O-shape
            { shape: [[1, 0, 0], [1, 1, 1]] },  // J-shape
            { shape: [[0, 0, 1], [1, 1, 1]] },  // L-shape
            { shape: [[0, 1, 1], [1, 1, 0]] },  // S-shape
            { shape: [[1, 1, 0], [0, 1, 1]] },  // Z-shape
            { shape: [[0, 1, 0], [1, 1, 1]] }   // T-shape
        ];
        const activePieces = [];
        let messageTimeout;
        let currentPiece = null; // Keep track of the current piece
        let isTrayFull = false;

        function showMessage(text, duration = 3000) {
            const messageBox = $('#message-box');
            messageBox.text(text).addClass('show-message');
            clearTimeout(messageTimeout);
            messageTimeout = setTimeout(() => {
                messageBox.removeClass('show-message');
            }, duration);
        }

        function createTetromino() {
            if (isTrayFull) {
                showMessage("The tray is full!", 2000);
                return null; // Don't create a new piece if tray is full
            }

            const t = tetrominos[Math.floor(Math.random() * tetrominos.length)];
            const shape = t.shape;
            const pieceId = `piece-${Date.now()}-${Math.random().toString(36).substring(7)}`;
            const blocks = [];

            shape.forEach((row, gridY) => {
                row.forEach((val, gridX) => {
                    if (val) {
                        const digit = Math.floor(Math.random() * 10);
                        const blockElement = $('<div class="block"></div>')
                            .text(digit)
                            .css({
                                position: 'absolute',
                                left: gridX * 40 + 'px',
                                top: gridY * 40 + 'px'
                            })
                            .appendTo('#tetromino-container');

                        const block = {
                            x: gridX,
                            y: gridY,
                            element: blockElement[0],
                            digit: digit,
                            relX: gridX,
                            relY: gridY
                        };
                        blocks.push(block);
                    }
                });
            });

            const newPiece = {
                id: pieceId,
                blocks: blocks,
                shape: shape,
                xOffset: 0,
                yOffset: 0,
                inTray: true, // Track whether the piece is in the tray
            };
            activePieces.push(newPiece);
            currentPiece = newPiece; // Store the newly created piece
             // Position the piece in the tray
            positionPieceInTray(newPiece);
            isTrayFull = true;
            $('#tray').removeClass('tray-empty').addClass('tray-full');
            showMessage(`Piece created and placed in tray!`, 2000);
            return newPiece;
        }

        function positionPieceInTray(piece) {
            const trayRect = $('#tray')[0].getBoundingClientRect();
            const pieceWidth = piece.shape[0].length * 40; // Rough width, assumes 40px blocks
            const pieceHeight = piece.shape.length * 40;

            // Center the piece in the tray
            const xOffset = (trayRect.width - pieceWidth) / 2;
            const yOffset = (trayRect.height - pieceHeight) / 2;

            piece.blocks.forEach(block => {
                block.element.style.left = xOffset + block.relX * 40 + 'px';
                block.element.style.top = yOffset + block.relY * 40 + 'px';
            });
            piece.xOffset = xOffset / 40;
            piece.yOffset = yOffset / 40;

        }

        let draggingPieceId = null;
        let dragOffsetX, dragOffsetY;
        let dragStartX, dragStartY;

        function startDrag(pieceId, e) {
            e.preventDefault();
            e.stopPropagation();

            const piece = activePieces.find(p => p.id === pieceId);
            if (!piece) return;

            if (piece.inTray) {
                piece.inTray = false; // Remove from tray
                $('#tray').removeClass('tray-full').addClass('tray-empty');
                isTrayFull = false;
            }

            draggingPieceId = pieceId;

            piece.blocks.forEach(block => {
                block.element.style.zIndex = '1000';
                $(block.element).addClass('dragging');
            });

            const pageX = e.type.includes('touch') ? e.touches[0].pageX : e.pageX;
            const pageY = e.type.includes('touch') ? e.touches[0].pageY : e.pageY;
            const containerRect = $('#tetromino-container')[0].getBoundingClientRect();
            // Calculate offset based on the container and the first block's position
            const firstBlockRect = piece.blocks[0].element.getBoundingClientRect();
            dragOffsetX = pageX - (firstBlockRect.left - containerRect.left);
            dragOffsetY = pageY - (firstBlockRect.top - containerRect.top);
            dragStartX = pageX;
            dragStartY = pageY;

            $(document).on('mousemove.dragging', handleDrag);
            $(document).on('mouseup.dragging touchend.dragging', handleDrop);
        }

        function handleDrag(e) {
            if (!draggingPieceId) return;
            const pageX = e.type.includes('touch') ? e.touches[0].pageX : e.pageX;
            const pageY = e.type.includes('touch') ? e.touches[0].pageY : e.pageY;

            const piece = activePieces.find(p => p.id === draggingPieceId);
            if (!piece) return;

            const dx = pageX - dragStartX;
            const dy = pageY - dragStartY;

            piece.blocks.forEach(block => {
                const newLeft = pageX - dragOffsetX;
                const newTop = pageY - dragOffsetY;
                block.element.style.left = newLeft + 'px';
                block.element.style.top = newTop + 'px';
            });
        }

        function handleDrop(e) {
            if (!draggingPieceId) return;
            $(document).off('.dragging');

            const piece = activePieces.find(p => p.id === draggingPieceId);
            if (!piece) return;

            let collision = false;
            const boardRect = board[0].getBoundingClientRect();
            const newBlockPositions = piece.blocks.map(block => {
                const blockRect = block.element.getBoundingClientRect();
                const x = Math.round((blockRect.left - boardRect.left) / 40);
                const y = Math.round((blockRect.top - boardRect.top) / 40);
                return { x, y };
            });

            const occupiedCells = new Set();
            activePieces.forEach(otherPiece => {
                if (otherPiece.id !== draggingPieceId) {
                    otherPiece.blocks.forEach(block => {
                        occupiedCells.add(`${block.x},${block.y}`);
                    });
                }
            });

            collision = newBlockPositions.some(pos => occupiedCells.has(`${pos.x},${pos.y}`) || pos.x < 0 || pos.x >= 10 || pos.y < 0 || pos.y >= 10);

            if (collision) {
                showMessage("Collision detected!  Returning piece to original position.", 2000);
                piece.blocks.forEach(block => {
                    block.element.style.left = (block.x * 40) + 'px';
                    block.element.style.top = (block.y * 40) + 'px';
                    $(block.element).removeClass('dragging');
                });
                 if (piece.inTray)
                 {
                     $('#tray').removeClass('tray-empty').addClass('tray-full');
                 }
            } else {
                showMessage("Piece dropped.", 1000);
                piece.blocks.forEach(block => {
                    block.x = newBlockPositions[piece.blocks.indexOf(block)].x;
                    block.y = newBlockPositions[piece.blocks.indexOf(block)].y;
                    block.element.style.left = block.x * 40 + 'px';
                    block.element.style.top = block.y * 40 + 'px';
                });

                $(piece.blocks[0].element).removeClass('dragging');
                draggingPieceId = null;
            }

            activePieces.forEach(p => p.blocks.forEach(b => b.element.style.zIndex = ''));
            draggingPieceId = null;
        }

        function rotatePiece(pieceId) {
            const piece = activePieces.find(p => p.id === pieceId);
            if (!piece) return;

            const { shape } = piece;
            const N = shape.length;
            const M = shape[0].length;
            const rotatedShape = Array.from({ length: M }, (_, x) =>
                Array.from({ length: N }, (_, y) => shape[N - 1 - y][x])
            );
            piece.shape = rotatedShape;

            const centerX = piece.blocks[0].x;
            const centerY = piece.blocks[0].y;

            const newBlocks = [];
            rotatedShape.forEach((row, y) => {
                row.forEach((val, x) => {
                    if (val)
                    {
                        const relX = x;
                        const relY = y;
                        const newX = -relY;
                        const newY = relX;

                        const block = piece.blocks.find(b => b.relX === relX && b.relY === relY);
                        if (block)
                        {
                            block.x = centerX + newX;
                            block.y = centerY + newY;
                            block.element.style.left = block.x * 40 + 'px';
                            block.element.style.top = block.y * 40 + 'px';
                            newBlocks.push(block);
                        }
                    }
                })
            })
            piece.blocks = newBlocks;
            showMessage(`Piece ${pieceId} rotated!`, 1000);
        }

        function initBoard() {
            for (let y = 0; y < 11; y++) {
                for (let x = 0; x < 11; x++) {
                    const cell = $('<div class="cell"></div>');
                    if (x === 10 || y === 10) cell.addClass('sum');
                    board.append(cell);
                }
            }
        }

        function computeSums() {
            const cellMap = Array.from({ length: 10 }, () => Array(10).fill(0));

            activePieces.forEach(piece => {
                piece.blocks.forEach(block => {
                    if (block.x >= 0 && block.x < 10 && block.y >= 0 && block.y < 10) {
                        cellMap[block.y][block.x] += block.digit;
                    }
                });
            });

            for (let y = 0; y < 10; y++) {
                const rowSum = cellMap[y].reduce((a, b) => a + b, 0);
                board.children().eq(y * 11 + 10).text(rowSum || '');
            }

            for (let x = 0; x < 10; x++) {
                let colSum = 0;
                for (let y = 0; y < 10; y++) {
                    colSum += cellMap[y][x];
                }
                board.children().eq(10 * 11 + x).text(colSum || '');
            }
            showMessage("Sums computed!", 1000);
        }

        // --- Initialization ---
        initBoard();
        const startingPiece = createTetromino();

        // Event Listeners
        $('#tetromino-container').on('click', '.block', (e) => {
            if (currentPiece && currentPiece.inTray) {
                startDrag(currentPiece.id, e);
            }
        });
        $('#compute-sums').on('click', computeSums);
    </script>
</body>
</html>
