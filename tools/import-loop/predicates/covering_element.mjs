// Exit 0 when the Blocks Engine output renders a button link that covers the page
// when the same link is short in the source page (for example a fixed 34px
// banner link stretched by percentage-height button fills).
// Usage: node predicate.mjs <php-transformer dir> <candidate.html>
import { chromium } from 'playwright';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
const [transformer, candidate] = process.argv.slice(2);
const transform = new URL('../../reduce-finding/proofs/transform.php', import.meta.url).pathname;
const out = spawnSync('php', ['-d', 'memory_limit=2G', transform, transformer, candidate], { encoding: 'utf8', maxBuffer: 512 << 20 });
if (out.status !== 0) process.exit(2);
let r; try { r = JSON.parse(out.stdout); } catch { process.exit(2); }
const markup = String(r.serialized_blocks || '');
if (!markup.includes('wp-block-button__link')) process.exit(1);
const css = (r.assets || []).filter((a) => a?.kind === 'css' && typeof a.content === 'string').map((a) => a.content).join('\n');
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
// Height of the tallest link per visible text, so the check follows one link across both renders.
const heights = async (sel) => page.evaluate((s) => { const m = {}; for (const e of document.querySelectorAll(s)) { const t = e.textContent.replace(/\s+/g, ' ').trim(); if (t) m[t] = Math.max(m[t] || 0, e.getBoundingClientRect().height); } return m; }, sel);
await page.setContent(fs.readFileSync(candidate, 'utf8'), { waitUntil: 'domcontentloaded' });
const source = await heights('a');
await page.setContent(`<!doctype html><html><head><style>${css}</style></head><body><div class="wp-site-blocks" style="position:relative;min-height:2000px">${markup}</div></body></html>`, { waitUntil: 'domcontentloaded' });
const output = await heights('.wp-block-button__link');
await browser.close();
const covering = Object.entries(output).filter(([text, h]) => h > 800 && (source[text] ?? 0) < 200);
if (process.env.PREDICATE_DEBUG) console.error(JSON.stringify({ covering, source: Object.entries(source).filter(([t]) => t in output) }));
process.exit(covering.length ? 0 : 1);
