#!/usr/bin/env node

import fs from 'node:fs';
import { materializeGeneratedArtifactFixtures } from '../lib/artifact-intake.mjs';

const options = parseArgs(process.argv.slice(2));
if (options.help) {
  printHelp();
  process.exit(0);
}

const result = materializeGeneratedArtifactFixtures(options);
if (options.manifest) {
  fs.writeFileSync(options.manifest, `${JSON.stringify(result, null, 2)}\n`);
}
process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);

function parseArgs(args) {
  const options = {};
  for (let index = 0; index < args.length; index += 1) {
    const arg = args[index];
    if (arg === '--help' || arg === '-h') {
      options.help = true;
      continue;
    }
    if (arg.startsWith('--')) {
      const [rawKey, rawValue] = arg.slice(2).split('=');
      const value = rawValue === undefined ? args[index + 1] : rawValue;
      if (rawValue === undefined) {
        index += 1;
      }
      options[camelCase(rawKey)] = value;
      continue;
    }
    if (!options.artifactRoot) {
      options.artifactRoot = arg;
    } else if (!options.fixtureRoot) {
      options.fixtureRoot = arg;
    }
  }
  return options;
}

function camelCase(value) {
  return value.replace(/-([a-z])/g, (_, char) => char.toUpperCase());
}

function printHelp() {
  process.stdout.write(`Usage: node tools/artifact-intake.mjs --artifact-root <dir> --fixture-root <dir> [--manifest <file>]\n\nMaterializes generated static-site artifacts into SSI fixture-matrix directories.\n`);
}
