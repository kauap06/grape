/**
 * Gaming Calls Platform - Home Page Logic
 */

import { Logger, Toast, Modal, Storage, API, Devices, Utils, SoundStore } from './utils.js';

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
  await Promise.race([
    loadCustomSounds(),
    new Promise(resolve => setTimeout(resolve, 1500))
  ]);
  setupEventListeners();
  updateAvatarPreview();
  await loadDevices();

  Modal.init();

  // Link de convite inválido: o room.php redireciona para cá com o aviso.
  if (new URLSearchParams(window.location.search).get('error') === 'invalid_room') {
    Toast.error('Sala não encontrada');
  }

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
  elements.resetDataBtn = document.getElementById('reset-data-btn');
  
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

  // Sons personalizados
  elements.soundsBtn = document.getElementById('sounds-btn');
  elements.soundsModal = document.getElementById('sounds-modal');
  elements.soundsClose = document.getElementById('sounds-close');
  elements.soundsDone = document.getElementById('sounds-done');
  elements.soundSettingsList = document.getElementById('sound-settings-list');
  elements.cameraSelect = document.getElementById('camera-select');
  elements.micSelect = document.getElementById('mic-select');
  elements.speakerSelect = document.getElementById('speaker-select');
  elements.testMediaBtn = document.getElementById('test-media-btn');
  elements.devicesRefreshBtn = document.getElementById('devices-refresh-btn');
  elements.devicesSummary = document.getElementById('devices-summary');
  elements.deviceList = document.getElementById('device-list');
  elements.devicePermissionRow = document.getElementById('device-permission-row');
  elements.devicePermissionBtn = document.getElementById('device-permission-btn');
  
  // Settings preferences
  elements.compactMode = document.getElementById('compact-mode');
  elements.videoQuality = document.getElementById('video-quality');
  elements.maxBitrate = document.getElementById('max-bitrate');
  elements.maxBitrateValue = document.getElementById('max-bitrate-value');

  // Certificado HTTPS
  elements.caStatus = document.getElementById('ca-status');
  elements.caTitle = document.getElementById('ca-title');
  elements.caDetail = document.getElementById('ca-detail');
  elements.caHint = document.getElementById('ca-hint');
  elements.caPaths = document.getElementById('ca-paths');
  elements.caCertPath = document.getElementById('ca-cert-path');
  elements.caInstallerRow = document.getElementById('ca-installer-row');
  elements.caInstallerPath = document.getElementById('ca-installer-path');
  elements.caCheckBtn = document.getElementById('ca-check-btn');
}

function setupEventListeners() {
  // Profile form
  elements.displayName.addEventListener('input', debounce(saveDisplayName, 300));
  elements.saveProfileBtn.addEventListener('click', saveProfile);
  elements.resetDataBtn?.addEventListener('click', resetUserData);
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
  elements.settingsBtn.addEventListener('click', () => {
    Modal.open('settings-modal');
    refreshCaStatus();
  });
  elements.soundsBtn?.addEventListener('click', () => {
    renderSoundSettings();
    Modal.open('sounds-modal');
  });
  elements.soundsClose?.addEventListener('click', () => Modal.close('sounds-modal'));
  elements.soundsDone?.addEventListener('click', () => Modal.close('sounds-modal'));
  elements.soundSettingsList?.addEventListener('click', handleSoundListClick);
  elements.soundSettingsList?.addEventListener('change', handleSoundInputChange);
  elements.caCheckBtn?.addEventListener('click', refreshCaStatus);
  elements.settingsClose.addEventListener('click', () => Modal.close('settings-modal'));
  elements.settingsCancel.addEventListener('click', () => Modal.close('settings-modal'));
  elements.settingsSave.addEventListener('click', saveSettings);
  elements.testMediaBtn.addEventListener('click', testMediaDevices);
  elements.devicesRefreshBtn?.addEventListener('click', () => loadDevices({ announce: true }));
  elements.devicePermissionBtn?.addEventListener('click', requestDevicePermission);
  // Hotplug: atualiza a lista e os seletores sem recarregar a página.
  Devices.subscribe(() => loadDevices());
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
  Toast.success('Perfil salvo');
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
    Toast.error('Selecione uma imagem válida');
    return;
  }
  
  if (file.size > 5 * 1024 * 1024) {
    Toast.error('A imagem deve ter no máximo 5 MB');
    return;
  }
  
  const reader = new FileReader();
  reader.onload = async (event) => {
    const reduced = await Utils.shrinkImageDataUrl(event.target.result);
    state.avatar = { type: 'custom', data: reduced };
    updateAvatarPreview();
    saveProfileToStorage();
    Toast.success('Foto atualizada');
  };
  reader.readAsDataURL(file);
  
  // Clear input
  e.target.value = '';
}

// ============================================
// Device Management
// ============================================
function deviceSelection() {
  const saved = Storage.get('devices', {});
  return {
    cameraId: elements.cameraSelect?.value || saved.cameraId || '',
    micId: elements.micSelect?.value || saved.micId || '',
    speakerId: elements.speakerSelect?.value || saved.speakerId || ''
  };
}

function pluralize(count, singular, plural) {
  return count === 1 ? singular : plural;
}

function renderDeviceSummary(devices) {
  if (!elements.devicesSummary) return;
  const cameras = devices.cameras.length;
  const microphones = devices.microphones.length;
  const speakers = devices.speakers.length;
  const total = cameras + microphones + speakers;
  if (!total) {
    elements.devicesSummary.textContent = 'Nenhum dispositivo visível. Permita o acesso abaixo ou conecte um aparelho.';
    return;
  }
  const parts = [];
  if (cameras) parts.push(cameras + ' ' + pluralize(cameras, 'câmera', 'câmeras'));
  if (microphones) parts.push(microphones + ' ' + pluralize(microphones, 'microfone', 'microfones'));
  if (speakers) parts.push(speakers + ' ' + pluralize(speakers, 'alto-falante', 'alto-falantes'));
  elements.devicesSummary.textContent = parts.join(', ').replace(/, ([^,]*)$/, ' e $1') + ' ' + pluralize(total, 'disponível', 'disponíveis') + '.';
}

async function loadDevices(options = {}) {
  const selected = deviceSelection();
  try {
    const devices = await Devices.enumerate();
    Devices.populateSelect(elements.cameraSelect, devices.cameras, selected.cameraId);
    Devices.populateSelect(elements.micSelect, devices.microphones, selected.micId);
    Devices.populateSelect(elements.speakerSelect, devices.speakers, selected.speakerId);
    Devices.renderList(elements.deviceList, devices, {
      cameraId: elements.cameraSelect.value,
      micId: elements.micSelect.value,
      speakerId: elements.speakerSelect.value
    });
    renderDeviceSummary(devices);
    if (elements.devicePermissionRow) {
      elements.devicePermissionRow.hidden = !Devices.needsPermission(devices);
    }
    if (options.announce) Toast.success('Lista de dispositivos atualizada');
  } catch (e) {
    Logger.error('Falha ao carregar dispositivos:', e);
    if (elements.devicesSummary) elements.devicesSummary.textContent = 'Não foi possível listar os dispositivos. Tente de novo.';
  }
}

// O navegador só revela os nomes depois que o usuário libera o acesso.
// Pedimos áudio e vídeo separadamente para não perder um pelo outro.
async function requestDevicePermission() {
  if (!navigator.mediaDevices?.getUserMedia) {
    Toast.error('Este navegador não permite acessar câmera e microfone');
    return;
  }
  if (elements.devicePermissionBtn) {
    elements.devicePermissionBtn.disabled = true;
    elements.devicePermissionBtn.textContent = 'Liberando…';
  }
  let granted = false;
  for (const constraints of [{ audio: true }, { video: true }]) {
    try {
      const stream = await navigator.mediaDevices.getUserMedia(constraints);
      stream.getTracks().forEach(track => track.stop());
      granted = true;
    } catch (e) {
      Logger.warn('Permissão de dispositivo não concedida:', e);
    }
  }
  await loadDevices();
  if (elements.devicePermissionBtn) {
    elements.devicePermissionBtn.disabled = false;
    elements.devicePermissionBtn.textContent = 'Permitir acesso';
  }
  if (granted) Toast.success('Acesso liberado. Nomes dos dispositivos atualizados');
  else Toast.warning('O acesso não foi liberado. Os nomes podem aparecer de forma genérica');
}

// Câmera e microfone são testados separadamente: um aparelho em uso por
// outra aba ou programa (NotReadableError) não esconde o resultado do outro.
async function testMediaDevices() {
  const testar = async (video) => {
    const constraints = await Devices.getConstraints(video, !video, {
      deviceId: elements.cameraSelect.value || undefined,
      audioDeviceId: elements.micSelect.value || undefined
    });
    try {
      const stream = await navigator.mediaDevices.getUserMedia(constraints);
      stream.getTracks().forEach(track => track.stop());
      return null;
    } catch (e) {
      Logger.warn(`Media test failed (${video ? 'video' : 'audio'}):`, e);
      return e;
    }
  };

  const [erroCamera, erroMic] = await Promise.all([testar(true), testar(false)]);

  const mensagem = (erro, camera) => {
    switch (erro?.name) {
      case 'NotAllowedError':
      case 'SecurityError':
        return `Permissão de ${camera ? 'câmera' : 'microfone'} negada`;
      case 'NotReadableError':
      case 'TrackStartError':
        return camera ? 'A câmera está em uso' : 'O microfone está em uso';
      case 'NotFoundError':
      case 'DevicesNotFoundError':
        return camera ? 'Nenhuma câmera encontrada' : 'Nenhum microfone encontrado';
      default:
        return camera ? 'Não foi possível testar a câmera' : 'Não foi possível testar o microfone';
    }
  };

  const negado = (erro) => erro?.name === 'NotAllowedError' || erro?.name === 'SecurityError';

  if (!erroCamera && !erroMic) {
    Toast.success('Dispositivos funcionando');
  } else if (erroCamera && erroMic && negado(erroCamera) && negado(erroMic)) {
    Toast.error('Permissão de câmera e microfone negada');
  } else if (!erroCamera) {
    Toast.warning(`Câmera funcionando. ${mensagem(erroMic, false)}`);
  } else if (!erroMic) {
    Toast.warning(`Microfone funcionando. ${mensagem(erroCamera, true)}`);
  } else {
    Toast.error(`${mensagem(erroCamera, true)}. ${mensagem(erroMic, false)}`);
  }
  // O teste libera a permissão e revela os nomes reais dos aparelhos.
  loadDevices();
}

// ---- Certificado HTTPS ------------------------------------------------
// O Caddy usa uma CA própria ("tls internal"). Sem ela instalada o navegador
// avisa e bloqueia câmera/microfone. O endpoint devolve o caminho do .crt
// neste PC e se ele já está na loja de raízes confiáveis do Windows.
function renderCaStatus(data) {
  if (!elements.caStatus) return;

  const set = (state, title, detail, hint) => {
    elements.caStatus.dataset.state = state;
    elements.caTitle.textContent = title;
    elements.caDetail.textContent = detail || '';
    elements.caHint.textContent = hint || '';
    elements.caHint.hidden = !hint;
  };

  if (!data) {
    set('error', 'Não foi possível verificar', 'O servidor não respondeu');
    elements.caPaths.hidden = true;
    return;
  }

  if (!data.certPath) {
    set('error', 'Certificado não encontrado', 'Nenhum arquivo da CA neste PC',
      'Inicie o Grape com bin\\start-host.bat para gerar o certificado e depois execute o instalador deploy\\ca\\install-ca.bat.');
  } else if (data.installed === true && data.expired) {
    set('warn', 'Instalado, mas vencido', 'O navegador pode voltar a avisar',
      'Rode deploy\\ca\\renew-cert.bat e instale o certificado de novo.');
  } else if (data.installed === true) {
    const lojas = data.stores?.length ? ` (${data.stores.join(' e ')})` : '';
    set('ok', 'Instalado', `Raiz confiável neste PC${lojas}`);
  } else if (data.installed === false) {
    set('warn', 'Não instalado', 'Verificado neste PC',
      'Rode o instalador abaixo neste PC para o navegador confiar no certificado e abrir o Grape por HTTPS sem aviso.');
  } else {
    set('warn', 'Não foi possível verificar', data.platform === 'Windows' ? 'Verificação indisponível' : 'Disponível só no Windows');
  }

  elements.caPaths.hidden = !data.certPath;
  if (data.certPath) {
    elements.caCertPath.textContent = data.certPath;
    elements.caInstallerRow.hidden = !data.installerPath;
    elements.caInstallerPath.textContent = data.installerPath || '';
  }
}

async function refreshCaStatus() {
  if (!elements.caStatus) return;
  if (elements.caCheckBtn) elements.caCheckBtn.disabled = true;
  elements.caStatus.dataset.state = 'checking';
  elements.caTitle.textContent = 'Verificando…';
  elements.caDetail.textContent = '';
  elements.caHint.hidden = true;
  try {
    renderCaStatus(await API.get('/api/ca-status.php'));
  } catch (e) {
    Logger.warn('CA status failed:', e);
    renderCaStatus(null);
  } finally {
    if (elements.caCheckBtn) elements.caCheckBtn.disabled = false;
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
  
  Toast.success('Configurações salvas');
  Modal.close('settings-modal');
}

// ============================================
// Sons personalizados
// ============================================
const customSounds = {}; // key -> { url, name }
let soundPreviewAudio = null;

async function loadCustomSounds() {
  try {
    const saved = await SoundStore.readAll();
    saved.forEach(({ key, value }) => {
      if (!SoundStore.defaults[key] || !value?.blob) return;
      customSounds[key] = {
        url: URL.createObjectURL(value.blob),
        name: (value.name || 'Som personalizado').slice(0, 120)
      };
    });
  } catch (e) {
    Logger.warn('Custom sounds unavailable:', e);
  }
  renderSoundSettings();
}

function setCustomSound(key, blob, name) {
  const previous = customSounds[key];
  if (previous?.url) URL.revokeObjectURL(previous.url);
  customSounds[key] = {
    url: URL.createObjectURL(blob),
    name: (name || 'Som personalizado').slice(0, 120)
  };
}

function clearCustomSound(key) {
  const previous = customSounds[key];
  if (previous?.url) URL.revokeObjectURL(previous.url);
  delete customSounds[key];
}

function renderSoundSettings() {
  const list = elements.soundSettingsList;
  if (!list) return;
  list.querySelectorAll('[data-sound-row]').forEach(row => {
    const key = row.dataset.soundRow;
    const custom = customSounds[key];
    const status = row.querySelector('[data-sound-status]');
    const remove = row.querySelector('[data-sound-remove]');
    if (custom) {
      if (status) {
        status.textContent = custom.name;
        status.title = custom.name;
      }
      if (remove) remove.hidden = false;
      row.dataset.custom = 'true';
    } else {
      if (status) {
        status.textContent = 'Som padrão';
        status.removeAttribute('title');
      }
      if (remove) remove.hidden = true;
      delete row.dataset.custom;
    }
  });
}

function previewSound(key) {
  const file = SoundStore.defaults[key];
  if (!file) return;
  const source = SoundStore.resolve(file, customSounds);
  if (soundPreviewAudio) {
    soundPreviewAudio.pause();
    soundPreviewAudio.currentTime = 0;
  }
  soundPreviewAudio = new Audio(source);
  soundPreviewAudio.volume = 0.35;
  soundPreviewAudio.play().catch(() => Toast.error('Não foi possível reproduzir este áudio'));
}

async function handleSoundInputChange(e) {
  const input = e.target.closest('[data-sound-input]');
  if (!input) return;
  const row = input.closest('[data-sound-row]');
  const key = row?.dataset.soundRow;
  const file = input.files?.[0];
  input.value = '';
  if (!file || !key || !SoundStore.defaults[key]) return;

  const validation = await SoundStore.validateFile(file);
  if (!validation.ok) {
    Toast.error(validation.message);
    return;
  }

  try {
    await SoundStore.put(key, file);
    setCustomSound(key, file, file.name);
    renderSoundSettings();
    Toast.success('Som atualizado');
    previewSound(key);
  } catch (err) {
    Logger.error('Falha ao salvar som:', err);
    Toast.error('Não foi possível salvar o áudio');
  }
}

async function handleSoundListClick(e) {
  const row = e.target.closest('[data-sound-row]');
  if (!row) return;
  const key = row.dataset.soundRow;
  if (!key || !SoundStore.defaults[key]) return;

  if (e.target.closest('[data-sound-preview]')) {
    previewSound(key);
    return;
  }

  if (e.target.closest('[data-sound-upload]')) {
    row.querySelector('[data-sound-input]')?.click();
    return;
  }

  const removeBtn = e.target.closest('[data-sound-remove]');
  if (!removeBtn) return;
  removeBtn.disabled = true;
  try {
    await SoundStore.remove(key);
    clearCustomSound(key);
    renderSoundSettings();
    Toast.success('Som padrão restaurado');
  } catch (err) {
    Logger.error('Falha ao remover som:', err);
    Toast.error('Não foi possível remover o áudio');
  } finally {
    removeBtn.disabled = false;
  }
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
      Toast.success('Sala criada');
      window.location.href = `${API.baseUrl}/room.php?code=${response.room.invite_code}`;
    } else {
      throw new Error(response.error || 'Não foi possível criar a sala');
    }
  } catch (e) {
    Logger.error('Create room failed:', e);
    Toast.error(e.message || 'Não foi possível criar a sala');
  } finally {
    elements.createRoomBtn.disabled = false;
    elements.createRoomBtn.textContent = 'Criar sala';
  }
}

/**
 * Apaga tudo o que foi salvo sobre este navegador: preferências no servidor,
 * localStorage e cookies. Volta ao estado de primeiro acesso.
 */
async function resetUserData() {
  if (!window.confirm('Apagar seus dados salvos? Não dá para desfazer.')) return;

  const botao = elements.resetDataBtn;
  if (botao) botao.disabled = true;

  try {
    await API.post('/api/reset.php', {});
  } catch (e) {
    // Mesmo se o servidor falhar, limpa o lado do navegador
    Logger.warn('Reset no servidor falhou:', e);
  }

  Storage.clear();
  await Promise.all(
    Object.keys(SoundStore.defaults).map(key => SoundStore.remove(key).catch(() => {}))
  );

  // Cookies do app (sessão e chave de preferências)
  for (const nome of ['gaming_calls_session', 'grape_pref']) {
    document.cookie = `${nome}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
  }

  Toast.success('Dados apagados. Recarregando...');
  setTimeout(() => window.location.reload(), 700);
}

async function joinRoom() {
  const inviteCode = elements.inviteCode.value.trim().toUpperCase();
  const displayName = elements.displayName.value.trim() || state.displayName || 'Jogador';
  
  if (!inviteCode) {
    Toast.error('Digite o código da sala');
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
      Toast.success('Você entrou na sala');
      window.location.href = `${API.baseUrl}/room.php?code=${inviteCode}`;
    } else if (response.locked) {
      throw new Error('A sala está trancada');
    } else {
      throw new Error(response.error || 'Não foi possível entrar na sala');
    }
  } catch (e) {
    Logger.error('Join room failed:', e);
    Toast.error(e.message || 'Não foi possível entrar na sala');
  } finally {
    elements.joinRoomBtn.disabled = false;
    elements.joinRoomBtn.textContent = 'Entrar';
  }
}

// ============================================
// Initialize
// ============================================
// Make sure utils are available globally for inline scripts
window.GamingCalls = { Logger, Toast, Modal, Storage, API, Devices, Utils };