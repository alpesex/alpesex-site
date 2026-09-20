import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFileSync } from 'node:fs';
const queueSource = readFileSync(new URL('../../application/sync-queue.js', import.meta.url), 'utf8');
const source = queueSource + '\n' + readFileSync(new URL('../../application/mobile-bridge.js', import.meta.url), 'utf8');
function setup(responses, initial = {}) {
  const data = new Map(Object.entries(initial));
  const calls = [];
  const window = { addEventListener() {} };
  const localStorage = { getItem: k => data.get(k) ?? null, setItem: (k,v) => data.set(k,String(v)), removeItem: k => data.delete(k) };
  vm.runInNewContext(source, { window, localStorage, navigator: { onLine: true }, crypto: globalThis.crypto, Uint8Array, atob,
    fetch: async (url, options) => { calls.push({url, options}); const next = responses.shift(); assert.ok(next, 'unexpected request'); return { ok: next.ok !== false, headers: { get: () => 'application/json' }, json: async () => next.body }; }
  });
  return { window, data, calls };
}
test('background lists cannot advance the revision of an open editor', async () => {
  const x = setup([{body:{projects:[{localId:'p',revision:2}]}},{body:{id:'cloud',revision:2,access:'editor'}}]);
  x.window.erpAsmProjects.acceptSnapshot([{localId:'p',revision:1}]);
  await x.window.erpAsmProjects.list();
  await x.window.erpAsmProjects.save({meta:{portfolioId:'p'}});
  assert.equal(JSON.parse(x.calls[1].options.body).revision, 1);
});
test('a server conflict is surfaced and does not advance the revision', async () => {
  const x = setup([{ok:false,body:{error:'PROJECT_CONFLICT'}}]);
  x.window.erpAsmProjects.acceptSnapshot([{localId:'p',revision:1}]);
  await assert.rejects(x.window.erpAsmProjects.save({meta:{portfolioId:'p'}}), /autre appareil/);
  assert.equal(JSON.parse(x.data.get('alpesex.application.revisions')).p, 1);
});
test('logout clears project data but preserves this installation licence', async () => {
  const x = setup([{ok:false,body:{}},{ok:false,body:{}}], {'pcasm_pro_projects':'private','alpesex.application.revisions':'{}','alpesex.application.license':'secret','alpesex.application.account':'1:2','alpesex.application.device':'stable'});
  await x.window.erpAsmAuth.logout();
  assert.equal(x.data.size, 3);
  assert.equal(x.data.get('alpesex.application.device'), 'stable');
  assert.equal(x.data.get('alpesex.application.license'), 'secret');
  assert.equal(x.data.get('alpesex.application.account'), '1:2');
});
test('existing empty duplicate projects are pruned and their stale revisions are removed', async () => {
  const old = id => ({id,localId:id,revision:1,updatedAt:'2026-07-13',ownerEmail:'one@example.test',hasDocuments:false,data:{meta:{portfolioId:id,referenceProjet:'ASM-DEMO-002',derniereMiseAJour:'2026-07-13'}}});
  const current = {id:'current',localId:'current-local',revision:4,updatedAt:'2026-09-19',ownerEmail:'one@example.test',hasDocuments:false,data:{meta:{portfolioId:'current-local',referenceProjet:'ASM-DEMO-002',derniereMiseAJour:'2026-09-19'}}};
  const x = setup([
    {body:{projects:[old('old-a'),current,old('old-b')]}},
    {body:{ok:true,deletedIds:['old-a','old-b']}}
  ], {
    'alpesex.application.revisions':JSON.stringify({'old-a':1,'old-b':1,'current-local':4}),
    'alpesex.application.drafts':JSON.stringify({'old-a':{project:old('old-a').data,revision:1}})
  });
  const result = await x.window.erpAsmProjects.list();
  assert.equal(Array.from(result.projects, item => item.id).join(','), 'current');
  assert.deepEqual(JSON.parse(x.data.get('alpesex.application.revisions')), {'current-local':4});
  assert.deepEqual(JSON.parse(x.data.get('alpesex.application.drafts')), {});
  assert.equal(x.calls[1].url.endsWith('?action=project-prune'), true);
});
test('a second connected device receives the active device name', async () => {
  const x = setup([{body:{}},{ok:false,body:{error:'DEVICE_ALREADY_CONNECTED',deviceName:'POSTE-LUCAS'}}]);
  await assert.rejects(
    x.window.erpAsmAuth.login({email:'test@example.test',password:'unused',licenseToken:'token'}),
    /Un autre appareil est déjà connecté : POSTE-LUCAS/
  );
});
test('saving keeps one Cloud row when the local alias changed', async () => {
  const x = setup([{body:{id:'cloud-id',localId:'changed-alias',revision:4,access:'editor'}}]);
  x.window.erpAsmProjects.acceptSnapshot([{localId:'changed-alias',revision:3}]);
  const saved = await x.window.erpAsmProjects.save({meta:{portfolioId:'changed-alias',cloudId:'cloud-id'}});
  assert.equal(JSON.parse(x.data.get('alpesex.application.revisions'))['changed-alias'], 4);
  assert.equal(saved.localId, 'changed-alias');
});
test('deletion refreshes the Cloud revision instead of using a stale local revision', async () => {
  const cloud = {id:'cloud-id',localId:'p',revision:7,updatedAt:'2026-09-20',ownerEmail:'one@example.test',hasDocuments:false,data:{meta:{portfolioId:'p',cloudId:'cloud-id'}}};
  const x = setup([
    {body:{projects:[cloud]}},
    {body:{ok:true,cleanupPending:0}}
  ], {'alpesex.application.revisions':JSON.stringify({p:2})});
  await x.window.erpAsmProjects.remove(cloud.data);
  assert.equal(JSON.parse(x.calls[1].options.body).revision, 7);
  assert.equal(x.data.get('alpesex.application.revisions'), '{}');
});
test('new account never inherits the previous local project portfolio', async () => {
  const x = setup([{body:{}},{body:{user:{organizationId:2,id:3,email:'new@example.test',role:'user'},activation:{role:'user'}}}], {'pcasm_pro_projects':'private','alpesex.application.account':'1:1'});
  await x.window.erpAsmAuth.login({email:'new@example.test',password:'unused',licenseToken:'token'});
  assert.equal(x.data.has('pcasm_pro_projects'), false);
  assert.equal(x.data.get('alpesex.application.account'), '2:3');
});
test('first Cloud activation queues the existing V5.4.18 portfolio', async () => {
  const legacy = [{meta:{portfolioId:'legacy-project',nomProjet:'Projet V5.4.18'}}];
  const x = setup([{body:{}},{body:{user:{organizationId:2,id:3,email:'new@example.test',role:'user'},activation:{role:'user'}}}], {'pcasm_pro_projects':JSON.stringify(legacy)});
  await x.window.erpAsmAuth.login({email:'new@example.test',password:'unused',licenseToken:'token'});
  const drafts = JSON.parse(x.data.get('alpesex.application.drafts'));
  assert.equal(drafts['legacy-project'].project.meta.nomProjet, 'Projet V5.4.18');
  assert.equal(drafts['legacy-project'].revision, 0);
});
test('fresh V5.4.18 demonstration data is not uploaded as a user portfolio', async () => {
  const x = setup([{body:{}},{body:{user:{organizationId:2,id:3,email:'new@example.test',role:'user'},activation:{role:'user'}}}]);
  x.data.set('pcasm_pro_projects', JSON.stringify([{meta:{portfolioId:'demo',nomProjet:'Démonstration'}}]));
  await x.window.erpAsmAuth.login({email:'new@example.test',password:'unused',licenseToken:'token'});
  assert.equal(x.data.has('alpesex.application.drafts'), false);
});
test('backup adapter does not publish a project a second time', async () => {
  const x = setup([]);
  await x.window.erpAsmBackups.save({data:{meta:{portfolioId:'p'}}});
  assert.equal(x.calls.length, 0);
});
test('native activation uses the native platform and HTTPS API origin', async () => {
  const data = new Map();const calls=[];
  const window={cpmpNative:{origin:'https://alpes-ex.fr',platform:'ios'},addEventListener(){}};
  vm.runInNewContext(source,{window,navigator:{onLine:true},localStorage:{getItem:k=>data.get(k),setItem:(k,v)=>data.set(k,v),removeItem:k=>data.delete(k)},crypto:globalThis.crypto,Uint8Array,atob,fetch:async(url,options)=>{calls.push({url,options});return {ok:true,headers:{get:()=> 'application/json'},json:async()=>({user:{id:1,organizationId:1,email:'test@example.test'},activation:{role:'user'}})}}});
  await window.erpAsmAuth.login({email:'test@example.test',password:'unused',licenseToken:'token'});
  assert.equal(calls[1].url,'https://alpes-ex.fr/api/application/?action=activate');
  assert.equal(JSON.parse(calls[1].options.body).platform,'ios');
});
test('Windows activation migrates the previous random identifier to the stable device identity', async () => {
  const previous = 'a'.repeat(64), stable = 'b'.repeat(64), data = new Map([['alpesex.application.device', previous]]), calls=[];
  const window={cpmpNative:{origin:'https://alpes-ex.fr',platform:'windows',deviceIdentity:async()=>({identifier:stable,name:'POSTE-LUCAS — Windows'})},addEventListener(){}};
  vm.runInNewContext(source,{window,navigator:{onLine:true},localStorage:{getItem:k=>data.get(k)??null,setItem:(k,v)=>data.set(k,String(v)),removeItem:k=>data.delete(k)},crypto:globalThis.crypto,Uint8Array,atob,fetch:async(url,options)=>{calls.push({url,options});return {ok:true,headers:{get:()=> 'application/json'},json:async()=>({user:{id:1,organizationId:1,email:'test@example.test'},activation:{role:'user'}})}}});
  await window.erpAsmAuth.login({email:'test@example.test',password:'unused',licenseToken:'token'});
  const activation = JSON.parse(calls[1].options.body);
  assert.equal(activation.deviceIdentifier, stable);
  assert.equal(activation.previousDeviceIdentifier, previous);
  assert.equal(activation.deviceName, 'POSTE-LUCAS — Windows');
  assert.equal(data.get('alpesex.application.device'), stable);
});
