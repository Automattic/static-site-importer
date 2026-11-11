#!/usr/bin/env node
// Re-import callers provide two disposable, served WordPress trees.
import fs from 'node:fs';
import path from 'node:path';
import { args } from '../lib.mjs';
import { checkImport } from '../checks.mjs';
import { compareImports } from '../progress.mjs';
for (const key of ['base-url', 'candidate-url', 'base-site', 'candidate-site', 'capture', 'url', 'signature', 'wp-cli', 'out']) if (!args[key]) throw new Error(`accept_argument_missing: ${key}`);
const source = path.join(args.out, 'source');
fs.mkdirSync(source, { recursive: true });
fs.cpSync(args.capture, path.join(source, new URL(args.url).hostname), { recursive: true });
const common = { url: args.url, source, 'wp-cli': args['wp-cli'] };
const before = (await checkImport({ ...common, site: args['base-site'], 'wp-url': args['base-url'], out: path.join(args.out, 'before') })).result;
const after = (await checkImport({ ...common, site: args['candidate-site'], 'wp-url': args['candidate-url'], out: path.join(args.out, 'after') })).result;
const result = compareImports(before, after, args.signature, args.cells ? JSON.parse(args.cells) : undefined);
fs.writeFileSync(path.join(args.out, 'acceptance.json'), JSON.stringify(result, null, 2));
console.log(JSON.stringify(result));
process.exitCode = result.pass ? 0 : 1;
