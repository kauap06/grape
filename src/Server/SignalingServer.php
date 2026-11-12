<?php
/**
 * Gaming Calls Platform - WebSocket Signaling Server
 * Handles real-time WebRTC signaling between participants
 */

namespace GamingCalls\Server;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;
use GamingCalls\Config\Config;
use GamingCalls\Models\Room;
use GamingCalls\Models\Participant;

class SignalingServer implements MessageComponentInterface
{
    /** @var ConnectionInterface[] */
    protected array $connections = [];
    
    /** @var array<int, ConnectionInterface[]> */
    protected array $roomConnections = [];
    
    /** @var array<string, array{room_id: int, participant_id: int, session_id: string, metadata: array}> */
    protected array $connectionInfo = [];
    
    /** @var array<int, array{last_ping: int, pong_received: bool}> */
    protected array $connectionHealth = [];

    public function __construct(LoopInterface $loop)
    {
        // Start heartbeat checker
        $this->startHeartbeat($loop);
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->connections[$conn->resourceId] = $conn;
        $this->connectionHealth[$conn->resourceId] = [
            'last_ping' => time(),
            'pong_received' => true,
        ];
        
        error_log("New WebSocket connection: {$conn->resourceId} from {$conn->remoteAddress}");
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $resourceId = $from->resourceId;
        
        // Rate limiting check
        if (!$this->checkRateLimit($resourceId)) {
            $this->sendError($from, 'RATE_LIMITED', 'Too many messages');
            return;
        }

        $data = json_decode($msg, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $this->sendError($from, 'INVALID_JSON', 'Invalid message format');
            return;
        }

        // Validate message size
        $maxSize = Config::get('websocket.max_message_size', 1048576);
        if (strlen($msg) > $maxSize) {
            $this->sendError($from, 'MESSAGE_TOO_LARGE', 'Message exceeds maximum size');
            return;
        }

        // Handle message based on type
        $type = $data['type'] ?? '';
        $payload = $data['payload'] ?? [];

        try {
            switch ($type) {
                case 'join':
                    $this->handleJoin($from, $payload);
                    break;
                case 'leave':
                    $this->handleLeave($from, $payload);
                    break;
                case 'offer':
                case 'answer':
                case 'ice-candidate':
                    $this->handleSignaling($from, $type, $payload);
                    break;
                case 'media-state':
                    $this->handleMediaState($from, $payload);
                    break;
                case 'composition':
                    $this->handleComposition($from, $payload);
                    break;
                case 'ping':
                    $this->handlePing($from);
                    break;
                case 'pong':
                    $this->handlePong($resourceId);
                    break;
                case 'get-participants':
                    $this->handleGetParticipants($from, $payload);
                    break;
                case 'chat-message':
                    $this->handleChatMessage($from, $payload);
                    break;
                case 'profile-update':
                    $this->handleProfileUpdate($from, $payload);
                    break;
                default:
                    $this->sendError($from, 'UNKNOWN_TYPE', "Unknown message type: $type");
            }
        } catch (\Throwable $e) {
            error_log("WebSocket error: " . $e->getMessage());
            $this->sendError($from, 'SERVER_ERROR', 'Internal server error');
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $resourceId = $conn->resourceId;
        $this->cleanupConnection($resourceId);
        unset($this->connections[$resourceId], $this->connectionHealth[$resourceId]);
        error_log("WebSocket connection closed: $resourceId");
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        error_log("WebSocket error on connection {$conn->resourceId}: " . $e->getMessage());
        $conn->close();
    }

    protected function handleJoin(ConnectionInterface $conn, array $payload): void
    {
        $roomCode = $payload['room_code'] ?? '';
        $sessionId = $payload['session_id'] ?? '';
        $displayName = $payload['display_name'] ?? 'Anonymous';
        $avatarData = $payload['avatar_data'] ?? null;
        $metadata = $payload['metadata'] ?? [];

        if (empty($roomCode) || empty($sessionId)) {
            $this->sendError($conn, 'INVALID_PAYLOAD', 'Room code and session ID required');
            return;
        }

        $room = Room::findByInviteCode($roomCode);
        if (!$room) {
            $this->sendError($conn, 'ROOM_NOT_FOUND', 'Room not found or inactive');
            return;
        }

        if (!$room->canJoin()) {
            $this->sendError($conn, 'ROOM_FULL', 'Room is full or expired');
            return;
        }

        // Create or update participant
        $participant = Participant::create($room->id, $sessionId, $displayName, $avatarData, $metadata);
        
        // Store connection info
        $this->connectionInfo[$conn->resourceId] = [
            'room_id' => $room->id,
            'participant_id' => $participant->id,
            'session_id' => $sessionId,
            'metadata' => $metadata,
        ];

        // Add to room connections
        if (!isset($this->roomConnections[$room->id])) {
            $this->roomConnections[$room->id] = [];
        }
        $this->roomConnections[$room->id][$conn->resourceId] = $conn;

        // Notify others in room
        $this->broadcastToRoom($room->id, [
            'type' => 'participant-joined',
            'payload' => $participant->toPublicArray(),
        ], $conn->resourceId);

        // Send current participants list to new joiner
        $participants = Participant::findActiveByRoom($room->id);
        $participantList = array_map(fn($p) => $p->toPublicArray(), $participants);

        $this->send($conn, [
            'type' => 'joined',
            'payload' => [
                'room' => $room->toArray(),
                'participant' => $participant->toPublicArray(),
                'participants' => $participantList,
                'webrtc_config' => $this->getWebRTCConfig(),
            ],
        ]);

        error_log("Participant {$participant->id} ({$displayName}) joined room {$room->id}");
    }

    protected function handleLeave(ConnectionInterface $conn, array $payload): void
    {
        $resourceId = $conn->resourceId;
        $this->cleanupConnection($resourceId);
        $this->send($conn, ['type' => 'left', 'payload' => []]);
    }

    protected function handleSignaling(ConnectionInterface $from, string $type, array $payload): void
    {
        $resourceId = $from->resourceId;
        $info = $this->connectionInfo[$resourceId] ?? null;
        
        if (!$info) {
            $this->sendError($from, 'NOT_IN_ROOM', 'Not joined to any room');
            return;
        }

        $targetSessionId = $payload['target_session_id'] ?? null;
        $targetResourceId = $this->findConnectionBySession($info['room_id'], $targetSessionId);

        if (!$targetResourceId) {
            $this->sendError($from, 'TARGET_NOT_FOUND', 'Target participant not found');
            return;
        }

        // Forward signaling message
        $this->send($this->connections[$targetResourceId], [
            'type' => $type,
            'payload' => [
                'from_session_id' => $info['session_id'],
                'from_participant_id' => $info['participant_id'],
                'data' => $payload['data'] ?? null,
            ],
        ]);
    }

    protected function handleMediaState(ConnectionInterface $from, array $payload): void
    {
        $resourceId = $from->resourceId;
        $info = $this->connectionInfo[$resourceId] ?? null;
        
        if (!$info) {
            return;
        }

        // Update participant metadata
        $participant = Participant::findById($info['participant_id']);
        if ($participant) {
            $participant->updateMetadata($payload);
        }

        // Broadcast to room
        $this->broadcastToRoom($info['room_id'], [
            'type' => 'media-state-changed',
            'payload' => [
                'participant_id' => $info['participant_id'],
                'session_id' => $info['session_id'],
                'state' => $payload,
            ],
        ], $resourceId);
    }

    protected function handleComposition(ConnectionInterface $from, array $payload): void
    {
        $resourceId = $from->resourceId;
        $info = $this->connectionInfo[$resourceId] ?? null;
        
        if (!$info) {
            return;
        }

        // Broadcast composition update to room
        $this->broadcastToRoom($info['room_id'], [
            'type' => 'composition-changed',
            'payload' => [
                'participant_id' => $info['participant_id'],
                'session_id' => $info['session_id'],
                'composition' => $payload,
            ],
        ], $resourceId);
    }

    protected function handleGetParticipants(ConnectionInterface $from, array $payload): void
    {
        $resourceId = $from->resourceId;
        $info = $this->connectionInfo[$resourceId] ?? null;
        
        if (!$info) {
            $this->sendError($from, 'NOT_IN_ROOM', 'Not joined to any room');
            return;
        }

        $participants = Participant::findActiveByRoom($info['room_id']);
        $participantList = array_map(fn($p) => $p->toPublicArray(), $participants);

        $this->send($from, [
            'type' => 'participants-list',
            'payload' => ['participants' => $participantList],
        ]);
    }

    protected function handleChatMessage(ConnectionInterface $from, array $payload): void
    {
        $resourceId = $from->resourceId;
        $info = $this->connectionInfo[$resourceId] ?? null;

        if (!$info) {
            $this->sendError($from, 'NOT_IN_ROOM', 'Not joined to any room');
            return;
        }

        $message = trim($payload['message'] ?? '');
        if ($message === '') {
            $this->sendError($from, 'EMPTY_MESSAGE', 'Message cannot be empty');
            return;
        }

        $maxLength = (int) Config::get('chat.max_length', 500);
        if (mb_strlen($message) > $maxLength) {
            $this->sendError($from, 'MESSAGE_TOO_LONG', "Message exceeds {$maxLength} characters");
            return;
        }

        // Resolve sender display info from the participant record
        $displayName = '';
        $avatarData = null;
        $participant = Participant::findById($info['participant_id']);
        if ($participant) {
            $public = $participant->toPublicArray();
            $displayName = $public['display_name'] ?? '';
            $avatarData = $public['avatar_data'] ?? null;
        }

        // Distribute to all other participants in the same room only.
        // The sender renders its own message optimistically, so we exclude it
        // to avoid duplicates and self-notifications.
        $this->broadcastToRoom($info['room_id'], [
            'type' => 'chat-message',
            'payload' => [
                'from_session_id' => $info['session_id'],
                'from_participant_id' => $info['participant_id'],
                'display_name' => $displayName,
                'avatar_data' => $avatarData,
                'message' => htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'timestamp' => time(),
            ],
        ], $resourceId);

        error_log("Chat message from participant {$info['participant_id']} in room {$info['room_id']}");
    }

    protected function handleProfileUpdate(ConnectionInterface $from, array $payload): void
    {
        $resourceId = $from->resourceId;
        $info = $this->connectionInfo[$resourceId] ?? null;

        if (!$info) {
            $this->sendError($from, 'NOT_IN_ROOM', 'Not joined to any room');
            return;
        }

        $displayName = trim((string) ($payload['display_name'] ?? ''));
        if ($displayName === '' || mb_strlen($displayName) > 50) {
            $this->sendError($from, 'INVALID_NAME', 'Invalid display name');
            return;
        }

        // Avatar: foto (data URL de imagem) ou cor ({"type":"gradient","color":"#rrggbb"})
        $avatarData = $payload['avatar_data'] ?? null;
        if (is_string($avatarData) && $avatarData !== '') {
            $isImage = preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=]+$#', $avatarData) === 1;
            $decoded = $isImage ? null : json_decode($avatarData, true);
            $isColor = is_array($decoded)
                && ($decoded['type'] ?? '') === 'gradient'
                && preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($decoded['color'] ?? '')) === 1;
            if (!$isImage && !$isColor) {
                $avatarData = null;
            }
        } else {
            $avatarData = null;
        }

        $participant = Participant::findById($info['participant_id']);
        if ($participant) {
            $participant->update(['display_name' => $displayName, 'avatar_data' => $avatarData]);
        }

        $this->broadcastToRoom($info['room_id'], [
            'type' => 'profile-updated',
            'payload' => [
                'participant_id' => $info['participant_id'],
                'session_id' => $info['session_id'],
                'display_name' => $displayName,
                'avatar_data' => $avatarData,
            ],
        ], $resourceId);
    }

    protected function handlePing(ConnectionInterface $from): void
    {
        $this->send($from, ['type' => 'pong', 'payload' => ['timestamp' => time()]]);
    }

    protected function handlePong(int $resourceId): void
    {
        if (isset($this->connectionHealth[$resourceId])) {
            $this->connectionHealth[$resourceId]['pong_received'] = true;
            $this->connectionHealth[$resourceId]['last_ping'] = time();
        }
    }

    protected function cleanupConnection(int $resourceId): void
    {
        $info = $this->connectionInfo[$resourceId] ?? null;
        if (!$info) {
            return;
        }

        $roomId = $info['room_id'];
        $participantId = $info['participant_id'];
        $sessionId = $info['session_id'];

        // Remove from room connections
        if (isset($this->roomConnections[$roomId][$resourceId])) {
            unset($this->roomConnections[$roomId][$resourceId]);
            if (empty($this->roomConnections[$roomId])) {
                unset($this->roomConnections[$roomId]);
            }
        }

        // Mark participant as left in database
        $participant = Participant::findById($participantId);
        if ($participant && $participant->isActive) {
            $participant->leave();
        }

        // Notify others
        $this->broadcastToRoom($roomId, [
            'type' => 'participant-left',
            'payload' => [
                'participant_id' => $participantId,
                'session_id' => $sessionId,
            ],
        ], $resourceId);

        unset($this->connectionInfo[$resourceId]);
        error_log("Participant $participantId ($sessionId) left room $roomId");
    }

    protected function broadcastToRoom(int $roomId, array $message, int $excludeResourceId = null): void
    {
        if (!isset($this->roomConnections[$roomId])) {
            return;
        }

        $json = json_encode($message);
        foreach ($this->roomConnections[$roomId] as $resourceId => $conn) {
            if ($resourceId !== $excludeResourceId) {
                $this->send($conn, $message);
            }
        }
    }

    protected function findConnectionBySession(int $roomId, ?string $sessionId): ?int
    {
        if (!$sessionId || !isset($this->roomConnections[$roomId])) {
            return null;
        }

        foreach ($this->roomConnections[$roomId] as $resourceId => $conn) {
            $info = $this->connectionInfo[$resourceId] ?? null;
            if ($info && $info['session_id'] === $sessionId) {
                return $resourceId;
            }
        }
        return null;
    }

    protected function send(ConnectionInterface $conn, array $message): void
    {
        $conn->send(json_encode($message));
    }

    protected function sendError(ConnectionInterface $conn, string $code, string $message): void
    {
        $this->send($conn, [
            'type' => 'error',
            'payload' => ['code' => $code, 'message' => $message],
        ]);
    }

    protected function checkRateLimit(int $resourceId): bool
    {
        // Simple in-memory rate limiting
        // In production, use Redis or similar
        return true;
    }

    protected function getWebRTCConfig(): array
    {
        return [
            'iceServers' => array_merge(
                Config::get('webrtc.stun_servers', []),
                Config::get('webrtc.turn_servers', [])
            ),
            'iceTransportPolicy' => Config::get('webrtc.ice_transport_policy', 'all'),
            'bundlePolicy' => Config::get('webrtc.bundle_policy', 'max-bundle'),
            'rtcpMuxPolicy' => Config::get('webrtc.rtcp_mux_policy', 'require'),
            'iceCandidatePoolSize' => Config::get('webrtc.ice_candidate_pool_size', 10),
        ];
    }

    protected function startHeartbeat(LoopInterface $loop): void
    {
        $interval = Config::get('websocket.ping_interval', 25000);
        $timeout = Config::get('websocket.ping_timeout', 10000);

        $loop->addPeriodicTimer($interval / 1000, function () use ($timeout) {
            $now = time();
            foreach ($this->connectionHealth as $resourceId => $health) {
                if ($now - $health['last_ping'] > ($timeout / 1000) && !$health['pong_received']) {
                    // Connection timed out
                    if (isset($this->connections[$resourceId])) {
                        error_log("Connection $resourceId timed out, closing");
                        $this->connections[$resourceId]->close();
                    }
                } else {
                    // Send ping
                    if (isset($this->connections[$resourceId])) {
                        $this->connectionHealth[$resourceId]['pong_received'] = false;
                        $this->connectionHealth[$resourceId]['last_ping'] = $now;
                        $this->send($this->connections[$resourceId], ['type' => 'ping', 'payload' => ['timestamp' => $now]]);
                    }
                }
            }
        });
    }
}