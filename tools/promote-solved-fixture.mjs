#!/usr/bin/env node

/**
 * External dependencies
 */
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const packageRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));

export async function main(argv = process.argv.slice(2)) {
  const options = parseArgs(argv);
  if (options.help) {
    printHelp();
    return { ok: true };
  }

  const result = promoteSolvedFixture(options);
  process.stdout.write(`${result.message}\n`);
  return result;
}

export function promoteSolvedFixture(options) {
  validateOptions(options);

  const registry = readJson(options.registry);
  if (!registry || typeof registry !== 'object') {
    throw new Error(`Registry file is missing or invalid: ${options.registry}`);
  }

  const decisions = Array.isArray(registry.fixture_decisions) ? registry.fixture_decisions : [];
  const decision = decisions.find((row) => row.fixture_id === options.fixtureId);
  if (!decision) {
    throw new Error(`Fixture "${options.fixtureId}" not found in registry decisions.`);
  }

  if (decision.acceptance_status !== 'solved_candidate') {
    throw new Error(`Fixture "${options.fixtureId}" has acceptance_status "${decision.acceptance_status}"; promotion requires "solved_candidate".`);
  }

  const blocksEngine = path.resolve(options.blocksEngine);
  const source = path.join(blocksEngine, 'fixtures', 'websites', options.fixtureId);
  const target = path.join(blocksEngine, 'fixtures', 'solved', options.fixtureId);

  if (!fs.existsSync(source)) {
    throw new Error(`Source fixture does not exist: ${source}`);
  }
  if (fs.existsSync(target)) {
    throw new Error(`Target already exists (duplicate fixture ID?): ${target}`);
  }

  const gitResult = spawnSync('git', ['-C', blocksEngine, 'mv', `fixtures/websites/${options.fixtureId}`, `fixtures/solved/${options.fixtureId}`], {
    encoding: 'utf8',
  });
  if (gitResult.status !== 0) {
    throw new Error(`git mv failed: ${gitResult.stderr || gitResult.stdout || `exit ${gitResult.status}`}`);
  }

  return {
    ok: true,
    fixture_id: options.fixtureId,
    blocks_engine: blocksEngine,
    message: [
      `Promoted ${options.fixtureId} to fixtures/solved/.`,
      '',
      'Next steps:',
      `  cd ${blocksEngine}`,
      `  git status`,
      `  git commit -m "promote(fixture): move ${options.fixtureId} to solved corpus"`,
      '  git push origin <branch>',
      '',
      `Registry: ${path.resolve(options.registry)}`,
    ].join('\n'),
  };
}

function validateOptions(options) {
  if (!options.fixtureId) {
    throw new Error('--fixture-id is required');
  }
  if (!options.registry) {
    throw new Error('--registry is required');
  }
  if (!options.blocksEngine) {
    throw new Error('--blocks-engine is required');
  }
}

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
      const key = camelCase(rawKey);
      const value = rawValue === undefined ? args[index + 1] : rawValue;
      if (rawValue === undefined) {
        index += 1;
      }
      options[key] = value;
      continue;
    }
  }
  return options;
}

function camelCase(value) {
  return value.replace(/-([a-z])/g, (_match, letter) => letter.toUpperCase());
}

function readJson(filePath) {
  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch {
    return null;
  }
}

function printHelp() {
  process.stdout.write(`Usage: node tools/promote-solved-fixture.mjs --fixture-id <id> --registry <path> --blocks-engine <path>

Promote a fixture from blocks-engine/fixtures/websites/ to fixtures/solved/.

The registry row for the fixture must have acceptance_status "solved_candidate".
Any other status is refused (no --force). The move is performed with "git mv" in
the blocks-engine checkout.

Options:
  --fixture-id <id>       Fixture ID matching a registry fixture_decisions row.
  --registry <path>       Path to a gutenberg-incompatibility-registry.json from a matrix run.
  --blocks-engine <path>  Blocks Engine checkout path.
  --help, -h              Show this help.
`);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  try {
    await main();
  } catch (error) {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 1;
  }
}
