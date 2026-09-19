/* Shared by the browser, iOS, Android and the Windows Cloud preview. */
(() => {
  'use strict';
  let ready = false, applying = false, busy = false, lastPull = 0;
  const baseline = new Map();
  const status = document.createElement('button');
  status.id = 'syncStatus'; status.type = 'button'; status.hidden = true;
  status.setAttribute('aria-live', 'polite');
  document.getElementById('logoutButton').before(status);
  function fingerprint(project) {
    const p = normalizeProject(project);
    for (const key of ['cloudId', 'cloudAccess', 'ownerEmail']) delete p.meta[key];
    return JSON.stringify(p);
  }
  function message(text) { status.hidden = !ERP_SESSION; status.textContent = text; }
  function stage(list) {
    if (!ready || applying || !ERP_SESSION) return;
    for (const p of list) {
      if (projectCanEdit(p) && baseline.get(p.meta.portfolioId) !== fingerprint(p)) {
        window.erpAsmProjects.stage(p);
        message('Modifications en attente');
      }
    }
  }
  const originalSave = saveProjects;
  saveProjects = function (list) {
    stage(list); originalSave(list);
    if (ready && ERP_SESSION) void tick(true);
  };
  syncProjectsFromCloud = async function (snapshot) {
    const result = snapshot || await window.erpAsmProjects.list();
    if (ready && ['admin','dc-admin'].includes(currentMode)) return false;
    const drafts = window.erpAsmProjects.drafts(), merged = new Map(), accepted = [];
    for (const item of result.projects || []) {
      if (!item.data?.meta) continue;
      const p = normalizeProject(item.data);
      Object.assign(p.meta, {portfolioId:item.localId, cloudId:item.id, cloudAccess:item.access, ownerEmail:item.ownerEmail || ''});
      merged.set(item.localId, p);
      if (!drafts[item.localId]) { accepted.push(item); baseline.set(item.localId, fingerprint(p)); }
    }
    for (const [id, entry] of Object.entries(drafts)) merged.set(id, normalizeProject(entry.project));
    applying = true;
    try { originalSave([...merged.values()]); window.erpAsmProjects.acceptSnapshot(accepted); }
    finally { applying = false; }
    ready = true; lastPull = Date.now();
    message(Object.keys(drafts).length ? 'Modifications en attente' : 'Synchronisé');
    return true;
  };
  function capture() {
    if (!ready || !ERP_SESSION) return;
    if (currentMode === 'admin' && adminFrame.contentWindow?.PCASMBridge) savePCBeforeDC();
    else if (currentMode === 'dc-admin') dcPersist();
  }
  async function flush() {
    capture();
    for (const id of Object.keys(window.erpAsmProjects.drafts())) {
      const result = await window.erpAsmProjects.flush(id);
      const list = getProjects(), project = list.find(p => p.meta.portfolioId === id);
      if (project && result) {
        Object.assign(project.meta, {cloudId:result.id, cloudAccess:result.access});
        baseline.set(id, fingerprint(project));
        originalSave(list);
      }
    }
  }
  async function tick(force = false) {
    if (busy || !ready || !ERP_SESSION || !authScreen.classList.contains('hidden')) return;
    busy = true;
    try {
      await flush();
      // Never replace an open editor, including controls that have not lost focus yet.
      if (!['admin','dc-admin'].includes(currentMode) && (force || Date.now() - lastPull > 15000)) {
        const mode = currentMode, id = currentProjectId, before = id && projectById(id);
        if (!await syncProjectsFromCloud()) return;
        const after = id && projectById(id);
        if (mode === 'portfolio' || !after) renderPortfolio(portfolioAdmin);
        else if (JSON.stringify(before) !== JSON.stringify(after)) {
          if (mode === 'dc-client') openDCProject(id, false); else showClient(after);
        }
      }
      message('Synchronisé');
    } catch (error) {
      message(error.code === 'PROJECT_CONFLICT' || error.code === 'PROJECT_DELETED'
        ? 'Conflit — copie locale conservée' : 'En attente de synchronisation');
      status.title = error.message;
    } finally { busy = false; }
  }
  const originalCloudSave = saveCloudProject;
  saveCloudProject = async function (project) {
    const snapshot = clone(project), result = await originalCloudSave(project);
    if (result) baseline.set(snapshot.meta.portfolioId, fingerprint(snapshot));
    return result;
  };
  const originalPortfolio = renderPortfolio;
  renderPortfolio = function (adminMode) { capture(); originalPortfolio(adminMode); };
  const originalLogout = logoutButton.onclick;
  logoutButton.onclick = async function () {
    capture();
    try { await flush(); } catch (_) {}
    if (Object.keys(window.erpAsmProjects.drafts()).length) {
      alert('Des modifications ne sont pas encore synchronisées. Exportez votre portefeuille ou résolvez le conflit avant de vous déconnecter.');
      return;
    }
    ready = false; baseline.clear(); await originalLogout(); status.hidden = true;
  };
  status.onclick = async () => {
    await tick(true);
    if (!Object.keys(window.erpAsmProjects.drafts()).length) return;
    const modal = document.createElement('div'); modal.className = 'modal open';
    const box = document.createElement('div'); box.className = 'dialog';
    const title = document.createElement('h2'); title.textContent = 'Modifications conservées sur cet appareil';
    const explanation = document.createElement('p');
    explanation.textContent = (status.title || 'Connexion indisponible.') + ' Vous pouvez exporter votre copie, la conserver pour réessayer, ou reprendre la version du Cloud.';
    const exportButton = document.createElement('button'); exportButton.textContent = 'Exporter ma copie';
    exportButton.onclick = async () => {
      capture();
      const data = {type:'ERP_ASM_PORTFOLIO',projects:getProjects(),exportedAt:new Date().toISOString()};
      try { if (window.cpmpNative) await window.cpmpNative.exportJson(data,'CPMP-copie-locale.json'); else downloadJsonFile(data,'CPMP-copie-locale.json'); }
      catch (error) { explanation.textContent = error.message; }
    };
    const reload = document.createElement('button'); reload.textContent = 'Reprendre la version Cloud';
    reload.onclick = async () => {
      if (busy || !confirm('Abandonner les modifications locales non synchronisées et charger les versions du Cloud ? Exportez votre copie avant de continuer si vous souhaitez la conserver.')) return;
      busy = true;
      // Obtain the replacement first: a network failure must never delete the local draft.
      try {
        const replacement = await window.erpAsmProjects.list();
        ready = false;
        for (const id of Object.keys(window.erpAsmProjects.drafts())) window.erpAsmProjects.discardDraft(id);
        currentMode = 'portfolio'; currentProjectId = null;
        await syncProjectsFromCloud(replacement); renderPortfolio(portfolioAdmin); modal.remove();
      } catch (error) { explanation.textContent = error.message; }
      finally { ready = true; busy = false; }
    };
    const close = document.createElement('button'); close.textContent = 'Conserver et fermer'; close.onclick = () => modal.remove();
    box.append(title, explanation, exportButton, reload, close); modal.append(box); document.body.append(modal);
  };
  adminFrame.addEventListener('load', () => {
    // Capture after the embedded form has applied its own change handlers.
    for (const event of ['input', 'change']) adminFrame.contentDocument?.addEventListener(event, () => setTimeout(capture, 0));
  });
  window.addEventListener('online', () => tick(true));
  window.addEventListener('cpmp:resume', () => tick(true));
  document.addEventListener('visibilitychange', () => { capture(); if (!document.hidden) tick(true); });
  window.addEventListener('beforeunload', event => {
    capture();
    if (ready && Object.keys(window.erpAsmProjects.drafts()).length) { event.preventDefault(); event.returnValue = ''; }
  });
  setInterval(() => { if (!document.hidden) tick(true); }, 2000);
})();
