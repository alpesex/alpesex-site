const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'..');

test('le pont natif annonce Windows ou macOS selon la plateforme',()=>{
  const preload=fs.readFileSync(path.join(root,'preload.cjs'),'utf8');
  assert.match(preload,/process\.platform === 'darwin' \? 'macos'/);
  assert.match(preload,/process\.platform === 'win32' \? 'windows'/);
});

test('l’identité macOS utilise l’UUID matériel avec repli réseau',()=>{
  const main=fs.readFileSync(path.join(root,'main.cjs'),'utf8');
  assert.match(main,/IOPlatformUUID/);
  assert.match(main,/alpesex-cpmp-\$\{process\.platform\}/);
  assert.match(main,/systemIdentifier \|\| \[\.\.\.new Set\(macs\)\]/);
});

test('le build macOS produit DMG et ZIP universels',()=>{
  const pkg=JSON.parse(fs.readFileSync(path.join(root,'package.json'),'utf8'));
  assert.equal(pkg.build.mac.artifactName,'CPMP-ASM-V6.0.2.1-macOS-universal.${ext}');
  assert.deepEqual(pkg.build.mac.target,['dmg','zip']);
  assert.match(pkg.scripts['dist:mac'],/--universal/);
});

test('le serveur accepte la plateforme macOS',()=>{
  const candidates=[path.resolve(root,'../server/src/Application/ApplicationAccess.php'),path.resolve(root,'../../cpmp-asm-macos-release/server/src/Application/ApplicationAccess.php')];
  const access=fs.readFileSync(candidates.find(fs.existsSync),'utf8');
  assert.match(access,/\['web', 'ios', 'android', 'windows', 'macos'\]/);
  assert.match(access,/\$platform === 'macos' \? 'Mac'/);
});
