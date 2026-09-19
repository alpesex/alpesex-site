import { Capacitor, CapacitorHttp } from '@capacitor/core';
import { App } from '@capacitor/app';
import { Browser } from '@capacitor/browser';
import { Filesystem, Directory, Encoding } from '@capacitor/filesystem';
import { Share } from '@capacitor/share';

const origin = 'https://alpes-ex.fr';
if (Capacitor.isNativePlatform()) {
  const safeName = name => String(name || 'document').replace(/[^\p{L}\p{N}._-]/gu, '_').slice(0, 150);
  async function shareFile(data, name, encoding) {
    const path = `cpmp-share/${Date.now()}-${safeName(name)}`;
    const { uri } = await Filesystem.writeFile({ path, data, directory: Directory.Cache, recursive: true, ...(encoding ? { encoding } : {}) });
    try { await Share.share({ title: name, files: [uri] }); }
    finally { await Filesystem.deleteFile({ path, directory: Directory.Cache }).catch(() => {}); }
  }
  window.cpmpNative = {
    origin,
    platform: Capacitor.getPlatform(),
    openAccount: () => Browser.open({ url: origin + '/compte/' }),
    exportJson: (data, name) => shareFile(JSON.stringify(data, null, 2), name, Encoding.UTF8),
    async openDocument(path, name) {
      const url = new URL(path, origin);
      if (url.origin !== origin || url.pathname !== '/api/application/' || url.searchParams.get('action') !== 'document-open') throw new Error('Adresse documentaire invalide.');
      const result = await CapacitorHttp.get({ url: url.href, responseType: 'arraybuffer' });
      if (result.status !== 200 || typeof result.data !== 'string') throw new Error('Le document n’est pas accessible.');
      await shareFile(result.data, name);
    }
  };
  App.addListener('backButton', () => window.dispatchEvent(new Event('cpmp:native-back')));
  App.addListener('appStateChange', ({ isActive }) => {
    if (isActive) window.dispatchEvent(new Event('cpmp:resume'));
  });
}
