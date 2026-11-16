<?php
/**
 * Gaming Calls Platform - Leave Room API
 * POST /api/rooms/leave.php
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use GamingCalls\Config\Config;
use GamingCalls\Session\SessionManager;
use GamingCalls\Models\Participant;
use GamingCalls\Database\Database;

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

$roomId = SessionManager::getCurrentRoomId();
$sessionId = SessionManager::getId();

if (!$roomId || !$sessionId) {
    echo json_encode(['success' => true, 'message' => 'Você não está em uma sala']);
    exit;
}

try {
    $participant = Participant::findByRoomAndSession($roomId, $sessionId);
    if ($participant && $participant->isActive) {
        $participant->leave();
    }

    SessionManager::clearCurrentRoom();

    echo json_encode(['success' => true, 'message' => 'Você saiu da sala']);
} catch (\Throwable $e) {
    error_log('Leave room error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Não foi possível sair da sala']);
}