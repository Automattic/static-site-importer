#!/usr/bin/env node
// The developer baseline behind quality.mjs's thresholds: measure the
// templates and patterns of block themes from WordPress.org, and optionally
// imported sites, with the measures the loop's checks use.
//
//   node calibrate.mjs [--site <WordPress dir>]... <theme slug>...
//
// Each theme's templates and patterns of 25 or more blocks count as pages.
// Prints, per source, each measure's 50th and 90th percentile and maximum, and
// the site-wide share of literal style values.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { wrapperWeight, styleTokens, QUALITY } from './quality.mjs';
const { parse } = createRequire(import.meta.url)('@wordpress/block-serialization-default-parser');

const flat = (markup) => {
  const all = [];
  const walk = (list, depth, parent) => list.forEach((b) => {
    if (!b.blockName) return;
    walk(b.innerBlocks, depth + 1, all.push({ name: b.blockName, depth, parent }) - 1);
  });
  walk(parse(markup), 1, -1);
  return all;
};

function themeMarkup(slug) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'calibrate-'));
  execFileSync('curl', ['-fsSL', '-o', `${dir}/theme.zip`, `https://downloads.wordpress.org/theme/${slug}.zip`]);
  execFileSync('unzip', ['-q', `${dir}/theme.zip`, '-d', dir]);
  return ['templates', 'patterns'].flatMap((sub) => {
    const at = path.join(dir, slug, sub);
    return fs.existsSync(at) ? fs.readdirSync(at).filter((f) => /\.(html|php)$/.test(f)).map((f) => fs.readFileSync(path.join(at, f), 'utf8').replace(/<\?php[^]*?\?>/g, 'x')) : [];
  });
}

function siteMarkup(site) {
  const wp = (...a) => execFileSync('wp', [`--path=${site}`, '--skip-plugins', '--skip-themes', ...a], { encoding: 'utf8', maxBuffer: 64 << 20 });
  return JSON.parse(wp('post', 'list', '--post_type=page,wp_template,wp_template_part', '--post_status=publish', '--fields=post_content', '--format=json')).map((p) => p.post_content);
}

const percentile = (values, p) => { const s = [...values].sort((a, b) => a - b); return s[Math.min(s.length - 1, Math.floor(p * s.length))]; };
function summarize(label, markups) {
  const pages = markups.map(flat).filter((blocks) => blocks.length >= QUALITY.min_blocks).map((blocks) => {
    const w = wrapperWeight(blocks);
    return { groups_per_leaf: +(w.groups / Math.max(1, w.leaves)).toFixed(2), depth: Math.max(...blocks.map((b) => b.depth)) };
  });
  const tokens = styleTokens(markups.join('\n'));
  console.log(`${label}: ${pages.length} pages, literal style share ${tokens.share}`);
  for (const key of ['groups_per_leaf', 'depth']) if (pages.length) console.log(`  ${key}: p50 ${percentile(pages.map((p) => p[key]), 0.5)}, p90 ${percentile(pages.map((p) => p[key]), 0.9)}, max ${percentile(pages.map((p) => p[key]), 1)}`);
}

const argv = process.argv.slice(2);
for (let i = 0; i < argv.length; i++) {
  if (argv[i] === '--site') summarize(argv[i + 1], siteMarkup(argv[++i]));
  else summarize(argv[i], themeMarkup(argv[i]));
}
console.log(`thresholds: ${JSON.stringify(QUALITY)}`);
