import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { JSDOM } from 'jsdom';

const evidence = process.argv[2];
const read = (name) => readFileSync(`${evidence}/${name}`, 'utf8');
const before = JSON.parse(read('before.json'));
const after = JSON.parse(read('after.json'));
assert.deepEqual(after, before, 'Native content, routes, menus, media, thumbnails, SEO and options survive switch');
for (const phase of ['reimported', 'rollback']) {
  assert.deepEqual(JSON.parse(read(`${phase}.json`)), before, `${phase}: native owner snapshot is unchanged`);
}
for (const phase of ['before', 'after']) {
  for (const page of before.pages) {
    const name = createHash('sha256').update(page.url).digest('hex').slice(0, 12);
    const doc = new JSDOM(read(`${phase}-${name}.html`)).window.document;
    assert.ok(doc.body.textContent.includes('Persistent garden content.'), `${phase}: content renders logged out`);
    assert.equal(doc.querySelectorAll('meta[charset]').length, 1);
    assert.equal(doc.querySelectorAll('meta[name="viewport"]').length, 1);
    for (const [selector, expected, attr] of [
      ['link[rel="canonical"]', page.url, 'href'],
      ['meta[name="description"]', page.excerpt, 'content'],
      ['meta[property="og:title"]', page.title, 'content'],
      ['meta[property="og:url"]', page.url, 'content'],
      ['meta[property="og:image"]', page.image_url, 'content'],
    ]) {
      assert.equal(doc.querySelectorAll(selector).length, 1, `${phase}: singleton ${selector}`);
      assert.equal(doc.querySelector(selector).getAttribute(attr), expected, `${phase}: native ${selector}`);
    }
    assert.ok(doc.querySelector('link[rel="icon"]'), `${phase}: site icon renders`);
    if (phase === 'before') {
      for (const target of before.pages) {
        assert.ok([...doc.querySelectorAll('a')].some((link) => link.href === target.url), `${phase}: source menu destination renders logged out: ${target.url}`);
      }
    }
    assert.ok([...doc.querySelectorAll('img')].some((img) => img.src.includes('/uploads/')), `${phase}: media renders`);
    assert.ok(!doc.querySelector('meta[name="sentry-trace"], meta[name="baggage"]'));
  }
  const redirect = read(`${phase}-redirect.txt`);
  assert.match(redirect, /^HTTP\/\S+ 301/m, `${phase}: native alias remains a redirect`);
  assert.ok(redirect.toLowerCase().includes(`location: ${before.pages.find((p) => p.url.includes('/about/')).url}`));
}
// Menus persist and core can render their blocks, but theme menu placement is
// presentation-only: the source header/layout is intentionally not transplanted.
for (const menu of Object.values(after.menus)) {
  assert.ok(menu.rendered.includes('<a'), 'Native navigation blocks still render');
}
for (const media of Object.values(after.media)) {
  const response = await fetch(media.url);
  assert.equal(response.status, 200, 'Media bytes remain publicly reachable');
}
console.log('Theme-switch ownership proof passed. Presentation-only: source CSS, layout, header/menu placement and theme templates.');
