const { contextBridge, ipcRenderer } = require('electron');
const legacyProjects = ipcRenderer.sendSync('cpmp:legacy-projects');
if (legacyProjects && localStorage.getItem('alpesex.application.account') === null && localStorage.getItem('pcasm_pro_projects') === null) {
  localStorage.setItem('pcasm_pro_projects', legacyProjects);
}
contextBridge.exposeInMainWorld('cpmpNative', {
  origin: 'https://alpes-ex.fr', platform: 'windows',
  openAccount: () => ipcRenderer.invoke('cpmp:account'),
  exportJson: (data, name) => ipcRenderer.invoke('cpmp:export', {data, name}),
  openDocument: (url, name) => ipcRenderer.invoke('cpmp:document', {url, name})
});
