<?php
/**
 * Gaming Calls Platform - Preferences API
 *
 * GET  /api/preferences.php  -> devolve as preferencias do navegador
 * POST /api/preferences.php  -> grava as preferencias (JSON no corpo)
 *
 * A identificacao e o cookie grape_pref (longa duracao), nao a sessao.
 * POST exige CSRF; GET nao (leitura sem efeito colateral).
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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Cria a chave ja na leitura: o navegador passa a te-la antes de salvar.
    Preferences::key();
    echo json_encode(['success' => true, 'preferences' => Preferences::load()]);
    exit;
}

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

$raw = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

// Lista fechada de chaves aceitas: evita virar lixeira arbitraria.
$allowed = [
    'profile', 'devices', 'uiPreferences', 'roomSettings',
    'roomView', 'cameraPos', 'notificationsMuted',
];

$clean = [];
foreach ($allowed as $key) {
    if (array_key_exists($key, $data)) {
        $clean[$key] = $data[$key];
    }
}

if (!Preferences::save($clean)) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not save preferences']);
    exit;
}

echo json_encode(['success' => true, 'saved' => array_keys($clean)]);
