import test from 'node:test';
import assert from 'node:assert/strict';
import '../../application/sync-queue.js';
const project = n => ({meta:{portfolioId:'p'}, value:n});
function storage() { const data=new Map();return {getItem:k=>data.get(k),setItem:(k,v)=>data.set(k,v)}; }
test('edits during a request are sent in order with the acknowledged revision', async () => {
  const calls=[];let resolve;
  const queue=new CpmpSyncQueue(storage(),async (p,r)=>{calls.push([p.value,r]);if(calls.length===1)await new Promise(done=>resolve=done);return {revision:r+1};});
  queue.stage(project(1),5);const running=queue.flush('p');
  queue.stage(project(2),5);assert.equal(queue.flush('p'),running);resolve();await running;
  assert.deepEqual(calls,[[1,5],[2,6]]);assert.deepEqual(queue.entries(),{});
});
test('network failures and conflicts retain both the draft and its original revision after restart', async () => {
  const local=storage();const queue=new CpmpSyncQueue(local,async()=>{throw new Error('conflict');});
  queue.stage(project(1),5);await assert.rejects(queue.flush('p'));
  const restarted=new CpmpSyncQueue(local,async(p,r)=>{assert.equal(r,5);return {revision:6};});
  restarted.stage(project(2),99);await restarted.flush('p');assert.deepEqual(restarted.entries(),{});
});
