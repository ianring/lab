<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Numino</title>
  <link rel="stylesheet" href="pagination.css" />
  <link rel="stylesheet" href="style.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=East+Sea+Dokdo&display=swap" rel="stylesheet">
</head>
<body>

  <h1><img src="logo.svg" width="350"></h1>
  <div class="instructions"><p>Click to rotate. Drag to move. Sums appear bottom and right. Try to make sums match the top and left.</p></div>

<div id="board"></div>
<br/>
<div id="nav"></div>


<div id="stats"></div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="script.js" ></script>


<audio id="buzzer" preload="auto">
  <source src="https://actions.google.com/sounds/v1/alarms/beep_short.ogg" type="audio/ogg">
  <source src="https://actions.google.com/sounds/v1/alarms/beep_short.mp3" type="audio/mpeg">
  Your browser does not support the audio element.
</audio>

  
</body>

</html>