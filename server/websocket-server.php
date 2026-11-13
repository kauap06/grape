<?php
/**
 * Gaming Calls Platform - WebSocket Server Entry Point
 * Run this script to start the WebSocket signaling server
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Horário do Brasil nos logs e em date() (o php.ini do XAMPP usa Europe/Berlin)
date_default_timezone_set('America/Sao_Paulo');

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\Socket\SocketServer;
use React\EventLoop\Factory;
use GamingCalls\Config\Config;
use GamingCalls\Server\SignalingServer;
use GamingCalls\Database\Database;

// Envia os error_log() para um arquivo que sempre existe (o php.ini do XAMPP aponta para uma pasta inexistente)
@mkdir(__DIR__ . '/../logs', 0777, true);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/websocket-error.log');

// Load configuration
Config::load(__DIR__ . '/../config/config.php');

// Set database config
Database::setConfig(Config::get('database'));

// Get WebSocket config
$wsHost = Config::get('websocket.host', '0.0.0.0');
$wsPort = Config::get('websocket.port', 8081);

// Create event loop
$loop = Factory::create();

// Create WebSocket server
$signalingServer = new SignalingServer($loop);
$webSocketServer = new WsServer($signalingServer);
$httpServer = new HttpServer($webSocketServer);

// Create socket server
$socket = new SocketServer("{$wsHost}:{$wsPort}", [], $loop);
$server = new IoServer($httpServer, $socket, $loop);

echo "Gaming Calls WebSocket Signaling Server\n";
echo "=======================================\n";
echo "Listening on {$wsHost}:{$wsPort}\n";
echo "Press Ctrl+C to stop\n\n";

// Handle shutdown signals (só disponível com a extensão pcntl; no Windows use Ctrl+C)
if (function_exists('pcntl_signal') && defined('SIGINT')) {
    $stop = function () use ($loop) {
        echo "\nShutting down...\n";
        $loop->stop();
    };
    $loop->addSignal(SIGINT, $stop);
    $loop->addSignal(SIGTERM, $stop);
}

// Run the server
$loop->run();

echo "Server stopped.\n";