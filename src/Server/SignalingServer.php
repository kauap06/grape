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
    /** Microfone aberto. */
    public const MUTE_UNMUTED = 'UNMUTED';
    /** O proprio participante silenciou o microfone. */
    public const MUTE_SELF = 'SELF_MUTED';
    /** O host silenciou o participante. */
    public const MUTE_HOST = 'HOST_MUTED';

    /** @var ConnectionInterface[] */
    protected array $connections = [];
    
    /** @var array<int, ConnectionInterface[]> */
    protected array $roomConnections = [];
    
    /** @var array<string, array{room_id: int, participant_id: int, session_id: string, metadata: array}> */
    protected array $connectionInfo = [];

    /**
     * Estado de mute autoritativo por sala/sessao: guarda a origem e quem aplicou.
     * @var array<int, array<string, array{state: string, by: ?string}>>
     */
    protected array $muteStates = [];

    /** @var array<int, int> Contador monotono por sala: descarta eventos fora de ordem */
    protected array $muteSeq = [];
    
    /** @var array<int, array{last_ping: int, pong_received: bool}> */
    protected array $connectionHealth = [];

    /** @var array<int, array{window_start: int, count: int}> Janela deslizante por conexao (security.rate_limit.websocket) */
    protected array $rateLimitBuckets = [];

    protected LoopInterface $loop;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
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

        // O payload precisa ser um objeto/array; qualquer outra coisa causaria TypeError
        if (!is_array($payload)) {
            $this->sendError($from, 'INVALID_PAYLOAD', 'Payload must be an object');
            return;
        }

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
                case 'self-mute':
                    $this->handleSelfMute($from, $payload);
                    break;
                // --- moderacao (somente host) ---
                case 'admin-mute':
                    $this->handleAdminMute($from, $payload);
                    break;
                case 'admin-camera':
                    $this->handleAdminCamera($from, $payload);
                    break;
                case 'admin-kick':
                    $this->handleAdminKick($from, $payload);
                    break;
                case 'admin-lock':
                    $this->handleAdminLock($from, $payload);
                    break;
                case 'admin-mute-all':
                    $this->handleAdminMuteAll($from);
                    break;
                default:
                    $this->sendError($from, 'UNKNOWN_TYPE', "Unknown message type: $type");
            }
        } catch (\Throwable $e) {
            error_log("WebSocket error [type=$type]: " . get_class($e) . ": " . $e->getMessage()
                . " em " . $e->getFile() . ":" . $e->getLine()
                . "\n" . $e->getTraceAsString());
            $this->sendError($from, 'SERVER_ERROR', 'Internal server error');
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $resourceId = $conn->resourceId;
        try {
            $this->cleanupConnection($resourceId);
        } catch (\Throwable $e) {
            error_log("Cleanup error on close: " . get_class($e) . ": " . $e->getMessage()
                . " em " . $e->getFile() . ":" . $e->getLine());
        }
        unset($this->connections[$resourceId], $this->connectionHealth[$resourceId], $this->rateLimitBuckets[$resourceId]);
        error_log("WebSocket connection closed: $resourceId");
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        error_log("WebSocket error on connection {$conn->resourceId}: " . $e->getMessage()
            . " em " . $e->getFile() . ":" . $e->getLine());
        $conn->close();
    }

    protected function handleJoin(ConnectionInterface $conn, array $payload): void
    {
        $roomCode = $payload['room_code'] ?? '';
        $sessionId = $payload['session_id'] ?? '';
        $displayName = $payload['display_name'] ?? 'Anonymous';
        $avatarData = $payload['avatar_data'] ?? null;
        $metadata = $payload['metadata'] ?? [];

        // O model exige ?string para avatar_data e array para metadata
        if ($avatarData !== null && !is_string($avatarData)) {
            $avatarData = json_encode($avatarData);
        }
        if (!is_array($metadata)) {
            $metadata = [];
        }
        // O mute e do servidor: o cliente nao pode definir o proprio estado
        // no join (seria uma forma de escapar de um mute do host).
        unset($metadata['mute_state'], $metadata['mute_by']);
        $displayName = (string) $displayName;

        // Avatar maior que a coluna faria o INSERT falhar (SQLSTATE 22001 / 1406)
        // e o join nunca seria confirmado. Descarta o avatar, nao a entrada na sala.
        if (is_string($avatarData) && strlen($avatarData) > 8 * 1024 * 1024) {
            error_log("Avatar de {$sessionId} ignorado: " . strlen($avatarData) . " bytes (limite 8MB)");
            $avatarData = null;
        }

        if (empty($roomCode) || empty($sessionId)) {
            $this->sendError($conn, 'INVALID_PAYLOAD', 'Room code and session ID required');
            return;
        }

        $room = Room::findByInviteCode($roomCode);
        if (!$room) {
            $this->sendError($conn, 'ROOM_NOT_FOUND', 'Room not found or inactive');
            return;
        }

        // Host entra mesmo com a sala trancada ou cheia
        $isHostEarly = hash_equals($room->creatorSessionId, (string) $sessionId);
        if (!$isHostEarly && !$room->canJoin()) {
            $this->sendError($conn, $room->isLocked ? 'ROOM_LOCKED' : 'ROOM_FULL',
                $room->isLocked ? 'Room is locked by the host' : 'Room is full or expired');
            return;
        }

        // Create or update participant (o metadata anterior e preservado:
        // dele vem o mute salvo que sobrevive a saida/reentrada).
        $participant = Participant::create($room->id, $sessionId, $displayName, $avatarData, $metadata);
        
        // Store connection info
        // Host = quem criou a sala. Só ele recebe is_host e pode moderar.
        $isHost = hash_equals($room->creatorSessionId, (string) $sessionId);

        $this->connectionInfo[$conn->resourceId] = [
            'room_id' => $room->id,
            'participant_id' => $participant->id,
            'session_id' => $sessionId,
            'metadata' => $metadata,
            'is_host' => $isHost,
        ];

        // Restaura o mute anterior (sobrevive a reconexao). SELF_MUTED e
        // HOST_MUTED continuam valendo ate o participante voltar e agir.
        $restoredMute = $this->muteStateOf($room->id, (string) $sessionId);
        if ($restoredMute === self::MUTE_UNMUTED && !empty($participant->metadata['mute_state'])) {
            $restoredMute = (string) $participant->metadata['mute_state'];
            $this->muteStates[$room->id][(string) $sessionId] = [
                'state' => $restoredMute,
                'by' => $participant->metadata['mute_by'] ?? null,
            ];
        }
        // Sala nova/limpa: garante entrada no mapa para o host tambem
        if (!isset($this->muteStates[$room->id][(string) $sessionId])) {
            $this->muteStates[$room->id][(string) $sessionId] = ['state' => self::MUTE_UNMUTED, 'by' => null];
        }

        // O join manda o metadata de midia antes do getUserMedia, entao
        // `microphoneMuted` pode nao refletir o mute restaurado. A lista de
        // participantes usa esses campos antes do primeiro media-state:
        // deixa o registro coerente com o estado autoritativo.
        $muteBy = $this->muteStates[$room->id][(string) $sessionId]['by'] ?? null;
        if (($participant->metadata['mute_state'] ?? null) !== $restoredMute
            || ($participant->metadata['microphoneMuted'] ?? false) !== ($restoredMute !== self::MUTE_UNMUTED)) {
            try {
                $participant->updateMetadata([
                    'mute_state' => $restoredMute,
                    'mute_by' => $muteBy,
                    'microphoneMuted' => $restoredMute !== self::MUTE_UNMUTED,
                ]);
            } catch (\Throwable $e) {
                // Nao impede a entrada: o estado em memoria ja esta correto.
                error_log("Falha ao sincronizar metadata de mute de {$sessionId}: " . $e->getMessage());
            }
        }

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
                'is_host' => $isHost,
                'creator_session_id' => $room->creatorSessionId,
                'is_locked' => $room->isLocked,
                'mute_state' => $restoredMute,
                'mute_by' => $this->muteStates[$room->id][(string) $sessionId]['by'] ?? null,
                'mute_seq' => $this->muteSeq[$room->id] ?? 0,
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

        // O mute e do servidor: o cliente so reporta camera/tela/ensurdecido.
        // Assim um media-state atrasado nao desfaz um mute de host.
        $muteState = $this->muteStateOf((int) $info['room_id'], (string) $info['session_id']);
        $payload['microphoneMuted'] = $muteState !== self::MUTE_UNMUTED;
        $payload['mute_state'] = $muteState;

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

        $message = trim((string) ($payload['message'] ?? ''));
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

    // ========================================================================
    // Moderacao: apenas o host da sala. O alvo e sempre outro participante.
    // ========================================================================

    /**
     * Confere se a conexao e do host e devolve o room_id; senao responde erro.
     */
    protected function requireHost(ConnectionInterface $conn): ?int
    {
        $info = $this->connectionInfo[$conn->resourceId] ?? null;
        if (!$info) {
            $this->sendError($conn, 'NOT_IN_ROOM', 'Join a room first');
            return null;
        }
        if (empty($info['is_host'])) {
            $this->sendError($conn, 'NOT_HOST', 'Only the room host can do this');
            return null;
        }
        return (int) $info['room_id'];
    }

    // ========================================================================
    // Estado de mute: fonte unica de verdade.
    //
    // Cada participante esta em UNMUTED, SELF_MUTED ou HOST_MUTED. O mute
    // guarda a origem (quem aplicou), e toda transicao passa por
    // applyMuteTransition(), que valida a autorizacao ANTES de mudar o estado.
    // Transicao negada nao altera nada e devolve erro a quem pediu.
    //
    //   estado        acao           autorizado      novo estado
    //   UNMUTED       mute-self      o proprio       SELF_MUTED
    //   SELF_MUTED    unmute-self    o proprio       UNMUTED
    //   HOST_MUTED    unmute-self    o proprio       UNMUTED
    //   UNMUTED       host-mute      host            HOST_MUTED
    //   HOST_MUTED    host-unmute    host            UNMUTED
    //
    // Negadas: host/admin nao removem SELF_MUTED; participante nao mexe no
    // mute de outro; admin (nao-host) nao remove mute nenhum.
    // ========================================================================

    /**
     * Estado de mute atual de uma sessao na sala (UNMUTED quando desconhecido).
     */
    protected function muteStateOf(int $roomId, string $sessionId): string
    {
        return $this->muteStates[$roomId][$sessionId]['state'] ?? self::MUTE_UNMUTED;
    }

    /**
     * Aplica uma transicao de mute com validacao.
     *
     * @param string $action mute-self | unmute-self | host-mute | host-unmute
     * @param string $role   host | admin | participant
     * @return array{ok: bool, reason?: string, state: string, by: ?string, changed: bool}
     */
    protected function applyMuteTransition(
        int $roomId,
        string $targetSession,
        string $action,
        string $actorSession,
        string $role
    ): array {
        $current = $this->muteStateOf($roomId, $targetSession);
        $isSelf = $actorSession === $targetSession;
        $isHost = $role === 'host';
        $isAdmin = $role === 'admin';

        // Autorizacao por acao
        if ($action === 'mute-self' || $action === 'unmute-self') {
            if (!$isSelf) {
                return $this->muteReject($roomId, $targetSession, 'NOT_SELF',
                    'Only the participant can change their own mute');
            }
        } elseif ($action === 'host-mute' || $action === 'host-unmute') {
            if (!$isHost) {
                // Admin existe no vocabulario do dominio, mas hoje so o host modera
                return $this->muteReject($roomId, $targetSession,
                    $isAdmin ? 'ADMIN_CANNOT_UNMUTE' : 'NOT_HOST',
                    $isAdmin ? 'Only the host can change another participant mute'
                             : 'Only the room host can do this');
            }
        } else {
            return $this->muteReject($roomId, $targetSession, 'INVALID_ACTION', 'Unknown mute action');
        }

        // Transicao de estado
        $next = $current;
        $by = $this->muteStates[$roomId][$targetSession]['by'] ?? null;

        switch ($action) {
            case 'mute-self':
                // So sai de UNMUTED. Se ja e SELF_MUTED, idempotente.
                if ($current === self::MUTE_UNMUTED) {
                    $next = self::MUTE_SELF;
                    $by = $actorSession;
                } elseif ($current === self::MUTE_HOST) {
                    // Host ja o silenciou e ele nao reabre: continua HOST_MUTED.
                    // A origem do host e preservada (o host ainda pode liberar).
                    $next = self::MUTE_HOST;
                }
                break;

            case 'unmute-self':
                // O participante sempre remove o proprio mute, de qualquer origem.
                $next = self::MUTE_UNMUTED;
                $by = null;
                break;

            case 'host-mute':
                if ($current === self::MUTE_SELF) {
                    return $this->muteReject($roomId, $targetSession, 'SELF_MUTED',
                        'Participant muted themselves; the host cannot remove that');
                }
                if ($current === self::MUTE_UNMUTED) {
                    $next = self::MUTE_HOST;
                    $by = $actorSession;
                }
                break;

            case 'host-unmute':
                if ($current === self::MUTE_SELF) {
                    return $this->muteReject($roomId, $targetSession, 'SELF_MUTED',
                        'Participant muted themselves; only they can unmute');
                }
                if ($current === self::MUTE_HOST) {
                    $next = self::MUTE_UNMUTED;
                    $by = null;
                }
                break;
        }

        $changed = $next !== $current;
        if ($changed) {
            $this->muteStates[$roomId][$targetSession] = ['state' => $next, 'by' => $by];
        }

        return [
            'ok' => true,
            'state' => $next,
            'by' => $by,
            'changed' => $changed,
        ];
    }

    /**
     * Monta a resposta de transicao negada (sem alterar o estado atual).
     */
    protected function muteReject(int $roomId, string $sessionId, string $code, string $message): array
    {
        $entry = $this->muteStates[$roomId][$sessionId] ?? null;
        return [
            'ok' => false,
            'reason' => $code,
            'message' => $message,
            'state' => $entry['state'] ?? self::MUTE_UNMUTED,
            'by' => $entry['by'] ?? null,
            'changed' => false,
        ];
    }

    /**
     * Publica o estado de mute para a sala inteira e para o alvo, com um
     * numero de sequencia que o cliente usa para descartar eventos repetidos
     * ou fora de ordem.
     */
    protected function publishMuteState(
        int $roomId,
        string $sessionId,
        string $state,
        ?string $by,
        string $cause
    ): void {
        $seq = ($this->muteSeq[$roomId] ?? 0) + 1;
        $this->muteSeq[$roomId] = $seq;

        $payload = [
            'session_id' => $sessionId,
            'state' => $state,
            'by' => $by,
            'cause' => $cause, // self | host | sync
            'seq' => $seq,
        ];

        // Todos recebem, inclusive o alvo (que ajusta o proprio microfone).
        $this->broadcastToRoom($roomId, ['type' => 'mute-state', 'payload' => $payload]);

        // Persiste para sobreviver a reconexao do participante.
        $this->persistMuteState($roomId, $sessionId, $state, $by);
    }

    /**
     * Guarda o mute no metadata do participante (restaurado no rejoin).
     */
    protected function persistMuteState(int $roomId, string $sessionId, string $state, ?string $by): void
    {
        $participant = Participant::findByRoomAndSession($roomId, $sessionId);
        if (!$participant) {
            return;
        }
        try {
            $participant->updateMetadata([
                'mute_state' => $state,
                'mute_by' => $by,
                'microphoneMuted' => $state !== self::MUTE_UNMUTED,
            ]);
        } catch (\Throwable $e) {
            error_log("Falha ao persistir mute de {$sessionId}: " . $e->getMessage());
        }
    }

    /**
     * Papel do ator na sala (host | admin | participant).
     */
    protected function roleOf(ConnectionInterface $conn): string
    {
        $info = $this->connectionInfo[$conn->resourceId] ?? null;
        if (!$info) {
            return 'participant';
        }
        return empty($info['is_host']) ? 'participant' : 'host';
    }

    /**
     * Evento do proprio participante abrindo/fechando o microfone.
     */
    protected function handleSelfMute(ConnectionInterface $from, array $payload): void
    {
        $info = $this->connectionInfo[$from->resourceId] ?? null;
        if (!$info) {
            $this->sendError($from, 'NOT_IN_ROOM', 'Join a room first');
            return;
        }

        $roomId = (int) $info['room_id'];
        $sessionId = (string) $info['session_id'];
        $muted = (bool) ($payload['muted'] ?? true);
        $action = $muted ? 'mute-self' : 'unmute-self';

        $result = $this->applyMuteTransition($roomId, $sessionId, $action, $sessionId, $this->roleOf($from));

        if (!$result['ok']) {
            $this->sendError($from, (string) $result['reason'], (string) ($result['message'] ?? 'Mute rejected'));
            // Reafirma o estado atual para o cliente voltar ao que o servidor manda.
            $this->send($from, [
                'type' => 'mute-state',
                'payload' => [
                    'session_id' => $sessionId,
                    'state' => $result['state'],
                    'by' => $result['by'],
                    'cause' => 'sync',
                    'seq' => $this->muteSeq[$roomId] ?? 0,
                ],
            ]);
            return;
        }

        if ($result['changed']) {
            $this->publishMuteState($roomId, $sessionId, (string) $result['state'], $result['by'], 'self');
        } else {
            // Sem mudanca (ex.: tentou mutar estando HOST_MUTED): confirma o
            // estado autoritativo para o cliente desfazer o otimista.
            $this->send($from, [
                'type' => 'mute-state',
                'payload' => [
                    'session_id' => $sessionId,
                    'state' => $result['state'],
                    'by' => $result['by'],
                    'cause' => 'sync',
                    'seq' => $this->muteSeq[$roomId] ?? 0,
                ],
            ]);
        }
    }


    /**
     * Host silencia / libera o microfone de outro participante.
     * Rejeita quando o mute foi aplicado pelo proprio participante.
     */
    protected function handleAdminMute(ConnectionInterface $from, array $payload): void
    {
        $roomId = $this->requireHost($from);
        if ($roomId === null) {
            return;
        }

        $targetSession = (string) ($payload['target_session_id'] ?? '');
        $muted = (bool) ($payload['muted'] ?? true);
        if ($targetSession === '') {
            $this->sendError($from, 'INVALID_PAYLOAD', 'target_session_id required');
            return;
        }

        $targetId = $this->findConnectionBySession($roomId, $targetSession);
        $target = $targetId !== null ? ($this->connections[$targetId] ?? null) : null;
        if (!$target) {
            $this->sendError($from, 'TARGET_LEFT', 'Target is not in the room');
            return;
        }

        $actorSession = (string) $this->connectionInfo[$from->resourceId]['session_id'];
        $action = $muted ? 'host-mute' : 'host-unmute';

        $result = $this->applyMuteTransition($roomId, $targetSession, $action, $actorSession, $this->roleOf($from));

        if (!$result['ok']) {
            $this->sendError($from, (string) $result['reason'], (string) ($result['message'] ?? 'Mute rejected'));
            // Devolve o estado real para o host reverter a interface.
            $this->send($from, [
                'type' => 'mute-state',
                'payload' => [
                    'session_id' => $targetSession,
                    'state' => $result['state'],
                    'by' => $result['by'],
                    'cause' => 'sync',
                    'seq' => $this->muteSeq[$roomId] ?? 0,
                ],
            ]);
            return;
        }

        if ($result['changed']) {
            $this->publishMuteState($roomId, $targetSession, (string) $result['state'], $result['by'], 'host');
        } else {
            $this->send($from, [
                'type' => 'mute-state',
                'payload' => [
                    'session_id' => $targetSession,
                    'state' => $result['state'],
                    'by' => $result['by'],
                    'cause' => 'sync',
                    'seq' => $this->muteSeq[$roomId] ?? 0,
                ],
            ]);
        }
    }

    protected function handleAdminCamera(ConnectionInterface $from, array $payload): void
    {
        $roomId = $this->requireHost($from);
        if ($roomId === null) {
            return;
        }
        $targetSession = (string) ($payload['target_session_id'] ?? '');
        if ($targetSession === '') {
            $this->sendError($from, 'INVALID_PAYLOAD', 'target_session_id required');
            return;
        }
        $targetId = $this->findConnectionBySession($roomId, $targetSession);
        $target = $targetId !== null ? ($this->connections[$targetId] ?? null) : null;
        if (!$target) {
            $this->sendError($from, 'TARGET_LEFT', 'Target is not in the room');
            return;
        }

        $this->send($target, ['type' => 'admin-camera-off', 'payload' => []]);
        $this->broadcastToRoom($roomId, [
            'type' => 'admin-camera-off',
            'payload' => ['session_id' => $targetSession],
        ], $target->resourceId);
    }

    protected function handleAdminKick(ConnectionInterface $from, array $payload): void
    {
        $roomId = $this->requireHost($from);
        if ($roomId === null) {
            return;
        }
        $targetSession = (string) ($payload['target_session_id'] ?? '');
        if ($targetSession === '') {
            $this->sendError($from, 'INVALID_PAYLOAD', 'target_session_id required');
            return;
        }
        $targetId = $this->findConnectionBySession($roomId, $targetSession);
        $target = $targetId !== null ? ($this->connections[$targetId] ?? null) : null;
        if (!$target) {
            $this->sendError($from, 'TARGET_LEFT', 'Target is not in the room');
            return;
        }

        $this->send($target, [
            'type' => 'kicked',
            'payload' => ['by' => $this->connectionInfo[$from->resourceId]['session_id']],
        ]);
        // Fecha depois de entregar o aviso: fechar na mesma volta do loop pode
        // descartar o buffer e o cliente reconectaria em vez de sair.
        $this->loop->addTimer(0.25, static function () use ($target) {
            try { $target->close(); } catch (\Throwable $e) { /* ja fechado */ }
        });
        error_log("Host expulsou {$targetSession} da sala {$roomId}");
    }

    protected function handleAdminLock(ConnectionInterface $from, array $payload): void
    {
        $roomId = $this->requireHost($from);
        if ($roomId === null) {
            return;
        }
        $locked = (bool) ($payload['locked'] ?? true);

        try {
            $room = Room::findById($roomId);
            $room->update(['is_locked' => $locked]);
        } catch (\Throwable $e) {
            error_log("Falha ao trancar sala {$roomId}: " . $e->getMessage());
            $this->sendError($from, 'SERVER_ERROR', 'Could not update the room');
            return;
        }

        $this->broadcastToRoom($roomId, [
            'type' => 'room-lock',
            'payload' => ['locked' => $locked],
        ]);
        error_log("Sala {$roomId} " . ($locked ? 'trancada' : 'destrancada') . ' pelo host');
    }

    protected function handleAdminMuteAll(ConnectionInterface $from): void
    {
        $roomId = $this->requireHost($from);
        if ($roomId === null) {
            return;
        }
        $hostSession = (string) $this->connectionInfo[$from->resourceId]['session_id'];
        $role = $this->roleOf($from);
        $muted = 0;

        foreach ($this->roomConnections[$roomId] ?? [] as $conn) {
            $info = $this->connectionInfo[$conn->resourceId] ?? null;
            if (!$info || $info['session_id'] === $hostSession) {
                continue; // nao silencia o proprio host
            }

            // Passa pela mesma maquina de estados: quem ja esta SELF_MUTED
            // continua SELF_MUTED (o host nao pode remover esse mute).
            $result = $this->applyMuteTransition(
                $roomId, (string) $info['session_id'], 'host-mute', $hostSession, $role
            );
            if (!$result['ok'] || !$result['changed']) {
                continue;
            }
            $this->publishMuteState($roomId, (string) $info['session_id'],
                (string) $result['state'], $result['by'], 'host');
            $muted++;
        }

        $this->broadcastToRoom($roomId, [
            'type' => 'admin-mute-all',
            'payload' => ['by' => $hostSession, 'count' => $muted],
        ]);
        error_log("Host silenciou {$muted} participante(s) na sala {$roomId}");
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

        // Garante que o estado em memória é limpo mesmo se o banco falhar
        unset($this->connectionInfo[$resourceId]);

        // Mark participant as left in database
        try {
            $participant = Participant::findById($participantId);
            if ($participant && $participant->isActive) {
                $participant->leave();
            }
        } catch (\Throwable $e) {
            error_log("Failed to mark participant $participantId as left: " . $e->getMessage()
                . " em " . $e->getFile() . ":" . $e->getLine());
        }

        // Notify others
        $this->broadcastToRoom($roomId, [
            'type' => 'participant-left',
            'payload' => [
                'participant_id' => $participantId,
                'session_id' => $sessionId,
            ],
        ], $resourceId);

        error_log("Participant $participantId ($sessionId) left room $roomId");
    }

    protected function broadcastToRoom(int $roomId, array $message, ?int $excludeResourceId = null): void
    {
        if (!isset($this->roomConnections[$roomId])) {
            return;
        }

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

    /**
     * Janela fixa por conexao: no maximo N mensagens por janela de W segundos.
     * Valores em security.rate_limit.websocket {messages, window}; zerados desativam.
     */
    protected function checkRateLimit(int $resourceId): bool
    {
        $messages = (int) Config::get('security.rate_limit.websocket.messages', 100);
        $window = (int) Config::get('security.rate_limit.websocket.window', 60);
        if ($messages <= 0 || $window <= 0) {
            return true; // desativado por configuracao
        }

        $now = time();
        if (!isset($this->rateLimitBuckets[$resourceId])) {
            $this->rateLimitBuckets[$resourceId] = ['window_start' => $now, 'count' => 0];
        }

        $bucket = &$this->rateLimitBuckets[$resourceId];
        if (($now - $bucket['window_start']) >= $window) {
            $bucket['window_start'] = $now;
            $bucket['count'] = 0;
        }

        $bucket['count']++;
        if ($bucket['count'] > $messages) {
            error_log("WebSocket rate limit exceeded: connection $resourceId ({$bucket['count']} msgs in window)");
            return false;
        }
        return true;
    }

    protected function getWebRTCConfig(): array
    {
        $stun = Config::get('webrtc.stun_servers', []);
        $turn = Config::get('webrtc.turn_servers', []);

        return [
            'iceServers' => array_merge(
                is_array($stun) ? $stun : [],
                is_array($turn) ? $turn : []
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
