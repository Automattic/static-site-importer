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
fs.writeFileSync(path.join(args.out, 'acceptance.json'), JSON.stringify(result, null, 2));
console.log(JSON.stringify(result));
process.exitCode = result.pass ? 0 : 1;
