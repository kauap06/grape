<?php
/**
 * Gaming Calls Platform - Status da CA do HTTPS
 *
 * GET /api/ca-status.php -> onde esta o certificado neste PC e se ele ja esta
 * instalado na loja de raizes confiaveis do Windows. Somente leitura.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use GamingCalls\Config\Config;
use GamingCalls\Session\SessionManager;
use GamingCalls\Support\CertificateAuthority;

Config::load(__DIR__ . '/../../config/config.php');
SessionManager::start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Requisição inválida']);
    exit;
}

echo json_encode(['success' => true] + CertificateAuthority::status());
