#!/usr/bin/env node
// The candidate is a disposable, served re-import; the base is the pass's own
// evaluation at the same trunk, measured once (--base-findings).
import fs from 'node:fs';
import path from 'node:path';
import { args } from '../lib.mjs';
import { checkImport } from '../checks.mjs';
import { compareImports } from '../progress.mjs';
for (const key of ['base-findings', 'candidate-url', 'candidate-site', 'capture', 'url', 'signature', 'wp-cli', 'out']) if (!args[key]) throw new Error(`accept_argument_missing: ${key}`);
const source = path.join(args.out, 'source');
fs.mkdirSync(source, { recursive: true });
fs.cpSync(args.capture, path.join(source, new URL(args.url).hostname), { recursive: true });
const common = { url: args.url, source, 'wp-cli': args['wp-cli'] };
const before = JSON.parse(fs.readFileSync(args['base-findings'], 'utf8'));
const after = (await checkImport({ ...common, site: args['candidate-site'], 'wp-url': args['candidate-url'], out: path.join(args.out, 'after') })).result;
const result = compareImports(before, after, args.signature, args.cells ? JSON.parse(args.cells) : undefined);
// The candidate site is disposable; its server log (with each 5xx response's
// head, reimport.sh) is the only record of why a page failed to serve.
const serverLog = path.join(args['candidate-site'], '.server.log');
if (fs.existsSync(serverLog)) fs.writeFileSync(path.join(args.out, 'server-errors.log'), fs.readFileSync(serverLog, 'utf8').split('\n').filter((line) => line.includes('gate-site ') || /\[5\d\d\]/.test(line)).join('\n'));
fs.writeFileSync(path.join(args.out, 'acceptance.json'), JSON.stringify(result, null, 2));
// The verdict, compact: a gate report keeps only its output's tail.
console.log(JSON.stringify({ pass: result.pass, own: result.own, blockers_added: result.blockers.added.map((f) => `${f.signature}:${f.after}`), warnings: result.warnings.map((f) => `${f.signature}:excess ${f.excess.before}->${f.excess.after}`), blockers_removed: result.blockers.removed.map((f) => `${f.signature}:${f.before}->${f.after}`), new_scope: { routes: result.new_scope.routes.length, blockers: result.new_scope.blockers.map((f) => `${f.signature}:${f.count}`) }, visual_regressions: result.visual_regressions, visual_unmeasured: result.visual_unmeasured.length }));
process.exitCode = result.pass ? 0 : 1;
