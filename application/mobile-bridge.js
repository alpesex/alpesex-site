(function () {
  'use strict';

  const API = '/api/application/';
  const AUTH = '/api/auth/';
  const licenseKey = 'alpesex.application.license';
  const deviceKey = 'alpesex.application.device';
  const revisionKey = 'alpesex.application.revisions';
  let currentSession = null;
  let projectCache = [];

  function errorText(code) {
    return ({
      AUTHENTICATION_REQUIRED: 'Connectez-vous avec votre compte ALPES’Ex.',
      LICENSE_ACTIVATION_REQUIRED: 'Saisissez votre licence utilisateur pour activer cet appareil.',
      LICENSE_INVALID: 'Cette licence est absente, expirée, suspendue ou révoquée.',
      LICENSE_ACCOUNT_MISMATCH: 'Cette licence n’est pas affectée à ce compte.',
      DEVICE_LIMIT_REACHED: 'Cette licence est déjà active sur trois appareils.',
      DEVICE_REVOKED: 'Cet appareil a été révoqué.',
      READ_ONLY: 'Ce projet est accessible en lecture seule.',
      PROJECT_CONFLICT: 'Le projet a été modifié sur un autre appareil. Rechargez les données.',
      DOCUMENT_TOO_LARGE: 'Le document dépasse la limite de 1 Mo.',
      DOCUMENT_NOT_FOUND: 'Ce document n’est pas accessible sur le Cloud.',
      APPLICATION_UNAVAILABLE: 'L’application ALPES’Ex est momentanément indisponible.'
    })[code] || code || 'Opération impossible.';
  }

  async function request(url, options) {
    const response = await fetch(url, { credentials: 'same-origin', ...options });
    const type = response.headers.get('content-type') || '';
    const body = type.includes('application/json') ? await response.json().catch(() => ({})) : null;
    if (!response.ok) throw new Error(errorText(body?.error || body?.message));
    return body;
  }

  function claims(token) {
    try {
      const value = String(token || '').split('.')[0].replace(/-/g, '+').replace(/_/g, '/');
      return JSON.parse(decodeURIComponent(Array.from(atob(value), c => '%' + c.charCodeAt(0).toString(16).padStart(2, '0')).join('')));
    } catch (_) { return {}; }
  }

  function deviceIdentifier() {
    let value = localStorage.getItem(deviceKey);
    if (/^[a-f0-9]{64}$/.test(value || '')) return value;
    const bytes = crypto.getRandomValues(new Uint8Array(32));
    value = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
    localStorage.setItem(deviceKey, value);
    return value;
  }

  function revisions() {
    try { return JSON.parse(localStorage.getItem(revisionKey) || '{}'); } catch (_) { return {}; }
  }

  function saveRevision(localId, revision) {
    const all = revisions(); all[localId] = revision;
    localStorage.setItem(revisionKey, JSON.stringify(all));
  }

  async function projects() {
    const result = await request(API + '?action=projects');
    projectCache = result.projects || [];
    projectCache.forEach(item => saveRevision(item.localId, item.revision));
    return projectCache;
  }

  async function cloudProject(project) {
    const localId = String(project?.id || project?.meta?.portfolioId || '');
    let found = projectCache.find(item => item.localId === localId);
    if (!found) found = (await projects()).find(item => item.localId === localId);
    if (found) return found;
    const minimal = project?.meta ? project : { meta: { portfolioId: localId, nomProjet: project?.name || 'Projet CPMP - ASM' } };
    const result = await window.erpAsmProjects.save(minimal);
    return { id: result.id, localId, revision: result.revision, access: 'editor', data: minimal };
  }

  window.erpAsmAuth = {
    async state() {
      const token = localStorage.getItem(licenseKey) || '';
      try {
        const result = await request(API + '?action=session');
        currentSession = result.user;
        return { configured: true, online: true, needsLicense: false, email: result.user.email, licenseEmail: claims(token).email || result.user.email, licenseToken: token, rememberMe: true };
      } catch (_) {
        return { configured: false, online: navigator.onLine, needsLicense: true, email: claims(token).email || '', licenseEmail: claims(token).email || '', licenseToken: token, rememberMe: true };
      }
    },
    async login(payload) {
      const token = String(payload.licenseToken || localStorage.getItem(licenseKey) || '').trim();
      const role = String(claims(token).role || 'user').toLowerCase();
      await request(AUTH + 'login/', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: payload.email, password: payload.password, profileType: role === 'manager' ? 'manager' : 'user', remember: Boolean(payload.rememberMe) }) });
      const activated = await request(API + '?action=activate', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ licenseToken: token, deviceIdentifier: deviceIdentifier(), deviceName: `${navigator.platform || 'Mobile'} - CPMP ASM` }) });
      localStorage.setItem(licenseKey, token);
      currentSession = activated.user;
      const effectiveRole = activated.activation.role || activated.user.role;
      return { company: 'Organisation ASM', manager: activated.user.email, email: activated.user.email, role: effectiveRole, mode: effectiveRole === 'direction' ? 'viewer' : 'manager', offline: false, mustChangePassword: false };
    },
    register() { throw new Error('Créez votre compte depuis alpes-ex.fr.'); },
    forgotPassword() { window.open('/compte/', '_blank'); return Promise.resolve({ message: 'Utilisez « Mot de passe oublié » sur alpes-ex.fr.' }); },
    changePassword() { throw new Error('Modifiez votre mot de passe depuis alpes-ex.fr.'); },
    verifyPassword() { return Promise.resolve({ ok: true }); },
    async session() { return currentSession; },
    async logout() {
      await request(AUTH + 'logout/', { method: 'POST' }).catch(() => null);
      currentSession = null; projectCache = [];
      return true;
    }
  };

  window.erpAsmProjects = {
    async list() { return { projects: await projects() }; },
    async save(project) {
      const localId = String(project?.meta?.portfolioId || '');
      const result = await request(API + '?action=project-save', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ project, revision: revisions()[localId] }) });
      saveRevision(localId, result.revision);
      projectCache = projectCache.filter(item => item.localId !== localId);
      projectCache.push({ id: result.id, localId, revision: result.revision, access: result.access, data: project, ownerEmail: currentSession?.email || '' });
      return result;
    }
  };

  window.erpAsmDocuments = {
    async state() { return { configured: true, host: 'Cloud ALPES’Ex', connected: navigator.onLine }; },
    async ensureProject(payload) { const item = await cloudProject(payload.project); return { id: item.id }; },
    async status(payload) {
      const item = await cloudProject(payload.project);
      return request(API + '?action=documents-status', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ projectId: item.id, documents: payload.documents }) });
    },
    async upload(payload) {
      const item = await cloudProject(payload.project);
      const file = await new Promise(resolve => {
        const input = document.createElement('input'); input.type = 'file'; input.style.display = 'none';
        input.onchange = () => { const selected = input.files?.[0] || null; input.remove(); resolve(selected); };
        document.body.appendChild(input); input.click();
      });
      if (!file) return { canceled: true };
      if (file.size > 1024 * 1024) throw new Error('Le document dépasse la limite de 1 Mo.');
      const form = new FormData(); form.append('projectId', item.id); form.append('ref', payload.document.ref); form.append('family', payload.document.family || ''); form.append('file', file);
      return request(API + '?action=document-upload', { method: 'POST', body: form });
    },
    async open(payload) {
      const item = await cloudProject(payload.project);
      const url = API + '?action=document-open&projectId=' + encodeURIComponent(item.id) + '&ref=' + encodeURIComponent(payload.document.ref);
      window.open(url, '_blank', 'noopener');
      return { ok: true };
    }
  };

  window.erpAsmBackups = {
    async list() {
      const list = await projects();
      return { backups: list.map(item => ({ id: item.id, name: 'Sauvegarde_' + item.name, manager: item.ownerEmail, category: 'Projet Cloud', project: item.name, modifiedAt: item.updatedAt })) };
    },
    async load(id) {
      const item = (await projects()).find(project => project.id === id);
      if (!item) throw new Error('Sauvegarde introuvable.');
      return { data: item.data };
    },
    async save(payload) {
      if (Array.isArray(payload.data?.projects)) {
        for (const project of payload.data.projects) await window.erpAsmProjects.save(project);
      } else if (Array.isArray(payload.data)) {
        for (const project of payload.data) await window.erpAsmProjects.save(project);
      } else if (payload.data?.meta) await window.erpAsmProjects.save(payload.data);
      return { ok: true };
    }
  };

  if ('serviceWorker' in navigator) window.addEventListener('load', () => navigator.serviceWorker.register('/application/service-worker.js').catch(() => null));
})();
