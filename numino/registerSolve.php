<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Configuration - customize these variables
$host = 'ianring.com';   // Remote database server
$db   = 'numino';        // Your database name
$user = 'root';        // Your database username
$pass = 'DoNotShareThisWithAnyone';    // Your database password

// Ensure request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Only POST method is allowed.']);
    exit;
}

// Validate input
if (!isset($_POST['solve']['moves']) || !is_array($_POST['solve']['moves'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Missing or invalid "moves" parameter.']);
    exit;
}

$levelId = $_POST['solve']['levelId'];
$moves = json_encode($_POST['solve']['moves']);
$seconds = $_POST['solve']['seconds'];
$datetime = $_POST['solve']['datetime'];

// get this from a session?
$userId = 1;

$mysqli = new mysqli($host, $user, $pass, $db);

// Check connection
if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Connection failed: ' . $mysqli->connect_error]);
    exit;
}

// Set charset
$mysqli->set_charset("utf8mb4");

// Prepare and bind
$stmt = $mysqli->prepare("INSERT INTO solves (levelId, moves, seconds, `datetime`, userId) VALUES (?,?,?,?,?)");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Prepare failed: ' . $mysqli->error]);
    exit;
}

// Bind and execute
$stmt->bind_param("isisi", $levelId, $moves, $seconds, $datetime, $userId);
if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Moves saved.']);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Execute failed: ' . $stmt->error]);
}

// Clean up
$stmt->close();
$mysqli->close();
