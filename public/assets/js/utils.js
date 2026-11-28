/**
 * Gaming Calls Platform - Shared Utilities
 */

// ============================================
// Configuration
// ============================================
window.APP_CONFIG = window.APP_CONFIG || {};

// ============================================
// Logger
// ============================================
const Logger = {
  // Logs detalhados só quando APP_CONFIG.debug estiver ativo; em produção
  // evitam custo de formatar e escrever no console a cada evento de mídia.
  enabled: false,
  
  log(...args) {
    if (this.enabled || window.APP_CONFIG.debug) {
      console.log('[GamingCalls]', new Date().toISOString(), ...args);
    }
  },
  
  debug(...args) {
    if (this.enabled || window.APP_CONFIG.debug) {
      console.debug('[GamingCalls]', new Date().toISOString(), ...args);
    }
  },
  
  warn(...args) {
    console.warn('[GamingCalls]', new Date().toISOString(), ...args);
  },
  
  error(...args) {
    console.error('[GamingCalls]', new Date().toISOString(), ...args);
  },
  
  setDebug(enabled) {
    this.enabled = enabled;
  }
};

// ============================================
// Room Code
// ============================================
// O servidor gera codigos com o IPv4 do host (prefixo G + 11 caracteres).
// Decodificar no cliente evita consultar um registro central: o codigo ja
// diz para qual host a sala deve ser aberta.
const RoomCode = {
  PREFIX: 'G',
  LENGTH: 12,
  PORT: 8443,
  ALPHABET: 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',

  decode(raw) {
    const code = String(raw || '').trim().toUpperCase();
    if (code.length !== this.LENGTH || code[0] !== this.PREFIX) return null;

    let value = 0n;
    for (let i = 1; i < code.length; i++) {
      const index = this.ALPHABET.indexOf(code[i]);
      if (index < 0) return null;
      value = (value << 5n) | BigInt(index);
    }

    if ((value & 3n) !== 0n) return null;

    const checksum = Number((value >> 2n) & 31n);
    const nonce = Number((value >> 7n) & 0xFFFFn);
    const ip = Number((value >> 23n) & 0xFFFFFFFFn);
    if (checksum !== this.checksum(ip, nonce)) return null;

    const host = this.intToIpv4(ip);
    if (!this.isShareableIpv4(host)) return null;

    return {
      host,
      port: this.PORT,
      scheme: 'https',
      code,
      url: 'https://' + host + ':' + this.PORT + '/room.php?code=' + encodeURIComponent(code)
    };
  },

  joinUrl(info) {
    return info && info.url ? info.url : '';
  },

  checksum(ip, nonce) {
    const bytes = [
      (ip >>> 24) & 0xFF,
      (ip >>> 16) & 0xFF,
      (ip >>> 8) & 0xFF,
      ip & 0xFF,
      (nonce >>> 8) & 0xFF,
      nonce & 0xFF
    ];
    let check = 0;
    bytes.forEach((byte, index) => {
      check = ((check * 33) + byte + index) & 0x1F;
    });
    return check;
  },

  isShareableIpv4(ip) {
    if (typeof ip !== 'string') return false;
    const parts = ip.split('.');
    if (parts.length !== 4 || parts.some(part => !/^\d{1,3}$/.test(part) || Number(part) > 255)) {
      return false;
    }
    const first = Number(parts[0]);
    const second = Number(parts[1]);
    if (first === 0 || first === 127 || first >= 224) return false;
    if (first === 169 && second === 254) return false;
    return true;
  },

  intToIpv4(ip) {
    return [
      (ip >>> 24) & 0xFF,
      (ip >>> 16) & 0xFF,
      (ip >>> 8) & 0xFF,
      ip & 0xFF
    ].join('.');
  }
};

// ============================================
// Event Emitter
// ============================================
class EventEmitter {
  constructor() {
    this.events = new Map();
  }
  
  on(event, listener) {
    if (!this.events.has(event)) {
      this.events.set(event, new Set());
    }
    this.events.get(event).add(listener);
    return () => this.off(event, listener);
  }
  
  off(event, listener) {
    const listeners = this.events.get(event);
    if (listeners) {
      listeners.delete(listener);
    }
  }
  
  emit(event, ...args) {
    const listeners = this.events.get(event);
    if (listeners) {
      listeners.forEach(listener => {
        try {
          listener(...args);
        } catch (e) {
          Logger.error(`Error in event listener for ${event}:`, e);
        }
      });
    }
  }
  
  once(event, listener) {
    const wrapper = (...args) => {
      this.off(event, wrapper);
      listener(...args);
    };
    this.on(event, wrapper);
  }
}

// ============================================
// Toast Notifications
// ============================================
function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text == null ? '' : String(text);
  return div.innerHTML;
}

const Toast = {
  container: null,
  
  init() {
    this.container = document.getElementById('toast-container');
    if (!this.container) {
      this.container = document.createElement('div');
      this.container.id = 'toast-container';
      this.container.className = 'toast-container';
      document.body.appendChild(this.container);
    }
  },
  
  show(message, type = 'info', duration = 4000, options = {}) {
    if (!this.container) this.init();
    
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    
    const titleHtml = options.title ? `<div class="toast-title">${escapeHtml(options.title)}</div>` : '';
    const bodyHtml = options.body 
      ? `<div class="toast-body">${escapeHtml(options.body)}</div>` 
      : `<div class="toast-content">${escapeHtml(message)}</div>`;
    
    let avatarHtml = '';
    if (options.avatar) {
      if (typeof options.avatar === 'string' && options.avatar.startsWith('data:image/')) {
        // Raw data URL photo
        avatarHtml = `<img class="toast-avatar" src="${escapeHtml(options.avatar)}" alt="Avatar">`;
      } else if (typeof options.avatar === 'string') {
        try {
          const parsed = JSON.parse(options.avatar);
          if (parsed && parsed.type === 'custom' && parsed.data) {
            // Custom photo stored as JSON: {"type":"custom","data":"data:image/..."}
            avatarHtml = `<img class="toast-avatar" src="${escapeHtml(parsed.data)}" alt="Avatar">`;
          } else if (parsed && parsed.type === 'gradient' && parsed.color) {
            // Gradient avatar: {"type":"gradient","color":"#52525b"}
            avatarHtml = `<div class="toast-avatar-gradient" style="background:${escapeHtml(parsed.color)};"></div>`;
          }
        } catch (e) {
          // Not valid JSON, fall through to initials fallback
        }
      }
    }
    
    // Fallback: initials in a colored circle when there is no photo avatar
    if (!avatarHtml && options.title) {
      const initial = options.title.trim()[0]?.toUpperCase() || '?';
      const hue = ((initial.charCodeAt(0) * 31) % 360);
      avatarHtml = `<div class="toast-avatar-initials" style="background:hsl(${hue}, 60%, 50%);">${escapeHtml(initial)}</div>`;
    }
    
    toast.innerHTML = `
      ${avatarHtml}
      <div class="toast-message">
        ${titleHtml}
        ${bodyHtml}
      </div>
      <button class="toast-close" aria-label="Fechar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="18" y1="6" x2="6" y2="18"/>
          <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    `;
    
    toast.querySelector('.toast-close').addEventListener('click', () => {
      this.remove(toast);
    });
    
    this.container.appendChild(toast);
    
    if (duration > 0) {
      setTimeout(() => this.remove(toast), duration);
    }
    
    return toast;
  },
  
  remove(toast) {
    toast.style.animation = 'slideIn 0.3s ease reverse';
    setTimeout(() => toast.remove(), 300);
  },
  
  success(message, duration, options) { return this.show(message, 'success', duration, options); },
  error(message, duration, options) { return this.show(message, 'error', duration, options); },
  warning(message, duration, options) { return this.show(message, 'warning', duration, options); },
  info(message, duration, options) { return this.show(message, 'info', duration, options); }
};

// ============================================
// Modal Manager
// ============================================
const Modal = {
  open(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
      modal.classList.add('active');
      document.body.style.overflow = 'hidden';
      // Focus first focusable element
      const focusable = modal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
      if (focusable) focusable.focus();
    }
  },
  
  close(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
      modal.classList.remove('active');
      document.body.style.overflow = '';
    }
  },
  
  closeAll() {
    document.querySelectorAll('.modal-overlay.active').forEach(modal => {
      modal.classList.remove('active');
    });
    document.body.style.overflow = '';
  },
  
  init() {
    // Close on overlay click
    document.addEventListener('click', (e) => {
      if (e.target.classList.contains('modal-overlay')) {
        this.close(e.target.id);
      }
    });
    
    // Close on Escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        this.closeAll();
      }
    });
    
    // Close buttons
    document.addEventListener('click', (e) => {
      if (e.target.closest('.modal-close')) {
        const modal = e.target.closest('.modal-overlay');
        if (modal) this.close(modal.id);
      }
    });
  }
};

// ============================================
// Local Storage Helpers
// ============================================
const Storage = {
  prefix: 'gaming_calls_',

  // Preferências vindas do banco (injetadas pelo servidor em APP_CONFIG).
  // Servem de fallback quando o localStorage está vazio: assim elas sobrevivem
  // à limpeza de cache e à troca de origem (localhost <-> IP do Radmin).
  // Normaliza para objeto: o PHP envia [] quando não há nada salvo, e um array
  // descartaria as chaves string no JSON.stringify.
  server: (window.APP_CONFIG.preferences && !Array.isArray(window.APP_CONFIG.preferences))
    ? window.APP_CONFIG.preferences
    : {},

  timer: null,

  get(key, defaultValue = null) {
    try {
      const item = localStorage.getItem(this.prefix + key);
      if (item !== null) return JSON.parse(item);
    } catch { /* modo privado */ }
    const remote = this.server[key];
    return remote !== undefined && remote !== null ? remote : defaultValue;
  },

  set(key, value) {
    try {
      localStorage.setItem(this.prefix + key, JSON.stringify(value));
    } catch (e) {
      Logger.error('Storage set error:', e);
    }
    this.sync(key, value);
    return true;
  },

  // Espelha no banco, agrupando gravações próximas numa única requisição.
  sync(key, value) {
    this.server[key] = value;
    clearTimeout(this.timer);
    this.timer = setTimeout(() => this.flush(), 400);
  },

  flush() {
    const token = window.APP_CONFIG.csrfToken;
    if (!token) return;
    try {
      fetch(window.APP_CONFIG.baseUrl + '/api/preferences.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
        credentials: 'same-origin',
        body: JSON.stringify(this.server)
      }).catch(() => { /* offline: o localStorage continua valendo */ });
    } catch { /* ignore */ }
  },

  remove(key) {
    try {
      localStorage.removeItem(this.prefix + key);
    } catch { /* ignore */ }
    delete this.server[key];
    this.flush();
    return true;
  },

  clear() {
    try {
      Object.keys(localStorage)
        .filter(k => k.startsWith(this.prefix))
        .forEach(k => localStorage.removeItem(k));
    } catch { /* ignore */ }
    this.server = {};
    return true;
  }
};

// ============================================
// API Helper
// ============================================
const API = {
  baseUrl: window.APP_CONFIG.baseUrl || '',
  
  async request(endpoint, options = {}) {
    const url = `${this.baseUrl}${endpoint}`;
    const config = {
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': window.APP_CONFIG.csrfToken || '',
        ...options.headers
      },
      credentials: 'same-origin',
      ...options
    };
    
    if (config.body && typeof config.body === 'object') {
      config.body = JSON.stringify(config.body);
    }
    
    let response;
    try {
      response = await fetch(url, config);
    } catch (error) {
      Logger.error(`API error (${endpoint}):`, error);
      throw new Error('Sem conexão com o servidor');
    }

    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      Logger.error(`API error (${endpoint}):`, data.error || response.status);
      throw new Error(data.error || 'Não foi possível concluir a ação');
    }

    return data;
  },
  
  get(endpoint) { return this.request(endpoint, { method: 'GET' }); },
  post(endpoint, data) { return this.request(endpoint, { method: 'POST', body: data }); },
  put(endpoint, data) { return this.request(endpoint, { method: 'PUT', body: data }); },
  delete(endpoint) { return this.request(endpoint, { method: 'DELETE' }); }
};

// ============================================
// Device Helpers
// ============================================
const DEVICE_TYPES = {
  camera: { title: 'Câmeras', empty: 'Nenhuma câmera encontrada', fallback: 'Câmera', selectedKey: 'cameraId' },
  microphone: { title: 'Microfones', empty: 'Nenhum microfone encontrado', fallback: 'Microfone', selectedKey: 'micId' },
  speaker: { title: 'Alto-falantes', empty: 'Nenhum alto-falante encontrado', fallback: 'Alto-falante', selectedKey: 'speakerId' }
};

const Devices = {
  _callbacks: null,
  _bound: false,
  _changeTimer: null,

  _labelFor(device, type, index) {
    if (device.label && device.label.trim()) return device.label.trim();
    // Sem permissão o navegador esconde o nome: numera para diferenciar.
    return `${DEVICE_TYPES[type].fallback} ${index + 1}`;
  },

  _pick(all, kind, type) {
    const seen = new Set();
    const picked = [];
    all.forEach(device => {
      if (device.kind !== kind || !device.deviceId) return;
      // "default" e "communications" são atalhos virtuais do sistema, não
      // aparelhos físicos; a opção "Padrão do sistema" já cobre esse caso.
      if (device.deviceId === 'default' || device.deviceId === 'communications') return;
      if (seen.has(device.deviceId)) return;
      seen.add(device.deviceId);
      picked.push(device);
    });

    const counts = new Map();
    return picked.map((device, index) => {
      const base = this._labelFor(device, type, index);
      const count = (counts.get(base) || 0) + 1;
      counts.set(base, count);
      return {
        deviceId: device.deviceId,
        groupId: device.groupId || '',
        kind: device.kind,
        label: count > 1 ? `${base} (${count})` : base,
        hasLabel: !!(device.label && device.label.trim())
      };
    });
  },

  async enumerate() {
    try {
      const all = await navigator.mediaDevices.enumerateDevices();
      return {
        cameras: this._pick(all, 'videoinput', 'camera'),
        microphones: this._pick(all, 'audioinput', 'microphone'),
        speakers: this._pick(all, 'audiooutput', 'speaker')
      };
    } catch (e) {
      Logger.error('Falha ao listar dispositivos:', e);
      return { cameras: [], microphones: [], speakers: [] };
    }
  },

  needsPermission(devices) {
    const all = [devices.cameras, devices.microphones, devices.speakers].flat();
    // Sem permissão o Chromium pode não devolver nenhum aparelho (nem sem nome).
    // Nesse caso ainda mostramos o botão para liberar o acesso.
    return all.length === 0 || all.some(device => !device.hasLabel);
  },

  async getConstraints(video = true, audio = true, options = {}) {
    const constraints = {};

    if (video) {
      constraints.video = {
        width: { ideal: options.width || 1280, max: options.maxWidth || 1920 },
        height: { ideal: options.height || 720, max: options.maxHeight || 1080 },
        frameRate: { ideal: options.frameRate || 30, max: options.maxFrameRate || 60 },
        facingMode: options.facingMode || 'user',
        ...(options.deviceId ? { deviceId: { exact: options.deviceId } } : {})
      };
    }

    if (audio) {
      constraints.audio = {
        echoCancellation: options.echoCancellation ?? true,
        noiseSuppression: options.noiseSuppression ?? true,
        autoGainControl: true,
        sampleRate: 48000,
        channelCount: 2,
        ...(options.audioDeviceId ? { deviceId: { exact: options.audioDeviceId } } : {})
      };
    }

    return constraints;
  },

  populateSelect(selectElement, devices, currentDeviceId = null) {
    if (!selectElement) return;
    const previous = currentDeviceId === null || currentDeviceId === undefined
      ? selectElement.value
      : currentDeviceId;
    selectElement.innerHTML = '';

    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = 'Padrão do sistema';
    selectElement.appendChild(defaultOption);

    (devices || []).forEach(device => {
      const option = document.createElement('option');
      option.value = device.deviceId;
      option.textContent = device.label;
      option.title = device.label;
      selectElement.appendChild(option);
    });

    if (previous && !(devices || []).some(device => device.deviceId === previous)) {
      const unavailable = document.createElement('option');
      unavailable.value = previous;
      unavailable.textContent = 'Dispositivo indisponível (desconectado)';
      unavailable.disabled = true;
      selectElement.appendChild(unavailable);
    }

    if (!(devices || []).length) {
      const empty = document.createElement('option');
      empty.value = '';
      empty.textContent = 'Nenhum dispositivo encontrado';
      empty.disabled = true;
      selectElement.appendChild(empty);
    }

    selectElement.value = previous && (devices || []).some(device => device.deviceId === previous)
      ? previous
      : '';
  },

  renderList(container, devices, selected = {}) {
    if (!container) return;
    container.innerHTML = '';
    const groups = [
      ['camera', devices.cameras || []],
      ['microphone', devices.microphones || []],
      ['speaker', devices.speakers || []]
    ];
    const total = groups.reduce((sum, entry) => sum + entry[1].length, 0);
    if (!total) {
      const empty = document.createElement('p');
      empty.className = 'device-list-empty';
      empty.textContent = 'Nenhum dispositivo visível. Permita o acesso ou conecte um aparelho.';
      container.appendChild(empty);
      return;
    }

    groups.forEach(([type, items]) => {
      const meta = DEVICE_TYPES[type];
      const group = document.createElement('section');
      group.className = 'device-group';
      const heading = document.createElement('h4');
      heading.className = 'device-group-title';
      heading.textContent = `${meta.title} (${items.length})`;
      group.appendChild(heading);

      const list = document.createElement('ul');
      list.className = 'device-list';
      if (!items.length) {
        const empty = document.createElement('li');
        empty.className = 'device-list-empty';
        empty.textContent = meta.empty;
        list.appendChild(empty);
      } else {
        items.forEach(device => {
          const item = document.createElement('li');
          item.className = 'device-list-item';
          const name = document.createElement('span');
          name.className = 'device-list-name';
          name.textContent = device.label;
          name.title = device.label;
          item.appendChild(name);
          const preferred = selected[meta.selectedKey] === device.deviceId;
          if (preferred) {
            const badge = document.createElement('span');
            badge.className = 'device-list-badge';
            badge.textContent = 'Preferido';
            item.appendChild(badge);
          }
          item.dataset.deviceId = device.deviceId;
          list.appendChild(item);
        });
      }
      group.appendChild(list);
      container.appendChild(group);
    });
  },

  subscribe(callback) {
    if (typeof callback !== 'function') return () => {};
    if (!navigator.mediaDevices || typeof navigator.mediaDevices.addEventListener !== 'function') return () => {};
    if (!this._callbacks) this._callbacks = new Set();
    this._callbacks.add(callback);
    if (!this._bound) {
      this._bound = true;
      navigator.mediaDevices.addEventListener('devicechange', () => {
        clearTimeout(this._changeTimer);
        // Agrupa conexões/desconexões em rajada em uma única atualização.
        this._changeTimer = setTimeout(() => {
          const callbacks = this._callbacks ? [...this._callbacks] : [];
          callbacks.forEach(handler => {
            try { handler(); } catch (e) { Logger.error('Falha ao atualizar dispositivos:', e); }
          });
        }, 300);
      });
    }
    return () => { if (this._callbacks) this._callbacks.delete(callback); };
  }
};

// ============================================
// Utility Functions
// ============================================
const Utils = {
  generateId(prefix = '') {
    return prefix + Math.random().toString(36).substr(2, 9) + Date.now().toString(36);
  },
  
  formatDuration(seconds) {
    const hrs = Math.floor(seconds / 3600);
    const mins = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    if (hrs > 0) return `${hrs}:${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    return `${mins}:${secs.toString().padStart(2, '0')}`;
  },
  
  formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  },
  
  debounce(fn, delay) {
    let timeoutId;
    return (...args) => {
      clearTimeout(timeoutId);
      timeoutId = setTimeout(() => fn.apply(this, args), delay);
    };
  },
  
  throttle(fn, limit) {
    let inThrottle;
    return (...args) => {
      if (!inThrottle) {
        fn.apply(this, args);
        inThrottle = true;
        setTimeout(() => inThrottle = false, limit);
      }
    };
  },
  
  clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
  },

  // ==========================================
  // Avatares
  // ==========================================
  // O avatar trafega como data URL dentro do JSON de 'join' e fica salvo no
  // banco. Uma foto original (até 5MB) vira ~6,7MB em base64 e o servidor
  // WebSocket (Ratchet) corta mensagens acima de 1MB, fechando a conexão - o
  // cliente reconectava para sempre em "Reconectando". Reduzir para 256px JPEG
  // mantém o avatar bonito e o pacote em dezenas de KB.
  async shrinkImageDataUrl(dataUrl, maxSize = 256, quality = 0.85) {
    try {
      const img = await new Promise((resolve, reject) => {
        const image = new Image();
        // Nunca deixa a redução travar a página (data URL corrompida, codec
        // sem suporte, etc.).
        const timer = setTimeout(() => reject(new Error('Tempo esgotado ao decodificar a imagem')), 15000);
        image.onload = () => { clearTimeout(timer); resolve(image); };
        image.onerror = () => { clearTimeout(timer); reject(new Error('Falha ao decodificar a imagem')); };
        image.src = dataUrl;
      });

      const scale = Math.min(1, maxSize / Math.max(img.width, img.height));
      const width = Math.max(1, Math.round(img.width * scale));
      const height = Math.max(1, Math.round(img.height * scale));

      const canvas = document.createElement('canvas');
      canvas.width = width;
      canvas.height = height;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0, width, height);

      const reduced = canvas.toDataURL('image/jpeg', quality);
      // Se por algum motivo ficou maior, mantém o original
      return reduced.length < dataUrl.length ? reduced : dataUrl;
    } catch (e) {
      Logger.warn('Não foi possível reduzir a imagem do avatar:', e);
      return dataUrl;
    }
  },

};

// ============================================
// Sons personalizáveis (IndexedDB, compartilhado entre Home e sala)
// ============================================
const SOUND_DEFAULTS = Object.freeze({
  join: 'join.mp3',
  leave: 'exit-call.mp3',
  mute: 'mute.wav',
  unmute: 'unmute.wav',
  screen: 'screen.mp3'
});
const SOUND_FILE_KEYS = Object.freeze({
  'join.mp3': 'join',
  'exit-call.mp3': 'leave',
  'mute.wav': 'mute',
  'unmute.wav': 'unmute',
  'screen.mp3': 'screen'
});

const SoundStore = {
  defaults: SOUND_DEFAULTS,
  fileKeys: SOUND_FILE_KEYS,
  maxBytes: 5 * 1024 * 1024,
  _dbPromise: null,

  openDatabase() {
    if (!('indexedDB' in window)) return Promise.reject(new Error('IndexedDB indisponível'));
    if (!this._dbPromise) {
      this._dbPromise = new Promise((resolve, reject) => {
        const req = indexedDB.open('grape_room_sounds', 1);
        req.onupgradeneeded = () => {
          if (!req.result.objectStoreNames.contains('sounds')) {
            req.result.createObjectStore('sounds');
          }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => {
          this._dbPromise = null;
          reject(req.error);
        };
        req.onblocked = () => {
          this._dbPromise = null;
          reject(new Error('IndexedDB bloqueado'));
        };
      });
    }
    return this._dbPromise;
  },

  readAll() {
    return this.openDatabase().then(db => new Promise((resolve, reject) => {
      const tx = db.transaction('sounds', 'readonly');
      const store = tx.objectStore('sounds');
      const keysReq = store.getAllKeys();
      const valuesReq = store.getAll();
      tx.oncomplete = () => resolve(
        keysReq.result.map((key, index) => ({ key, value: valuesReq.result[index] }))
      );
      tx.onerror = () => reject(tx.error);
    }));
  },

  put(key, file) {
    if (!SOUND_DEFAULTS[key]) return Promise.reject(new Error('Som inválido'));
    return this.openDatabase().then(db => new Promise((resolve, reject) => {
      const tx = db.transaction('sounds', 'readwrite');
      tx.objectStore('sounds').put({ blob: file, name: file.name, type: file.type }, key);
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    }));
  },

  remove(key) {
    return this.openDatabase().then(db => new Promise((resolve, reject) => {
      const tx = db.transaction('sounds', 'readwrite');
      tx.objectStore('sounds').delete(key);
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    }));
  },

  resolve(file, custom = {}) {
    const key = SOUND_FILE_KEYS[file];
    if (key && custom[key]?.url) return custom[key].url;
    return (window.APP_CONFIG?.baseUrl || '') + '/assets/sound/' + (key ? SOUND_DEFAULTS[key] : file);
  },

  async validateFile(file) {
    if (!file || file.size === 0) {
      return { ok: false, message: 'Escolha um arquivo de áudio válido' };
    }
    if (file.size > this.maxBytes) {
      return { ok: false, message: 'O áudio deve ter no máximo 5 MB' };
    }
    if (file.type && !file.type.startsWith('audio/')) {
      return { ok: false, message: 'Escolha um arquivo de áudio válido' };
    }
    if (!(await this.canPlay(file))) {
      return { ok: false, message: 'Não foi possível reproduzir este áudio' };
    }
    return { ok: true };
  },

  canPlay(file) {
    return new Promise(resolve => {
      const url = URL.createObjectURL(file);
      const audio = new Audio();
      let settled = false;
      const finish = (valid) => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        audio.removeAttribute('src');
        URL.revokeObjectURL(url);
        resolve(valid);
      };
      const timer = setTimeout(() => finish(false), 6000);
      audio.preload = 'metadata';
      audio.onloadedmetadata = () => finish(true);
      audio.onerror = () => finish(false);
      audio.src = url;
    });
  }
};

// ============================================
// Export for modules
// ============================================
export { Logger, EventEmitter, Toast, Modal, Storage, API, Devices, Utils, SoundStore, RoomCode };