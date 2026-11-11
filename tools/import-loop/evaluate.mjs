#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { args } from './lib.mjs';
import { checkImport } from './checks.mjs';
// Workflow pass directories are <slug>-pN-eval. Persist real progress
// automatically between live imports, including unchanged blocker counts.
if (!args.before && args.out) {
  const match = /^(.*-p)(\d+)(-eval)$/.exec(args.out);
  if (match && Number(match[2]) > 1) {
    const prior = path.join(`${match[1]}${Number(match[2]) - 1}${match[3]}`, 'findings.json');
    if (fs.existsSync(prior)) args.before = prior;
  }
}
const { output } = await checkImport(args);
fs.writeFileSync(process.env.HOMEBOY_STEP_OUTPUT || '/dev/stdout', JSON.stringify(output));
