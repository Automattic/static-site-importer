#!/usr/bin/env node
import { chromium } from 'playwright';
import { fileURLToPath } from 'node:url';
import { collectAuthoringParity } from '../../lib/authoring-parity.mjs';
import { wordpressRunner } from './wordpress.mjs';

export async function authoringParity(site, wpCli) {
  const browser = await chromium.launch();
  try { return await collectAuthoringParity({ wp: wordpressRunner(site, wpCli), browser }); }
  finally { await browser.close(); }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const [site, wpCli = 'wp'] = process.argv.slice(2);
  if (!site) throw new Error('Usage: node tools/import-loop/authoring-parity.mjs <site-path> [wp-cli]');
  const evidence = await authoringParity(site, wpCli);
  console.log(JSON.stringify(evidence, null, 2));
  process.exitCode = evidence.length && evidence.every(row => row.pass) ? 0 : 1;
}
