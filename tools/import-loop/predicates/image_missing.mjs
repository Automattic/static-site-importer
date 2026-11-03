// Exit 0 when an image the source page shows is absent from the imported
// output: same media key, measured the way the evaluator measures it.
//
// The source image must still render at the size the finding recorded, so a
// reduction keeps what makes it that image instead of drifting to another
// defect (an emptied <svg> renders 300x150 and is a different case).
// Usage: PREDICATE_TARGET='{"subject":"group:social bar","width":1440,"evidence":{"missing":[{"key":"link:x.com","source":[27,27]}]}}' \
//   WORDPRESS_PATH=<site> [WP_CLI='studio wp'] \
//   node image_missing.mjs <php-transformer dir> <candidate.html>
import { chromium } from 'playwright';
import fs from 'node:fs';
import { fitViewport, measure } from '../measure.mjs';
import { renderOutput } from './render.mjs';

// Any failure is an error (2), never a verdict.
process.on('uncaughtException', () => process.exit(2));
process.on('unhandledRejection', () => process.exit(2));

const [transformer, candidate] = process.argv.slice(2);
const { subject, width = 1440, evidence } = JSON.parse(process.env.PREDICATE_TARGET || '{}');
const recorded = new Map((evidence?.missing || []).map((m) => [m.key, m.source]));
if (!subject || !recorded.size) process.exit(2);
const asRecorded = (m) => recorded.has(m.key) && recorded.get(m.key).every((size, i) => Math.abs(size - [m.w, m.h][i]) <= 1);
const output = renderOutput(transformer, candidate);

const browser = await chromium.launch();
const page = await browser.newPage();
const media = async (html) => {
  await page.setContent(html, { waitUntil: 'load' });
  await fitViewport(page, width);
  return (await page.evaluate(measure, { coverShare: 1, exclusions: [], geometryOnly: false })).media;
};
const wanted = (await media(fs.readFileSync(candidate, 'utf8'))).filter((m) => (m.key === subject || m.group === subject) && asRecorded(m));
const shown = new Set((await media(output)).map((m) => m.key));
await browser.close();
const missing = wanted.filter((m) => !shown.has(m.key));
if (process.env.PREDICATE_DEBUG) console.error(JSON.stringify({ wanted: wanted.map((m) => m.key), missing: missing.map((m) => m.key) }));
process.exit(wanted.length && missing.length ? 0 : 1);
