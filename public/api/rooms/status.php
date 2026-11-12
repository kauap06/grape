<?php
/**
 * Gaming Calls Platform - Room Status API
 * GET /api/rooms/status.php?code=INVITE_CODE
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use GamingCalls\Config\Config;
use GamingCalls\Models\Room;
use GamingCalls\Models\Participant;
use GamingCalls\Database\Database;

Config::load(__DIR__ . '/../../../config/config.php');
Database::setConfig(Config::get('database'));

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$inviteCode = strtoupper(trim($_GET['code'] ?? ''));

if (empty($inviteCode)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invite code is required']);
    exit;
}

$room = Room::findByInviteCode($inviteCode);
if (!$room) {
    http_response_code(404);
    echo json_encode(['error' => 'Room not found']);
    exit;
}

$participants = Participant::findActiveByRoom($room->id);
$participantList = array_map(fn($p) => $p->toPublicArray(), $participants);

echo json_encode([
    'success' => true,
    'room' => $room->toArray(),
    'participants' => $participantList,
    'can_join' => $room->canJoin(),
]);