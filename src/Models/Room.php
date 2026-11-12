<?php
/**
 * Gaming Calls Platform - Room Model
 */

namespace GamingCalls\Models;

use GamingCalls\Database\Database;
use GamingCalls\Config\Config;

class Room
{
    public readonly int $id;
    public readonly string $name;
    public readonly string $inviteCode;
    public readonly string $creatorSessionId;
    public readonly int $maxParticipants;
    public readonly ?array $settings;
    public readonly string $createdAt;
    public readonly string $updatedAt;
    public readonly ?string $expiresAt;
    public readonly bool $isActive;

    public function __construct(array $data)
    {
        $this->id = (int)$data['id'];
        $this->name = $data['name'];
        $this->inviteCode = $data['invite_code'];
        $this->creatorSessionId = $data['creator_session_id'];
        $this->maxParticipants = (int)$data['max_participants'];
        $this->settings = $data['settings'] ? json_decode($data['settings'], true) : null;
        $this->createdAt = $data['created_at'];
        $this->updatedAt = $data['updated_at'];
        $this->expiresAt = $data['expires_at'];
        $this->isActive = (bool)$data['is_active'];
    }

    public static function create(string $name, string $creatorSessionId, array $options = []): self
    {
        $db = Database::getInstance();
        $inviteCode = self::generateInviteCode();
        $maxParticipants = $options['max_participants'] ?? Config::get('room.max_participants', 10);
        $settings = $options['settings'] ?? null;
        $expiresAt = $options['expires_at'] ?? null;

        $stmt = $db->prepare(
            "INSERT INTO `rooms` (`name`, `invite_code`, `creator_session_id`, `max_participants`, `settings`, `expires_at`) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $name,
            $inviteCode,
            $creatorSessionId,
            $maxParticipants,
            $settings ? json_encode($settings) : null,
            $expiresAt,
        ]);

        $roomId = (int)$db->lastInsertId();
        return self::findById($roomId);
    }

    public static function findById(int $id): ?self
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM `rooms` WHERE `id` = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? new self($row) : null;
    }

    public static function findByInviteCode(string $inviteCode): ?self
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM `rooms` WHERE `invite_code` = ? AND `is_active` = 1");
        $stmt->execute([strtoupper($inviteCode)]);
        $row = $stmt->fetch();
        return $row ? new self($row) : null;
    }

    public static function findByCreatorSession(string $sessionId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM `rooms` WHERE `creator_session_id` = ? AND `is_active` = 1 ORDER BY `created_at` DESC");
        $stmt->execute([$sessionId]);
        $rows = $stmt->fetchAll();
        return array_map(fn($row) => new self($row), $rows);
    }

    public static function generateInviteCode(int $length = null, string $chars = null): string
    {
        $length = $length ?? Config::get('room.invite_code_length', 8);
        $chars = $chars ?? Config::get('room.invite_code_chars', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
        $code = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, $max)];
        }
        return $code;
    }

    public static function isInviteCodeUnique(string $code): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT 1 FROM `rooms` WHERE `invite_code` = ?");
        $stmt->execute([$code]);
        return !$stmt->fetch();
    }

    public function update(array $data): bool
    {
        $allowed = ['name', 'max_participants', 'settings', 'expires_at', 'is_active'];
        $fields = [];
        $values = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $fields[] = "`$key` = ?";
                $values[] = $value === null ? null : (is_array($value) ? json_encode($value) : $value);
            }
        }

        if (empty($fields)) {
            return false;
        }

        $values[] = $this->id;
        $db = Database::getInstance();
        $stmt = $db->prepare("UPDATE `rooms` SET " . implode(', ', $fields) . " WHERE `id` = ?");
        return $stmt->execute($values);
    }

    public function deactivate(): bool
    {
        return $this->update(['is_active' => false]);
    }

    public function getActiveParticipantCount(): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT COUNT(*) FROM `room_participants` WHERE `room_id` = ? AND `is_active` = 1");
        $stmt->execute([$this->id]);
        return (int)$stmt->fetchColumn();
    }

    public function canJoin(): bool
    {
        if (!$this->isActive) {
            return false;
        }
        if ($this->expiresAt && strtotime($this->expiresAt) < time()) {
            return false;
        }
        return $this->getActiveParticipantCount() < $this->maxParticipants;
    }

    public function getJoinUrl(string $baseUrl = ''): string
    {
        $base = $baseUrl ?: (PHP_SAPI === 'cli'
            ? Config::get('app.base_url', '')
            : \GamingCalls\Support\Url::absolute());
        return rtrim($base, '/') . '/room.php?code=' . $this->inviteCode;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'invite_code' => $this->inviteCode,
            'creator_session_id' => $this->creatorSessionId,
            'max_participants' => $this->maxParticipants,
            'settings' => $this->settings,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'expires_at' => $this->expiresAt,
            'is_active' => $this->isActive,
            'participant_count' => $this->getActiveParticipantCount(),
            'join_url' => $this->getJoinUrl(),
        ];
    }
}