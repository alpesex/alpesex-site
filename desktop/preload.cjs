const { contextBridge, ipcRenderer } = require('electron');
contextBridge.exposeInMainWorld('cpmpNative', {
  origin: 'https://alpes-ex.fr', platform: 'windows',
  openAccount: () => ipcRenderer.invoke('cpmp:account'),
  exportJson: (data, name) => ipcRenderer.invoke('cpmp:export', {data, name}),
  openDocument: (url, name) => ipcRenderer.invoke('cpmp:document', {url, name})
});
