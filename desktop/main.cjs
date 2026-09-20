const {app, BrowserWindow, session, ipcMain, dialog, shell} = require('electron');
const path = require('node:path');
const fs = require('node:fs/promises');
const origin = 'https://alpes-ex.fr';
const smoke = process.argv.includes('--smoke-test');
// Keep the validated V5.4.18 profile so its existing portfolio can be migrated.
app.setPath('userData', path.join(app.getPath('appData'), 'cpmp-asm'));
let win, legacyProjectsJson = null;
async function readLegacyProjects(ses) {
  const reader = new BrowserWindow({show:false,webPreferences:{session:ses,sandbox:true,contextIsolation:true,nodeIntegration:false}});
  try {
    await reader.loadFile(path.join(__dirname, 'legacy-reader.html'));
    const value = await reader.webContents.executeJavaScript("localStorage.getItem('pcasm_pro_projects')");
    return typeof value === 'string' && value !== '[]' ? value : null;
  } catch { return null; }
  finally { reader.destroy(); }
}
function allowedSender(event) {
  if (event.sender !== win.webContents || event.senderFrame !== win.webContents.mainFrame ||
      event.senderFrame.url !== origin + '/application/') throw new Error('Accès refusé');
}
function filename(value) { return path.basename(String(value || 'document')).replace(/[<>:"/\\|?*\x00-\x1f]/g, '_'); }
app.whenReady().then(async () => {
  const ses = smoke ? session.fromPartition('smoke-test') : session.defaultSession;
  legacyProjectsJson = await readLegacyProjects(ses);
  ses.setPermissionRequestHandler((_contents, _permission, callback) => callback(false));
  ses.protocol.handle('https', async request => {
    const url = new URL(request.url);
    if (url.origin !== origin) return new Response('Forbidden', {status:403});
    if (url.pathname.startsWith('/api/')) {
      if (smoke) return new Response(JSON.stringify({error:'AUTHENTICATION_REQUIRED'}), {status:401, headers:{'Content-Type':'application/json'}});
      return ses.fetch(request, {bypassCustomProtocolHandlers:true, credentials:'include', redirect:'error'});
    }
    let pathname = decodeURIComponent(url.pathname);
    if (pathname === '/application/') pathname += 'index.html';
    if (!pathname.startsWith('/application/') && !pathname.startsWith('/assets/')) return new Response('Not found', {status:404});
    const root = path.join(__dirname, 'www'), file = path.resolve(root, '.' + pathname);
    if (!file.startsWith(root + path.sep)) return new Response('Forbidden', {status:403});
    try {
      const type = {'.html':'text/html; charset=utf-8','.js':'application/javascript','.css':'text/css','.png':'image/png','.svg':'image/svg+xml','.webmanifest':'application/manifest+json'}[path.extname(file)] || 'application/octet-stream';
      return new Response(await fs.readFile(file), {headers:{'Content-Type':type,'Cache-Control':'no-store'}});
    } catch { return new Response('Not found', {status:404}); }
  });
  ipcMain.on('cpmp:legacy-projects', event => { event.returnValue = legacyProjectsJson; legacyProjectsJson = null; });
  win = new BrowserWindow({width:1360,height:900,show:!smoke,title:'CPMP ASM',icon:path.join(__dirname,'www','assets','favicon.ico'),
    webPreferences:{session:ses,preload:path.join(__dirname,'preload.cjs'),sandbox:true,contextIsolation:true,nodeIntegration:false}});
  win.webContents.setWindowOpenHandler(({url}) => url === 'about:blank'
    ? {action:'allow', overrideBrowserWindowOptions:{webPreferences:{sandbox:true,contextIsolation:true,nodeIntegration:false,preload:''}}}
    : {action:'deny'});
  win.webContents.on('will-navigate', (event, url) => {if (url !== origin + '/application/') event.preventDefault();});
  win.webContents.on('will-attach-webview', event => event.preventDefault());
  ipcMain.handle('cpmp:account', async event => {allowedSender(event);await shell.openExternal(origin + '/compte/');});
  ipcMain.handle('cpmp:export', async (event, payload) => {
    allowedSender(event);
    const {canceled,filePath} = await dialog.showSaveDialog(win, {defaultPath:filename(payload.name), filters:[{name:'Projet CPMP',extensions:['json']}]});
    if (canceled) throw new Error('Export annulé');
    await fs.writeFile(filePath, JSON.stringify(payload.data,null,2));
  });
  ipcMain.handle('cpmp:document', async (event, payload) => {
    allowedSender(event); const url = new URL(payload.url);
    if (url.origin !== origin || url.pathname !== '/api/application/' || url.searchParams.get('action') !== 'document-open') throw new Error('Adresse documentaire invalide');
    const response = await ses.fetch(url.href,{credentials:'include',bypassCustomProtocolHandlers:true,redirect:'error'});
    if (!response.ok) throw new Error('Document inaccessible');
    const bytes = Buffer.from(await response.arrayBuffer());
    if (bytes.length > 1024*1024) throw new Error('Document trop volumineux');
    const {canceled,filePath} = await dialog.showSaveDialog(win, {defaultPath:filename(payload.name)});
    if (!canceled) await fs.writeFile(filePath,bytes);
  });
  await win.loadURL(origin + '/application/');
  if (smoke) {
    const result = await win.webContents.executeJavaScript(`(async()=>{
      const base={platform:window.cpmpNative.platform,bridge:typeof window.erpAsmProjects.save,queue:typeof window.CpmpSyncQueue,form:!!document.getElementById('authEmail'),node:typeof require};
      ERP_SESSION={mode:'manager',role:'manager',offline:true,email:'smoke@example.test'};renderPortfolio(true);createNewProject();
      base.newProjectModal=document.getElementById('newProjectModal').classList.contains('open');
      document.getElementById('newProjectName').value='Projet smoke Windows';await confirmCreateProject({preventDefault(){}});
      base.newProjectSaved=getProjects().some(project=>project.meta.nomProjet==='Projet smoke Windows');
      base.newProjectOpened=currentMode==='admin'&&document.getElementById('newProjectModal').classList.contains('open')===false;
      return base;
    })()`);
    if (result.platform !== 'windows' || result.bridge !== 'function' || result.queue !== 'function' || !result.form || result.node !== 'undefined' || !result.newProjectModal || !result.newProjectSaved || !result.newProjectOpened) throw new Error(JSON.stringify(result));
    console.log('Windows shared UI, new project flow, bridge, isolated preload and sandbox: OK');
    app.exit(0);
  }
}).catch(error => {console.error(error);app.exit(1);});
// Destroying the temporary legacy reader must not quit the application before
// the main window has been created.
app.on('window-all-closed', () => { if (win) app.quit(); });
