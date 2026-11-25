import assert from 'node:assert/strict';
import test from 'node:test';
import { chromium } from 'playwright';
import { ARCHIVE_ITEM_LANDMARKS, authoringParityRuntime, collectAuthoringParity, authoringParityExpression, authoringParityPasses, compareAuthoringLandmarks } from '../lib/authoring-parity.mjs';

test('shared browser probe measures authored pages and cleans up on success and navigation failure', async () => {
  const browser = await chromium.launch();
  try {
    for (const mode of ['pass', 'related-count', 'different', 'missing-template', 'record-failure']) {
      const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
      let cleanupCount = 0;
      let recorded;
      let recordCount = 0;
      await page.route('http://parity.test/**', async route => {
        const request = route.request();
        if (request.method() === 'POST') {
          const { operation, evidence } = JSON.parse(request.postData());
          if (operation === 'record') {
            recordCount++;
            if (mode === 'record-failure') return route.fulfill({ status: 500, json: { error: 'Storage failed' } });
            recorded = evidence;
            return route.fulfill({ json: true });
          }
          if (operation === 'clean') cleanupCount++;
          const values = {
            list: { posts: [{ id: 1, type: 'page', url: 'http://parity.test/imported', title: 'Imported' }], terms: [] },
            create: { url: 'http://parity.test/probe', title: 'Authoring parity probe' },
            clean: true,
          };
          return route.fulfill({ json: values[operation] });
        }
        const probe = request.url().endsWith('/probe');
        const title = probe ? 'Authoring parity probe' : 'Imported';
        const template = probe && mode === 'missing-template' ? '' : '<meta name="ssi-authoring-template" content="page">';
        const sidebar = probe && mode === 'different' ? '<aside>Unexpected sidebar</aside>' : '';
        // A related query lists however many entries it finds; each card's date is one landmark.
        const related = mode === 'related-count' ? `<div class="wp-block-query">${'<p><time>Nov 1, 2023</time></p>'.repeat(probe ? 5 : 1)}</div>` : '';
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html>${template}<main><h1>${title}</h1><time>Nov 2, 2023</time><div class="entry-content">Body</div>${sidebar}${related}</main>` });
      });
      try {
        await page.goto('http://parity.test/endpoint');
        if (mode === 'missing-template') {
          await assert.rejects(page.evaluate(authoringParityExpression('http://parity.test/endpoint')), /No served template/);
        } else if (mode === 'record-failure') {
          await assert.rejects(page.evaluate(authoringParityExpression('http://parity.test/endpoint')), /endpoint failed: 500/);
        } else {
          assert.equal(await page.evaluate(authoringParityExpression('http://parity.test/endpoint')), undefined);
          assert.equal(authoringParityPasses(recorded), mode !== 'different');
          assert.equal(recorded[0].pass, mode !== 'different');
        }
        assert.equal(recordCount, mode === 'missing-template' ? 0 : 1);
        assert.equal(cleanupCount, 2);
      } finally { await page.close(); }
    }
  } finally { await browser.close(); }
});

test('runtime cleanup echoes token-scoped evidence before deleting all staged resources', () => {
  const token = 'a'.repeat(32);
  const runtime = authoringParityRuntime(token, 'fixture-id');
  assert.match(runtime.stage, new RegExp(`delete_option\\('ssi_authoring_evidence_${token}'\\)`));
  assert.ok(runtime.cleanup.startsWith('echo wp_json_encode('));
  assert.match(runtime.cleanup, /'fixture_id'=>json_decode/);
  assert.match(runtime.cleanup, new RegExp(`get_option\\('ssi_authoring_evidence_${token}', null\\)`));
  assert.ok(runtime.cleanup.indexOf('delete_option') < runtime.cleanup.indexOf('ssi_authoring_cleanup'));
  assert.match(runtime.cleanup, /ssi_authoring_cleanup\(\);/);
  assert.match(runtime.cleanup, /unlink\(\$file\)/);
});

test('local collector reads evidence from cleanup stdout and still cleans up after browser failure', async () => {
  for (const fail of [false, true]) {
    const evidence = [{ cluster: 'test', pass: true }];
    const calls = [];
    let closed = false;
    const wp = (command, code) => {
      assert.equal(command, 'eval');
      calls.push(code);
      return JSON.stringify(calls.length === 1 ? { url: 'http://parity.test/endpoint' } : { fixture_id: null, authoring_parity_evidence: fail ? null : evidence });
    };
    const browser = { newPage: async () => ({
      goto: async () => {},
      evaluate: async expression => {
        assert.match(expression, /rpc\('record'/);
        if (fail) throw new Error('Collection failed');
      },
      close: async () => { closed = true; },
    }) };
    if (fail) await assert.rejects(collectAuthoringParity({ wp, browser }), /Collection failed/);
    else assert.deepEqual(await collectAuthoringParity({ wp, browser }), evidence);
    assert.equal(closed, true);
    assert.equal(calls.length, 2);
    assert.ok(calls[1].startsWith('echo wp_json_encode('));
  }
});

const landmarks = { title: 1, date: 1, author: 1, terms: 2, sidebar: 2, related: 2, comments: 1, contentLeft: 180, contentWidth: 760 };
for (const [name, changes, missing, presence = [], base = {}] of [
  ['matching landmarks', {}, []],
  ['missing title', { title: 0 }, ['title']],
  ['missing sidebar', { sidebar: 1 }, ['sidebar']],
  ['missing related query', { related: 1 }, ['related']],
  ['within tolerance', { contentLeft: 184, contentWidth: 756 }, []],
  ['outside tolerance', { contentWidth: 765 }, ['contentWidth']],
  ['missing column', { contentLeft: null, contentWidth: null }, ['contentLeft', 'contentWidth']],
  ['no column on either', { contentLeft: null, contentWidth: null }, [], [], { contentLeft: null, contentWidth: null }],
  ['archive with fewer items', { date: 1, author: 1, terms: 1 }, [], ARCHIVE_ITEM_LANDMARKS],
  ['archive without dates', { date: 0 }, ['date'], ARCHIVE_ITEM_LANDMARKS],
]) {
  test(`authoring parity: ${name}`, () => {
    assert.deepEqual(compareAuthoringLandmarks({ ...landmarks, date: 9, ...base }, { ...landmarks, date: 9, ...changes }, { presence }), { pass: !missing.length, differences: missing });
  });
}

test('static front page served through front-page does not set the column authored pages must match', async () => {
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    let recorded;
    await page.route('http://parity.test/**', async route => {
      const request = route.request();
      if (request.method() === 'POST') {
        const { operation, evidence } = JSON.parse(request.postData());
        if (operation === 'record') recorded = evidence;
        const values = {
          // The front page has the lowest ID, so it lists first.
          list: { posts: [
            { id: 1, type: 'page', url: 'http://parity.test/', title: 'Home', front: true },
            { id: 2, type: 'page', url: 'http://parity.test/about', title: 'About', front: false },
          ], terms: [] },
          create: { url: 'http://parity.test/probe', title: 'Authoring parity probe' },
        };
        return route.fulfill({ json: values[operation] ?? true });
      }
      const path = new URL(request.url()).pathname;
      const served = {
        '/': ['Home', 'theme//front-page', 'margin:0 auto;width:672px'],
        '/about': ['About', 'theme//page', 'margin-left:120px;width:576px'],
        '/probe': ['Authoring parity probe', 'theme//authored-page', 'margin-left:120px;width:576px'],
      }[path];
      if (!served) return route.fulfill({ status: 404, body: '' });
      const [title, template, column] = served;
      return route.fulfill({ contentType: 'text/html', body: `<!doctype html><meta name="ssi-authoring-template" content="${template}"><body style="margin:0"><main><h1>${title}</h1><p style="${column}">Body</p></main>` });
    });
    await page.goto('http://parity.test/endpoint');
    await page.evaluate(authoringParityExpression('http://parity.test/endpoint'));
    assert.deepEqual(recorded.map(row => [row.cluster, row.pass]), [['page × theme//page', true]]);
    assert.equal(authoringParityPasses(recorded), true);
    await page.close();
  } finally { await browser.close(); }
});
