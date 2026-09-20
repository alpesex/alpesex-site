import { readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { resolve, dirname } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const applicationPath = resolve(root, 'application/index.html');
let application = await readFile(applicationPath, 'utf8');
const match = application.match(/const ADMIN_TEMPLATE_B64="([A-Za-z0-9+/=]+)"/);
if (!match) throw new Error('ADMIN_TEMPLATE_B64 introuvable.');

let admin = Buffer.from(match[1], 'base64').toString('utf8');
admin = admin.replace(
  "['ASM-204','Budget'],['ASM-205','Délais']",
  "['ASM-204','Coût'],['ASM-205','Délai']"
);

if (!admin.includes('function euro(v)')) {
  const anchor = "function dayDiff(a,b){const da=dateOrNull(a),db=dateOrNull(b);return da&&db?Math.round((db-da)/86400000):0}\n";
  const helpers = `${anchor}function euro(v){return new Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR',maximumFractionDigits:0}).format(num(v))}\nfunction formatDate(v){const d=dateOrNull(v);return d?d.toLocaleDateString('fr-FR'):'—'}\n`;
  if (!admin.includes(anchor)) throw new Error('Point d’insertion des formats Coût/Délai introuvable.');
  admin = admin.replace(anchor, helpers);
}

const encoded = Buffer.from(admin, 'utf8').toString('base64');
application = application.replace(match[0], `const ADMIN_TEMPLATE_B64="${encoded}"`);
await writeFile(applicationPath, application);
console.log('Sections Coût et Délai du pilotage transverse réparées.');
