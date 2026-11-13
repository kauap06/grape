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
    // Avatares padrão: cores sólidas e discretas
    { type: 'gradient', color: '#52525b' },
    { type: 'gradient', color: '#4d7c6f' },
    { type: 'gradient', color: '#a07a3c' },
    { type: 'gradient', color: '#a14b4b' },
    { type: 'gradient', color: '#5b6b9a' },
    { type: 'gradient', color: '#4a7f94' },
    { type: 'gradient', color: '#8a5a7a' },
    { type: 'gradient', color: '#6b7280' },
  ],
  selectedAvatarIndex: 0
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
  renderAvatars();
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
  elements.avatarOptions = document.getElementById('avatar-options');
  elements.avatarUpload = document.getElementById('avatar-upload');
  elements.customAvatarBtn = document.getElementById('custom-avatar-btn');
  elements.saveProfileBtn = document.getElementById('save-profile-btn');
  
  // Preference checkboxes
  elements.autoMute = document.getElementById('auto-mute');
  elements.autoVideo = document.getElementById('auto-video');
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
  elements.showTimestamps = document.getElementById('show-timestamps');
  elements.animationsEnabled = document.getElementById('animations-enabled');
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
  elements.autoVideo.addEventListener('change', () => savePreference('autoVideo', elements.autoVideo.checked));
  elements.noiseSuppression.addEventListener('change', () => savePreference('noiseSuppression', elements.noiseSuppression.checked));
  elements.echoCancellation.addEventListener('change', () => savePreference('echoCancellation', elements.echoCancellation.checked));
  
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
  state.selectedAvatarIndex = saved.selectedAvatarIndex || 0;
  
  // Apply to UI
  elements.displayName.value = state.displayName;
  elements.autoMute.checked = state.preferences.autoMute;
  elements.autoVideo.checked = state.preferences.autoVideo;
  elements.noiseSuppression.checked = state.preferences.noiseSuppression;
  elements.echoCancellation.checked = state.preferences.echoCancellation;
  
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

function saveProfile() {
  saveDisplayName();
  saveProfileToStorage();
  Toast.success('Perfil salvo com sucesso!');
}

function saveProfileToStorage() {
  Storage.set('profile', {
    displayName: state.displayName,
    avatar: state.avatar,
    selectedAvatarIndex: state.selectedAvatarIndex,
    preferences: state.preferences,
    cameraOffDefaultApplied: true
  });
}

function updateAvatarPreview() {
  const avatar = state.avatar || state.availableAvatars[state.selectedAvatarIndex];
  
  if (avatar.type === 'custom' && avatar.data) {
    elements.avatarPreview.style.background = 'none';
    elements.avatarPreview.innerHTML = `<img src="${avatar.data}" alt="Avatar" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">`;
    elements.avatarInitials.textContent = '';
  } else if (avatar.type === 'gradient') {
    elements.avatarPreview.style.background = avatar.color;
    elements.avatarPreview.innerHTML = '';
    elements.avatarInitials.textContent = state.displayName ? state.displayName[0].toUpperCase() : '?';
  }
}

function adjustColor(color, amount) {
  const hex = color.replace('#', '');
  const num = parseInt(hex, 16);
  const r = Math.max(0, Math.min(255, (num >> 16) + amount));
  const g = Math.max(0, Math.min(255, ((num >> 8) & 0x00FF) + amount));
  const b = Math.max(0, Math.min(255, (num & 0x0000FF) + amount));
  return '#' + ((r << 16) | (g << 8) | b).toString(16).padStart(6, '0');
}

function renderAvatars() {
  elements.avatarOptions.innerHTML = '';
  
  state.availableAvatars.forEach((avatar, index) => {
    const option = document.createElement('div');
    option.className = `avatar-option ${index === state.selectedAvatarIndex ? 'selected' : ''}`;
    option.dataset.index = index;
    option.style.background = avatar.color;
    option.addEventListener('click', () => selectAvatar(index));
    elements.avatarOptions.appendChild(option);
  });
}

function selectAvatar(index) {
  state.selectedAvatarIndex = index;
  state.avatar = state.availableAvatars[index];
  updateAvatarPreview();
  
  // Update selection UI
  elements.avatarOptions.querySelectorAll('.avatar-option').forEach((el, i) => {
    el.classList.toggle('selected', i === index);
  });
  
  saveProfileToStorage();
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
    compactMode: elements.compactMode.checked,
    showTimestamps: elements.showTimestamps.checked,
    animationsEnabled: elements.animationsEnabled.checked,
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