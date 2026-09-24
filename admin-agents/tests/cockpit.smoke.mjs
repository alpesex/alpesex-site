import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const [html, css, js, api, migration, inputMigration] = await Promise.all([
  readFile(new URL('../index.html', import.meta.url), 'utf8'),
  readFile(new URL('../agents.css', import.meta.url), 'utf8'),
  readFile(new URL('../agents.js', import.meta.url), 'utf8'),
  readFile(new URL('../../server/public/api/admin/agents/index.php', import.meta.url), 'utf8'),
  readFile(new URL('../../server/migrations/016_create_agent_cockpit.sql', import.meta.url), 'utf8'),
  readFile(new URL('../../server/migrations/018_agent_input_queue.sql', import.meta.url), 'utf8'),
]);

for (const id of ['agent-network', 'decision-list', 'journal-body', 'decision-dialog', 'input-form', 'input-body']) {
  assert.match(html, new RegExp(`id="${id}"`), `Missing UI target ${id}`);
}
assert.match(html, /noindex,nofollow,noarchive/);
assert.match(js, /credentials:'same-origin'/);
assert.match(js, /X-CSRF-Token/);
assert.match(js, /const form=event\.currentTarget,button=form\.querySelector/, 'Async form handlers must retain the submitted form before awaiting');
assert.doesNotMatch(js, /await response\.json\(\)[\s\S]*event\.currentTarget\.reset\(\)/, 'Do not access event.currentTarget after an await');
assert.ok(js.includes(`replace(/[&<>'"]/g`), 'Dynamic values must be escaped before rendering');
assert.match(api, /hash_equals\(\$expected, \$provided\)/, 'Ingest token must use constant-time comparison');
assert.match(api, /agent_cockpit_csrf/, 'Admin decisions must use a dedicated CSRF token');
assert.match(api, /ALPESEX_ADMIN_EMAILS/, 'Cockpit must be restricted to an administrator allowlist');
assert.match(migration, /CREATE TABLE IF NOT EXISTS agent_events/);
assert.match(migration, /CREATE TABLE IF NOT EXISTS agent_decisions/);
assert.match(inputMigration, /CREATE TABLE IF NOT EXISTS agent_inputs/);
assert.match(inputMigration, /UNIQUE KEY uq_agent_inputs_source_reference/);
assert.equal((css.match(/{/g) || []).length, (css.match(/}/g) || []).length, 'CSS braces must balance');

console.log('Agent cockpit smoke tests: OK');
