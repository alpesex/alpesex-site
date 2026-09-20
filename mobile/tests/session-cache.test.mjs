import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
const source=readFileSync(new URL('../../application/service-worker.js',import.meta.url),'utf8');
function worker(fetcher){
 const handlers={},deleted=[],cached=[];
 const context={URL,Response,fetch:fetcher,self:{location:{origin:'https://alpes-ex.fr'},clients:{claim:async()=>{}},skipWaiting:async()=>{},addEventListener:(name,fn)=>handlers[name]=fn},caches:{keys:async()=>['cpmp-asm-web-6.0.0-1','cpmp-asm-mobile-old','other-app'],delete:async key=>deleted.push(key),open:async()=>({addAll:async paths=>cached.push(...paths),match:async()=>new Response('OLD CACHED HTML')})}};
 vm.runInNewContext(source,context);
 return {handlers,deleted,cached};
}
test('activation deletes legacy HTML caches without touching unrelated applications',async()=>{
 const {handlers,deleted,cached}=worker(()=>{});let pending;
 handlers.install({waitUntil:p=>pending=p});await pending;
 assert.equal(cached.some(p=>p.endsWith('.html')||p==='/application/'),false);
 handlers.activate({waitUntil:p=>pending=p});await pending;
 assert.deepEqual(deleted,['cpmp-asm-web-6.0.0-1','cpmp-asm-mobile-old']);
});
test('entry URLs always use the server session gate, including direct HTML fetches',async()=>{
 for(const path of ['/application','/application/','/application/index.html','/application/index.php?x=1']){
  let options,pending;
  const {handlers}=worker(async(req,opt)=>{options=opt;return new Response('',{status:302,headers:{Location:'/compte/'}})});
  handlers.fetch({request:{url:'https://alpes-ex.fr'+path,method:'GET',mode:'same-origin'},respondWith:p=>pending=p});
  assert.equal((await pending).status,302);assert.equal(options.cache,'no-store');
 }
});
test('offline opening does not return the previously cached application',async()=>{
 const {handlers}=worker(async()=>{throw Error('offline')});let pending;
 handlers.fetch({request:{url:'https://alpes-ex.fr/application/',method:'GET',mode:'navigate'},respondWith:p=>pending=p});
 const response=await pending;assert.equal(response.status,503);assert.match(await response.text(),/Connexion Internet requise/);
});
