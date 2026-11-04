#!/usr/bin/env node
// Scope gate: a fix may change the imported site only inside the finding's
// subject. Compares the computed visual style of every element on every route
// and width of two imports of the same capture, the candidate's base and the
// candidate, and lists each element outside the subject whose style changed.
// Computed styles do not move when layout shifts, so a restored image pushing
// content down is not a change; a rule that restyles an unrelated button is.
//
// The scope is the finding's subject and evidence keys: `group:<label>` is
// the element labelled <label>, `link:<host>` the links to <host>, `img:<name>`
// that image and its parent. A source group label need not survive the import,
// so a group finding also names its members' keys. Findings without element
// keys have no style scope and skip the style comparison.
//
// Images are checked whatever the scope: every rendered image outside the
// subject keeps its rendered size (2px tolerance) and stays rendered. A fix
// that changes how media is emitted (a carrier block lowered to core/image,
// a new wrapper) must not resize, crop, or drop the images it touches.
//
// Usage: node collateral.mjs <base url> <candidate url> <key,...> <route,...>
// Exit 0: no change outside the subject. 1: changes outside it (on stderr).
// 2: the comparison failed.
import { chromium } from 'playwright';

process.on('uncaughtException', (error) => { console.error(`collateral: ${error.message || error}`); process.exit(2); });
process.on('unhandledRejection', (error) => { console.error(`collateral: ${error?.message || error}`); process.exit(2); });

const [baseUrl, candidateUrl, keyList, routeList] = process.argv.slice(2);
if (!baseUrl || !candidateUrl || !keyList || !routeList) process.exit(2);
const keys = keyList.split(',').map((key) => { const at = key.indexOf(':'); return [key.slice(0, at), key.slice(at + 1)]; })
  .filter(([kind]) => ['group', 'link', 'img'].includes(kind));
if (!keys.length) console.error(`collateral: ${keyList} has no element scope; style comparison skipped, image geometry checked`);

const WIDTHS = [390, 768, 1440];
const PROPERTIES = [
  'display', 'visibility', 'opacity', 'position', 'float', 'z-index', 'overflow-x', 'overflow-y', 'box-sizing',
  'min-width', 'min-height', 'max-width', 'max-height',
  'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
  'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
  'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style',
  'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color', 'border-top-left-radius',
  'background-color', 'background-image', 'background-size', 'background-position', 'background-repeat',
  'color', 'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing',
  'text-align', 'text-transform', 'text-decoration-line', 'object-fit',
  'flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'column-gap', 'row-gap',
];

// Every element of the body: its tag:nth-of-type path, its scope keys, and its
// computed style. Paths ignore classes, which carry content hashes.
const snapshot = ({ properties }) => {
  const path = (el) => {
    const parts = [];
    for (let node = el; node && node !== document.body; node = node.parentElement) {
      const same = [...(node.parentElement?.children || [])].filter((c) => c.tagName === node.tagName);
      parts.unshift(`${node.tagName.toLowerCase()}:${same.indexOf(node) + 1}`);
    }
    return parts.join('>');
  };
  const host = (a) => a.hostname.split('.').slice(-2).join('.');
  const file = (el) => {
    const src = el.currentSrc || el.src || /url\(["']?([^"')]+)/.exec(getComputedStyle(el).backgroundImage)?.[1] || '';
    if (!src || src.startsWith('data:')) return null;
    return decodeURIComponent(new URL(src, location.href).pathname.split('/').pop() || '').toLowerCase().replace(/\.[a-z0-9]+$/, '').replace(/-(?:\d+x\d+|scaled)$/, '').replace(/[^a-z0-9]/g, '') || null;
  };
  return [...document.body.querySelectorAll('*')].filter((el) => !el.closest('script,style,noscript,template')).map((el) => {
    const style = getComputedStyle(el);
    return {
      path: path(el),
      label: el.getAttribute('aria-label')?.trim().toLowerCase() || null,
      link: el.tagName === 'A' && el.getAttribute('href') && !el.getAttribute('href').startsWith('#') ? host(el) : null,
      img: file(el),
      box: el.tagName === 'IMG' ? (({ width, height }) => [Math.round(width), Math.round(height)])(el.getBoundingClientRect()) : null,
      style: Object.fromEntries(properties.map((p) => [p, p === 'background-image' ? style.getPropertyValue(p).slice(0, 200) : style.getPropertyValue(p)])),
    };
  });
};

// Paths an element may change under: the subject's own elements.
const scopes = (elements) => elements.flatMap((el) => keys.flatMap(([kind, name]) => {
  if (kind === 'group' && el.label === name) return [el.path];
  if (kind === 'link' && el.link === name) return [el.path];
  if (kind === 'img' && el.img === name) return [el.path, el.path.split('>').slice(0, -1).join('>')];
  return [];
}));
const inside = (path, roots) => roots.some((root) => root && (path === root || path.startsWith(`${root}>`)));

const browser = await chromium.launch();
const page = await browser.newPage();
const changes = [];
for (const route of routeList.split(',')) {
  for (const width of WIDTHS) {
    const sides = [];
    for (const base of [baseUrl, candidateUrl]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(new URL(route, base).href, { waitUntil: 'networkidle' });
      sides.push(await page.evaluate(snapshot, { properties: PROPERTIES }));
    }
    const [before, after] = sides;
    const roots = [...scopes(before), ...scopes(after)];
    const previous = new Map(before.map((el) => [el.path, el]));
    // Images pair by file name and occurrence, since a fix may move them in the tree.
    const images = (els) => { const seen = new Map(); return new Map(els.filter((el) => el.box && el.img && !keys.some(([k, n]) => k === 'img' && n === el.img)).map((el) => { const n = (seen.get(el.img) || 0) + 1; seen.set(el.img, n); return [`${el.img}#${n}`, el]; })); };
    const afterImages = images(after);
    for (const [id, was] of images(before)) {
      const now = afterImages.get(id);
      const box = now?.box || [0, 0];
      if (Math.abs(box[0] - was.box[0]) > 2 || Math.abs(box[1] - was.box[1]) > 2) changes.push({ route, width, image: id, box: [was.box, now ? box : 'missing'] });
    }
    if (!keys.length) continue;
    for (const el of after) {
      const was = previous.get(el.path);
      if (!was || inside(el.path, roots)) continue;
      const changed = PROPERTIES.filter((p) => was.style[p] !== el.style[p]);
      if (changed.length) changes.push({ route, width, path: el.path, changed: Object.fromEntries(changed.map((p) => [p, [was.style[p], el.style[p]]])) });
    }
  }
}
await browser.close();
for (const change of changes.slice(0, 20)) console.error(JSON.stringify(change));
if (changes.length > 20) console.error(`... ${changes.length - 20} more`);
console.error(`collateral: ${changes.length} change(s) outside ${keyList}`);
process.exit(changes.length ? 1 : 0);
