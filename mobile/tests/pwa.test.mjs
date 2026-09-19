import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const manifest = JSON.parse(readFileSync(new URL('../../application/manifest.webmanifest', import.meta.url), 'utf8'));
const worker = readFileSync(new URL('../../application/service-worker.js', import.meta.url), 'utf8');
const html = readFileSync(new URL('../../application/index.html', import.meta.url), 'utf8');
const gate = readFileSync(new URL('../../application/index.php', import.meta.url), 'utf8');
const accessRules = readFileSync(new URL('../../application/.htaccess', import.meta.url), 'utf8');

test('web application is installable from the canonical application URL', () => {
  assert.equal(manifest.id, '/application/');
  assert.equal(manifest.start_url, '/application/');
  assert.equal(manifest.scope, '/application/');
  assert.equal(manifest.display, 'standalone');
  assert.ok(manifest.icons.some(icon => icon.sizes === '512x512' && icon.purpose.includes('maskable')));
  assert.match(html, /data-install-app/);
  assert.match(html, /application\/install\.js/);
});

test('web installation requires a customer session and a mobile or tablet user agent', () => {
  assert.match(gate, /ALPESEXSESSID/);
  assert.match(gate, /Android\|iPhone\|iPad/);
  assert.match(gate, /Application Windows requise/);
  assert.match(accessRules, /RewriteRule \^index\\\.html\$ index\.php/);
});

test('offline shell never caches accounts, APIs, projects or documents', () => {
  assert.match(worker, /application\/install\.js/);
  for (const forbidden of ['/api/', 'project_data', 'application_projects', 'documents-status', 'document-open']) {
    assert.equal(worker.includes(forbidden), false, `${forbidden} must not enter the public cache`);
  }
});
