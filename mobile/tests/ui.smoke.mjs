import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import assert from 'node:assert/strict';
const root = fileURLToPath(new URL('../../', import.meta.url));
const server = createServer(async (req, res) => {
  try {
    let p = new URL(req.url, 'http://localhost').pathname;
    if (p.endsWith('/')) p += 'index.html';
    const f = path.resolve(root, '.' + p);
    if (!f.startsWith(root)) throw new Error('Invalid path');
    res.setHeader('Content-Type', p.endsWith('.js') ? 'application/javascript' : p.endsWith('.css') ? 'text/css' : p.endsWith('.webmanifest') ? 'application/manifest+json' : p.endsWith('.png') ? 'image/png' : 'text/html');
    res.end(await readFile(f));
  } catch { res.statusCode = 404; res.end(); }
}).listen(0, '127.0.0.1');
await new Promise(resolve => server.once('listening', resolve));
const browser = await chromium.launch({ headless: true, ...(process.env.CPMP_TEST_BROWSER ? {executablePath:process.env.CPMP_TEST_BROWSER} : {}), args: ['--no-sandbox', '--disable-gpu', '--disable-software-rasterizer', '--use-gl=disabled'] });
try {
  for (const width of [390, 768, 1024]) {
    const page = await browser.newPage({ viewport: { width, height: 844 } });
    const errors = [];
    let project, createdProject, revision = 1;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/api/**', async route => {
      const url = new URL(route.request().url());
      const action = url.searchParams.get('action');
      let body = {}, status = 200;
      if (action === 'session') { status = 401; body = { error: 'AUTHENTICATION_REQUIRED' }; }
      if (action === 'activate') body = { user: {id:1,organizationId:1,email:'smoke@example.test',role:'user'}, activation:{role:'user'} };
      if (action === 'projects') body = {projects:[{id:'cloud',localId:'SMOKE',revision,access:'editor',data:project}]};
      if (action === 'project-save') {
        const input=route.request().postDataJSON();
        const localId=input.project?.meta?.portfolioId;
        if(localId!=='SMOKE'&&input.revision===0){createdProject=input.project;body={id:'cloud-'+localId,revision:1,access:'editor'};}
        else if(input.revision!==revision){status=409;body={error:'PROJECT_CONFLICT'};}
        else {project=input.project;revision++;body={id:'cloud',revision,access:'editor'};}
      }
      if (action === 'documents-status') body = {documents:[]};
      await route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
    });
    await page.goto(`http://127.0.0.1:${server.address().port}/application/`);
    await page.locator('#authEmail').waitFor({state:'visible'});
    if (width === 390) {
      await page.locator('[data-install-app]').first().click();
      await page.locator('#installAppModal').waitFor({state:'visible'});
      assert.match(await page.locator('#installAppSteps').innerText(), /Installer l’application|Ajouter à l’écran d’accueil/);
      await page.locator('.install-cancel').click();
    }
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, `Login overflow ${width}`);
    project = await page.evaluate(() => {const p=normalizeProject(EMPTY_PROJECT);p.meta.portfolioId='SMOKE';p.meta.nomProjet='Projet test mobile';return p;});
    await page.locator('#authEmail').fill('smoke@example.test');
    await page.locator('#authPassword').fill('test-password-123');
    await page.locator('#authLicense').fill('test-license');
    await page.locator('#authForm button[type="submit"]').click();
    await page.locator('#authScreen').waitFor({state:'hidden'});
    assert.match(await page.locator('#projectList').innerText(), /Projet test mobile/);
    await page.locator('#adminButton').click();
    const fileChooser = page.waitForEvent('filechooser');
    await page.getByRole('button',{name:'Importer une sauvegarde Windows',exact:true}).click();
    await fileChooser;
    await page.locator('#addProjectButton').click();
    await page.locator('#newProjectModal').waitFor({state:'visible'});
    await page.locator('#newProjectName').fill(`Nouveau projet ${width}`);
    await page.locator('#newProjectConfirm').click();
    await page.locator('#newProjectModal').waitFor({state:'hidden'});
    await page.frameLocator('#adminFrame').locator('body').waitFor({state:'visible'});
    assert.equal(await page.evaluate(name => getProjects().some(item => item.meta.nomProjet === name), `Nouveau projet ${width}`), true, `Project creation ${width}`);
    assert.equal(createdProject.meta.nomProjet, `Nouveau projet ${width}`, `Cloud project creation ${width}`);
    const createdId = await page.evaluate(name => getProjects().find(item => item.meta.nomProjet === name).meta.portfolioId, `Nouveau projet ${width}`);
    await page.evaluate(id => { const items=getProjects().filter(item=>item.meta.portfolioId!==id);saveProjects(items);renderPortfolio(false); }, createdId);
    project = await page.evaluate(() => projectById('SMOKE'));
    revision = 1;
    await page.evaluate(() => openProject('SMOKE', false));
    await page.frameLocator('#clientFrame').locator('body').waitFor({state:'visible'});
    await page.evaluate(() => openProject('SMOKE', true));
    await page.frameLocator('#adminFrame').locator('body').waitFor({state:'visible'});
    await page.evaluate(() => openDCProject('SMOKE', true));
    await page.locator('#dcFrame').waitFor({state:'visible'});
    await page.evaluate(() => {activeDcProject.meta.referenceProjet='AUTO-SYNC';dcPersist();});
    await page.waitForFunction(() => document.getElementById('syncStatus').textContent==='Synchronisé' && Object.keys(window.erpAsmProjects.drafts()).length===0);
    assert.equal(project.meta.referenceProjet,'AUTO-SYNC','DC changes saved automatically');
    await page.evaluate(() => renderPortfolio(false));
    project.meta.nomProjet='Modifié depuis un autre appareil';revision++;
    await page.locator('#syncStatus').click();
    await page.waitForFunction(() => document.getElementById('projectList').textContent.includes('Modifié depuis un autre appareil'));
    await page.evaluate(() => openDCProject('SMOKE', true));
    project.meta.nomProjet='Version concurrente';revision++;
    await page.evaluate(() => {activeDcProject.meta.referenceProjet='LOCAL-CONFLICT';dcPersist();});
    await page.waitForFunction(() => document.getElementById('syncStatus').textContent.includes('Conflit'));
    assert.equal(await page.evaluate(() => window.erpAsmProjects.drafts().SMOKE.project.meta.referenceProjet),'LOCAL-CONFLICT');
    const recoverCloud = page.getByRole('button',{name:'Reprendre la version Cloud',exact:true});
    if (!await recoverCloud.isVisible()) await page.locator('#syncStatus').click();
    await recoverCloud.waitFor();
    page.once('dialog',dialog=>dialog.accept());
    await recoverCloud.click();
    await page.waitForFunction(() => document.getElementById('projectList').textContent.includes('Version concurrente'));
    assert.equal(await page.evaluate(() => Object.keys(window.erpAsmProjects.drafts()).length),0);
    await page.locator('#logoutButton').click();
    await page.locator('#authEmail').waitFor({state:'visible'});
    assert.equal(await page.evaluate(() => localStorage.getItem('pcasm_pro_projects')), null);
    assert.deepEqual(errors, [], `JavaScript errors at ${width}px`);
    console.log(`Login, new project, portfolio, PC view/edit, DC edit, automatic save/pull, conflict recovery, logout: OK (${width}px; mocked API)`);
    await page.close();
  }
  const ios = await browser.newContext({userAgent:'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1',viewport:{width:390,height:844}});
  const iosPage = await ios.newPage();
  await iosPage.route('**/api/**', route => route.fulfill({status:401,contentType:'application/json',body:'{"error":"AUTHENTICATION_REQUIRED"}'}));
  await iosPage.goto(`http://127.0.0.1:${server.address().port}/application/`);
  await iosPage.locator('[data-install-app]').first().click();
  assert.match(await iosPage.locator('#installAppSteps').innerText(), /Safari[\s\S]*Partager[\s\S]*Sur l’écran d’accueil/);
  console.log('iPhone/iPad web installation guidance: OK');
  await ios.close();
} finally { await browser.close(); server.close(); }
