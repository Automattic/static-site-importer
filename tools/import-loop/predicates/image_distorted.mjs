// Exit 0 when an image the source page shows renders in the imported output
// with a different aspect ratio: same media key, measured the way the
// evaluator measures it (aspect change above 20%).
//
// The source image must still render at the size the finding recorded, so a
// reduction keeps what gives the source its box (the sizing rule) instead of
// drifting to another image or another defect.
// Usage: PREDICATE_TARGET='{"subject":"img:hero","width":1440,"evidence":{"source":[705,520],"candidate":[705,397]}}' \
//   WORDPRESS_PATH=<site> [WP_CLI='studio wp'] [PREDICATE_SITE_URL=<reimported site>] \
//   node image_distorted.mjs <php-transformer dir> <candidate.html>
import { chromium } from 'playwright';
import fs from 'node:fs';
import { fitViewport, measure } from '../measure.mjs';
import { loadOutput } from './render.mjs';

// Any failure is an error (2), never a verdict.
process.on('uncaughtException', () => process.exit(2));
process.on('unhandledRejection', () => process.exit(2));

const ASPECT_CHANGE = 0.2;
const [transformer, candidate] = process.argv.slice(2);
const { subject, route = '/', width = 1440, evidence } = JSON.parse(process.env.PREDICATE_TARGET || '{}');
const [sw, sh] = evidence?.source || [];
if (!subject || !sw || !sh) process.exit(2);

const browser = await chromium.launch();
const page = await browser.newPage();
const media = async (load) => {
  await load();
  await fitViewport(page, width);
  return (await page.evaluate(measure, { coverShare: 1, exclusions: [], geometryOnly: false })).media;
};
const source = (await media(() => page.setContent(fs.readFileSync(candidate, 'utf8'), { waitUntil: 'load' })))
  .find((m) => m.key === subject && Math.abs(m.w - sw) <= 1 && Math.abs(m.h - sh) <= 1);
const output = source ? (await media(() => loadOutput(page, transformer, candidate, route))).find((m) => m.key === subject) : null;
await browser.close();
const distorted = Boolean(source && output && Math.min(output.w, output.h) >= 8
  && Math.abs((output.w / output.h) / (source.w / source.h) - 1) > ASPECT_CHANGE);
if (process.env.PREDICATE_DEBUG) console.error(JSON.stringify({ source, output }));
process.exit(distorted ? 0 : 1);
