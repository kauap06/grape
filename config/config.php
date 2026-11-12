<?php
/**
 * Gaming Calls Platform - Configuration
 * Copy this file to config.php and adjust values for your environment
 */

return [
    // Application settings
    'app' => [
        'name' => 'Gaming Calls',
        'version' => '1.0.0',
        'debug' => true,
        'timezone' => 'America/Sao_Paulo',
        'base_url' => 'http://localhost:8080', // Adjust for your environment
    ],

    // Database settings
    'database' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'gaming_calls',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ],
    ],

    // Session settings
    'session' => [
        'name' => 'gaming_calls_session',
        'lifetime' => 7200, // 2 hours
        'path' => '/',
        'domain' => '',
        'secure' => false, // Set to true in production with HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ],

    // WebSocket server settings
    'websocket' => [
        'host' => '0.0.0.0',
        'port' => 8081,
        'ping_interval' => 25000, // ms
        'ping_timeout' => 10000,  // ms
        'max_message_size' => 10485760, // 10MB (permite fotos de perfil em base64)
    ],

    // WebRTC settings
    'webrtc' => [
        'stun_servers' => [
            ['urls' => 'stun:stun.l.google.com:19302'],
            ['urls' => 'stun:stun1.l.google.com:19302'],
            ['urls' => 'stun:stun2.l.google.com:19302'],
        ],
        'turn_servers' => [
            // Add your TURN servers here for production
            // ['urls' => 'turn:your-turn-server.com:3478', 'username' => 'user', 'credential' => 'pass'],
        ],
        'ice_transport_policy' => 'all', // 'all' or 'relay'
        'bundle_policy' => 'max-bundle',
        'rtcp_mux_policy' => 'require',
        'ice_candidate_pool_size' => 10,
    ],

    // Media constraints
    'media' => [
        'video' => [
            'default' => [
                'width' => ['ideal' => 1280, 'max' => 1920],
                'height' => ['ideal' => 720, 'max' => 1080],
                'frameRate' => ['ideal' => 30, 'max' => 60],
                'facingMode' => 'user',
            ],
            'screen' => [
                'width' => ['max' => 1920],
                'height' => ['max' => 1080],
                'frameRate' => ['max' => 30],
            ],
        ],
        'audio' => [
            'default' => [
                'echoCancellation' => true,
                'noiseSuppression' => true,
                'autoGainControl' => true,
                'sampleRate' => 48000,
                'channelCount' => 2,
            ],
            'system' => [
                'echoCancellation' => false,
                'noiseSuppression' => false,
                'autoGainControl' => false,
            ],
        ],
    ],

    // Chat settings
    'chat' => [
        'max_length' => 500,
    ],

    // Room settings
    'room' => [
        'max_participants' => 10,
        'invite_code_length' => 8,
        'invite_code_chars' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789', // No confusing chars
        'cleanup_inactive_hours' => 24,
        'participant_timeout_seconds' => 30,
    ],

    // Security settings
    'security' => [
        'csrf_token_length' => 32,
        'rate_limit' => [
            'api' => ['requests' => 60, 'window' => 60], // 60 requests per minute
            'websocket' => ['messages' => 100, 'window' => 60], // 100 messages per minute
        ],
        'allowed_origins' => ['*'], // Configure for production
    ],

    // Logging
    'logging' => [
        'level' => 'debug',
        'path' => __DIR__ . '/../logs',
        'max_files' => 30,
    ],
];