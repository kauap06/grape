<?php
/**
 * Gaming Calls Platform - Reset de dados do usuário
 *
 * POST /api/reset.php
 *
 * Apaga o que o servidor guarda sobre este navegador: preferências (cookie
 * grape_pref), participações em salas e a própria sessão. O navegador limpa
 * localStorage e cookies depois; aqui a sessão é destruída e o cookie de
 * preferência expira.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use GamingCalls\Config\Config;
use GamingCalls\Session\SessionManager;
use GamingCalls\Support\Preferences;
use GamingCalls\Database\Database;

Config::load(__DIR__ . '/../../config/config.php');
Database::setConfig(Config::get('database'));
SessionManager::start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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

$sessionId = SessionManager::getId();

try {
    $db = Database::getInstance();

    // Preferências guardadas para este navegador
    $chave = Preferences::key();
    if ($chave) {
        $stmt = $db->prepare("DELETE FROM `user_preferences` WHERE `pref_key` = ?");
        $stmt->execute([$chave]);
    }

    // Salas criadas por este usuário saem junto (o FK leva os participantes)
    if ($sessionId) {
        $stmt = $db->prepare("DELETE FROM `rooms` WHERE `creator_session_id` = ?");
        $stmt->execute([$sessionId]);

        // E as participações em salas de terceiros
        $stmt = $db->prepare(
            "UPDATE `room_participants` SET `is_active` = 0, `left_at` = NOW()
             WHERE `session_id` = ? AND `is_active` = 1"
        );
        $stmt->execute([$sessionId]);
    }

    // Cookie de preferência e sessão
    Preferences::forget();
    SessionManager::destroy();
} catch (\Throwable $e) {
    error_log('Falha ao apagar dados do usuário: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Não foi possível apagar os dados']);
    exit;
}

echo json_encode(['success' => true]);
