import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { coreHtmlFindings } from '../import-loop/core-html.mjs';

const root = path.resolve(import.meta.dirname, '../..');

test('import continues only for a preserved quality-failed preview', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'preview-import-'));
  try {
    fs.writeFileSync(path.join(dir, 'gh'), '#!/bin/sh\necho token\n', { mode: 0o755 });
    fs.writeFileSync(path.join(dir, 'studio'), `#!/bin/bash
if [[ "$1 $2" == 'site list' ]]; then echo '[]'; exit 0; fi
if [[ "$1 $2" == 'site start' ]]; then echo started >> "$START_LOG"; exit 0; fi
if [[ "$1" == create ]]; then
  [[ "$CREATE_SITE" == yes ]] && mkdir -p "$3"
  echo "$URL_LABEL: http://localhost:9000"
  echo "$FAILURE"
  exit 1
fi
exit 1
`, { mode: 0o755 });
    const site = path.join(dir, 'site');
    const output = path.join(dir, 'output.json');
    const env = { ...process.env, PATH: `${dir}:${process.env.PATH}`, HOMEBOY_STEP_OUTPUT: output, START_LOG: path.join(dir, 'starts'), CREATE_SITE: 'yes', URL_LABEL: 'URL', FAILURE: 'static_site_importer_quality_failed' };
    const run = () => spawnSync('bash', ['tools/import-loop/import.sh', 'https://example.org/', site], { cwd: root, env, encoding: 'utf8' });
    assert.equal(run().status, 0);
    assert.deepEqual(JSON.parse(fs.readFileSync(output)), { url: 'https://example.org/', site, site_url: 'http://localhost:9000', log: `${site}.log`, source: `${site}-source` });
    assert.equal(fs.readFileSync(env.START_LOG, 'utf8'), 'started\n');
    env.URL_LABEL = 'Site URL';
    assert.equal(run().status, 0);
    env.CREATE_SITE = 'no';
    assert.notEqual(run().status, 0);
    env.FAILURE = 'unrelated failure';
    assert.notEqual(run().status, 0);
  } finally { fs.rmSync(dir, { recursive: true, force: true }); }
});
test('core HTML findings retain evidence and stable subjects, with route fallback', () => {
  const diagnostic = { type: 'core_html_block', tag_name: 'p', reason_code: 'generated_document_contains_core_html', selector: 'p.source-123', source_path: 'posts/page-home.post_content', html_excerpt: '<p>Example</p>' };
  const findings = coreHtmlFindings({ diagnostics: [diagnostic] }, ['/', '/contact/']);
  assert.equal(findings.length, 1);
  assert.equal(findings[0].route, '/');
  assert.equal(findings[0].stage, 'editability');
  assert.equal(findings[0].check, 'core_html_block_count');
  assert.deepEqual(findings[0].evidence, { selector: diagnostic.selector, source_path: diagnostic.source_path, html_excerpt: diagnostic.html_excerpt });
  assert.equal(coreHtmlFindings({ diagnostics: [{ ...diagnostic, selector: 'p.source-456' }] }, ['/'])[0].subject, findings[0].subject);
  assert.equal(coreHtmlFindings({ diagnostics: [{ ...diagnostic, source_path: 'posts/page-contact.post_content' }] }, ['/', '/contact/'])[0].route, '/contact/');
  assert.equal(coreHtmlFindings({ diagnostics: [{ ...diagnostic, source_path: 'unknown' }] }, ['/', '/contact/']).length, 2);
});

test('predicate counts materialized remnants, not the engine form fallback', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'core-html-'));
  try {
    const candidate = path.join(dir, 'index.html');
    const transformer = path.join(root, 'vendor/automattic/blocks-engine-php-transformer');
    const run = (trap, body = `<form action="/submit" method="post"><label>Email <input type="email" name="email"></label>${trap}<button type="submit">Send</button></form>`) => {
      fs.writeFileSync(candidate, `<!doctype html><html><head><title>Contact</title></head><body>${body}</body></html>`);
      return spawnSync('node', ['tools/import-loop/predicates/core_html_block_count.mjs', transformer, candidate], {
        cwd: root, encoding: 'utf8', env: { ...process.env, WORDPRESS_PATH: dir, WP_CLI: `php ${root}/tests/fixtures/core-html-predicate-wp.php`, PREDICATE_TARGET: '{"subject":"P:generated_document_contains_core_html"}' },
      });
    };
    // core/html outside a materialized form has another owner: reduction must not drift onto it.
    const loose = run('', '<p><input></p>');
    assert.equal(loose.status, 1, loose.stderr);
    const clean = run('');
    assert.equal(clean.status, 1, clean.stderr);
    // The current materializer already suppresses optional hidden controls.
    // Do not count the engine's whole-form fallback as leftover core/html.
    const hidden = run('<p style="display:none"><label>Optional field <input name="extra-field"></label></p>');
    assert.equal(hidden.status, 1, hidden.stderr);
  } finally { fs.rmSync(dir, { recursive: true, force: true }); }
});
