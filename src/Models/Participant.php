<?php
/**
 * Gaming Calls Platform - Participant Model
 */

namespace GamingCalls\Models;

use GamingCalls\Database\Database;

class Participant
{
    public readonly int $id;
    public readonly int $roomId;
    public readonly string $sessionId;
    public readonly string $displayName;
    public readonly ?string $avatarData;
    public readonly string $joinedAt;
    public readonly ?string $leftAt;
    public readonly bool $isActive;
    public readonly ?array $metadata;

    public function __construct(array $data)
    {
        $this->id = (int)$data['id'];
        $this->roomId = (int)$data['room_id'];
        $this->sessionId = $data['session_id'];
        $this->displayName = $data['display_name'];
        $this->avatarData = $data['avatar_data'] ?? null;
        $this->joinedAt = $data['joined_at'];
        $this->leftAt = $data['left_at'] ?? null;
        $this->isActive = (bool)$data['is_active'];
        $this->metadata = !empty($data['metadata']) ? json_decode($data['metadata'], true) : null;
    }

    public static function create(int $roomId, string $sessionId, string $displayName, ?string $avatarData = null, array $metadata = []): self
    {
        $db = Database::getInstance();

        // Serializa os joins da sala para que requisições simultâneas
        // não ultrapassem max_participants.
        $db->beginTransaction();
        try {
            $roomStmt = $db->prepare("SELECT `max_participants` FROM `rooms` WHERE `id` = ? FOR UPDATE");
            $roomStmt->execute([$roomId]);
            $maxParticipants = (int)($roomStmt->fetchColumn() ?: 10);

            $existing = self::findByRoomAndSession($roomId, $sessionId);

            // Já está ativo: só atualiza os dados
            if ($existing && $existing->isActive) {
                $existing->update([
                    'display_name' => $displayName,
                    'avatar_data'  => $avatarData,
                    'metadata'     => $metadata,
                ]);
                $db->commit();
                return self::findById($existing->id);
            }

            $countStmt = $db->prepare("SELECT COUNT(*) FROM `room_participants` WHERE `room_id` = ? AND `is_active` = 1");
            $countStmt->execute([$roomId]);
            $activeCount = (int)$countStmt->fetchColumn();

            if ($activeCount >= $maxParticipants) {
                throw new \RuntimeException('Room is full');
            }

            // Já existiu e saiu: reativa o mesmo registro (evita erro de chave única)
            if ($existing) {
                $existing->update([
                    'display_name' => $displayName,
                    'avatar_data'  => $avatarData,
                    'metadata'     => $metadata,
                    'is_active'    => true,
                    'left_at'      => null,
                ]);
                $db->commit();
                return self::findById($existing->id);
            }

            // Novo participante
            $stmt = $db->prepare(
                "INSERT INTO `room_participants` (`room_id`, `session_id`, `display_name`, `avatar_data`, `metadata`)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $roomId,
                $sessionId,
                $displayName,
                $avatarData,
                $metadata ? json_encode($metadata) : null,
            ]);

            $participantId = (int)$db->lastInsertId();
            $db->commit();
            return self::findById($participantId);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function findById(int $id): ?self
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM `room_participants` WHERE `id` = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? new self($row) : null;
    }

    public static function findByRoomAndSession(int $roomId, string $sessionId): ?self
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM `room_participants` WHERE `room_id` = ? AND `session_id` = ?");
        $stmt->execute([$roomId, $sessionId]);
        $row = $stmt->fetch();
        return $row ? new self($row) : null;
    }

    public static function findActiveByRoom(int $roomId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM `room_participants` WHERE `room_id` = ? AND `is_active` = 1 ORDER BY `joined_at` ASC");
        $stmt->execute([$roomId]);
        $rows = $stmt->fetchAll();
        return array_map(fn($row) => new self($row), $rows);
    }

    public static function findBySession(string $sessionId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM `room_participants` WHERE `session_id` = ? ORDER BY `joined_at` DESC");
        $stmt->execute([$sessionId]);
        $rows = $stmt->fetchAll();
        return array_map(fn($row) => new self($row), $rows);
    }

    public function update(array $data): bool
    {
        $allowed = ['display_name', 'avatar_data', 'metadata', 'is_active', 'left_at'];
        $fields = [];
        $values = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $fields[] = "`$key` = ?";
                if ($value === null) {
                    $values[] = null;
                } elseif (is_array($value)) {
                    $values[] = json_encode($value);
                } elseif (is_bool($value)) {
                    $values[] = (int)$value;
                } else {
                    $values[] = $value;
                }
            }
        }

        if (empty($fields)) {
            return false;
        }

        $values[] = $this->id;
        $db = Database::getInstance();
        $stmt = $db->prepare("UPDATE `room_participants` SET " . implode(', ', $fields) . " WHERE `id` = ?");
        return $stmt->execute($values);
    }

    public function leave(): bool
    {
        return $this->update(['is_active' => false, 'left_at' => date('Y-m-d H:i:s')]);
    }

    public function updateMetadata(array $metadata): bool
    {
        $merged = array_merge($this->metadata ?? [], $metadata);
        return $this->update(['metadata' => $merged]);
    }

    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function setMetadata(string $key, mixed $value): bool
    {
        $metadata = $this->metadata ?? [];
        $metadata[$key] = $value;
        return $this->update(['metadata' => $metadata]);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'room_id' => $this->roomId,
            'session_id' => $this->sessionId,
            'display_name' => $this->displayName,
            'avatar_data' => $this->avatarData,
            'joined_at' => $this->joinedAt,
            'left_at' => $this->leftAt,
            'is_active' => $this->isActive,
            'metadata' => $this->metadata,
        ];
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->sessionId,
            'display_name' => $this->displayName,
            'avatar_data' => $this->avatarData,
            'is_active' => $this->isActive,
            'metadata' => $this->metadata,
        ];
    }
}