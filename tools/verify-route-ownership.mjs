import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { JSDOM } from 'jsdom';

const evidence = process.argv[2];
const read = (name) => readFileSync(`${evidence}/${name}`, 'utf8');
const before = JSON.parse(read('before.json'));
const after = JSON.parse(read('after.json'));
// The site language is the one deliberate phase difference: the import applies
// the source document language (nl -> nl_NL), then the owner's fr_FR survives
// reimport, rollback and the theme switch. Everything else must be identical.
const withoutPhaseLanguage = (snapshot) => JSON.parse(JSON.stringify({
  ...snapshot,
  settings: { ...snapshot.settings, WPLANG: '<phase-dependent>' },
  pages: snapshot.pages.map(({ route_language: _route_language, ...page }) => page),
}));
assert.deepEqual(withoutPhaseLanguage(after), withoutPhaseLanguage(before), 'Native content, routes, menus, media, thumbnails, SEO and options survive switch');
// The first import installs the source language through the core language-pack
// path; the owner value the site set between imports is never overwritten.
assert.equal(before.settings.WPLANG, 'nl_NL', 'The source document language becomes the site language owner');
for (const phase of ['reimported', 'rollback']) {
  const snapshot = JSON.parse(read(`${phase}.json`));
  assert.deepEqual(withoutPhaseLanguage(snapshot), withoutPhaseLanguage(before), `${phase}: native owner snapshot is unchanged`);
  assert.equal(snapshot.settings.WPLANG, 'fr_FR', `${phase}: a language the site owner set is preserved, never overwritten`);
}
assert.equal(after.settings.WPLANG, 'fr_FR', 'The preserved owner language survives the theme switch');
const routeLanguage = (snapshot, title) => {
  const page = snapshot.pages.find((candidate) => candidate.title === title);
  assert.ok(page, `route snapshot contains ${title}`);
  return page.route_language;
};
assert.equal(routeLanguage(before, 'Garden home'), '', 'A route sharing the site language is not recorded');
assert.equal(routeLanguage(before, 'About the garden'), 'en', 'A differing route language is recorded on its owner');
assert.equal(routeLanguage(before, 'Contact the garden'), 'fr', 'A nested differing route language is recorded on its owner');
assert.equal(routeLanguage(after, 'Garden home'), 'nl', 'Route language records follow the effective site language');
assert.equal(routeLanguage(after, 'About the garden'), 'en', 'Route language records survive the theme switch');
assert.equal(routeLanguage(after, 'Contact the garden'), 'fr', 'Route language records survive the theme switch');
const expectedCoreLanguage = { before: 'nl-NL', after: 'fr-FR' };
for (const phase of ['before', 'after']) {
  for (const page of before.pages) {
    const name = createHash('sha256').update(page.url).digest('hex').slice(0, 12);
    const html = read(`${phase}-${name}.html`);
    const doc = new JSDOM(html).window.document;
    assert.ok(doc.body.textContent.includes('Persistent garden content.'), `${phase}: content renders logged out`);
    // Core owns <html lang>: exactly one language attribute, in the site
    // locale's core form, never a replayed source tag.
    assert.equal(doc.documentElement.getAttribute('lang'), expectedCoreLanguage[phase], `${phase}: core language_attributes() owns <html lang>`);
    assert.equal([...doc.querySelectorAll('[lang]')].length, 1, `${phase}: exactly one lang owner on the page`);
    assert.equal(html.match(/\slang=/g)?.length, 1, `${phase}: no second lang emitter in the markup`);
    assert.equal(doc.querySelectorAll('meta[charset]').length, 1);
    assert.equal(doc.querySelectorAll('meta[name="viewport"]').length, 1);
    // Verification-only probes never ship to the logged-out public front end.
    assert.ok(!html.includes('static-site-importer-font-readiness'), `${phase}: the readiness probe is not enqueued on the public front end`);
    assert.ok(!html.includes('font-readiness.js'), `${phase}: no readiness probe script is public`);
    assert.ok(!html.includes('static_site_importer_verify'), `${phase}: the verification signal is not part of public rendering`);
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
  // The verification runtime that needs the readiness probe still gets it when
  // it asks for it on its own capture request.
  const verification = read(`${phase}-verification.html`);
  assert.ok(verification.includes('static-site-importer-font-readiness'), `${phase}: the verification runtime can still request the readiness probe`);
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
