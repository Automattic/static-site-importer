#!/usr/bin/env node
// Run inside npx's pinned client environment. The CLI exposes profiles only;
// its public checkFidelity API also bounds routes and widths without rewriting
// (and invalidating) the capture's hash-pinned reference evidence.
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
const [executable, capture, candidateUrl, scopeFile] = process.argv.slice(2);
const binary = process.env.PATH.split(path.delimiter).map((dir) => path.join(dir, executable)).find((file) => fs.existsSync(file));
if (!binary) throw new Error('compare client not on PATH');
const entry = fs.realpathSync(binary);
const { checkFidelity } = await import(pathToFileURL(path.join(path.dirname(entry), 'capture-engine.bundle.mjs')));
const scope = JSON.parse(fs.readFileSync(scopeFile, 'utf8'));
const cells = scope.flatMap((s) => s.cells).filter((c) => c.route != null && c.width != null);
const reports = [];
// Group identical width scopes so a route/width not in the findings is never
// scored just because another route requested it.
const routes = [...new Set(cells.map((c) => c.route))];
for (const route of routes) {
  reports.push(await checkFidelity({ directory: capture, candidateUrl, stage: 'materialization', routes: [route], widths: [...new Set(cells.filter((c) => c.route === route).map((c) => c.width))], log: console.error }));
}
const report = { scores: reports.flatMap((r) => r.scores || []), pending: reports.flatMap((r) => r.pending || []) };
const out = path.join(capture, 'compare', 'materialization');
fs.mkdirSync(out, { recursive: true });
fs.writeFileSync(path.join(out, 'report.json'), JSON.stringify(report));
