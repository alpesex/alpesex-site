import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';

const base = 'http://127.0.0.1:18765';
const resource = 'https://alpes-ex.fr/api/admin/agents/mcp/';
const client = 'https://chatgpt.com/oauth/client.json';
const redirect = 'https://chatgpt.com/connector_platform_oauth_redirect';
const scope = 'agent_cockpit:coordinate';
const verifier = 'v'.repeat(43);
const challenge = createHash('sha256').update(verifier).digest('base64url');

async function request(path, options = {}) {
  return fetch(base + path, { redirect: 'manual', ...options });
}

let ready = false;
for (let attempt = 0; attempt < 30; attempt++) {
  try {
    const response = await request('/session-fixture.php');
    if (response.ok) { ready = true; break; }
  } catch { /* PHP startup */ }
  await new Promise(resolve => setTimeout(resolve, 300));
}
assert.ok(ready, 'Serveur PHP local indisponible');

const anonymous = await request('/api/admin/agents/mcp/', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'initialize', params: {} }) });
assert.equal(anonymous.status, 401);
assert.match(anonymous.headers.get('www-authenticate') || '', /resource_metadata=/);

const session = await request('/session-fixture.php');
const cookie = session.headers.get('set-cookie')?.split(';')[0];
assert.ok(cookie?.startsWith('ALPESEXSESSID='));
const authorize = new URLSearchParams({
  flow: 'authorize', client_id: client, redirect_uri: redirect,
  response_type: 'code', resource, code_challenge_method: 'S256',
  code_challenge: challenge, scope, state: 'http-test-state-1234',
});
const page = await request('/api/admin/agents/oauth/?' + authorize, { headers: { Cookie: cookie } });
assert.equal(page.status, 200);
const html = await page.text();
const csrf = /name="csrf" value="([a-f0-9]+)"/.exec(html)?.[1];
assert.ok(csrf, 'Formulaire d’autorisation absent');

const approval = await request('/api/admin/agents/oauth/?flow=authorize', {
  method: 'POST', headers: { Cookie: cookie, 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams({ ...Object.fromEntries(authorize), csrf }),
});
assert.equal(approval.status, 303);
const location = new URL(approval.headers.get('location'));
assert.equal(location.origin + location.pathname, redirect);
assert.equal(location.searchParams.get('iss'), 'https://alpes-ex.fr');
const code = location.searchParams.get('code');
assert.ok(code);

const exchangeBody = new URLSearchParams({
  grant_type: 'authorization_code', client_id: client, redirect_uri: redirect,
  resource, code, code_verifier: verifier,
});
const exchange = await request('/api/admin/agents/oauth/?flow=token', {
  method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: exchangeBody,
});
assert.equal(exchange.status, 200);
const access = (await exchange.json()).access_token;
assert.ok(access);
const replay = await request('/api/admin/agents/oauth/?flow=token', {
  method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: exchangeBody,
});
assert.equal(replay.status, 400);

async function rpc(method, params = {}) {
  const response = await request('/api/admin/agents/mcp/', {
    method: 'POST', headers: { Authorization: 'Bearer ' + access, 'Content-Type': 'application/json' },
    body: JSON.stringify({ jsonrpc: '2.0', id: 1, method, params }),
  });
  assert.equal(response.status, 200);
  return response.json();
}

assert.equal((await rpc('initialize', { protocolVersion: '2025-11-25' })).result.protocolVersion, '2025-11-25');
const names = (await rpc('tools/list')).result.tools.map(tool => tool.name);
assert.deepEqual(names, ['dossier_upsert', 'enregistrer_evenement', 'demander_decision', 'lire_decisions', 'lire_entrees', 'traiter_entree']);
const dossier = 'TEST-HTTP-LOCAL';
assert.equal((await rpc('tools/call', { name: 'dossier_upsert', arguments: { dossierId: dossier, title: 'Fictif', status: 'open', priority: 'normal' } })).result.structuredContent.dossierId, dossier);
const cockpit = await request('/api/admin/agents/', { headers: { Cookie: cookie, Accept: 'application/json' } });
assert.equal(cockpit.status, 200);
const cockpitState = await cockpit.json();
const createInput = await request('/api/admin/agents/', {
  method: 'POST',
  headers: { Cookie: cookie, 'Content-Type': 'application/json', 'X-CSRF-Token': cockpitState.csrf },
  body: JSON.stringify({ action: 'create_input', inputId: 'TEST-HTTP-INPUT', source: 'cockpit', title: 'Entrée HTTP fictive', summary: 'Tester la file MCP', priority: 'high' }),
});
assert.equal(createInput.status, 200);
const inputs = (await rpc('tools/call', { name: 'lire_entrees', arguments: { limit: 10 } })).result.structuredContent.entrees;
assert.ok(inputs.some(input => input.entreeId === 'TEST-HTTP-INPUT'));
assert.equal((await rpc('tools/call', { name: 'traiter_entree', arguments: { entreeId: 'TEST-HTTP-INPUT', executionId: 'http:run:0001', action: 'prendre' } })).result.structuredContent.status, 'processing');
assert.equal((await rpc('tools/call', { name: 'traiter_entree', arguments: { entreeId: 'TEST-HTTP-INPUT', executionId: 'http:run:0001', action: 'terminer', dossierId: dossier, note: 'Test terminé' } })).result.structuredContent.status, 'completed');
const event = {
  eventId: 'http:coordination:0001', dossierId: dossier,
  agent: 'coordination', destinataire: 'satisfaction', type: 'transmission',
  statut: 'active', resume: 'Transmission fictive', livrables: [], prochaineAction: 'Qualifier',
};
assert.equal((await rpc('tools/call', { name: 'enregistrer_evenement', arguments: event })).result.structuredContent.duplicate, false);
assert.equal((await rpc('tools/call', { name: 'enregistrer_evenement', arguments: event })).result.structuredContent.duplicate, true);
assert.deepEqual((await rpc('tools/call', { name: 'lire_decisions', arguments: { dossierId: dossier } })).result.structuredContent.decisions, []);
console.log('Cockpit HTTP OAuth/MCP integration: OK');
