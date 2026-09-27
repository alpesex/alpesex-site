const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const repo=path.resolve(__dirname,'../..');

test('la version Web est proposée à côté de Windows et macOS',()=>{
  const html=fs.readFileSync(path.join(repo,'mon-compte','index.html'),'utf8');
  const windows=html.indexOf('Télécharger pour Windows');
  const web=html.indexOf('Ouvrir la version Web');
  const macos=html.indexOf('Télécharger pour macOS');
  assert.ok(windows>=0 && web>windows && macos>web);
  assert.match(html,/href="\.\.\/application\/"/);
  assert.match(html,/href="\.\.\/api\/downloads\/macos\/"/);
});

test('le navigateur de bureau reçoit l’application authentifiée',()=>{
  const php=fs.readFileSync(path.join(repo,'application','index.php'),'utf8');
  assert.doesNotMatch(php,/HTTP_USER_AGENT|Application Windows requise|http_response_code\(403\)/);
  assert.match(php,/readfile\(__DIR__ \. '\/index\.html'\)/);
});

test('le téléchargement macOS reste protégé comme Windows',()=>{
  const php=fs.readFileSync(path.join(repo,'server','public','api','downloads','macos','index.php'),'utf8');
  assert.match(php,/AUTHENTICATION_REQUIRED/);
  assert.match(php,/LICENSE_REQUIRED/);
  assert.match(php,/CPMP-ASM-V6\.0\.2\.1-macOS-universal\.dmg/);
  assert.match(php,/application\/x-apple-diskimage/);
});
