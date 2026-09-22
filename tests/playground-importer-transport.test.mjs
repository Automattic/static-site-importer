import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { JSDOM } from 'jsdom';

const script = await readFile(new URL('../demos/playground-importer/blocks/importer/view.js', import.meta.url), 'utf8');
for (const transport of ['fetch', 'playground', 'other-playground']) {
  test(`import continuation carries the fresh-runtime checkpoint through ${transport}`, async () => {
    const dom = new JSDOM(`<div data-static-site-importer data-static-site-importer-rest-url="https://example.test/scope:one/wp-json/static-site-importer/v1/imports" data-static-site-importer-nonce="nonce"><textarea data-static-site-importer-source-html>&lt;main&gt;Source&lt;/main&gt;</textarea><button data-static-site-importer-submit>Import</button><div data-static-site-importer-status><div data-static-site-importer-progress></div><textarea data-static-site-importer-report></textarea></div></div>`, { url: 'https://example.test/scope:one/import/', runScripts: 'outside-only' });
    const { window } = dom;
    const bodies = [];
    const handle = async (options) => {
      assert.equal(options.headers['X-WP-Nonce'], 'nonce');
      bodies.push(JSON.parse(options.body));
      if (bodies.length === 1) return { success: true, continuation: true, import_id: 'run', result: { runtime_lifecycle_checkpoint: 'checkpoint', fresh_runtime: { request_id: 'prepared-request' } } };
      if (bodies.length === 2) return { success: true, continuation: true, import_id: 'run', continuation_reason: 'deadline_exhausted' };
      return { success: false, error: { code: 'test_complete', message: 'End of transport probe.' } };
    };
    window.Response = Response;
    window.TextEncoder = TextEncoder;
    window.fetch = async (_url, options) => {
      assert.notEqual(transport, 'playground', 'Matching Playground must use its supported request API');
      return Response.json(await handle(options));
    };
    if (transport !== 'fetch') {
      window.playground = {
        absoluteUrl: Promise.resolve(`https://example.test/scope:${transport === 'playground' ? 'one' : 'two'}`),
        request: async (options) => {
          assert.equal(transport, 'playground', 'Another Playground instance must never receive this import');
          return { httpStatusCode: 200, bytes: new TextEncoder().encode(JSON.stringify(await handle({ ...options, body: new TextDecoder().decode(options.body) }))) };
        },
      };
    }
    try {
      window.eval(script);
      window.document.querySelector('[data-static-site-importer-source-html]').value = '<main>Source</main>';
      window.document.querySelector('button').click();
      for (let attempt = 0; attempt < 100 && bodies.length < 3; attempt++) await new Promise(resolve => setTimeout(resolve, 5));
      assert.equal(bodies.length, 3);
      for (const body of bodies.slice(1)) {
        assert.deepEqual(body.source, { type: 'files', import_id: 'run' });
        assert.equal(body.runtime_lifecycle_phase, 'resume');
        assert.equal(body.runtime_lifecycle_checkpoint, 'checkpoint');
        assert.equal(body.runtime_lifecycle_request_id, 'prepared-request');
      }
      assert.equal(window.location.pathname, '/scope:one/import/');
    } finally {
      window.close();
    }
  });
}

test('submit is disabled from the click until the report is written', async () => {
  const dom = new JSDOM(`<div data-static-site-importer data-static-site-importer-rest-url="https://example.test/wp-json/static-site-importer/v1/imports" data-static-site-importer-nonce="nonce"><textarea data-static-site-importer-source-html></textarea><button data-static-site-importer-submit>Import</button><div data-static-site-importer-status><div data-static-site-importer-progress></div><textarea data-static-site-importer-report></textarea></div></div>`, { url: 'https://example.test/import/', runScripts: 'outside-only' });
  const { window } = dom;
  let release;
  const gate = new Promise((resolve) => { release = resolve; });
  let calls = 0;
  window.Response = Response;
  window.TextEncoder = TextEncoder;
  window.fetch = async () => {
    calls++;
    await gate;
    return Response.json({ success: false, error: { code: 'static_site_importer_missing_entrypoint', message: 'The ZIP must include an index.html entry document.' } });
  };
  try {
    window.eval(script);
    const submit = window.document.querySelector('button');
    const report = window.document.querySelector('[data-static-site-importer-report]');
    window.document.querySelector('[data-static-site-importer-source-html]').value = '<main>Source</main>';
    submit.click();
    // Anything that waits for the button to re-enable must find a finished
    // report, so the button has to be disabled before any async source read.
    assert.equal(submit.disabled, true, 'Submit must be disabled synchronously on click');
    submit.click();
    for (let attempt = 0; attempt < 100 && calls < 1; attempt++) await new Promise((resolve) => setTimeout(resolve, 5));
    assert.equal(report.value, '', 'No report exists while the request is in flight');
    release();
    for (let attempt = 0; attempt < 100 && submit.disabled; attempt++) await new Promise((resolve) => setTimeout(resolve, 5));
    assert.equal(submit.disabled, false);
    assert.equal(calls, 1, 'A second click while busy must not start another import');
    assert.equal(JSON.parse(report.value).error.code, 'static_site_importer_missing_entrypoint');
  } finally {
    window.close();
  }
});
