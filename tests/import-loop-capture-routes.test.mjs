import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { captureRoutes, foldedRoutes, sourceDeviceDocuments } from '../tools/import-loop/checks.mjs';
import { routeDocument } from '../tools/import-loop/lib.mjs';

test('routes come from the capture receipt for directory and file sites', () => {
  for (const [paths, routes] of [
    [['website/index.html', 'website/privacy-policy/index.html', 'website/about/index.html'], ['/', '/about/', '/privacy-policy/']],
    [['website/index.html', 'website/store.html', 'website/about.html', 'website/products.html'], ['/', '/about.html', '/products.html', '/store.html']],
  ]) {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'capture-routes-'));
    fs.writeFileSync(path.join(root, 'capture-receipt.json'), JSON.stringify({ routes: paths.map((p) => ({ path: p })) }));
    assert.deepEqual(captureRoutes(root), routes);
    // Each route reads back the document it was captured from.
    assert.deepEqual(captureRoutes(root).map((route) => path.relative(root, routeDocument(path.join(root, 'website'), route))).sort(), [...paths].sort());
  }
});

test('a WordPress route reads its capture document however the capture saved it', () => {
  const website = fs.mkdtempSync(path.join(os.tmpdir(), 'capture-case-'));
  fs.mkdirSync(path.join(website, 'shop', 'Suspension'), { recursive: true });
  fs.writeFileSync(path.join(website, 'shop', 'Suspension', 'index.html'), 'suspension');
  fs.writeFileSync(path.join(website, 'shop', 'index-query-1.html'), 'query variant');
  for (const [route, content] of [['/shop/suspension/', 'suspension'], ['/shop/index-query-1/', 'query variant']]) {
    assert.equal(fs.readFileSync(routeDocument(website, route), 'utf8'), content);
  }
});

test('a route folded into a page with different text is reported; aliases and query renditions are not', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'capture-folded-'));
  const site = 'https://spa.example/';
  const pages = { '': 'Home', about: 'About us', alias: 'Home', 'gallery?c=x': 'Gallery X' };
  fs.mkdirSync(path.join(root, 'html')); fs.mkdirSync(path.join(root, 'screenshots'));
  const entries = {};
  for (const [route, text] of Object.entries(pages)) {
    const file = `html/${route.replace(/\W/g, '-') || 'homepage'}.html`;
    fs.writeFileSync(path.join(root, file), `<html><head><link rel="canonical" href="${site}"><script>x()</script></head><body><h1>${text}</h1></body></html>`);
    entries[site + route] = { html: file };
  }
  fs.writeFileSync(path.join(root, 'screenshots', 'manifest.json'), JSON.stringify({ version: 1, entries }));
  fs.writeFileSync(path.join(root, 'capture-receipt.json'), JSON.stringify({ duplicateRoutes: ['about', 'alias', 'gallery?c=x'].map((r) => ({ url: site + r, canonicalUrl: site })) }));
  assert.deepEqual(foldedRoutes(root), [{ route: '/about', canonical: '/' }]);
});

test('source device documents: a dual-document route blocks, a diverged one advises, a single document reports nothing', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'capture-devices-'));
  const rv = (outcome, extra = {}) => ({ variants: 1, outcome, css: 'shared', matchedNodes: 40, desktopOnlyElements: 0, mobileOnlyElements: 0, ...extra });
  fs.writeFileSync(path.join(root, 'capture-receipt.json'), JSON.stringify({ routes: [
    { path: 'website/index.html', responsiveVariants: rv('collapsed-identity-subset', { divergedComponents: 6, css: 'viewport-scoped' }) },
    { path: 'website/about/index.html', responsiveVariants: rv('collapsed-identity-subset', { desktopOnlyElements: 1, divergedComponents: 3 }) },
    { path: 'website/home-alt-bedford/index.html', responsiveVariants: rv('dual-structural', { variants: 2 }) },
    { path: 'website/blog/index.html', responsiveVariants: rv('collapsed-equivalent') },
    { path: 'website/contact/index.html' },
  ] }));
  const found = sourceDeviceDocuments(root);
  assert.deepEqual(found.map(({ route, severity, subject }) => [route, severity, subject]), [
    ['/', 'advisory', 'viewport_divergence'],
    ['/about/', 'advisory', 'viewport_divergence'],
    ['/home-alt-bedford/', 'blocker', 'dual_document'],
  ]);
  assert.deepEqual(found[2].evidence, { outcome: 'dual-structural', variants: 2, matchedNodes: 40, desktopOnlyElements: 0, mobileOnlyElements: 0, divergedComponents: 0, css: 'shared' });

  fs.writeFileSync(path.join(root, 'capture-receipt.json'), JSON.stringify({ routes: [{ path: 'website/index.html' }] }));
  assert.deepEqual(sourceDeviceDocuments(root), []);
});
