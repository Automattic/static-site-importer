import assert from 'node:assert/strict';
import test from 'node:test';
import { chromium } from 'playwright';
import { ARCHIVE_ITEM_LANDMARKS, authoringParityExpression, authoringParityPasses, compareAuthoringLandmarks } from '../lib/authoring-parity.mjs';

test('shared browser probe measures authored pages and cleans up on success and navigation failure', async () => {
  const browser = await chromium.launch();
  try {
    for (const mode of ['pass', 'different', 'missing-template']) {
      const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
      let cleanupCount = 0;
      await page.route('http://parity.test/**', async route => {
        const request = route.request();
        if (request.method() === 'POST') {
          const { operation } = JSON.parse(request.postData());
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
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html>${template}<main><h1>${title}</h1><div class="entry-content">Body</div>${sidebar}</main>` });
      });
      try {
        await page.goto('http://parity.test/endpoint');
        if (mode === 'missing-template') {
          await assert.rejects(page.evaluate(authoringParityExpression('http://parity.test/endpoint')), /No served template/);
        } else {
          const result = await page.evaluate(authoringParityExpression('http://parity.test/endpoint'));
          assert.equal(authoringParityPasses(result.authoring_parity_evidence), mode === 'pass');
          assert.equal(result.authoring_parity_evidence[0].pass, mode === 'pass');
        }
        assert.equal(cleanupCount, 2);
      } finally { await page.close(); }
    }
  } finally { await browser.close(); }
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
