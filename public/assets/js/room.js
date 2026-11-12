/**
 * Gaming Calls Platform - Room Page Logic
 * Chamada WebRTC com lista de participantes e palco por participante
 * (câmera e/ou tela de quem está selecionado).
 */

import { Logger, Toast, Modal, Storage, Devices } from './utils.js';

// ============================================
// Constantes
// ============================================
// Cada conexão entre dois participantes tem 4 "canais" fixos, negociados uma
// única vez, na ordem abaixo. Assim câmera e tela viajam ao mesmo tempo e o
// lado que recebe sabe o que é cada trilha só pela posição, sem renegociar.
const ROLES = ['audio', 'camera', 'screen', 'screenAudio'];
const ROLE_KINDS = { audio: 'audio', camera: 'video', screen: 'video', screenAudio: 'audio' };

const ICONS = {
  mic: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/></svg>',
  micOff: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="1" y1="1" x2="23" y2="23"/><path d="M9 9v3a3 3 0 0 0 5.12 2.12M15 9.34V4a3 3 0 0 0-5.94-.6"/><path d="M17 16.95A7 7 0 0 1 5 12v-2m14 0v2a7 7 0 0 1-.11 1.23"/><line x1="12" y1="19" x2="12" y2="23"/></svg>',
  camera: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>',
  screen: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>',
  more: '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>',
  volumeOff: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>',
  headphonesOff: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 14v-2a9 9 0 0 1 14.5-7.1M21 12v2"/><path d="M21 15a2 2 0 0 1-2 2h-1a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h3v3zM3 15a2 2 0 0 0 2 2h1a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1H3v3z"/><line x1="1" y1="1" x2="23" y2="23"/></svg>',
  volume: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>'
};

// ============================================
// State
// ============================================
const state = {
  // Room info
  roomId: null,
  roomName: '',
  inviteCode: '',
  inviteUrl: '',
  maxParticipants: 10,

  // User info
  sessionId: '',
  displayName: '',
  avatar: '',

  // Connection
  ws: null,
  wsConnected: false,
  wsReconnectAttempts: 0,
  maxReconnectAttempts: 5,
  connection: 'connecting', // connecting | connected | disconnected
  joinedAt: null,

  // WebRTC
  peers: new Map(), // sessionId -> peer (ver createPeerConnection)
  localStream: null,
  screenStream: null,

  // Estado de mídia local (também enviado aos demais)
  media: {
    microphoneMuted: false,
    cameraMuted: true,
    screenShare: false
  },

  // Chat: contagem de mensagens não lidas e se o chat está aberto/visível.
  chatUnread: 0,
  chatViewed: false,

  // Atividade de áudio (ativo falante): sessionIds com áudio detectado no momento.
  audioActive: new Set(),
  audioMonitors: new Map(), // sessionId -> { analyser, source }
  audioCtx: null, // ÁudioContext compartilhado (Web Audio API)

  // "Ensurdecer": silencia toda a saída de áudio (como no Discord).
  // Também muta o microfone e, ao desfazer, devolve o microfone ao que estava.
  deafened: false,
  micMutedBeforeDeafen: null,

  // Participantes
  participants: new Map(), // sessionId -> dados do servidor
  order: [], // sessionIds na ordem de exibição

  // O que está no palco
  view: {
    sessionId: null,
    tab: 'camera', // camera | screen
    split: false, // câmera e tela lado a lado
    manual: false, // o usuário já escolheu alguém?
    tabPref: new Map() // sessionId -> aba escolhida manualmente
  },

  // Câmera flutuante sobre a tela (posição como fração do espaço livre: 1,1 = canto inferior direito)
  camPos: { x: 1, y: 1 },
  camHidden: new Set(), // participantes cuja câmera flutuante eu fechei

  // Settings
  settings: {
    videoQuality: 'auto',
    maxBitrate: 2500,
    frameRate: 30
  }
};

const elements = {};
let webrtcConfig = null;
let timerHandle = null;
let selfCameraCache = null; // { track, stream }
const blockedMedia = new Set(); // elementos cujo autoplay foi bloqueado

// Janela separada (nativa do sistema) com vídeo e tela
const popout = {
  mode: null,     // 'document' | 'video' | null
  win: null,      // janela do Document Picture-in-Picture
  host: null,     // raiz criada dentro da janela
  controls: {},   // botões clonados para dentro da janela
  nameEl: null
};

// ============================================
// Initialization
// ============================================
document.addEventListener('DOMContentLoaded', async () => {
  Logger.log('Room page initializing...');

  const cfg = window.APP_CONFIG;
  if (cfg) {
    state.roomId = cfg.room?.id;
    state.roomName = cfg.room?.name || '';
    state.inviteCode = cfg.room?.inviteCode || '';
    state.inviteUrl = cfg.room?.joinUrl || '';
    state.maxParticipants = cfg.room?.maxParticipants || 10;
    state.sessionId = cfg.user?.sessionId || '';
    state.displayName = cfg.user?.displayName || '';
    state.avatar = cfg.user?.avatar || '';
  }
  if (!state.inviteUrl) state.inviteUrl = window.location.href;

  // O perfil (nome/avatar) fica salvo no navegador; a sessão PHP não o conhece.
  const profile = Storage.get('profile', {});
  state.displayName = (state.displayName || profile.displayName || '').trim() || 'Jogador';
  state.avatar = state.avatar || serializeAvatar(profile.avatar);
  state.view.split = !!Storage.get('roomView', {}).split;
  const savedPos = Storage.get('cameraPos', null);
  if (savedPos && isFinite(savedPos.x) && isFinite(savedPos.y)) state.camPos = savedPos;

  cacheElements();
  loadSettings();
  setupEventListeners();
  setupMediaSession();
  setupFloatingCamera();
  updateMediaButtons();
  renderAll();
  setupMediaDevices();
  initializeWebSocket();
  initializeLocalMedia();

  Modal.init();

  Logger.log('Room page ready');
});

// ---- Avatares -------------------------------------------------------------
// O servidor guarda o avatar como texto: data URL (foto) ou JSON {type:'gradient', color}.
function serializeAvatar(avatar) {
  if (!avatar) return '';
  if (typeof avatar === 'string') return avatar;
  if (avatar.type === 'custom' && avatar.data) {
    return avatar.data;
  }
  if (avatar.type === 'gradient' && avatar.color) return JSON.stringify(avatar);
  return '';
}

function parseAvatar(data) {
  if (!data) return null;
  if (typeof data === 'object') return data;
  if (typeof data === 'string') {
    if (data.startsWith('data:image/')) return { type: 'custom', data };
    try {
      const parsed = JSON.parse(data);
      return parsed && typeof parsed === 'object' ? parsed : null;
    } catch (e) {
      return null;
    }
  }
  return null;
}

// Retorna { html, style } para preencher um elemento .user-avatar
function avatarParts(avatarData, displayName) {
  const avatar = parseAvatar(avatarData);
  const initial = displayName ? escapeHtml(displayName.trim()[0]?.toUpperCase() || '?') : '?';
  if (avatar?.type === 'custom' && /^data:image\//.test(avatar.data || '')) {
    return { html: `<img src="${escapeAttr(avatar.data)}" alt="">`, style: '' };
  }
  if (avatar?.type === 'gradient' && /^#[0-9a-f]{3,8}$/i.test(avatar.color || '')) {
    return { html: initial, style: `background:${avatar.color};color:#fff;` };
  }
  return { html: initial, style: '' };
}

function setAvatar(el, avatarData, name) {
  const parts = avatarParts(avatarData, name);
  el.innerHTML = parts.html;
  el.style.cssText = parts.style;
}

function avatarHtml(participant, extraClass = '') {
  const parts = avatarParts(participant.avatar_data, participant.display_name);
  return `<div class="user-avatar ${extraClass}" style="${escapeAttr(parts.style)}">${parts.html}</div>`;
}

function cacheElements() {
  const $ = (id) => document.getElementById(id);

  elements.room = $('room');
  elements.side = $('side');
  elements.sideOverlay = $('side-overlay');
  elements.sideToggle = $('side-toggle');
  elements.sideClose = $('side-close');
  elements.roomName = $('room-name');
  elements.statusDot = $('status-dot');
  elements.statusText = $('call-status-text');
  elements.leaveRoomBtn = $('leave-room-btn');

  elements.sideTabs = document.querySelectorAll('.side-tab');
  elements.panelPeople = $('panel-people');
  elements.panelChat = $('panel-chat');
  elements.participantCount = $('participant-count-value');
  elements.participantsList = $('participants-list');
  elements.inviteBtn = $('invite-btn');

  elements.chatMessages = $('chat-messages');
  elements.chatForm = $('chat-form');
  elements.chatInput = $('chat-input');

  elements.stageCard = $('stage-card');
  elements.stage = $('stage');
  elements.viewingAvatar = $('viewing-avatar');
  elements.viewingName = $('viewing-name');
  elements.viewTabs = $('view-tabs');
  elements.viewTabCamera = $('view-tab-camera');
  elements.viewTabScreen = $('view-tab-screen');
  elements.fullscreenBtn = $('fullscreen-btn');
  elements.popoutBtn = $('popout-btn');
  elements.popinBtn = $('popin-btn');
  elements.stageDetached = $('stage-detached');
  elements.camRestore = $('cam-restore');
  elements.panes = {
    camera: { pane: $('pane-camera'), video: $('video-camera'), empty: $('empty-camera') },
    screen: { pane: $('pane-screen'), video: $('video-screen'), empty: $('empty-screen') }
  };

  elements.toggleMicBtn = $('toggle-mic-btn');
  elements.toggleDeafenBtn = $('toggle-deafen-btn');
  elements.toggleCameraBtn = $('toggle-camera-btn');
  elements.toggleScreenBtn = $('toggle-screen-btn');
  elements.dockInviteBtn = $('dock-invite-btn');
  elements.leaveCallBtn = $('leave-call-btn');
  elements.layoutBtn = $('layout-btn');
  elements.callSettingsBtn = $('call-settings-btn');

  elements.personMenu = $('person-menu');
  elements.audioSink = $('audio-sink');

  // Settings modal
  elements.settingsClose = $('settings-close');
  elements.settingsCancel = $('settings-cancel');
  elements.settingsSave = $('settings-save');
  elements.cameraSelect = $('camera-select');
  elements.micSelect = $('mic-select');
  elements.speakerSelect = $('speaker-select');
  elements.testMediaBtn = $('test-media-btn');
  elements.videoQuality = $('video-quality');
  elements.maxBitrate = $('max-bitrate');
  elements.maxBitrateValue = $('max-bitrate-value');
  elements.frameRate = $('frame-rate');

  if (elements.roomName) elements.roomName.textContent = state.roomName;
}

function setupEventListeners() {
  // Sidebar
  elements.leaveRoomBtn?.addEventListener('click', leaveRoom);
  elements.sideToggle?.addEventListener('click', () => toggleSide(true));
  elements.sideClose?.addEventListener('click', () => toggleSide(false));
  elements.sideOverlay?.addEventListener('click', () => toggleSide(false));
  elements.sideTabs.forEach(tab => tab.addEventListener('click', () => showSidePanel(tab.dataset.panel)));
  elements.inviteBtn?.addEventListener('click', copyInviteLink);
  elements.chatForm?.addEventListener('submit', sendChatMessage);

  // Lista de participantes (delegação)
  elements.participantsList?.addEventListener('click', (e) => {
    const menuBtn = e.target.closest('[data-menu]');
    if (menuBtn) {
      e.stopPropagation();
      openPersonMenu(menuBtn.dataset.menu, menuBtn);
      return;
    }
    const row = e.target.closest('[data-select]');
    if (row) selectParticipant(row.dataset.select);
  });

  // Menu do participante
  elements.personMenu?.addEventListener('click', handleMenuClick);
  document.addEventListener('click', (e) => {
    if (!elements.personMenu.hidden && !e.target.closest('#person-menu') && !e.target.closest('[data-menu]')) {
      closePersonMenu();
    }
  });

  // Palco
  elements.viewTabs?.addEventListener('click', (e) => {
    const tab = e.target.closest('[data-view]');
    if (tab) setViewTab(tab.dataset.view);
  });
  elements.stage?.addEventListener('click', (e) => {
    const action = e.target.closest('[data-action]')?.dataset.action;
    if (action === 'toggle-camera') toggleCamera();
    if (action === 'toggle-screen') toggleScreenShare();
    if (action === 'hide-camera') setCamHidden(true);
    if (action === 'show-camera') setCamHidden(false);
  });
  elements.fullscreenBtn?.addEventListener('click', toggleFullscreen);
  elements.popoutBtn?.addEventListener('click', togglePopout);
  elements.popinBtn?.addEventListener('click', closePopout);
  if (popoutSupported()) elements.popoutBtn.hidden = false;

  // Controles
  elements.toggleMicBtn?.addEventListener('click', toggleMicrophone);
  elements.toggleDeafenBtn?.addEventListener('click', toggleDeafen);
  elements.toggleCameraBtn?.addEventListener('click', toggleCamera);
  elements.toggleScreenBtn?.addEventListener('click', toggleScreenShare);
  elements.dockInviteBtn?.addEventListener('click', copyInviteLink);
  elements.leaveCallBtn?.addEventListener('click', leaveRoom);
  elements.layoutBtn?.addEventListener('click', toggleSplit);
  elements.callSettingsBtn?.addEventListener('click', () => Modal.open('settings-modal'));

  // Settings modal
  elements.settingsClose?.addEventListener('click', () => Modal.close('settings-modal'));
  elements.settingsCancel?.addEventListener('click', () => Modal.close('settings-modal'));
  elements.settingsSave?.addEventListener('click', saveSettings);
  elements.testMediaBtn?.addEventListener('click', testMediaDevices);
  elements.maxBitrate?.addEventListener('input', (e) => {
    if (elements.maxBitrateValue) elements.maxBitrateValue.textContent = `${e.target.value} kbps`;
  });

  // Window events
  window.addEventListener('beforeunload', cleanup);
  window.addEventListener('resize', closePersonMenu);
  document.addEventListener('keydown', handleKeyboardShortcuts);

  // Se o navegador bloqueou o autoplay de algum áudio/vídeo, o primeiro clique libera
  document.addEventListener('click', unlockMedia);
  document.addEventListener('keydown', unlockMedia);
  // Resume o AudioContext do ativo falante na primeira interação do usuário
  // (exigido por navegadores antes de permitir processamento de áudio).
  document.addEventListener('click', ensureAudioContextRunning, { once: true });
  document.addEventListener('keydown', ensureAudioContextRunning, { once: true });
}

function handleKeyboardShortcuts(e) {
  if (e.key === 'Escape') {
    closePersonMenu();
    toggleSide(false);
    Modal.closeAll();
    return;
  }

  const tag = e.target.tagName;
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

  const key = e.key.toLowerCase();
  if (e.ctrlKey || e.metaKey) {
    if (key === 's' && e.shiftKey) {
      e.preventDefault();
      toggleScreenShare();
    }
    return;
  }
  if (e.altKey) return;

  switch (key) {
    case 'm':
      e.preventDefault();
      toggleMicrophone();
      break;
    case 'v':
      e.preventDefault();
      toggleCamera();
      break;
    case 'f':
      e.preventDefault();
      toggleFullscreen();
      break;
    case 'd':
      e.preventDefault();
      toggleDeafen();
      break;
    case 'p':
      if (popoutSupported()) {
        e.preventDefault();
        togglePopout();
      }
      break;
  }
}

function unlockMedia() {
  if (!blockedMedia.size) return;
  blockedMedia.forEach(el => {
    el.play().then(() => blockedMedia.delete(el)).catch(() => {});
  });
}

function playMedia(el) {
  el.play().catch(err => {
    if (err?.name === 'NotAllowedError') blockedMedia.add(el);
  });
}

// ============================================
// Settings
// ============================================
function loadSettings() {
  const saved = Storage.get('roomSettings', {});
  state.settings = { ...state.settings, ...saved };

  if (elements.videoQuality) elements.videoQuality.value = state.settings.videoQuality;
  if (elements.maxBitrate) elements.maxBitrate.value = state.settings.maxBitrate;
  if (elements.maxBitrateValue) elements.maxBitrateValue.textContent = `${state.settings.maxBitrate} kbps`;
  if (elements.frameRate) elements.frameRate.value = state.settings.frameRate;
}

function saveSettings() {
  state.settings = {
    videoQuality: elements.videoQuality?.value || 'auto',
    maxBitrate: parseInt(elements.maxBitrate?.value) || 2500,
    frameRate: parseInt(elements.frameRate?.value) || 30
  };

  Storage.set('roomSettings', state.settings);
  Toast.success('Configurações salvas!');
  Modal.close('settings-modal');

  applyVideoConstraints();
}

// ============================================
// WebSocket Connection
// ============================================
function initializeWebSocket() {
  const wsUrl = window.APP_CONFIG?.wsUrl || `${location.protocol === 'https:' ? 'wss:' : 'ws:'}//${location.hostname}:8081`;
  updateConnectionStatus('connecting');

  try {
    state.ws = new WebSocket(wsUrl);
    state.ws.binaryType = 'arraybuffer';

    state.ws.onopen = () => {
      Logger.log('WebSocket connected');
      state.wsConnected = true;
      state.wsReconnectAttempts = 0;
      updateConnectionStatus('connected');
      joinRoomViaWebSocket();
    };

    state.ws.onmessage = (event) => {
      handleWebSocketMessage(JSON.parse(event.data));
    };

    state.ws.onclose = () => {
      Logger.log('WebSocket disconnected');
      state.wsConnected = false;
      updateConnectionStatus('disconnected');
      scheduleReconnect();
    };

    state.ws.onerror = (error) => {
      Logger.error('WebSocket error:', error);
    };
  } catch (e) {
    Logger.error('Failed to create WebSocket:', e);
    scheduleReconnect();
  }
}

function scheduleReconnect() {
  if (state.wsReconnectAttempts >= state.maxReconnectAttempts) {
    Toast.error('Não foi possível reconectar. Recarregue a página.');
    return;
  }

  state.wsReconnectAttempts++;
  const delay = Math.min(1000 * Math.pow(2, state.wsReconnectAttempts), 30000);
  Logger.log(`Reconnecting in ${delay}ms (attempt ${state.wsReconnectAttempts})`);

  setTimeout(() => {
    if (!state.wsConnected) {
      initializeWebSocket();
    }
  }, delay);
}

function joinRoomViaWebSocket() {
  if (!state.ws || state.ws.readyState !== WebSocket.OPEN) return;

  state.ws.send(JSON.stringify({
    type: 'join',
    payload: {
      room_code: state.inviteCode,
      session_id: state.sessionId,
      display_name: state.displayName,
      avatar_data: state.avatar,
      // Chaves planas: são as mesmas que 'media-state' atualiza depois
      metadata: mediaMetadata()
    }
  }));
}

function mediaMetadata() {
  return {
    microphoneMuted: state.media.microphoneMuted,
    cameraMuted: state.media.cameraMuted,
    screenShare: state.media.screenShare,
    deafened: state.deafened
  };
}

function handleWebSocketMessage(message) {
  Logger.debug('WS Message:', message.type, message.payload);

  switch (message.type) {
    case 'joined':
      handleJoined(message.payload);
      break;
    case 'participant-joined':
      handleParticipantJoined(message.payload);
      break;
    case 'participant-left':
      handleParticipantLeft(message.payload);
      break;
    case 'participants-list':
      handleParticipantsList(message.payload);
      break;
    case 'offer':
      handleOffer(message.payload);
      break;
    case 'answer':
      handleAnswer(message.payload);
      break;
    case 'ice-candidate':
      handleIceCandidate(message.payload);
      break;
    case 'media-state-changed':
      handleMediaStateChanged(message.payload);
      break;
    case 'error':
      handleWebSocketError(message.payload);
      break;
    case 'chat-message':
      handleChatMessage(message.payload);
      break;
    case 'ping':
      // O servidor derruba a conexão se não responder ao ping
      sendWebSocketMessage('pong', {});
      break;
  }
}

function handleJoined(payload) {
  Logger.log('Joined room:', payload.room.name);

  state.roomName = payload.room.name;
  state.maxParticipants = payload.room.max_participants;
  elements.roomName.textContent = state.roomName;
  if (!state.joinedAt) state.joinedAt = Date.now();
  startTimer();

  // Eu primeiro, depois os demais
  const next = new Map();
  next.set(payload.participant.session_id, payload.participant);
  payload.participants.forEach(p => {
    if (p.session_id !== state.sessionId) next.set(p.session_id, p);
  });
  state.participants = next;
  state.order = Array.from(next.keys());

  initializeWebRTC(payload.webrtc_config);

  // Quem entra liga para todos que já estavam na sala
  state.order.forEach(sessionId => {
    if (sessionId !== state.sessionId) createPeerConnection(sessionId, true);
  });

  // Mostra alguém (um participante, se houver; senão eu mesmo)
  const current = state.view.sessionId;
  if (!current || !state.participants.has(current)) {
    state.view.manual = false;
    selectParticipant(firstRemoteOrSelf(), { manual: false });
  } else {
    renderAll();
  }

  Toast.success(`Entrou na sala "${escapeHtml(state.roomName)}"`);
}

function handleParticipantJoined(payload) {
  state.participants.set(payload.session_id, payload);
  if (!state.order.includes(payload.session_id)) state.order.push(payload.session_id);

  // Quem já estava na sala NÃO cria uma conexão de saída pro novo participante.
  // Apenas o participante que entrou (iniciador) cria os peer connections.
  // Essa sala espera a oferta do novo participante via handleOffer, que cria
  // o lado respondente sob demanda. Assim existe exatamente UM peer connection
  // por par de participantes (sem duplicar mídia/áudio).

  // Se eu estava sozinho olhando para mim, passa a mostrar quem chegou
  if (!state.view.manual && state.view.sessionId === state.sessionId) {
    selectParticipant(payload.session_id, { manual: false });
  } else {
    renderAll();
  }

  Toast.info(`${escapeHtml(payload.display_name)} entrou na sala`);
}

function handleParticipantLeft(payload) {
  const { session_id: sessionId } = payload;
  const participant = state.participants.get(sessionId);

  state.participants.delete(sessionId);
  state.order = state.order.filter(id => id !== sessionId);
  state.view.tabPref.delete(sessionId);
  closePeer(sessionId);

  if (state.view.sessionId === sessionId) {
    state.view.manual = false;
    selectParticipant(firstRemoteOrSelf(), { manual: false });
  } else {
    renderAll();
  }

  if (participant) Toast.info(`${escapeHtml(participant.display_name)} saiu da sala`);
}

function handleParticipantsList(payload) {
  payload.participants.forEach(p => {
    if (p.session_id === state.sessionId) return;
    state.participants.set(p.session_id, p);
    if (!state.order.includes(p.session_id)) state.order.push(p.session_id);
  });
  renderAll();
}

function handleMediaStateChanged(payload) {
  const { session_id: sessionId, state: mediaState } = payload;
  const participant = state.participants.get(sessionId);
  if (!participant) return;

  const before = flagsOf(sessionId);
  participant.metadata = { ...participant.metadata, ...mediaState };
  autoAdjustTab(sessionId, before, flagsOf(sessionId));

  renderParticipants();
  if (state.view.sessionId === sessionId) renderStage();
}

function handleWebSocketError(payload) {
  Toast.error(`${escapeHtml(payload.code)}: ${escapeHtml(payload.message)}`);
}

function sendWebSocketMessage(type, payload) {
  if (state.ws && state.ws.readyState === WebSocket.OPEN) {
    state.ws.send(JSON.stringify({ type, payload }));
  }
}

function broadcastMediaState() {
  sendWebSocketMessage('media-state', mediaMetadata());
}

function updateConnectionStatus(status) {
  state.connection = status;
  elements.statusDot?.classList.remove('connected', 'connecting', 'offline');
  elements.statusDot?.classList.add(status === 'connected' ? 'connected' : status === 'connecting' ? 'connecting' : 'offline');
  refreshStatusText();
}

// ============================================
// Cronômetro / status da chamada
// ============================================
function startTimer() {
  if (timerHandle) return;
  timerHandle = setInterval(refreshStatusText, 1000);
  refreshStatusText();
}

function formatClock(totalSeconds) {
  const h = Math.floor(totalSeconds / 3600);
  const m = Math.floor((totalSeconds % 3600) / 60);
  const s = totalSeconds % 60;
  return [h, m, s].map(n => String(n).padStart(2, '0')).join(':');
}

function refreshStatusText() {
  if (!elements.statusText) return;
  if (state.connection === 'disconnected') {
    elements.statusText.textContent = 'Reconectando...';
  } else if (state.connection === 'connected' && state.joinedAt) {
    elements.statusText.textContent = formatClock(Math.floor((Date.now() - state.joinedAt) / 1000));
  } else {
    elements.statusText.textContent = 'Conectando...';
  }
}

// ============================================
// WebRTC
// ============================================
function initializeWebRTC(config) {
  webrtcConfig = config;
  initAudioMonitor();
  requestAnimationFrame(updateAudioActivity);
  Logger.log('WebRTC config:', config);
}

function createPeerConnection(remoteSessionId, isInitiator) {
  const existing = state.peers.get(remoteSessionId);
  if (existing) return existing;

  const pc = new RTCPeerConnection(webrtcConfig || window.APP_CONFIG?.webrtc);
  const peer = {
    id: remoteSessionId,
    pc,
    roles: {}, // papel -> RTCRtpTransceiver
    cameraStream: null, // MediaStream remoto só com a câmera
    screenStream: null, // MediaStream remoto com a tela (+ áudio da tela)
    audioEl: null,
    localMuted: false, // silenciado só para mim
    pendingIce: []
  };
  state.peers.set(remoteSessionId, peer);

  pc.ontrack = (event) => handleRemoteTrack(peer, event);

  pc.onicecandidate = (event) => {
    if (event.candidate) {
      sendWebSocketMessage('ice-candidate', {
        target_session_id: remoteSessionId,
        data: event.candidate.toJSON()
      });
    }
  };

  pc.onconnectionstatechange = () => {
    Logger.log(`PC state with ${remoteSessionId}:`, pc.connectionState);
    renderParticipants();
    if (state.view.sessionId === remoteSessionId) renderStage();
  };

  if (isInitiator) {
    // Quem liga define os 4 canais, sempre na mesma ordem (ROLES)
    ROLES.forEach(role => {
      peer.roles[role] = pc.addTransceiver(ROLE_KINDS[role], { direction: 'sendrecv' });
    });
    syncPeerSenders(peer);
    createOffer(peer);
  }

  return peer;
}

function closePeer(sessionId) {
  const peer = state.peers.get(sessionId);
  if (!peer) return;
  peer.pc.close();
  stopAudioMonitor(sessionId);
  if (peer.audioEl) {
    peer.audioEl.srcObject = null;
    peer.audioEl.remove();
    blockedMedia.delete(peer.audioEl);
  }
  state.peers.delete(sessionId);
}

// Trilhas que eu estou enviando agora, por papel
function getLocalTracks() {
  return {
    audio: state.localStream?.getAudioTracks()[0] || null,
    camera: state.localStream?.getVideoTracks()[0] || null,
    screen: state.screenStream?.getVideoTracks()[0] || null,
    screenAudio: state.screenStream?.getAudioTracks()[0] || null
  };
}

function syncPeerSenders(peer) {
  const tracks = getLocalTracks();
  ROLES.forEach(role => {
    const sender = peer.roles[role]?.sender;
    if (!sender) return;
    const track = tracks[role];
    if ((sender.track || null) === track) return;
    sender.replaceTrack(track).catch(e => Logger.warn(`replaceTrack(${role}) failed:`, e));
  });
}

function syncAllPeers() {
  state.peers.forEach(syncPeerSenders);
}

async function createOffer(peer) {
  try {
    const offer = await peer.pc.createOffer();
    await peer.pc.setLocalDescription(offer);
    sendWebSocketMessage('offer', {
      target_session_id: peer.id,
      data: peer.pc.localDescription
    });
  } catch (e) {
    Logger.error('Create offer failed:', e);
  }
}

function handleOffer(payload) {
  const peer = createPeerConnection(payload.from_session_id, false);
  receiveOffer(peer, payload.data);
}

async function receiveOffer(peer, offer) {
  const pc = peer.pc;
  try {
    await pc.setRemoteDescription(new RTCSessionDescription(offer));

    // Os canais chegam na ordem em que quem ligou os criou
    const transceivers = pc.getTransceivers();
    ROLES.forEach((role, i) => {
      const t = transceivers[i];
      if (!t) return;
      peer.roles[role] = t;
      t.direction = 'sendrecv';
    });
    syncPeerSenders(peer);
    await flushPendingIce(peer);

    const answer = await pc.createAnswer();
    await pc.setLocalDescription(answer);
    sendWebSocketMessage('answer', {
      target_session_id: peer.id,
      data: pc.localDescription
    });
  } catch (e) {
    Logger.error('Receive offer failed:', e);
  }
}

async function handleAnswer(payload) {
  const peer = state.peers.get(payload.from_session_id);
  if (!peer) return;
  try {
    await peer.pc.setRemoteDescription(new RTCSessionDescription(payload.data));
    await flushPendingIce(peer);
  } catch (e) {
    Logger.error('Receive answer failed:', e);
  }
}

async function handleIceCandidate(payload) {
  const peer = state.peers.get(payload.from_session_id);
  if (!peer) return;
  // Candidatos podem chegar antes da oferta/resposta; guarda até poder usar
  if (!peer.pc.remoteDescription) {
    peer.pendingIce.push(payload.data);
    return;
  }
  try {
    await peer.pc.addIceCandidate(new RTCIceCandidate(payload.data));
  } catch (e) {
    Logger.error('Add ICE candidate failed:', e);
  }
}

async function flushPendingIce(peer) {
  const queue = peer.pendingIce.splice(0);
  for (const candidate of queue) {
    try {
      await peer.pc.addIceCandidate(new RTCIceCandidate(candidate));
    } catch (e) {
      Logger.error('Add queued ICE candidate failed:', e);
    }
  }
}

function handleRemoteTrack(peer, event) {
  const role = ROLES[peer.pc.getTransceivers().indexOf(event.transceiver)];
  if (!role) return;
  Logger.log(`Remote ${role} track from ${peer.id}`);

  const track = event.track;
  switch (role) {
    case 'audio': {
      const audio = ensureAudioEl(peer);
      audio.srcObject = new MediaStream([track]);
      playMedia(audio);
      // Monitora o áudio remoto para o indicador de ativo falante.
      startAudioMonitor(peer.id, audio.srcObject);
      break;
    }
    case 'camera':
      peer.cameraStream = new MediaStream([track]);
      break;
    case 'screen':
    case 'screenAudio':
      if (!peer.screenStream) peer.screenStream = new MediaStream();
      peer.screenStream.addTrack(track);
      break;
  }

  if (state.view.sessionId === peer.id) renderStage();
}

// Áudio de cada participante toca sempre, independente de qual aba está aberta
function ensureAudioEl(peer) {
  if (!peer.audioEl) {
    const audio = document.createElement('audio');
    audio.autoplay = true;
    audio.muted = peer.localMuted || state.deafened;
    elements.audioSink.appendChild(audio);
    peer.audioEl = audio;
  }
  return peer.audioEl;
}

// ============================================
// Detecção de áudio ativo (ativo falante)
// ============================================
// Usa Web Audio API (AnalyserNode) para medir o nível de áudio de cada
// participante remotamente e exibir uma borda verde enquanto fala.
// A detecção é independente por participante e consome pouca CPU.
const AUDIO_ACTIVITY_THRESHOLD = 0.02; // amplitude RMS mínima (0 a 1)
const AUDIO_ACTIVITY_INTERVAL = 250; // ms
const AUDIO_SILENCE_FRAMES = 3; // frames consecutivos abaixo do threshold para considerar silencioso
let audioLastCheck = 0;
let audioContextResumed = false;
const audioSilenceCount = new Map(); // sessionId -> frames silenciosos consecutivos

/** Resume o AudioContext na primeira interação do usuário (exigido por navegadores). */
function ensureAudioContextRunning() {
  if (!state.audioCtx) return;
  if (audioContextResumed) return;
  if (state.audioCtx.state === 'running') {
    audioContextResumed = true;
    return;
  }
  state.audioCtx.resume().then(() => {
    audioContextResumed = true;
    Logger.log('AudioContext ativo falante: resumed');
  }).catch(() => {
    // Não marca como resumido: tentará novamente no próximo frame/interação.
    audioContextResumed = false;
  });
}

function initAudioMonitor() {
  if (state.audioCtx) return;
  try {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return;
    state.audioCtx = new Ctx();
  } catch (e) {
    Logger.warn('ÁudioContext não disponível para ativo falante:', e);
  }
}

function startAudioMonitor(sessionId, stream) {
  if (!state.audioCtx || !stream) return;
  stopAudioMonitor(sessionId);
  try {
    const source = state.audioCtx.createMediaStreamSource(stream);
    const analyser = state.audioCtx.createAnalyser();
    analyser.fftSize = 2048;
    source.connect(analyser);
    state.audioMonitors.set(sessionId, { analyser, source });
  } catch (e) {
    Logger.warn('Falha ao iniciar monitor de áudio para', sessionId, e);
  }
}

function stopAudioMonitor(sessionId) {
  const mon = state.audioMonitors.get(sessionId);
  if (mon) {
    try {
      mon.source.disconnect();
    } catch (e) { /* ignore */ }
    state.audioMonitors.delete(sessionId);
  }
  state.audioActive.delete(sessionId);
  audioSilenceCount.delete(sessionId);
}

function startLocalAudioMonitor() {
  if (!state.audioCtx) return;
  const audioTracks = state.localStream?.getAudioTracks() || [];
  if (audioTracks.length === 0) return;
  const localAudioStream = new MediaStream(audioTracks);
  startAudioMonitor(state.sessionId, localAudioStream);
}

function stopLocalAudioMonitor() {
  stopAudioMonitor(state.sessionId);
}

function updateAudioActivity() {
  ensureAudioContextRunning();

  if (!state.audioCtx) {
    requestAnimationFrame(updateAudioActivity);
    return;
  }

  if (state.audioMonitors.size === 0) {
    if (state.audioActive.size) {
      state.audioActive.clear();
      renderParticipants();
      if (state.view.sessionId) renderStage();
    }
    requestAnimationFrame(updateAudioActivity);
    return;
  }

  const now = performance.now();
  if (now - audioLastCheck < AUDIO_ACTIVITY_INTERVAL) {
    requestAnimationFrame(updateAudioActivity);
    return;
  }
  audioLastCheck = now;

  const nowActive = new Set();
  for (const [sessionId, { analyser }] of state.audioMonitors) {
    const uint8 = new Uint8Array(analyser.fftSize);
    analyser.getByteTimeDomainData(uint8);
    let sum = 0;
    for (let i = 0; i < uint8.length; i++) {
      const v = (uint8[i] - 128) / 128; // -1 .. 1
      sum += v * v;
    }
    const rms = Math.sqrt(sum / uint8.length);
    const wasActive = state.audioActive.has(sessionId);
    const silenceCount = audioSilenceCount.get(sessionId) || 0;

    if (rms > AUDIO_ACTIVITY_THRESHOLD) {
      nowActive.add(sessionId);
      audioSilenceCount.delete(sessionId);
    } else if (wasActive && silenceCount >= AUDIO_SILENCE_FRAMES) {
      // Já ficou silencioso o suficiente: remove do ativo falante
      audioSilenceCount.delete(sessionId);
    } else if (wasActive) {
      // Ainda no período de histerese: mantém como ativo por enquanto
      nowActive.add(sessionId);
      audioSilenceCount.set(sessionId, silenceCount + 1);
    } else {
      // Nunca esteve ativo: garante que não entre por ruído
      audioSilenceCount.set(sessionId, silenceCount + 1);
    }
  }

  const changed =
    nowActive.size !== state.audioActive.size ||
    [...nowActive].some((id) => !state.audioActive.has(id));
  if (changed) {
    state.audioActive = nowActive;
    renderParticipants();
    if (state.view.sessionId) renderStage();
  }

  requestAnimationFrame(updateAudioActivity);
}

// ============================================
// Media Handling
// ============================================
async function initializeLocalMedia() {
  const devicePrefs = Storage.get('devices', {});
  const prefs = Storage.get('profile', {});

  try {
    const constraints = await Devices.getConstraints(true, true, {
      width: 1280,
      height: 720,
      frameRate: 30,
      deviceId: devicePrefs.cameraId || undefined,
      audioDeviceId: devicePrefs.micId || undefined
    });

    // Só abre a câmera se ela deve começar ligada; senão a luz nem acende
    if (prefs.preferences?.autoVideo !== true) delete constraints.video;

    try {
      state.localStream = await navigator.mediaDevices.getUserMedia(constraints);
    } catch (err) {
      if (!constraints.video) throw err;
      // Câmera indisponível/negada: entra só com áudio
      Logger.warn('Camera unavailable, joining audio-only:', err);
      delete constraints.video;
      state.localStream = await navigator.mediaDevices.getUserMedia(constraints);
      Toast.warning('Câmera indisponível. Entrando só com áudio.');
    }

    if (prefs.preferences?.autoMute) {
      state.localStream.getAudioTracks().forEach(t => t.enabled = false);
      state.media.microphoneMuted = true;
    }

    navigator.mediaDevices.addEventListener('devicechange', handleDeviceChange);
    Logger.log('Local media initialized');
  } catch (e) {
    Logger.error('Failed to initialize local media:', e);
    Toast.error(`Erro ao acessar mídia: ${escapeHtml(e.message)}`);
    // Sem microfone ainda dá para ligar a câmera depois
    state.localStream = new MediaStream();
    state.media.microphoneMuted = true;
  }

  state.media.cameraMuted = state.localStream.getVideoTracks().length === 0;
  updateMediaButtons();
  syncAllPeers();
  broadcastMediaState();

  // Inicia o monitor de áudio local (próprio microfone) para o ativo falante
  startLocalAudioMonitor();

  renderAll();
}

function handleDeviceChange() {
  Devices.enumerate().then(devices => {
    Devices.populateSelect(elements.cameraSelect, devices.cameras);
    Devices.populateSelect(elements.micSelect, devices.microphones);
    Devices.populateSelect(elements.speakerSelect, devices.speakers);
  });
}

async function setupMediaDevices() {
  const devices = await Devices.enumerate();
  Devices.populateSelect(elements.cameraSelect, devices.cameras);
  Devices.populateSelect(elements.micSelect, devices.microphones);
  Devices.populateSelect(elements.speakerSelect, devices.speakers);
}

function updateMediaButtons() {
  elements.toggleMicBtn?.setAttribute('aria-pressed', String(state.media.microphoneMuted));
  elements.toggleCameraBtn?.setAttribute('aria-pressed', String(state.media.cameraMuted));
  elements.toggleScreenBtn?.setAttribute('aria-pressed', String(state.media.screenShare));
  elements.toggleDeafenBtn?.setAttribute('aria-pressed', String(state.deafened));
  elements.layoutBtn?.setAttribute('aria-pressed', String(state.view.split));
  elements.popoutBtn?.setAttribute('aria-pressed', String(!!popout.mode));
  elements.popoutBtn?.setAttribute('title', popout.mode ? 'Trazer de volta (P)' : 'Janela flutuante (P)');
  syncPopoutControls();
  syncMediaSession();
}

// Liga/desliga o envio do microfone. Retorna false se não há microfone.
function setMicMuted(muted) {
  const tracks = state.localStream?.getAudioTracks() || [];
  if (tracks.length === 0) return false;
  tracks.forEach(track => track.enabled = !muted);
  state.media.microphoneMuted = muted;
  return true;
}

function toggleMicrophone() {
  if (!state.localStream?.getAudioTracks().length) {
    Toast.warning('Nenhum microfone disponível.');
    return;
  }

  // Como no Discord: abrir o microfone enquanto ensurdecido também volta o áudio
  if (state.deafened) {
    state.deafened = false;
    state.micMutedBeforeDeafen = null;
    setMicMuted(false);
    applyOutputMute();
  } else {
    setMicMuted(!state.media.microphoneMuted);
  }

  updateMediaButtons();
  broadcastMediaState();
  renderParticipants();
}

// Silencia (ou devolve) tudo o que eu ouço: vozes e áudio da tela de quem transmite
function toggleDeafen() {
  if (!state.deafened) {
    state.deafened = true;
    state.micMutedBeforeDeafen = state.media.microphoneMuted;
    setMicMuted(true);
    Toast.info('Áudio e microfone silenciados');
  } else {
    state.deafened = false;
    if (state.micMutedBeforeDeafen === false) setMicMuted(false);
    state.micMutedBeforeDeafen = null;
    Toast.info('Áudio reativado');
  }

  applyOutputMute();
  updateMediaButtons();
  broadcastMediaState();
  renderParticipants();
}

function applyOutputMute() {
  state.peers.forEach(peer => {
    if (peer.audioEl) peer.audioEl.muted = peer.localMuted || state.deafened;
  });
  renderStage(); // o áudio da tela é o do próprio <video>
}

async function toggleCamera() {
  if (!state.localStream) state.localStream = new MediaStream();

  try {
    if (state.localStream.getVideoTracks().length > 0) {
      stopCamera();
    } else {
      await startCamera();
    }
  } catch (e) {
    Logger.error('Toggle camera failed:', e);
    Toast.error(`Erro ao ligar a câmera: ${escapeHtml(e.message)}`);
    return;
  }

  updateMediaButtons();
  syncAllPeers();
  broadcastMediaState();
  renderAll();
}

// Desligar de verdade: stop() libera o dispositivo e apaga a luz da câmera
function stopCamera() {
  state.localStream.getVideoTracks().forEach(track => {
    track.stop();
    state.localStream.removeTrack(track);
  });
  state.media.cameraMuted = true;
}

async function startCamera() {
  const constraints = await Devices.getConstraints(true, false, {
    width: 1280,
    height: 720,
    frameRate: state.settings.frameRate || 30,
    deviceId: Storage.get('devices', {}).cameraId || undefined
  });
  const stream = await navigator.mediaDevices.getUserMedia(constraints);
  state.localStream.addTrack(stream.getVideoTracks()[0]);
  state.media.cameraMuted = false;

  // Quem acabou de ligar a câmera quer se ver
  if (state.view.sessionId === state.sessionId && !state.media.screenShare && !state.view.tabPref.has(state.sessionId)) {
    state.view.tab = 'camera';
  }
}

async function toggleScreenShare() {
  if (state.media.screenShare) {
    stopScreenShare();
  } else {
    await startScreenShare();
  }
}

async function startScreenShare() {
  let stream;
  try {
    stream = await navigator.mediaDevices.getDisplayMedia({
      video: { frameRate: { ideal: state.settings.frameRate || 30 } },
      audio: true,
      preferCurrentTab: false,
      selfBrowserSurface: 'exclude',
      systemAudio: 'include',
      surfaceSwitching: 'include',
      monitorTypeSurfaces: 'include'
    });
  } catch (e) {
    Logger.error('Screen share failed:', e);
    if (e.name !== 'AbortError' && e.name !== 'NotAllowedError') {
      Toast.error(`Erro ao compartilhar tela: ${escapeHtml(e.message)}`);
    }
    return;
  }

  // Se já estava compartilhando, encerra o compartilhamento anterior
  // (track replacement: substitui a trilha de tela em vez de duplicar).
  if (state.screenStream) {
    stopScreenShare();
  }

  state.screenStream = stream;
  state.media.screenShare = true;

  // O botão "Parar compartilhamento" do navegador também encerra
  const screenVideoTrack = stream.getVideoTracks()[0];
  if (screenVideoTrack) {
    screenVideoTrack.addEventListener('ended', () => {
      if (state.screenStream === stream) stopScreenShare();
    });
  }

  if (state.view.sessionId === state.sessionId) {
    state.view.tab = 'screen';
    state.view.tabPref.delete(state.sessionId);
  }

  updateMediaButtons();
  syncAllPeers();
  broadcastMediaState();
  renderAll();
  Toast.success('Compartilhamento de tela iniciado');
}

function stopScreenShare() {
  if (!state.screenStream) return;

  state.screenStream.getTracks().forEach(track => track.stop());
  state.screenStream = null;
  state.media.screenShare = false;

  if (state.view.sessionId === state.sessionId && state.view.tab === 'screen') {
    state.view.tab = 'camera';
  }

  updateMediaButtons();
  syncAllPeers();
  broadcastMediaState();
  renderAll();
  Toast.info('Compartilhamento de tela finalizado');
}

async function applyVideoConstraints() {
  const quality = state.settings.videoQuality;
  const dims = { '1080p': [1920, 1080], '720p': [1280, 720], '480p': [854, 480], '360p': [640, 360] }[quality] || [1280, 720];

  try {
    const track = state.localStream?.getVideoTracks()[0];
    if (track) {
      await track.applyConstraints({
        width: { ideal: dims[0] },
        height: { ideal: dims[1] },
        frameRate: { ideal: state.settings.frameRate }
      });
    }
  } catch (e) {
    Logger.error('Apply video constraints failed:', e);
  }

  // Limites de envio para câmera e tela
  state.peers.forEach(peer => {
    ['camera', 'screen'].forEach(role => {
      const sender = peer.roles[role]?.sender;
      if (!sender || !sender.track) return;
      const parameters = sender.getParameters();
      if (!parameters.encodings || !parameters.encodings.length) parameters.encodings = [{}];
      parameters.encodings[0].maxBitrate = state.settings.maxBitrate * 1000;
      parameters.encodings[0].maxFramerate = state.settings.frameRate;
      sender.setParameters(parameters).catch(e => Logger.warn('Set parameters failed:', e));
    });
  });
}

async function testMediaDevices() {
  const constraints = await Devices.getConstraints(true, true, {
    deviceId: elements.cameraSelect.value || undefined,
    audioDeviceId: elements.micSelect.value || undefined
  });

  try {
    const stream = await navigator.mediaDevices.getUserMedia(constraints);
    stream.getTracks().forEach(track => track.stop());
    Toast.success('Dispositivos funcionando corretamente!');
  } catch (e) {
    Logger.error('Media test failed:', e);
    Toast.error(`Erro ao testar dispositivos: ${escapeHtml(e.message)}`);
  }
}

// ============================================
// Estado dos participantes
// ============================================
// O que cada um está transmitindo / se o microfone está aberto
function flagsOf(sessionId) {
  if (sessionId === state.sessionId) {
    return {
      mic: !state.media.microphoneMuted && !!state.localStream?.getAudioTracks().length,
      camera: !state.media.cameraMuted && !!state.localStream?.getVideoTracks().length,
      screen: state.media.screenShare,
      deafened: state.deafened
    };
  }
  const meta = state.participants.get(sessionId)?.metadata || {};
  return {
    mic: meta.microphoneMuted !== true,
    camera: meta.cameraMuted === false,
    screen: meta.screenShare === true,
    deafened: meta.deafened === true
  };
}

function firstRemoteOrSelf() {
  return state.order.find(id => id !== state.sessionId) || state.sessionId;
}

// Quando o participante em exibição começa/para de compartilhar, acompanha
function autoAdjustTab(sessionId, before, after) {
  if (state.view.sessionId !== sessionId) return;
  if (!before.screen && after.screen) {
    // Passou a compartilhar a tela: mostra a tela, com a câmera (se houver) flutuando
    state.view.tab = 'screen';
    state.view.tabPref.delete(sessionId);
  } else if (before.screen && !after.screen && state.view.tab === 'screen') {
    state.view.tab = 'camera';
    state.view.tabPref.delete(sessionId);
  }
}

function connectionLabel(sessionId) {
  if (sessionId === state.sessionId) return null;
  const peer = state.peers.get(sessionId);
  const status = peer?.pc.connectionState;
  if (!peer || status === 'new' || status === 'connecting') return 'Conectando...';
  if (status === 'failed' || status === 'disconnected') return 'Sem conexão';
  return null;
}

// ============================================
// Seleção e palco
// ============================================
function selectParticipant(sessionId, { tab, manual = true } = {}) {
  if (!sessionId || !state.participants.has(sessionId)) {
    state.view.sessionId = null;
    renderAll();
    return;
  }

  state.view.sessionId = sessionId;
  if (manual) state.view.manual = true;

  if (tab) {
    state.view.tab = tab;
    state.view.tabPref.set(sessionId, tab);
  } else {
    // Abre direto no que a pessoa está transmitindo: tela, se houver
    state.view.tab = state.view.tabPref.get(sessionId) || (flagsOf(sessionId).screen ? 'screen' : 'camera');
  }

  renderAll();
  toggleSide(false);
}

function setViewTab(tab) {
  const id = state.view.sessionId;
  if (!id) return;
  state.view.tab = tab;
  state.view.tabPref.set(id, tab);
  renderStage();
}

function toggleSplit() {
  state.view.split = !state.view.split;
  Storage.set('roomView', { split: state.view.split });
  updateMediaButtons();
  renderStage();
}

function selfCameraStream() {
  const track = state.localStream?.getVideoTracks()[0];
  if (!track) return null;
  if (!selfCameraCache || selfCameraCache.track !== track) {
    selfCameraCache = { track, stream: new MediaStream([track]) };
  }
  return selfCameraCache.stream;
}

function renderAll() {
  renderParticipants();
  renderStage();
}

function renderStage() {
  if (!elements.stage) return;

  const id = state.view.sessionId;
  const participant = state.participants.get(id);
  const view = state.view;
  // Na janela flutuante não há lado a lado: mostra uma coisa por vez
  const split = view.split && popout.mode !== 'document';

  elements.stage.classList.toggle('is-empty', !participant);
  elements.stage.classList.toggle('split', split);
  elements.viewTabs.classList.toggle('is-hidden', split || !participant);

  if (!participant) {
    elements.viewingName.textContent = '—';
    if (popout.nameEl) popout.nameEl.textContent = '—';
    elements.viewingAvatar.innerHTML = '';
    elements.viewingAvatar.style.cssText = '';
    ['camera', 'screen'].forEach(kind => {
      const p = elements.panes[kind];
      p.pane.hidden = true;
      if (p.video.srcObject) p.video.srcObject = null;
    });
    return;
  }

  const isSelf = id === state.sessionId;
  const peer = state.peers.get(id);
  const flags = flagsOf(id);

  setAvatar(elements.viewingAvatar, participant.avatar_data, participant.display_name);
  if (state.audioActive.has(id)) {
    elements.viewingAvatar.classList.add('audio-active');
  } else {
    elements.viewingAvatar.classList.remove('audio-active');
  }
  elements.viewingName.textContent = (participant.display_name || 'Jogador') + (isSelf ? ' (você)' : '');
  if (popout.nameEl) popout.nameEl.textContent = elements.viewingName.textContent;

  // Abas Vídeo | Tela
  [['camera', elements.viewTabCamera, flags.camera], ['screen', elements.viewTabScreen, flags.screen]].forEach(([kind, el, live]) => {
    el.setAttribute('aria-selected', String(view.tab === kind));
    el.classList.toggle('is-live', live);
  });

  const connecting = !isSelf && connectionLabel(id) === 'Conectando...';

  // Câmera
  const cameraStream = isSelf ? selfCameraStream() : peer?.cameraStream;
  // Tela + câmera ao mesmo tempo: a câmera vira uma janelinha arrastável sobre a tela
  const canFloat = !split && view.tab === 'screen' && flags.screen && flags.camera && !!cameraStream;
  if (!(flags.screen && flags.camera)) state.camHidden.delete(id); // recomeçou: volta a aparecer
  const floating = canFloat && !state.camHidden.has(id);
  // câmera ligada, mas fechada por mim: botãozinho para trazer de volta
  elements.camRestore.hidden = !(canFloat && !floating);
  elements.panes.camera.pane.classList.toggle('is-floating', floating);
  renderPane('camera', {
    visible: split || view.tab === 'camera' || floating,
    stream: flags.camera ? cameraStream : null,
    emptyKey: `cam:${id}:${flags.camera}:${connecting}`,
    emptyHtml: flags.camera
      ? `<div class="spinner-text">Carregando vídeo...</div>`
      : `${avatarHtml(participant)}<div>Câmera desligada</div>${isSelf ? '<button class="empty-action" data-action="toggle-camera">Ligar câmera</button>' : ''}`
  });

  // Tela (o áudio da tela só toca enquanto estou olhando para ela)
  const screenStream = isSelf ? state.screenStream : peer?.screenStream;
  renderPane('screen', {
    visible: split || view.tab === 'screen',
    stream: flags.screen ? screenStream : null,
    muted: isSelf || !!peer?.localMuted || state.deafened,
    emptyKey: `scr:${id}:${flags.screen}:${connecting}`,
    emptyHtml: flags.screen
      ? `<div class="spinner-text">Carregando tela...</div>`
      : `${ICONS.screen.replace('<svg', '<svg class="ic-big"')}<div>${isSelf ? 'Você não está compartilhando a tela' : 'Nenhuma tela sendo compartilhada'}</div>${isSelf ? '<button class="empty-action" data-action="toggle-screen">Compartilhar tela</button>' : ''}`
  });

  positionFloatingCamera();
}

// ---- Câmera flutuante (arrastável) ----------------------------------------
function setCamHidden(hidden) {
  const id = state.view.sessionId;
  if (!id) return;
  if (hidden) state.camHidden.add(id);
  else state.camHidden.delete(id);
  renderStage();
}

const CAM_MARGIN = 12;

function positionFloatingCamera() {
  const pane = elements.panes.camera.pane;
  if (!pane.classList.contains('is-floating')) {
    pane.style.left = pane.style.top = '';
    return;
  }
  const stage = elements.stage.getBoundingClientRect();
  const w = pane.offsetWidth;
  const h = pane.offsetHeight;
  const freeX = Math.max(0, stage.width - w - CAM_MARGIN * 2);
  const freeY = Math.max(0, stage.height - h - CAM_MARGIN * 2);
  pane.style.left = `${CAM_MARGIN + state.camPos.x * freeX}px`;
  pane.style.top = `${CAM_MARGIN + state.camPos.y * freeY}px`;
}

function setupFloatingCamera() {
  const pane = elements.panes.camera.pane;
  let drag = null;

  pane.addEventListener('pointerdown', (e) => {
    if (!pane.classList.contains('is-floating') || e.button !== 0 || e.target.closest('.cam-close')) return;
    const rect = pane.getBoundingClientRect();
    drag = { dx: e.clientX - rect.left, dy: e.clientY - rect.top };
    pane.setPointerCapture(e.pointerId);
    pane.classList.add('is-dragging');
    e.preventDefault();
  });

  pane.addEventListener('pointermove', (e) => {
    if (!drag) return;
    const stage = elements.stage.getBoundingClientRect();
    const freeX = stage.width - pane.offsetWidth - CAM_MARGIN * 2;
    const freeY = stage.height - pane.offsetHeight - CAM_MARGIN * 2;
    const x = e.clientX - drag.dx - stage.left - CAM_MARGIN;
    const y = e.clientY - drag.dy - stage.top - CAM_MARGIN;
    state.camPos = {
      x: freeX > 0 ? Math.min(1, Math.max(0, x / freeX)) : 1,
      y: freeY > 0 ? Math.min(1, Math.max(0, y / freeY)) : 1
    };
    positionFloatingCamera();
  });

  const end = () => {
    if (!drag) return;
    drag = null;
    pane.classList.remove('is-dragging');
    Storage.set('cameraPos', state.camPos);
  };
  pane.addEventListener('pointerup', end);
  pane.addEventListener('pointercancel', end);

  // Mantém dentro do palco quando a janela (ou a janela flutuante) muda de tamanho
  if ('ResizeObserver' in window) new ResizeObserver(positionFloatingCamera).observe(elements.stage);
}

function renderPane(kind, { visible, stream, muted = true, emptyKey, emptyHtml }) {
  const { pane, video, empty } = elements.panes[kind];
  pane.hidden = !visible;

  const wanted = visible ? stream : null;
  video.muted = muted;
  if (video.srcObject !== (wanted || null)) {
    video.srcObject = wanted || null;
    if (wanted) playMedia(video);
  }
  pane.classList.toggle('has-video', !!wanted);

  if (!wanted && empty.dataset.key !== emptyKey) {
    empty.dataset.key = emptyKey;
    empty.innerHTML = emptyHtml;
  }
}

// ============================================
// Lista de participantes
// ============================================
function renderParticipants() {
  if (!elements.participantsList) return;

  elements.participantCount.textContent = state.participants.size;

  elements.participantsList.innerHTML = state.order.map(id => {
    const p = state.participants.get(id);
    if (!p) return '';

    const isSelf = id === state.sessionId;
    const flags = flagsOf(id);
    const peer = state.peers.get(id);
    const active = id === state.view.sessionId;

    let sub;
    let live = false;
    const conn = connectionLabel(id);
    if (conn) {
      sub = conn;
    } else if (flags.screen && flags.camera) {
      sub = `${ICONS.screen}Transmitindo tela e câmera`;
      live = true;
    } else if (flags.screen) {
      sub = `${ICONS.screen}Transmitindo tela`;
      live = true;
    } else if (flags.camera) {
      sub = `${ICONS.camera}Câmera ligada`;
      live = true;
    } else {
      sub = 'Somente áudio';
    }

    const locallyMuted = !!peer?.localMuted;
    const micIcon = locallyMuted ? ICONS.volumeOff : (flags.deafened ? ICONS.headphonesOff : (flags.mic ? ICONS.mic : ICONS.micOff));
    const micClass = locallyMuted || flags.deafened || !flags.mic ? 'muted' : '';
    const micTitle = locallyMuted ? 'Silenciado para você' : (flags.deafened ? 'Áudio desligado' : (flags.mic ? 'Microfone ligado' : 'Microfone desligado'));

    const audioActive = state.audioActive.has(id);

    return `
      <li class="person ${active ? 'active' : ''}${audioActive ? ' audio-active' : ''}" data-id="${escapeAttr(id)}">
        <button class="person-main" type="button" data-select="${escapeAttr(id)}" aria-current="${active}">
          ${avatarHtml(p)}
          <span class="person-text">
            <span class="person-name">${escapeHtml(p.display_name || 'Jogador')}${isSelf ? ' (você)' : ''}</span>
            <span class="person-sub ${live ? 'is-live' : ''}">${sub}</span>
          </span>
        </button>
        <span class="person-mic ${micClass}" title="${micTitle}">${micIcon}</span>
        <button class="icon-btn person-more" type="button" data-menu="${escapeAttr(id)}" aria-label="Mais opções" aria-haspopup="menu">${ICONS.more}</button>
      </li>`;
  }).join('');
}

// ---- Menu "..." -----------------------------------------------------------
let menuFor = null;

function openPersonMenu(sessionId, anchor) {
  if (menuFor === sessionId && !elements.personMenu.hidden) {
    closePersonMenu();
    return;
  }

  const isSelf = sessionId === state.sessionId;
  const flags = flagsOf(sessionId);
  const peer = state.peers.get(sessionId);
  menuFor = sessionId;

  const items = [
    `<button role="menuitem" data-act="camera" ${flags.camera ? '' : 'disabled'}>${ICONS.camera}Ver vídeo</button>`,
    `<button role="menuitem" data-act="screen" ${flags.screen ? '' : 'disabled'}>${ICONS.screen}Ver tela</button>`
  ];
  if (!isSelf) {
    items.push(peer?.localMuted
      ? `<button role="menuitem" data-act="unmute">${ICONS.volume}Ativar áudio</button>`
      : `<button role="menuitem" data-act="mute">${ICONS.volumeOff}Silenciar para mim</button>`);
  }
  elements.personMenu.innerHTML = items.join('');
  elements.personMenu.hidden = false;

  const rect = anchor.getBoundingClientRect();
  const menuRect = elements.personMenu.getBoundingClientRect();
  const left = Math.max(8, Math.min(rect.right - menuRect.width, window.innerWidth - menuRect.width - 8));
  const top = rect.bottom + 6 + menuRect.height > window.innerHeight
    ? Math.max(8, rect.top - menuRect.height - 6)
    : rect.bottom + 6;
  elements.personMenu.style.left = `${left}px`;
  elements.personMenu.style.top = `${top}px`;
}

function closePersonMenu() {
  if (!elements.personMenu) return;
  elements.personMenu.hidden = true;
  menuFor = null;
}

function handleMenuClick(e) {
  const btn = e.target.closest('button[data-act]');
  if (!btn || btn.disabled || !menuFor) return;

  const id = menuFor;
  const act = btn.dataset.act;
  closePersonMenu();

  if (act === 'camera' || act === 'screen') {
    selectParticipant(id, { tab: act });
    return;
  }

  const peer = state.peers.get(id);
  if (!peer) return;
  peer.localMuted = act === 'mute';
  if (peer.audioEl) peer.audioEl.muted = peer.localMuted || state.deafened;
  renderAll();
}

// ============================================
// Sidebar
// ============================================
function showSidePanel(panel) {
  const isChat = panel === 'chat';
  elements.panelPeople.hidden = isChat;
  elements.panelChat.hidden = !isChat;
  elements.sideTabs.forEach(tab => {
    const active = tab.dataset.panel === panel;
    tab.classList.toggle('active', active);
    tab.setAttribute('aria-selected', String(active));
  });
  if (isChat) {
    // O usuário abriu o chat: remove o indicador de mensagem nova.
    state.chatViewed = true;
    state.chatUnread = 0;
    updateChatBadge();
    elements.chatInput?.focus();
  }
}

// Em telas estreitas a sidebar vira uma gaveta
function toggleSide(open) {
  elements.room?.classList.toggle('side-open', !!open);
}

/** Atualiza o indicador `!` da aba de chat quando há mensagens não lidas. */
function updateChatBadge() {
  const tab = document.getElementById('tab-chat');
  if (!tab) return;
  const old = tab.querySelector('.chat-notice');
  if (old) old.remove();
  if (state.chatUnread > 0) {
    const badge = document.createElement('span');
    badge.className = 'chat-notice';
    badge.setAttribute('aria-label', `${state.chatUnread} mensagem nova`);
    badge.textContent = '!';
    tab.appendChild(badge);
  }
}

// ============================================
// Chat
// ============================================
function sendChatMessage(e) {
  e.preventDefault();
  const message = elements.chatInput?.value.trim();
  if (!message) return;

  // Envia ao servidor de sinalização, que distribui para todos os participantes
  // da mesma sala. O remetente não é notificado (evita duplicar a própria msg).
  sendWebSocketMessage('chat-message', { message });

  elements.chatInput.value = '';
}

/**
 * Exibe uma mensagem de chat recebida do servidor (ou do próprio remetente,
 * quando chamada diretamente). O remetente não é notificado; os demais veem
 * o indicador de mensagem nova se o chat não estiver aberto.
 */
function handleChatMessage(payload) {
  const {
    from_session_id: fromSessionId,
    from_participant_id: fromParticipantId,
    display_name: displayName,
    avatar_data: avatarData,
    message,
    timestamp,
  } = payload;

  const isOwn = fromSessionId === state.sessionId;

  // Só notifica quem não é o remetente e não está vendo o chat agora.
  if (!isOwn && !state.chatViewed) {
    state.chatUnread++;
    updateChatBadge();
  }

  appendChatMessage({
    displayName: displayName || 'Participante',
    avatar: avatarData,
    content: message,
    timestamp: timestamp ? new Date(timestamp * 1000) : new Date(),
    own: isOwn,
  });
}

function appendChatMessage(data) {
  const div = document.createElement('div');
  div.className = `chat-message ${data.own ? 'own' : ''}`;

  const time = data.timestamp.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
  const parts = avatarParts(data.avatar, data.displayName);

  div.innerHTML = `
    <div class="chat-message-header">
      <div class="user-avatar chat-message-avatar" style="${escapeAttr(parts.style)}">${parts.html}</div>
      <span class="chat-message-name">${escapeHtml(data.displayName)}${data.own ? ' (você)' : ''}</span>
      <span class="chat-message-time">${time}</span>
    </div>
    <div class="chat-message-content">${escapeHtml(data.content)}</div>
  `;

  elements.chatMessages.appendChild(div);
  elements.chatMessages.scrollTop = elements.chatMessages.scrollHeight;
}

// ============================================
// Janela separada (nativa do sistema)
// ============================================
// 1) Document Picture-in-Picture (Chrome/Edge 116+): uma janela de verdade do
//    sistema, sempre por cima, onde cabem vídeo, tela e os botões de mute.
// 2) Picture-in-Picture comum (Safari, Firefox): janela nativa só com o vídeo
//    visível; o mute fica pelos controles de mídia do navegador.
function popoutSupported() {
  return 'documentPictureInPicture' in window || !!document.pictureInPictureEnabled;
}

function togglePopout() {
  if (popout.mode) closePopout();
  else openPopout();
}

async function openPopout() {
  if (popout.mode) return;
  if (!state.view.sessionId) {
    Toast.info('Selecione um participante primeiro.');
    return;
  }

  if ('documentPictureInPicture' in window) {
    try {
      await openDocumentPopout();
      return;
    } catch (e) {
      Logger.warn('Document PiP failed, falling back:', e);
    }
  }
  await openVideoPopout();
}

async function openDocumentPopout() {
  const win = await window.documentPictureInPicture.requestWindow({ width: 560, height: 400 });

  // A janela nasce vazia: leva junto os estilos da página
  // (as regras vão inline; folhas de outra origem, como a fonte, entram por link absoluto)
  [...document.styleSheets].forEach(sheet => {
    try {
      const style = win.document.createElement('style');
      style.textContent = [...sheet.cssRules].map(rule => rule.cssText).join('\n');
      win.document.head.appendChild(style);
    } catch (e) {
      if (!sheet.href) return;
      const link = win.document.createElement('link');
      link.rel = 'stylesheet';
      link.href = sheet.href;
      win.document.head.appendChild(link);
    }
  });
  win.document.documentElement.lang = document.documentElement.lang;
  win.document.title = state.roomName;

  const host = win.document.createElement('div');
  host.className = 'room pip-room';

  const bar = win.document.createElement('div');
  bar.className = 'pip-bar';

  const name = win.document.createElement('span');
  name.className = 'pip-name';
  name.textContent = elements.viewingName.textContent;

  const views = win.document.createElement('div');
  views.className = 'pip-views';
  const controls = {};
  [['camera', 'Vídeo'], ['screen', 'Tela']].forEach(([view, label]) => {
    const b = win.document.createElement('button');
    b.type = 'button';
    b.className = 'pip-view';
    b.textContent = label;
    b.dataset.view = view;
    b.addEventListener('click', () => setPopoutView(view));
    views.appendChild(b);
  });

  const buttons = win.document.createElement('div');
  buttons.className = 'pip-buttons';
  [
    [elements.toggleMicBtn, toggleMicrophone],
    [elements.toggleDeafenBtn, toggleDeafen]
  ].forEach(([source, handler]) => {
    const clone = source.cloneNode(true);
    clone.classList.add('pip-btn');
    clone.addEventListener('click', handler);
    buttons.appendChild(clone);
    controls[source.id] = { clone, source };
  });

  const back = win.document.createElement('button');
  back.type = 'button';
  back.className = 'pip-btn pip-back';
  back.title = 'Fechar e voltar para a sala';
  back.setAttribute('aria-label', 'Fechar janela');
  back.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
  back.addEventListener('click', closePopout);
  buttons.appendChild(back);

  bar.append(name, views, buttons);
  host.append(elements.stage, bar); // o palco muda de janela; a barra só aparece com o mouse em cima
  win.document.body.appendChild(host);

  popout.mode = 'document';
  popout.win = win;
  popout.host = host;
  popout.controls = controls;
  popout.nameEl = name;
  popout.views = views;

  win.addEventListener('keydown', handleKeyboardShortcuts);
  win.addEventListener('click', unlockMedia);
  win.addEventListener('pagehide', () => closePopout({ fromWindow: true }));

  elements.stageCard.classList.add('is-detached');
  elements.stageDetached.hidden = false;
  closePersonMenu();
  renderStage();
  refreshStageMedia();
  updateMediaButtons();
}

async function openVideoPopout() {
  const panes = elements.panes;
  const candidates = state.view.tab === 'screen' ? [panes.screen, panes.camera] : [panes.camera, panes.screen];
  const target = candidates.find(p => p.video.srcObject && p.video.readyState >= 1);
  if (!target || !document.pictureInPictureEnabled) {
    Toast.warning('Nenhum vídeo disponível para abrir em janela separada.');
    return;
  }

  try {
    await target.video.requestPictureInPicture();
  } catch (e) {
    Logger.warn('Video PiP failed:', e);
    Toast.error('Não foi possível abrir a janela separada.');
    return;
  }

  popout.mode = 'video';
  popout.video = target.video;
  target.video.addEventListener('leavepictureinpicture', () => {
    popout.mode = null;
    popout.video = null;
    updateMediaButtons();
  }, { once: true });
  updateMediaButtons();
}

function closePopout({ fromWindow = false } = {}) {
  if (!popout.mode) return;

  if (popout.mode === 'video') {
    if (document.pictureInPictureElement) document.exitPictureInPicture().catch(() => {});
    popout.mode = null;
    popout.video = null;
    updateMediaButtons();
    return;
  }

  // Devolve o palco para a página principal
  const win = popout.win;
  popout.mode = null;
  popout.win = null;
  popout.host = null;
  popout.controls = {};
  popout.nameEl = null;
  popout.views = null;

  elements.stageCard.insertBefore(elements.stage, elements.stageDetached);
  elements.stageCard.classList.remove('is-detached');
  elements.stageDetached.hidden = true;

  if (!fromWindow && win && !win.closed) win.close();

  renderStage();
  refreshStageMedia();
  updateMediaButtons();
}

// Trocar um <video> de documento pode pausá-lo
function refreshStageMedia() {
  ['camera', 'screen'].forEach(kind => {
    const { video } = elements.panes[kind];
    if (video.srcObject) playMedia(video);
  });
}

function setPopoutView(view) {
  if (view === 'split') {
    state.view.split = true;
  } else {
    state.view.split = false;
    const id = state.view.sessionId;
    state.view.tab = view;
    if (id) state.view.tabPref.set(id, view);
  }
  Storage.set('roomView', { split: state.view.split });
  updateMediaButtons();
  renderStage();
}

// Mantém os botões da janela separada iguais aos da página
function syncPopoutControls() {
  if (popout.mode !== 'document') return;
  Object.values(popout.controls).forEach(({ clone, source }) => {
    clone.setAttribute('aria-pressed', source.getAttribute('aria-pressed'));
  });
  const current = state.view.split ? 'split' : state.view.tab;
  popout.views?.querySelectorAll('.pip-view').forEach(b => {
    b.setAttribute('aria-pressed', String(b.dataset.view === current));
  });
}

// Controles de mídia do navegador (usados também pelo PiP comum)
function syncMediaSession() {
  if (!('mediaSession' in navigator)) return;
  try {
    navigator.mediaSession.setMicrophoneActive?.(!state.media.microphoneMuted);
    navigator.mediaSession.setCameraActive?.(!state.media.cameraMuted);
  } catch (e) { /* não suportado */ }
}

function setupMediaSession() {
  if (!('mediaSession' in navigator)) return;
  const set = (action, handler) => {
    try { navigator.mediaSession.setActionHandler(action, handler); } catch (e) { /* ação não suportada */ }
  };
  set('togglemicrophone', toggleMicrophone);
  set('togglecamera', toggleCamera);
  set('hangup', leaveRoom);
}

// ============================================
// Fullscreen
// ============================================
function toggleFullscreen() {
  const target = elements.stageCard;
  if (!target) return;

  if (!document.fullscreenElement) {
    target.requestFullscreen().catch(e => Logger.error('Fullscreen failed:', e));
  } else {
    document.exitFullscreen().catch(e => Logger.error('Exit fullscreen failed:', e));
  }
}

// ============================================
// Invite Link
// ============================================
async function copyInviteLink() {
  const url = state.inviteUrl;
  try {
    await navigator.clipboard.writeText(url);
  } catch (e) {
    // Sem permissão de clipboard (ex.: HTTP): cai no método antigo
    const input = document.createElement('input');
    input.value = url;
    document.body.appendChild(input);
    input.select();
    document.execCommand('copy');
    input.remove();
  }
  Toast.success('Link de convite copiado!');
}

// ============================================
// Leave Room
// ============================================
function leaveRoom() {
  sendWebSocketMessage('leave', {});
  cleanup();
  window.location.href = (window.APP_CONFIG?.baseUrl || '') + '/';
}

function cleanup() {
  closePopout();

  if (timerHandle) {
    clearInterval(timerHandle);
    timerHandle = null;
  }

  Array.from(state.peers.keys()).forEach(closePeer);

  if (state.localStream) {
    state.localStream.getTracks().forEach(track => track.stop());
    state.localStream = null;
  }
  if (state.screenStream) {
    state.screenStream.getTracks().forEach(track => track.stop());
    state.screenStream = null;
  }

  if (state.ws) {
    state.ws.onclose = null;
    state.ws.close();
    state.ws = null;
  }

  navigator.mediaDevices.removeEventListener('devicechange', handleDeviceChange);
}

// ============================================
// Utility Functions
// ============================================
function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text == null ? '' : String(text);
  return div.innerHTML;
}

function escapeAttr(text) {
  return String(text == null ? '' : text)
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

// Export for debugging
window.GamingCallsRoom = { state };
