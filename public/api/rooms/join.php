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
    echo json_encode(['error' => 'Requisição inválida']);
    exit;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if (!SessionManager::validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Sessão expirada. Recarregue a página']);
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
    echo json_encode(['error' => 'Digite o código da sala']);
    exit;
}

if (empty($displayName)) {
    http_response_code(400);
    echo json_encode(['error' => 'Digite seu nome']);
    exit;
}

if (strlen($displayName) > 50) {
    http_response_code(400);
    echo json_encode(['error' => 'O nome deve ter até 50 caracteres']);
    exit;
}

$room = Room::findByInviteCode($inviteCode);
if (!$room) {
    http_response_code(404);
    echo json_encode(['error' => 'Sala não encontrada']);
    exit;
}

$sessionId = SessionManager::getId();
if (!$sessionId) {
    SessionManager::regenerate();
    $sessionId = SessionManager::getId();
}

// O host entra mesmo com a sala trancada ou cheia
$isHost = hash_equals($room->creatorSessionId, (string) $sessionId);
if (!$isHost && !$room->canJoin()) {
    http_response_code($room->isLocked ? 423 : 403);
    echo json_encode([
        'error' => $room->isLocked ? 'A sala está trancada' : 'A sala está cheia',
        'locked' => $room->isLocked,
    ]);
    exit;
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
        'websocket_url' => Url::websocket((int) Config::get('websocket.port', 8182)), // WebSocket server URL
    ]);
} catch (\Throwable $e) {
    error_log('Join room error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Não foi possível entrar na sala']);
}