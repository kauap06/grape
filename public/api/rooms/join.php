<?php
/**
 * Gaming Calls Platform - Join Room API
 * POST /api/rooms/join.php
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use GamingCalls\Config\Config;
use GamingCalls\Session\SessionManager;
use GamingCalls\Models\Room;
use GamingCalls\Models\Participant;
use GamingCalls\Database\Database;
use GamingCalls\Support\Url;

Config::load(__DIR__ . '/../../../config/config.php');
Database::setConfig(Config::get('database'));

SessionManager::start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if (!SessionManager::validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $input = $_POST;
}

$inviteCode = strtoupper(trim($input['invite_code'] ?? ''));
$displayName = trim($input['display_name'] ?? '');
$avatarData = $input['avatar_data'] ?? null;

if (empty($inviteCode)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invite code is required']);
    exit;
}

if (empty($displayName)) {
    http_response_code(400);
    echo json_encode(['error' => 'Display name is required']);
    exit;
}

if (strlen($displayName) > 50) {
    http_response_code(400);
    echo json_encode(['error' => 'Display name too long (max 50 characters)']);
    exit;
}

$room = Room::findByInviteCode($inviteCode);
if (!$room) {
    http_response_code(404);
    echo json_encode(['error' => 'Room not found or inactive']);
    exit;
}

if (!$room->canJoin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Room is full or expired']);
    exit;
}

$sessionId = SessionManager::getId();
if (!$sessionId) {
    SessionManager::regenerate();
    $sessionId = SessionManager::getId();
}

// Store user preferences in session
SessionManager::setDisplayName($displayName);
if ($avatarData) {
    SessionManager::setAvatar($avatarData);
}

try {
    $participant = Participant::create($room->id, $sessionId, $displayName, $avatarData);
    SessionManager::setCurrentRoomId($room->id);

    echo json_encode([
        'success' => true,
        'room' => $room->toArray(),
        'participant' => $participant->toPublicArray(),
        'websocket_url' => Url::websocket((int) Config::get('websocket.port', 8081)), // WebSocket server URL
    ]);
} catch (\Throwable $e) {
    error_log('Join room error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to join room']);
}