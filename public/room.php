<?php
/**
 * Gaming Calls Platform - Call Room Page
 * Main calling interface
 */

require_once __DIR__ . '/../vendor/autoload.php';

use GamingCalls\Config\Config;
use GamingCalls\Session\SessionManager;
use GamingCalls\Support\Url;
use GamingCalls\Models\Room;

Config::load(__DIR__ . '/../config/config.php');
SessionManager::start();

// Get invite code from URL
$inviteCode = isset($_GET['code']) ? strtoupper(trim($_GET['code'])) : '';

// Validate room exists
$room = null;
if ($inviteCode) {
    $room = Room::findByInviteCode($inviteCode);
}

if (!$room) {
    // Redirect to home with error
    header('Location: ' . Url::basePath() . '/?error=invalid_room');
    exit;
}

// Store room in session
SessionManager::setCurrentRoomId($room->id);

$displayName = SessionManager::getDisplayName() ?? '';
$avatar = SessionManager::getAvatar() ?? '';
$csrfToken = SessionManager::getCsrfToken();
$baseUrl = Url::basePath();
$wsUrl = Url::websocket((int) Config::get('websocket.port', 8081));
$joinUrl = $room->getJoinUrl(Url::absolute());

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars($room->name) ?> - Grape">
    <title><?= htmlspecialchars($room->name) ?> - Grape</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= Url::asset('assets/css/main.css') ?>">
    <link rel="stylesheet" href="<?= Url::asset('assets/css/theme.css') ?>">
    <link rel="stylesheet" href="<?= Url::asset('assets/css/room.css') ?>">
    <link rel="icon" type="image/png" href="<?= Url::asset('assets/img/logo-grape.png') ?>">
    <meta name="theme-color" content="#0b0717">
</head>
<body class="theme-grape theme-room">
    <div class="deco" aria-hidden="true">
        <span class="blob blob-tl"></span>
        <span class="blob blob-tr"></span>
        <span class="blob blob-br"></span>
    </div>
    <div class="room" id="room">

        <!-- ============ Sidebar: chamada, participantes e chat ============ -->
        <aside class="side" id="side" aria-label="Chamada">
            <div class="side-brand" aria-hidden="true">
                <img src="<?= Url::asset('assets/img/logo-grape.png') ?>" width="34" height="33" alt="">
                <span>grape</span>
            </div>
            <header class="side-head">
                <button class="icon-btn" id="leave-room-btn" aria-label="Sair da sala" title="Sair da sala">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                </button>
                <div class="call-info">
                    <h1 class="call-name" id="room-name"><?= htmlspecialchars($room->name) ?></h1>
                    <div class="call-sub" id="connection-status">
                        <span class="status-dot connecting" id="status-dot"></span>
                        <span id="call-status-text">Conectando...</span>
                    </div>
                </div>
                <button class="icon-btn side-close" id="side-close" aria-label="Fechar painel">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </header>

            <div class="side-tabs" role="tablist" aria-label="Painel lateral">
                <button class="side-tab active" role="tab" id="tab-people" data-panel="people" aria-selected="true" aria-controls="panel-people">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span>Participantes (<span id="participant-count-value">0</span>)</span>
                </button>
                <button class="side-tab" role="tab" id="tab-chat" data-panel="chat" aria-selected="false" aria-controls="panel-chat">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <span>Chat</span>
                </button>
            </div>

            <section class="side-panel" id="panel-people" role="tabpanel" aria-labelledby="tab-people">
                <ul class="people" id="participants-list"></ul>
                <button class="invite-btn" id="invite-btn" type="button">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                    Convidar pessoas
                </button>
            </section>

            <section class="side-panel" id="panel-chat" role="tabpanel" aria-labelledby="tab-chat" hidden>
                <div class="chat-messages" id="chat-messages"></div>
                <form class="chat-form" id="chat-form" autocomplete="off">
                    <input type="text" class="chat-input" id="chat-input" placeholder="Digite uma mensagem..." maxlength="500">
                    <button type="submit" class="icon-btn chat-send" aria-label="Enviar">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </button>
                </form>
            </section>
        </aside>
        <div class="side-overlay" id="side-overlay"></div>

        <!-- ============ Palco: o que o participante selecionado transmite ============ -->
        <main class="stage-card" id="stage-card">
            <header class="stage-head">
                <button class="icon-btn side-toggle" id="side-toggle" aria-label="Participantes" title="Participantes">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </button>

                <div class="viewing">
                    <div class="user-avatar" id="viewing-avatar"></div>
                    <span class="viewing-name" id="viewing-name">—</span>
                </div>

                <div class="view-tabs" id="view-tabs" role="tablist" aria-label="O que ver">
                    <button class="view-tab" role="tab" id="view-tab-camera" data-view="camera" aria-selected="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
                        Vídeo
                        <i class="live-pip" aria-hidden="true"></i>
                    </button>
                    <button class="view-tab" role="tab" id="view-tab-screen" data-view="screen" aria-selected="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                        Tela
                        <i class="live-pip" aria-hidden="true"></i>
                    </button>
                </div>

                <button class="popout-btn" id="popout-btn" type="button" title="Janela flutuante (P)" aria-label="Abrir em janela flutuante" aria-pressed="false" hidden>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5"/><path d="M15 3h6v6M21 3l-8 8"/></svg>
                </button>
                <button class="icon-btn" id="fullscreen-btn" aria-label="Tela cheia" title="Tela cheia (F)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>
                </button>
            </header>

            <div class="stage" id="stage">
                <div class="pane" id="pane-camera" data-kind="camera">
                    <video id="video-camera" playsinline autoplay muted></video>
                    <div class="pane-empty" id="empty-camera"></div>
                    <span class="pane-tag">Vídeo</span>
                    <button type="button" class="cam-close" data-action="hide-camera" aria-label="Fechar câmera" title="Fechar câmera">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="pane" id="pane-screen" data-kind="screen">
                    <video id="video-screen" playsinline autoplay></video>
                    <div class="pane-empty" id="empty-screen"></div>
                    <span class="pane-tag">Tela</span>
                </div>
                <button type="button" class="cam-restore" id="cam-restore" data-action="show-camera" aria-label="Mostrar câmera" title="Mostrar câmera" hidden>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
                </button>
                <div class="stage-none" id="stage-none">
                    <p>Selecione um participante para ver o que ele está transmitindo.</p>
                </div>
            </div>
            <div class="stage-detached" id="stage-detached" hidden>
                <p>Vídeo e tela estão em uma janela separada.</p>
                <button type="button" class="empty-action" id="popin-btn">Trazer de volta</button>
            </div>
        </main>

        <!-- ============ Controles ============ -->
        <footer class="dock" id="dock">
            <div class="dock-spacer"></div>

            <div class="dock-main">
                <button class="dock-btn" id="toggle-mic-btn" aria-label="Microfone" title="Microfone (M)" aria-pressed="false">
                    <svg class="ic-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                    <svg class="ic-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="1" y1="1" x2="23" y2="23"/><path d="M9 9v3a3 3 0 0 0 5.12 2.12M15 9.34V4a3 3 0 0 0-5.94-.6"/><path d="M17 16.95A7 7 0 0 1 5 12v-2m14 0v2a7 7 0 0 1-.11 1.23"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                </button>
                <button class="dock-btn" id="toggle-deafen-btn" aria-label="Ensurdecer (silenciar saída de áudio)" title="Silenciar áudio de saída (D)" aria-pressed="false">
                    <svg class="ic-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 14v-2a9 9 0 0 1 18 0v2"/><path d="M21 15a2 2 0 0 1-2 2h-1a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h3v3zM3 15a2 2 0 0 0 2 2h1a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1H3v3z"/></svg>
                    <svg class="ic-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 14v-2a9 9 0 0 1 14.5-7.1M21 12v2"/><path d="M21 15a2 2 0 0 1-2 2h-1a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h3v3zM3 15a2 2 0 0 0 2 2h1a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1H3v3z"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
                <button class="dock-btn" id="toggle-camera-btn" aria-label="Câmera" title="Câmera (V)" aria-pressed="true">
                    <svg class="ic-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
                    <svg class="ic-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 16v1a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2m5.66 0H14a2 2 0 0 1 2 2v3.34l1 1L23 7v10"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
                <button class="dock-btn" id="toggle-screen-btn" aria-label="Compartilhar tela" title="Compartilhar tela" aria-pressed="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/><path d="M12 13V8M9.5 10.5L12 8l2.5 2.5"/></svg>
                    <i class="dock-dot" aria-hidden="true"></i>
                </button>
                <button class="dock-btn" id="dock-invite-btn" aria-label="Convidar pessoas" title="Copiar link de convite">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                </button>
                <button class="dock-btn dock-leave" id="leave-call-btn" aria-label="Sair da chamada" title="Sair da chamada">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.68 13.31a16 16 0 0 0 3.41 2.6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7 2 2 0 0 1 1.72 2v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.42 19.42 0 0 1-3.33-2.67m-2.67-3.34a19.79 19.79 0 0 1-3.07-8.63A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91"/><line x1="23" y1="1" x2="1" y2="23"/></svg>
                </button>
            </div>

            <div class="dock-side">
                <button class="dock-btn dock-ghost" id="toggle-notif-btn" aria-label="Silenciar notificações da sala" title="Silenciar notificações da sala" aria-pressed="false">
                    <svg class="ic-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    <svg class="ic-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13.73 21a2 2 0 0 1-3.46 0"/><path d="M18.63 13A17.89 17.89 0 0 1 18 8"/><path d="M6.26 6.26A5.86 5.86 0 0 0 6 8c0 7-3 9-3 9h14"/><path d="M18 8a6 6 0 0 0-9.33-5"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
                <button class="dock-btn dock-ghost" id="layout-btn" aria-label="Ver vídeo e tela lado a lado" title="Vídeo e tela lado a lado" aria-pressed="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
                </button>
                <button class="dock-btn dock-ghost" id="call-settings-btn" aria-label="Configurações" title="Configurações">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                </button>
            </div>
        </footer>

        <!-- Menu "..." de participante -->
        <div class="menu" id="person-menu" role="menu" hidden></div>

        <!-- Áudio dos participantes (toca mesmo sem ver a aba deles) -->
        <div id="audio-sink" hidden></div>

        <!-- Settings Modal -->
        <div class="modal-overlay" id="settings-modal" role="dialog" aria-modal="true" aria-labelledby="settings-title">
            <div class="modal panel settings-modal">
                <div class="modal-header">
                    <h2 id="settings-title">Configurações</h2>
                    <button class="modal-close" id="settings-close" aria-label="Fechar">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>

                <div class="settings-tabs" role="tablist" aria-label="Seções das configurações">
                    <button type="button" class="settings-tab active" role="tab" id="settings-tab-profile" data-tab="profile" aria-selected="true" aria-controls="settings-panel-profile">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span>Perfil</span>
                    </button>
                    <button type="button" class="settings-tab" role="tab" id="settings-tab-devices" data-tab="devices" aria-selected="false" aria-controls="settings-panel-devices">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/></svg>
                        <span>Áudio e vídeo</span>
                    </button>
                    <button type="button" class="settings-tab" role="tab" id="settings-tab-stream" data-tab="stream" aria-selected="false" aria-controls="settings-panel-stream">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                        <span>Transmissão</span>
                    </button>
                </div>

                <div class="modal-body settings-body">
                    <!-- Perfil -->
                    <section class="settings-panel active" id="settings-panel-profile" role="tabpanel" aria-labelledby="settings-tab-profile" data-panel="profile">
                        <div class="profile-card">
                            <div class="profile-avatar" id="profile-avatar-preview" aria-hidden="true"></div>
                            <div class="profile-fields">
                                <label for="profile-name" class="form-label">Nome de exibição</label>
                                <input type="text" id="profile-name" class="settings-input" maxlength="50" autocomplete="off" placeholder="Como você quer ser chamado">
                                <span class="field-hint"><span id="profile-name-count">0</span>/50</span>
                            </div>
                        </div>

                        <div class="settings-group">
                            <span class="form-label">Cor do avatar</span>
                            <div class="swatches" id="profile-swatches" role="group" aria-label="Cor do avatar"></div>
                        </div>

                        <div class="settings-group">
                            <span class="form-label">Foto</span>
                            <div class="photo-actions">
                                <button type="button" class="btn btn-secondary btn-sm" id="profile-photo-btn">Enviar foto…</button>
                                <button type="button" class="btn btn-ghost btn-sm" id="profile-photo-remove" hidden>Remover foto</button>
                                <input type="file" id="profile-photo-input" accept="image/*" hidden>
                            </div>
                            <p class="field-hint">Imagens de até 2 MB. Todos na sala veem a mudança assim que você salvar.</p>
                        </div>
                    </section>

                    <!-- Áudio e vídeo -->
                    <section class="settings-panel" id="settings-panel-devices" role="tabpanel" aria-labelledby="settings-tab-devices" data-panel="devices" hidden>
                        <div class="settings-group">
                            <label for="camera-select" class="form-label">Câmera</label>
                            <select id="camera-select" class="form-select"></select>
                        </div>
                        <div class="settings-group">
                            <label for="mic-select" class="form-label">Microfone</label>
                            <select id="mic-select" class="form-select"></select>
                        </div>
                        <div class="settings-group">
                            <label for="speaker-select" class="form-label">Alto-falante</label>
                            <select id="speaker-select" class="form-select"></select>
                        </div>
                        <div class="settings-group">
                            <span class="form-label">Qualidade do áudio</span>
                            <div class="switch-list">
                                <label class="switch-row">
                                    <span class="switch-text"><strong>Supressão de ruído</strong><small>Reduz sons de fundo, como teclado e ventilador.</small></span>
                                    <input type="checkbox" id="noise-suppression" class="switch" checked>
                                </label>
                                <label class="switch-row">
                                    <span class="switch-text"><strong>Cancelamento de eco</strong><small>Evita que o som dos alto-falantes volte pelo microfone.</small></span>
                                    <input type="checkbox" id="echo-cancellation" class="switch" checked>
                                </label>
                            </div>
                        </div>
                        <button type="button" class="btn btn-secondary" id="test-media-btn">Testar dispositivos</button>
                    </section>

                    <!-- Transmissão -->
                    <section class="settings-panel" id="settings-panel-stream" role="tabpanel" aria-labelledby="settings-tab-stream" data-panel="stream" hidden>
                        <div class="settings-group">
                            <label for="video-quality" class="form-label">Resolução</label>
                            <select id="video-quality" class="form-select">
                                <option value="auto">Automático</option>
                                <option value="1080p">1080p</option>
                                <option value="720p">720p</option>
                                <option value="480p">480p</option>
                                <option value="360p">360p</option>
                            </select>
                        </div>
                        <div class="settings-group">
                            <div class="range-head">
                                <label for="max-bitrate" class="form-label">Bitrate máximo</label>
                                <span class="range-value" id="max-bitrate-value">2500 kbps</span>
                            </div>
                            <input type="range" id="max-bitrate" class="form-range" min="500" max="5000" step="500" value="2500">
                            <p class="field-hint">Valores maiores deixam a imagem mais nítida, mas exigem mais internet.</p>
                        </div>
                        <div class="settings-group">
                            <label for="frame-rate" class="form-label">Taxa de quadros</label>
                            <select id="frame-rate" class="form-select">
                                <option value="30">30 FPS</option>
                                <option value="60">60 FPS</option>
                            </select>
                        </div>
                    </section>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="settings-cancel">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="settings-save">Salvar</button>
                </div>
            </div>
        </div>

        <!-- Toast Container -->
        <div class="toast-container" id="toast-container"></div>
    </div>

    <script>
        window.APP_CONFIG = {
            baseUrl: '<?= $baseUrl ?>',
            wsUrl: '<?= $wsUrl ?>',
            csrfToken: '<?= $csrfToken ?>',
            debug: <?= Config::get('app.debug') ? 'true' : 'false' ?>,
            room: {
                id: <?= $room->id ?>,
                name: '<?= htmlspecialchars($room->name, ENT_QUOTES) ?>',
                inviteCode: '<?= $room->inviteCode ?>',
                joinUrl: <?= json_encode($joinUrl) ?>,
                maxParticipants: <?= $room->maxParticipants ?>,
            },
            user: {
                displayName: '<?= htmlspecialchars($displayName, ENT_QUOTES) ?>',
                avatar: <?= json_encode($avatar ?? '') ?>,
                sessionId: '<?= SessionManager::getId() ?>',
            },
            webrtc: <?= json_encode([
                'iceServers' => array_merge(
                    Config::get('webrtc.stun_servers', []),
                    Config::get('webrtc.turn_servers', [])
                ),
                'iceTransportPolicy' => Config::get('webrtc.ice_transport_policy', 'all'),
                'bundlePolicy' => Config::get('webrtc.bundle_policy', 'max-bundle'),
                'rtcpMuxPolicy' => Config::get('webrtc.rtcp_mux_policy', 'require'),
                'iceCandidatePoolSize' => Config::get('webrtc.ice_candidate_pool_size', 10),
            ]) ?>,
        };
    </script>
    <script type="module" src="<?= Url::asset('assets/js/room.js') ?>"></script>
</body>
</html>