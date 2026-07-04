#!/usr/bin/env node

/**
 * External dependencies
 */
import fs from 'node:fs';

/**
 * Internal dependencies
 */
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
  const parsedOptions = {};
  for (let index = 0; index < args.length; index += 1) {
    const arg = args[index];
    if (arg === '--help' || arg === '-h') {
      parsedOptions.help = true;
      continue;
    }
    if (arg.startsWith('--')) {
      const [rawKey, rawValue] = arg.slice(2).split('=');
      const value = rawValue === undefined ? args[index + 1] : rawValue;
      if (rawValue === undefined) {
        index += 1;
      }
      parsedOptions[camelCase(rawKey)] = value;
      continue;
    }
    if (!parsedOptions.artifactRoot) {
      parsedOptions.artifactRoot = arg;
    } else if (!parsedOptions.fixtureRoot) {
      parsedOptions.fixtureRoot = arg;
    }
  }
  return parsedOptions;
}

function camelCase(value) {
  return value.replace(/-([a-z])/g, (_, char) => char.toUpperCase());
}

function printHelp() {
  process.stdout.write(`Usage: node tools/artifact-intake.mjs --artifact-root <dir> --fixture-root <dir> [--manifest <file>]\n\nMaterializes generated static-site artifacts into SSI fixture-matrix directories.\n`);
}
