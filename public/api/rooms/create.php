<?php
/**
 * Gaming Calls Platform - Create Room API
 * POST /api/rooms/create.php
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use GamingCalls\Config\Config;
use GamingCalls\Session\SessionManager;
use GamingCalls\Models\Room;
use GamingCalls\Database\Database;

// Load configuration
Config::load(__DIR__ . '/../../../config/config.php');
Database::setConfig(Config::get('database'));

// Start session
SessionManager::start();

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Check CSRF token
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if (!SessionManager::validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $input = $_POST;
}

$name = trim($input['name'] ?? '');
$maxParticipants = (int)($input['max_participants'] ?? Config::get('room.max_participants', 10));
$settings = $input['settings'] ?? null;

if (empty($name)) {
    http_response_code(400);
    echo json_encode(['error' => 'Room name is required']);
    exit;
}

if (strlen($name) > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'Room name too long (max 100 characters)']);
    exit;
}

if ($maxParticipants < 2 || $maxParticipants > 10) {
    http_response_code(400);
    echo json_encode(['error' => 'Max participants must be between 2 and 10']);
    exit;
}

// Ensure user has a session ID
$sessionId = SessionManager::getId();
if (!$sessionId) {
    SessionManager::regenerate();
    $sessionId = SessionManager::getId();
}

try {
    $room = Room::create($name, $sessionId, [
        'max_participants' => $maxParticipants,
        'settings' => $settings,
    ]);

    // Store room in session
    SessionManager::setCurrentRoomId($room->id);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'room' => $room->toArray(),
    ]);
} catch (\Throwable $e) {
    error_log('Create room error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create room']);
}