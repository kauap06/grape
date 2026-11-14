/**
 * Gaming Calls Platform - Home Page Logic
 */

import { Logger, Toast, Modal, Storage, API, Devices, Utils } from './utils.js';

// ============================================
// State
// ============================================
const state = {
  displayName: '',
  avatar: '',
  preferences: {
    autoMute: true,
    autoVideo: false,
    noiseSuppression: true,
    echoCancellation: true
  },
  availableAvatars: [
    // Avatar padrão (sem foto): cor sólida discreta, apenas para o preview
    { type: 'gradient', color: '#52525b' },
  ]
};

// ============================================
// DOM Elements
// ============================================
const elements = {};

// ============================================
// Initialization
// ============================================
document.addEventListener('DOMContentLoaded', async () => {
  Logger.log('Home page initializing...');
  
  cacheElements();
  loadPreferences();
  setupEventListeners();
  updateAvatarPreview();
  await loadDevices();
  
  Modal.init();
  
  Logger.log('Home page ready');
});

function cacheElements() {
  // Form elements
  elements.displayName = document.getElementById('display-name');
  elements.avatarPreview = document.getElementById('avatar-preview');
  elements.avatarInitials = document.getElementById('avatar-initials');
  elements.avatarUpload = document.getElementById('avatar-upload');
  elements.customAvatarBtn = document.getElementById('custom-avatar-btn');
  elements.saveProfileBtn = document.getElementById('save-profile-btn');
  
  // Preference checkboxes
  elements.autoMute = document.getElementById('auto-mute');
  elements.noiseSuppression = document.getElementById('noise-suppression');
  elements.echoCancellation = document.getElementById('echo-cancellation');
  
  // Action buttons
  elements.createRoomBtn = document.getElementById('create-room-btn');
  elements.joinRoomBtn = document.getElementById('join-room-btn');
  elements.roomName = document.getElementById('room-name');
  elements.maxParticipants = document.getElementById('max-participants');
  elements.inviteCode = document.getElementById('invite-code');
  
  // Settings
  elements.settingsBtn = document.getElementById('settings-btn');
  elements.settingsModal = document.getElementById('settings-modal');
  elements.settingsClose = document.getElementById('settings-close');
  elements.settingsCancel = document.getElementById('settings-cancel');
  elements.settingsSave = document.getElementById('settings-save');
  elements.cameraSelect = document.getElementById('camera-select');
  elements.micSelect = document.getElementById('mic-select');
  elements.speakerSelect = document.getElementById('speaker-select');
  elements.testMediaBtn = document.getElementById('test-media-btn');
  
  // Settings preferences
  elements.compactMode = document.getElementById('compact-mode');
  elements.videoQuality = document.getElementById('video-quality');
  elements.maxBitrate = document.getElementById('max-bitrate');
  elements.maxBitrateValue = document.getElementById('max-bitrate-value');
}

function setupEventListeners() {
  // Profile form
  elements.displayName.addEventListener('input', debounce(saveDisplayName, 300));
  elements.saveProfileBtn.addEventListener('click', saveProfile);
  elements.customAvatarBtn.addEventListener('click', () => elements.avatarUpload.click());
  elements.avatarUpload.addEventListener('change', handleAvatarUpload);
  
  // Preferences
  elements.autoMute.addEventListener('change', () => savePreference('autoMute', elements.autoMute.checked));
  elements.noiseSuppression.addEventListener('change', () => savePreference('noiseSuppression', elements.noiseSuppression.checked));
  elements.echoCancellation.addEventListener('change', () => savePreference('echoCancellation', elements.echoCancellation.checked));

  // Modo compacto: aplica/reverte o preset das opções de interface
  if (elements.compactMode) {
    elements.compactMode.addEventListener('change', () => applyCompactMode(elements.compactMode.checked));
  }
  
  // Room actions
  elements.createRoomBtn.addEventListener('click', createRoom);
  elements.joinRoomBtn.addEventListener('click', joinRoom);
  elements.inviteCode.addEventListener('input', (e) => {
    e.target.value = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
  });

  // Link de convite (/?code=ABC123): já preenche o código da sala
  const codeFromLink = new URLSearchParams(window.location.search).get('code');
  if (codeFromLink) {
    elements.inviteCode.value = codeFromLink.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 12);
    elements.inviteCode.focus();
  }
  
  // Settings modal
  elements.settingsBtn.addEventListener('click', () => Modal.open('settings-modal'));
  elements.settingsClose.addEventListener('click', () => Modal.close('settings-modal'));
  elements.settingsCancel.addEventListener('click', () => Modal.close('settings-modal'));
  elements.settingsSave.addEventListener('click', saveSettings);
  elements.testMediaBtn.addEventListener('click', testMediaDevices);
  elements.maxBitrate.addEventListener('input', (e) => {
    elements.maxBitrateValue.textContent = `${e.target.value} kbps`;
  });
  
  // Enter key handlers
  elements.roomName.addEventListener('keydown', (e) => { if (e.key === 'Enter') createRoom(); });
  elements.inviteCode.addEventListener('keydown', (e) => { if (e.key === 'Enter') joinRoom(); });
}

function debounce(fn, delay) {
  let timeoutId;
  return (...args) => {
    clearTimeout(timeoutId);
    timeoutId = setTimeout(() => fn.apply(this, args), delay);
  };
}

// ============================================
// Preferences
// ============================================
function loadPreferences() {
  // Load from localStorage
  const saved = Storage.get('profile', {});
  state.displayName = saved.displayName || '';
  state.avatar = saved.avatar || '';
  state.preferences = { ...state.preferences, ...saved.preferences };
  // Nova regra: câmera desligada por padrão. Zera o valor antigo (true) uma única vez.
  if (!saved.cameraOffDefaultApplied) {
    state.preferences.autoVideo = false;
    savePreference('autoVideo', false); // grava o perfil já com a marca de migração
  }
  // Apply to UI
  elements.displayName.value = state.displayName;
  elements.autoMute.checked = state.preferences.autoMute;
  elements.noiseSuppression.checked = state.preferences.noiseSuppression;
  elements.echoCancellation.checked = state.preferences.echoCancellation;

  // Interface: o toggle do perfil aplica o que estiver salvo
  const ui = Storage.get('uiPreferences', {});
  elements.compactMode.checked = !!ui.compactMode;
  applyCompactMode(!!ui.compactMode, true);

  // Update avatar preview
  updateAvatarPreview();
}

function saveDisplayName() {
  state.displayName = elements.displayName.value.trim();
}

function savePreference(key, value) {
  state.preferences[key] = value;
  saveProfileToStorage();
}

// Modo compacto: interruptor único que troca a interface para um visual
// minimalista (CSS em body.is-compact) e desliga timestamps/animações.
// O snapshot guarda o que o usuário tinha, para o "desligar" restaurar.
const COMPACT_PRESET = { showTimestamps: false, animationsEnabled: false };

function applyCompactMode(enabled, silent = false) {
  const ui = Storage.get('uiPreferences', {});

  if (enabled) {
    if (!ui.compactSnapshot) {
      Storage.set('uiPreferences', {
        ...ui,
        compactSnapshot: {
          showTimestamps: !!ui.showTimestamps,
          animationsEnabled: ui.animationsEnabled !== false
        }
      });
    }
    document.body.classList.add('is-compact');
    document.body.classList.add('no-animations');
  } else {
    const snap = ui.compactSnapshot;
    if (snap) {
      document.body.classList.toggle('no-animations', snap.animationsEnabled === false);
    } else {
      document.body.classList.remove('no-animations');
    }
    document.body.classList.remove('is-compact');
  }

  if (!silent) {
    Storage.set('uiPreferences', {
      ...Storage.get('uiPreferences', {}),
      compactMode: enabled,
      showTimestamps: enabled ? COMPACT_PRESET.showTimestamps : (ui.compactSnapshot?.showTimestamps ?? false),
      animationsEnabled: enabled ? COMPACT_PRESET.animationsEnabled : (ui.compactSnapshot?.animationsEnabled ?? true)
    });
  }
}

function saveProfile() {
  saveDisplayName();
  saveProfileToStorage();
  Toast.success('Perfil salvo com sucesso!');
}

function saveProfileToStorage() {
  Storage.set('profile', {
    displayName: state.displayName,
    avatar: state.avatar,
    preferences: state.preferences,
    cameraOffDefaultApplied: true
  });
}

function updateAvatarPreview() {
  // Sem foto: mostra as iniciais sobre a cor padrão. Com foto: mostra a imagem.
  const avatar = (state.avatar && typeof state.avatar === 'object' && state.avatar.type === 'custom' && state.avatar.data)
    ? state.avatar
    : state.availableAvatars[0];

  if (avatar.type === 'custom' && avatar.data) {
    elements.avatarPreview.style.background = 'none';
    elements.avatarPreview.innerHTML = `<img src="${avatar.data}" alt="Avatar" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">`;
    elements.avatarInitials.textContent = '';
  } else {
    elements.avatarPreview.style.background = avatar.color;
    elements.avatarPreview.innerHTML = '';
    elements.avatarInitials.textContent = state.displayName ? state.displayName[0].toUpperCase() : '?';
  }
}

function handleAvatarUpload(e) {
  const file = e.target.files[0];
  if (!file) return;
  
  if (!file.type.startsWith('image/')) {
    Toast.error('Por favor, selecione uma imagem válida');
    return;
  }
  
  if (file.size > 2 * 1024 * 1024) {
    Toast.error('A imagem deve ter no máximo 2MB');
    return;
  }
  
  const reader = new FileReader();
  reader.onload = async (event) => {
    const reduced = await Utils.shrinkImageDataUrl(event.target.result);
    state.avatar = { type: 'custom', data: reduced };
    updateAvatarPreview();
    saveProfileToStorage();
    Toast.success('Avatar personalizado salvo!');
  };
  reader.readAsDataURL(file);
  
  // Clear input
  e.target.value = '';
}

// ============================================
// Device Management
// ============================================
async function loadDevices() {
  try {
    const devices = await Devices.enumerate();
    
    Devices.populateSelect(elements.cameraSelect, devices.cameras);
    Devices.populateSelect(elements.micSelect, devices.microphones);
    Devices.populateSelect(elements.speakerSelect, devices.speakers);
    
    // Load saved device preferences
    const savedDevices = Storage.get('devices', {});
    if (savedDevices.cameraId) {
      elements.cameraSelect.value = savedDevices.cameraId;
    }
    if (savedDevices.micId) {
      elements.micSelect.value = savedDevices.micId;
    }
    if (savedDevices.speakerId) {
      elements.speakerSelect.value = savedDevices.speakerId;
    }
  } catch (e) {
    Logger.error('Failed to load devices:', e);
  }
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
    Toast.error(`Erro ao testar dispositivos: ${e.message}`);
  }
}

function saveSettings() {
  const devices = {
    cameraId: elements.cameraSelect.value || null,
    micId: elements.micSelect.value || null,
    speakerId: elements.speakerSelect.value || null
  };
  
  Storage.set('devices', devices);

  Storage.set('uiPreferences', {
    ...Storage.get('uiPreferences', {}),
    videoQuality: elements.videoQuality.value,
    maxBitrate: parseInt(elements.maxBitrate.value)
  });
  
  Toast.success('Configurações salvas!');
  Modal.close('settings-modal');
}

// ============================================
// Room Actions
// ============================================
async function createRoom() {
  const name = elements.roomName.value.trim() || 'Minha Sala';
  const maxParticipants = parseInt(elements.maxParticipants.value) || 8;
  
  elements.createRoomBtn.disabled = true;
  elements.createRoomBtn.innerHTML = `
    <svg class="spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation: spin 1s linear infinite;">
      <circle cx="12" cy="12" r="10" stroke-opacity="0.25"/>
      <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/>
    </svg>
    Criando...
  `;
  
  try {
    const response = await API.post('/api/rooms/create.php', {
      name,
      max_participants: maxParticipants,
      settings: {
        autoMute: state.preferences.autoMute,
        autoVideo: state.preferences.autoVideo
      }
    });
    
    if (response.success) {
      Toast.success('Sala criada com sucesso!');
      window.location.href = `${API.baseUrl}/room.php?code=${response.room.invite_code}`;
    } else {
      throw new Error(response.error || 'Falha ao criar sala');
    }
  } catch (e) {
    Logger.error('Create room failed:', e);
    Toast.error(`Erro ao criar sala: ${e.message}`);
  } finally {
    elements.createRoomBtn.disabled = false;
    elements.createRoomBtn.textContent = 'Criar sala';
  }
}

async function joinRoom() {
  const inviteCode = elements.inviteCode.value.trim().toUpperCase();
  const displayName = elements.displayName.value.trim() || state.displayName || 'Anônimo';
  
  if (!inviteCode) {
    Toast.error('Digite o código de convite');
    return;
  }
  
  elements.joinRoomBtn.disabled = true;
  elements.joinRoomBtn.innerHTML = `
    <svg class="spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation: spin 1s linear infinite;">
      <circle cx="12" cy="12" r="10" stroke-opacity="0.25"/>
      <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/>
    </svg>
    Entrando...
  `;
  
  try {
    const response = await API.post('/api/rooms/join.php', {
      invite_code: inviteCode,
      display_name: displayName,
      avatar_data: typeof state.avatar === 'string' ? state.avatar : (state.avatar ? JSON.stringify(state.avatar) : null)
    });
    
    if (response.success) {
      Toast.success('Entrou na sala!');
      window.location.href = `${API.baseUrl}/room.php?code=${inviteCode}`;
    } else {
      throw new Error(response.error || 'Falha ao entrar na sala');
    }
  } catch (e) {
    Logger.error('Join room failed:', e);
    Toast.error(`Erro ao entrar na sala: ${e.message}`);
  } finally {
    elements.joinRoomBtn.disabled = false;
    elements.joinRoomBtn.textContent = 'Entrar na sala';
  }
}

// ============================================
// Initialize
// ============================================
// Make sure utils are available globally for inline scripts
window.GamingCalls = { Logger, Toast, Modal, Storage, API, Devices, Utils };