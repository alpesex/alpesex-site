// Bake the same presentation into embedded/exported views without changing their logic.
const fs = require('node:fs');
const path = require('node:path');
const appDir = path.resolve(__dirname, '../../application');
const css = fs.readFileSync(path.join(appDir, 'industrial.css'), 'utf8');
const effects = '<script src="/application/photon-effects.js"></script>';
const marker = /<style id="alpesex-industrial">[\s\S]*?<\/style>/g;
function themeEmbedded(html) {
  html = html.replaceAll('<script src="/application/photon-effects.js"><\\/script>', '');
  html = html.replace(/const (CLIENT_TEMPLATE_B64|ADMIN_TEMPLATE_B64)="([^"]+)"/g, (_all, name, encoded) => {
    const themed = themeEmbedded(Buffer.from(encoded, 'base64').toString('utf8'));
    return `const ${name}="${Buffer.from(themed).toString('base64')}"`;
  });
  html = html.replace(marker, '').replace('</head>', `<style id="alpesex-industrial">${css}</style></head>`);
  return html.includes('/application/photon-effects.js') ? html : html.replace('</body>', effects + '</body>');
}
const file = path.join(appDir, 'index.html');
let html = fs.readFileSync(file, 'utf8');
html = html.replace(/const (CLIENT_TEMPLATE_B64|ADMIN_TEMPLATE_B64)="([^"]+)"/g, (_all, name, encoded) =>
  `const ${name}="${Buffer.from(themeEmbedded(Buffer.from(encoded, 'base64').toString('utf8'))).toString('base64')}"`);
if (!html.includes('href="/application/industrial.css"')) html = html.replace('</head>', '<link rel="stylesheet" href="/application/industrial.css">\n</head>');
fs.writeFileSync(file, html);
console.log('Industrial theme applied to shell and embedded/exported views.');
