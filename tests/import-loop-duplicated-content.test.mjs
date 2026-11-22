import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { contentOccurrences, duplicatedContent, duplicatedContentOnPage, imageSource, repeatedKeys } from '../tools/import-loop/duplicated-content.mjs';
import { duplicatedContentFindings } from '../tools/import-loop/checks.mjs';

const WIDTHS = [390, 768, 1440];
const heading = (text) => `<!-- wp:heading -->\n<h2 class="wp-block-heading">${text}</h2>\n<!-- /wp:heading -->`;
const group = (className, inner) => `<!-- wp:group {"className":"${className}"} -->\n<div class="wp-block-group ${className}">${inner}</div>\n<!-- /wp:group -->`;

// Issue #3252: one heading shipped as a desktop copy and a mobile copy.
const breakpointCopies = group('desktop-only', heading('Build your <strong>dream</strong> home')) + group('mobile-only', heading('Build your <strong>dream</strong> home'));
// The same heading on three visible cards: repeated structure, not duplication.
const cardGrid = ['a', 'b', 'c'].map((c) => group(`card card-${c}`, heading('Build your dream home'))).join('');
const css = '<style>@media (max-width: 767px) { .desktop-only { display: none } } @media (min-width: 768px) { .mobile-only { display: none } }</style>';
const rendered = (content) => `<!doctype html><html><head>${css}</head><body><main><div class="wp-block-post-content">${content.replace(/<!--[^]*?-->/g, '')}</div></main></body></html>`;

test('saved content yields text runs and images with block paths', () => {
  const markup = group('hero', heading('Build your <strong>dream</strong> &amp; home') + '<!-- wp:image -->\n<figure class="wp-block-image"><img src="/wp-content/uploads/2024/01/hero-1024x768.jpg" alt=""/></figure>\n<!-- /wp:image --><!-- wp:paragraph --><p>Short</p><!-- /wp:paragraph -->');
  assert.deepEqual(contentOccurrences(markup), [
    { kind: 'text', key: 'buildyourdream&home', text: 'build your dream & home', block_path: 'core/group > core/heading' },
    { kind: 'image', key: '/wp-content/uploads/2024/01/hero.jpg', block_path: 'core/group > core/image' },
  ]);
  assert.equal(imageSource('https://example.com/wp-content/uploads/a-scaled.png'), '/wp-content/uploads/a.png');
});

test('copies visible at disjoint widths are one duplicate; copies visible together are none', () => {
  const copies = contentOccurrences(breakpointCopies);
  const [key] = repeatedKeys(copies);
  const at = (visible) => [{ ...key, visible }];
  const found = duplicatedContent(copies, { 390: at([false, true]), 768: at([true, false]), 1440: at([true, false]) });
  assert.deepEqual(found, [{ text: 'build your dream home', occurrences: 2, copies: [
    { block_path: 'core/group > core/heading', visible_widths: [768, 1440] },
    { block_path: 'core/group > core/heading', visible_widths: [390] },
  ] }]);

  const cards = contentOccurrences(cardGrid);
  const [cardKey] = repeatedKeys(cards);
  const all = { ...cardKey, visible: [true, true, true] };
  assert.deepEqual(duplicatedContent(cards, { 390: [all], 768: [all], 1440: [all] }), []);
  // A rendered count that differs from the saved count cannot be matched.
  assert.deepEqual(duplicatedContent(copies, { 390: at([true]), 768: at([true]), 1440: at([true]) }), []);
});

test('a duplicate is one blocker per route, routed to capture when the source shipped device documents', () => {
  assert.deepEqual(duplicatedContentFindings([], '/'), []);
  const dup = [{ text: 'x', occurrences: 2, copies: [] }];
  const [finding] = duplicatedContentFindings(dup, '/', [{ check: 'source_device_documents', signature: 'abc', occurrences: [{ route: '/' }] }]);
  assert.equal(finding.check, 'block_duplicated_content');
  assert.equal(finding.stage, 'editability');
  assert.equal(finding.owner_repo, 'blocks-engine');
  assert.deepEqual(finding.evidence, { count: 1, duplicates: dup, source_device_documents: ['abc'] });
  assert.equal(duplicatedContentFindings({ error: 'boom' }, '/')[0].check, 'block_duplicated_content_unverified');
});

test('rendered fixture: breakpoint copies report one duplicate, a visible card grid none', async (t) => {
  let browser;
  try { browser = await (await import('playwright')).chromium.launch(); } catch (error) { t.skip(`chromium unavailable: ${error.message.split('\n')[0]}`); return; }
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'dup-content-'));
  try {
    const page = (name, content) => { const file = path.join(dir, `${name}.html`); fs.writeFileSync(file, rendered(content)); return `file://${file}`; };
    const copies = await duplicatedContentOnPage(browser, page('copies', breakpointCopies), breakpointCopies, WIDTHS);
    assert.equal(copies.length, 1);
    assert.deepEqual(copies[0].copies.map((c) => c.visible_widths), [[768, 1440], [390]]);
    assert.deepEqual(await duplicatedContentOnPage(browser, page('cards', cardGrid), cardGrid, WIDTHS), []);
  } finally {
    await browser.close();
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
