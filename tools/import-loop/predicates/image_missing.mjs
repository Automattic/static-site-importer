// Exit 0 when an image the source page shows is absent from the imported
// output: same media key, measured the way the evaluator measures it.
// Usage: PREDICATE_TARGET='{"subject":"group:social bar","width":1440}' \
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
const { subject, width = 1440 } = JSON.parse(process.env.PREDICATE_TARGET || '{}');
if (!subject) process.exit(2);
const output = renderOutput(transformer, candidate);

const browser = await chromium.launch();
const page = await browser.newPage();
const media = async (html) => {
  await page.setContent(html, { waitUntil: 'load' });
  await fitViewport(page, width);
  return (await page.evaluate(measure, { coverShare: 1, exclusions: [], geometryOnly: false })).media;
};
const wanted = (await media(fs.readFileSync(candidate, 'utf8'))).filter((m) => m.key === subject || m.group === subject);
const shown = new Set((await media(output)).map((m) => m.key));
await browser.close();
const missing = wanted.filter((m) => !shown.has(m.key));
if (process.env.PREDICATE_DEBUG) console.error(JSON.stringify({ wanted: wanted.map((m) => m.key), missing: missing.map((m) => m.key) }));
process.exit(wanted.length && missing.length ? 0 : 1);
