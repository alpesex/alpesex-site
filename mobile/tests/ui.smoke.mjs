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
    res.setHeader('Content-Type', p.endsWith('.js') ? 'application/javascript' : p.endsWith('.css') ? 'text/css' : 'text/html');
    res.end(await readFile(f));
  } catch { res.statusCode = 404; res.end(); }
}).listen(0, '127.0.0.1');
await new Promise(resolve => server.once('listening', resolve));
const browser = await chromium.launch({ headless: true, ...(process.env.CPMP_TEST_BROWSER ? {executablePath:process.env.CPMP_TEST_BROWSER} : {}), args: ['--no-sandbox', '--disable-gpu', '--disable-software-rasterizer', '--use-gl=disabled'] });
try {
  for (const width of [390, 768, 1024]) {
    const page = await browser.newPage({ viewport: { width, height: 844 } });
    const errors = [];
    let project, revision = 1;
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
        if(input.revision!==revision){status=409;body={error:'PROJECT_CONFLICT'};}
        else {project=input.project;revision++;body={id:'cloud',revision,access:'editor'};}
      }
      if (action === 'documents-status') body = {documents:[]};
      await route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
    });
    await page.goto(`http://127.0.0.1:${server.address().port}/application/`);
    await page.locator('#authEmail').waitFor({state:'visible'});
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, `Login overflow ${width}`);
    project = await page.evaluate(() => {const p=normalizeProject(EMPTY_PROJECT);p.meta.portfolioId='SMOKE';p.meta.nomProjet='Projet test mobile';return p;});
    await page.locator('#authEmail').fill('smoke@example.test');
    await page.locator('#authPassword').fill('test-password-123');
    await page.locator('#authLicense').fill('test-license');
    await page.locator('#authForm button[type="submit"]').click();
    await page.locator('#authScreen').waitFor({state:'hidden'});
    assert.match(await page.locator('#projectList').innerText(), /Projet test mobile/);
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
    await page.locator('#logoutButton').click();
    await page.locator('#authEmail').waitFor({state:'visible'});
    assert.equal(await page.evaluate(() => localStorage.getItem('pcasm_pro_projects')), null);
    assert.deepEqual(errors, [], `JavaScript errors at ${width}px`);
    console.log(`Login, portfolio, PC view/edit, DC edit, logout: OK (${width}px; mocked API)`);
    await page.close();
  }
} finally { await browser.close(); server.close(); }
