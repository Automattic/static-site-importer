#!/usr/bin/env node
// Gate: a candidate leaves no more editability debt than its merge base.
// Compares the importer's own report for two imports of the same capture:
// invalid blocks, and raw-HTML companion blocks per slug. A fix that trades
// one companion for another (a new slug, or more of one) adds debt.
//
// Usage: node editability.mjs <base site dir> <candidate site dir>
// Exit 0: no new debt. 1: new debt, listed on stderr. 2: a report is missing.

import fs from 'node:fs';
import path from 'node:path';

const slugStem = (slug) => slug.replace(/-[0-9a-f]{64}$/, '');

function debt(site) {
  const find = (dir) => {
    for (const entry of fs.existsSync(dir) ? fs.readdirSync(dir, { withFileTypes: true }) : []) {
      const full = path.join(dir, entry.name);
      if (entry.isFile() && entry.name === 'import-report.json') return full;
      if (entry.isDirectory()) { const found = find(full); if (found) return found; }
    }
    return null;
  };
  const file = find(path.join(site, 'wp-content', 'static-site-importer'));
  if (!file) { console.error(`editability: no import-report.json under ${site}`); process.exit(2); }
  const report = JSON.parse(fs.readFileSync(file, 'utf8'));
  const counts = { invalid_block_count: Number(report.quality?.invalid_block_count || 0) };
  for (const d of report.diagnostics || []) {
    if (d.type !== 'raw_html_companion_block') continue;
    const key = `raw_html_companion:${slugStem(String(d.context?.block_name || '').split('/').pop() || 'unknown')}`;
    counts[key] = (counts[key] || 0) + 1;
  }
  return counts;
}

const [base, candidate] = process.argv.slice(2).map(debt);
const added = Object.entries(candidate).filter(([key, count]) => count > (base[key] || 0));
for (const [key, count] of added) console.error(`editability: ${key} ${base[key] || 0} -> ${count}`);
console.error(`editability: ${added.length} new debt item(s) against the merge base`);
process.exit(added.length ? 1 : 0);
