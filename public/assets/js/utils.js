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
  enabled: true,
  
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
    
    try {
      const response = await fetch(url, config);
      const data = await response.json().catch(() => ({}));
      
      if (!response.ok) {
        throw new Error(data.error || `HTTP ${response.status}`);
      }
      
      return data;
    } catch (error) {
      Logger.error(`API error (${endpoint}):`, error);
      throw error;
    }
  },
  
  get(endpoint) { return this.request(endpoint, { method: 'GET' }); },
  post(endpoint, data) { return this.request(endpoint, { method: 'POST', body: data }); },
  put(endpoint, data) { return this.request(endpoint, { method: 'PUT', body: data }); },
  delete(endpoint) { return this.request(endpoint, { method: 'DELETE' }); }
};

// ============================================
// Device Helpers
// ============================================
const Devices = {
  async enumerate() {
    try {
      const devices = await navigator.mediaDevices.enumerateDevices();
      return {
        cameras: devices.filter(d => d.kind === 'videoinput'),
        microphones: devices.filter(d => d.kind === 'audioinput'),
        speakers: devices.filter(d => d.kind === 'audiooutput')
      };
    } catch (e) {
      Logger.error('Device enumeration failed:', e);
      return { cameras: [], microphones: [], speakers: [] };
    }
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
    const placeholder = selectElement.querySelector('option[value=""]');
    selectElement.innerHTML = '';
    if (placeholder) selectElement.appendChild(placeholder);
    
    devices.forEach(device => {
      const option = document.createElement('option');
      option.value = device.deviceId;
      option.textContent = device.label || `Dispositivo ${device.deviceId.slice(0, 8)}...`;
      if (device.deviceId === currentDeviceId) option.selected = true;
      selectElement.appendChild(option);
    });
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
  // banco. Uma foto original (até 2MB) vira ~2,7MB em base64 e o servidor
  // WebSocket (Ratchet) corta mensagens acima de 1MB, fechando a conexão - o
  // cliente reconectava para sempre em "Reconectando". Reduzir para 256px JPEG
  // mantém o avatar bonito e o pacote em dezenas de KB.
  async shrinkImageDataUrl(dataUrl, maxSize = 256, quality = 0.85) {
    try {
      const img = await new Promise((resolve, reject) => {
        const image = new Image();
        // Nunca deixa a redução travar a página (data URL corrompida, codec
        // sem suporte, etc.).
        const timer = setTimeout(() => reject(new Error('Tempo esgotado ao decodificar a imagem')), 5000);
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
// Export for modules
// ============================================
export { Logger, EventEmitter, Toast, Modal, Storage, API, Devices, Utils };