// Exit 0 when the engine's authored-marquee duration disagrees with Chromium's
// computed animation-duration for the same page (BE#1914 :where() specificity).
// Usage: node predicate.mjs <php-transformer dir> <candidate.html>
import { chromium } from 'playwright';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
const [transformer, candidate] = process.argv.slice(2);
const out = spawnSync('php', ['-d', 'memory_limit=1G', new URL('../transform.php', import.meta.url).pathname, transformer, candidate], { encoding: 'utf8', maxBuffer: 256 << 20 });
if (out.status !== 0) process.exit(2);
let result; try { result = JSON.parse(out.stdout); } catch { process.exit(2); }
const engine = [...String(result.serialized_blocks || '').matchAll(/<!-- wp:custom\/authored-marquee (\{.*?\}) -->/g)].map((m) => JSON.parse(m[1]).duration);
if (!engine.length) process.exit(1);
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
await page.setContent(fs.readFileSync(candidate, 'utf8'), { waitUntil: 'domcontentloaded' });
const chrome = await page.evaluate(() => [...document.querySelectorAll('*')].map((el) => getComputedStyle(el)).filter((s) => s.animationName !== 'none' && s.animationIterationCount === 'infinite').map((s) => parseFloat(s.animationDuration) * (s.animationDuration.endsWith('ms') ? 0.001 : 1)));
await browser.close();
process.exit(engine.some((d) => !chrome.some((c) => Math.abs(c - d) < 0.01)) ? 0 : 1);
