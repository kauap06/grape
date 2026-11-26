<?php
/**
 * Gaming Calls Platform - Home Page
 * Entry point for the application
 */

require_once __DIR__ . '/../vendor/autoload.php';

use GamingCalls\Config\Config;
use GamingCalls\Session\SessionManager;
use GamingCalls\Support\Url;
use GamingCalls\Support\Security;
use GamingCalls\Support\Preferences;

Config::load(__DIR__ . '/../config/config.php');
SessionManager::start();

// CSP com nonce (o bloco APP_CONFIG embutido precisa do nonce)
Security::sendHeaders();

// Get user preferences from session/localStorage will be handled by JS
$displayName = SessionManager::getDisplayName() ?? '';
$avatar = SessionManager::getAvatar() ?? '';
$csrfToken = SessionManager::getCsrfToken();
$cspNonce = Security::nonce();
$baseUrl = Url::basePath();
$wsUrl = Url::websocket((int) Config::get('websocket.port', 8182));

// Preferencias salvas no banco para este navegador (fallback do localStorage)
Preferences::key();
$prefsJson = json_encode(
    (object) Preferences::load(),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?: '{}';

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Grape — conversas com voz, vídeo e compartilhamento de tela">
    <meta name="theme-color" content="#0e0a1c">
    <link rel="icon" type="image/png" href="<?= Url::asset('assets/img/logo-grape.png') ?>">
    <title>Grape</title>
    <link rel="stylesheet" href="<?= Url::asset('assets/css/main.css') ?>">
    <link rel="stylesheet" href="<?= Url::asset('assets/css/theme.css') ?>">
    <link rel="stylesheet" href="<?= Url::asset('assets/css/home.css') ?>">
</head>
<body class="theme-grape page-home">
    <div class="deco" aria-hidden="true">
        <span class="blob blob-tl"></span>
        <span class="blob blob-tr"></span>
        <span class="blob blob-br"></span>
        <img class="deco-grapes" src="<?= Url::asset('assets/img/logo-grape.png') ?>" alt="">
    </div>
    <div class="app-container">
        <header class="header">
            <div class="header-content">
                <div class="logo">
                    <img class="logo-mark" src="<?= Url::asset('assets/img/logo-grape.png') ?>" width="44" height="43" alt="">
                    <span class="logo-text">grape</span>
                </div>
                <nav class="header-nav">
                    <button type="button" class="nav-link" id="sounds-btn" aria-label="Personalizar sons" title="Personalizar sons">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M9 18V5l12-2v13"/>
                            <circle cx="6" cy="18" r="3"/>
                            <circle cx="18" cy="16" r="3"/>
                        </svg>
                    </button>
                    <button type="button" class="nav-link" id="settings-btn" aria-label="Configurações" title="Configurações">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="12" cy="12" r="3"/>
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                        </svg>
                    </button>
                </nav>
            </div>
        </header>

        <main class="main-content">
            <h1 class="sr-only">Grape</h1>
            <div class="home-grid">
                <!-- Perfil -->
                <section class="profile-card" id="profile-card" aria-labelledby="profile-title">
                    <h2 class="section-title" id="profile-title">Perfil</h2>
                    <div class="profile-grid">
                        <div class="profile-identity">
                            <div class="avatar-preview" id="avatar-preview" aria-hidden="true">
                                <span id="avatar-initials"><?= $displayName ? strtoupper($displayName[0]) : '?' ?></span>
                            </div>
                            <div class="form-group">
                                <label for="display-name" class="sr-only">Nome</label>
                                <input
                                    type="text"
                                    id="display-name"
                                    class="form-input"
                                    placeholder="Seu nome"
                                    value="<?= htmlspecialchars($displayName) ?>"
                                    maxlength="50"
                                >
                            </div>

                            <div class="avatar-selector">
                                <input type="file" id="avatar-upload" accept="image/*" hidden>
                                <button type="button" class="avatar-upload-btn" id="custom-avatar-btn">
                                    <span class="avatar-upload-icon" aria-hidden="true"></span>
                                    Enviar foto
                                </button>
                            </div>
                        </div>

                        <div class="profile-preferences" role="group" aria-label="Preferências de áudio">
                            <div class="preferences-grid">
                                <label class="preference-item">
                                    <input type="checkbox" id="auto-mute" checked>
                                    <span>Mudo ao entrar</span>
                                </label>
                                <label class="preference-item">
                                    <input type="checkbox" id="noise-suppression" checked>
                                    <span>Supressão de ruído</span>
                                </label>
                                <label class="preference-item">
                                    <input type="checkbox" id="echo-cancellation" checked>
                                    <span>Cancelamento de eco</span>
                                </label>
                            </div>
                            <label class="preference-switch" for="compact-mode">
                                <input type="checkbox" id="compact-mode" role="switch">
                                <span class="switch-track" aria-hidden="true"><span class="switch-thumb"></span></span>
                                <span class="switch-label">
                                    Modo compacto
                                    <small>Visual minimalista, sem animações</small>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div class="profile-footer">
                        <button type="button" class="btn btn-primary btn-full btn-lg btn-arrow" id="save-profile-btn">Salvar</button>
                    </div>
                </section>

                <!-- Sala: criar / entrar -->
                <section class="room-card" aria-labelledby="room-title">
                    <h2 class="section-title" id="room-title">Sala</h2>

                    <div class="tiles">
                        <article class="action-card tile" id="create-room-card" aria-label="Criar sala">
                            <div class="create-fields">
                                <div class="form-group has-icon ic-door">
                                    <label for="room-name" class="sr-only">Nome da sala</label>
                                    <input type="text" id="room-name" class="form-input" placeholder="Nome da sala" maxlength="100">
                                </div>
                                <div class="form-group has-icon ic-users">
                                    <label for="max-participants" class="sr-only">Máximo de participantes</label>
                                    <input type="number" id="max-participants" class="form-input" value="8" min="2" max="10" title="Máximo de participantes">
                                </div>
                            </div>
                            <button type="button" class="btn btn-primary btn-full btn-lg btn-create" id="create-room-btn">Criar sala</button>
                        </article>

                        <div class="divider" aria-hidden="true"><span>ou</span></div>

                        <article class="action-card tile" id="join-room-card" aria-label="Entrar na sala">
                            <div class="join-row">
                                <div class="form-group has-icon ic-hash">
                                    <label for="invite-code" class="sr-only">Código da sala</label>
                                    <input type="text" id="invite-code" class="form-input form-input-code" placeholder="Código da sala" maxlength="12" autocomplete="off">
                                </div>
                                <button type="button" class="btn btn-secondary btn-arrow" id="join-room-btn">Entrar</button>
                            </div>
                        </article>
                    </div>
                </section>
            </div>
        </main>

        <!-- Settings Modal -->
        <div class="modal-overlay" id="settings-modal" role="dialog" aria-modal="true" aria-labelledby="settings-title">
            <div class="modal panel">
                <div class="modal-header">
                    <h2 id="settings-title">Configurações</h2>
                    <button class="modal-close" id="settings-close" aria-label="Fechar">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="settings-section" id="devices-section">
                        <div class="settings-section-head">
                            <h3>Dispositivos</h3>
                            <button type="button" class="btn btn-ghost btn-sm" id="devices-refresh-btn">Atualizar lista</button>
                        </div>
                        <p class="settings-hint" id="devices-summary" aria-live="polite">Procurando dispositivos…</p>
                        <div class="device-permission" id="device-permission-row" hidden>
                            <p>Permita o acesso à câmera e ao microfone para ver os nomes completos dos aparelhos.</p>
                            <button type="button" class="btn btn-secondary btn-sm" id="device-permission-btn">Permitir acesso</button>
                        </div>
                        <div class="device-list-panel" id="device-list" aria-live="polite"></div>
                        <p class="settings-hint">A lista é atualizada automaticamente quando você conecta ou desconecta um dispositivo.</p>
                        <div class="form-group">
                            <label for="camera-select" class="form-label">Câmera</label>
                            <select id="camera-select" class="form-select"></select>
                        </div>
                        <div class="form-group">
                            <label for="mic-select" class="form-label">Microfone</label>
                            <select id="mic-select" class="form-select"></select>
                        </div>
                        <div class="form-group">
                            <label for="speaker-select" class="form-label">Alto-falante</label>
                            <select id="speaker-select" class="form-select"></select>
                        </div>
                        <button type="button" class="btn btn-secondary" id="test-media-btn">Testar dispositivos</button>
                    </div>

                    <div class="settings-section">
                        <h3>Vídeo</h3>
                        <div class="form-group">
                            <label for="video-quality" class="form-label">Resolução</label>
                            <select id="video-quality" class="form-select">
                                <option value="auto">Automático</option>
                                <option value="1080p">1080p</option>
                                <option value="720p">720p</option>
                                <option value="480p">480p</option>
                                <option value="360p">360p</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="max-bitrate" class="form-label">Taxa de bits máxima</label>
                            <input type="range" id="max-bitrate" class="form-range" min="500" max="5000" step="500" value="2500">
                            <span id="max-bitrate-value">2500 kbps</span>
                        </div>
                    </div>

                    <div class="settings-section">
                        <h3>Certificado HTTPS</h3>
                        <div class="ca-status" id="ca-status" data-state="checking">
                            <span class="ca-dot" aria-hidden="true"></span>
                            <div class="ca-status-text">
                                <strong id="ca-title">Verificando…</strong>
                                <small id="ca-detail"></small>
                            </div>
                            <button type="button" class="btn btn-secondary ca-check" id="ca-check-btn">Verificar</button>
                        </div>
                        <p class="settings-hint" id="ca-hint" hidden></p>
                        <div class="ca-paths" id="ca-paths" hidden>
                            <div>
                                <span>Certificado</span>
                                <code id="ca-cert-path"></code>
                            </div>
                            <div id="ca-installer-row" hidden>
                                <span>Instalador</span>
                                <code id="ca-installer-path"></code>
                            </div>
                        </div>
                    </div>

                    <div class="settings-section section-data">
                        <h3>Dados</h3>
                        <p class="settings-hint">Apaga perfil, preferências, dispositivos e sons personalizados salvos neste navegador. Não dá para desfazer.</p>
                        <button type="button" class="btn btn-danger" id="reset-data-btn">Apagar dados</button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="settings-cancel">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="settings-save">Salvar</button>
                </div>
            </div>
        </div>

        <!-- Sounds Modal -->
        <div class="modal-overlay" id="sounds-modal" role="dialog" aria-modal="true" aria-labelledby="sounds-title">
            <div class="modal panel sounds-modal">
                <div class="modal-header">
                    <h2 id="sounds-title">Sons da sala</h2>
                    <button class="modal-close" id="sounds-close" aria-label="Fechar">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
                <div class="modal-body">
                    <p class="settings-hint">Personalize os avisos de entrada, saída, mute e desmute. Cada arquivo pode ter até 5 MB. As mudanças são aplicadas na hora; remover restaura o som padrão.</p>

                    <div class="sound-settings-list" id="sound-settings-list">
                        <div class="sound-row" data-sound-row="join">
                            <div class="sound-row-info">
                                <span class="sound-row-title">Entrar na sala</span>
                                <span class="sound-row-status" data-sound-status>Som padrão</span>
                            </div>
                            <div class="sound-row-actions">
                                <button type="button" class="btn btn-secondary btn-sm" data-sound-preview>Ouvir</button>
                                <button type="button" class="btn btn-secondary btn-sm" data-sound-upload>Escolher áudio</button>
                                <button type="button" class="btn btn-ghost btn-sm" data-sound-remove hidden>Remover</button>
                                <input type="file" accept="audio/*,.mp3,.wav,.ogg,.m4a,.aac" data-sound-input hidden>
                            </div>
                        </div>
                        <div class="sound-row" data-sound-row="leave">
                            <div class="sound-row-info">
                                <span class="sound-row-title">Sair da sala</span>
                                <span class="sound-row-status" data-sound-status>Som padrão</span>
                            </div>
                            <div class="sound-row-actions">
                                <button type="button" class="btn btn-secondary btn-sm" data-sound-preview>Ouvir</button>
                                <button type="button" class="btn btn-secondary btn-sm" data-sound-upload>Escolher áudio</button>
                                <button type="button" class="btn btn-ghost btn-sm" data-sound-remove hidden>Remover</button>
                                <input type="file" accept="audio/*,.mp3,.wav,.ogg,.m4a,.aac" data-sound-input hidden>
                            </div>
                        </div>
                        <div class="sound-row" data-sound-row="screen">
                            <div class="sound-row-info">
                                <span class="sound-row-title">Transmitir tela</span>
                                <span class="sound-row-status" data-sound-status>Som padrão</span>
                            </div>
                            <div class="sound-row-actions">
                                <button type="button" class="btn btn-secondary btn-sm" data-sound-preview>Ouvir</button>
                                <button type="button" class="btn btn-secondary btn-sm" data-sound-upload>Escolher áudio</button>
                                <button type="button" class="btn btn-ghost btn-sm" data-sound-remove hidden>Remover</button>
                                <input type="file" accept="audio/*,.mp3,.wav,.ogg,.m4a,.aac" data-sound-input hidden>
                            </div>
                        </div>
                        <div class="sound-row" data-sound-row="mute">
                            <div class="sound-row-info">
                                <span class="sound-row-title">Mutar microfone</span>
                                <span class="sound-row-status" data-sound-status>Som padrão</span>
                            </div>
                            <div class="sound-row-actions">
                                <button type="button" class="btn btn-secondary btn-sm" data-sound-preview>Ouvir</button>
                                <button type="button" class="btn btn-secondary btn-sm" data-sound-upload>Escolher áudio</button>
                                <button type="button" class="btn btn-ghost btn-sm" data-sound-remove hidden>Remover</button>
                                <input type="file" accept="audio/*,.mp3,.wav,.ogg,.m4a,.aac" data-sound-input hidden>
                            </div>
                        </div>
                        <div class="sound-row" data-sound-row="unmute">
                            <div class="sound-row-info">
                                <span class="sound-row-title">Desmutar microfone</span>
                                <span class="sound-row-status" data-sound-status>Som padrão</span>
                            </div>
                            <div class="sound-row-actions">
                                <button type="button" class="btn btn-secondary btn-sm" data-sound-preview>Ouvir</button>
                                <button type="button" class="btn btn-secondary btn-sm" data-sound-upload>Escolher áudio</button>
                                <button type="button" class="btn btn-ghost btn-sm" data-sound-remove hidden>Remover</button>
                                <input type="file" accept="audio/*,.mp3,.wav,.ogg,.m4a,.aac" data-sound-input hidden>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" id="sounds-done">Concluir</button>
                </div>
            </div>
        </div>

        <!-- Toast Container -->
        <div class="toast-container" id="toast-container"></div>

        <!-- Scripts -->
        <script nonce="<?= $cspNonce ?>">
            // Configuration passed to frontend
            window.APP_CONFIG = {
                baseUrl: '<?= $baseUrl ?>',
                wsUrl: '<?= $wsUrl ?>',
                csrfToken: '<?= $csrfToken ?>',
                preferences: <?= $prefsJson ?>,
                debug: <?= Config::get('app.debug') ? 'true' : 'false' ?>,
            };
        </script>
        <script type="module" src="<?= Url::asset('assets/js/home.js') ?>"></script>
    </div>
</body>
</html>
