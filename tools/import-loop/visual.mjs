// Shared frozen-capture compare contract for the loop and corpus.
import fs from 'node:fs';
import path from 'node:path';
export const WIDTHS = [390, 768, 1440];
const client = JSON.parse(fs.readFileSync(new URL('./compare-client.json', import.meta.url), 'utf8'));
export function compareInvocation(capture, candidate, packageName) {
  return {
    command: ['--yes', `--package=${packageName}`, client.executable, 'compare', capture, '--candidate', candidate],
    reportPath: path.join(capture, 'compare', 'materialization', 'report.json'),
  };
}
export function visualCells(capture, routes, parity, url) {
  const reference = JSON.parse(fs.readFileSync(path.join(capture, 'fidelity-reference.json'), 'utf8'));
  const sampled = reference.scope?.referenceSample === undefined ? null : new Set((reference.entries || []).map(e => e.route));
  const cells = [], unsampled = [];
  for (const route of routes) for (const width of WIDTHS) {
    const matches = s => new URL(s.route, url).pathname === route && s.viewport === width;
    const scores = (parity?.scores || []).filter(matches);
    const pending = (parity?.pending || []).filter(matches);
    const cell = { route, width, scores, pending, verified: scores.length > 0 && pending.length === 0 };
    if (sampled && !sampled.has(route) && !scores.length && !pending.length) unsampled.push(cell);
    else cells.push(cell);
  }
  return { cells, unsampled, reference };
}
