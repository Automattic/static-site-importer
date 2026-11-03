// Exit 0 when an image the source page shows is absent from the Blocks Engine
// output: same media key, measured the way the evaluator measures it.
// Usage: PREDICATE_TARGET='{"subject":"group:social bar","width":1440}' \
//   node image_missing.mjs <php-transformer dir> <candidate.html>
// BLOCK_LIBRARY_CSS may name core's block-library stylesheet, which a
// WordPress front end loads under the output.
import { chromium } from 'playwright';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import { fitViewport, measure } from '../measure.mjs';

// Any failure is an error (2), never a verdict.
process.on('uncaughtException', () => process.exit(2));
process.on('unhandledRejection', () => process.exit(2));

const [transformer, candidate] = process.argv.slice(2);
const { subject, width = 1440 } = JSON.parse(process.env.PREDICATE_TARGET || '{}');
if (!subject) process.exit(2);
const transform = new URL('../../reduce-finding/proofs/transform.php', import.meta.url).pathname;
const out = spawnSync('php', ['-d', 'memory_limit=2G', transform, transformer, candidate], { encoding: 'utf8', maxBuffer: 512 << 20 });
if (out.status !== 0) process.exit(2);
let result; try { result = JSON.parse(out.stdout); } catch { process.exit(2); }
const css = [
  process.env.BLOCK_LIBRARY_CSS ? fs.readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8') : '',
  ...(result.assets || []).filter((a) => a?.kind === 'css' && typeof a.content === 'string').map((a) => a.content),
].join('\n');

const browser = await chromium.launch();
const page = await browser.newPage();
const media = async (html) => {
  await page.setContent(html, { waitUntil: 'load' });
  await fitViewport(page, width);
  return (await page.evaluate(measure, { coverShare: 1, exclusions: [], geometryOnly: false })).media;
};
const wanted = (await media(fs.readFileSync(candidate, 'utf8'))).filter((m) => m.key === subject || m.group === subject);
const shown = new Set((await media(`<!doctype html><html><head><style>${css}</style></head><body><div class="wp-site-blocks">${result.serialized_blocks || ''}</div></body></html>`)).map((m) => m.key));
await browser.close();
const missing = wanted.filter((m) => !shown.has(m.key));
if (process.env.PREDICATE_DEBUG) console.error(JSON.stringify({ wanted, missing }));
process.exit(wanted.length && missing.length ? 0 : 1);
