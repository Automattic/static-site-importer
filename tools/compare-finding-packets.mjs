#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const DEFAULT_TOP = 15;
const DIMENSIONS = [
  ['bucket', 'bucket'],
  ['group_key', 'group_key'],
  ['kind', 'kind'],
  ['fixture_id', 'fixture_id'],
  ['candidate_repo', 'candidate_repo'],
  ['selector_family', 'selector_family'],
];

const isCli = process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url);

if (isCli) {
  try {
    const options = parseArgs(process.argv.slice(2));
    if (options.help) {
      printHelp();
    } else {
      const summary = compareFindingPacketFiles(options);
      process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
    }
  } catch (error) {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 1;
  }
}

export function compareFindingPacketFiles(options = {}) {
  const baseFile = requiredString(options.base, '--base');
  const candidateFile = requiredString(options.candidate, '--candidate');
  return compareFindingPackets({
    base: readJsonFile(baseFile),
    candidate: readJsonFile(candidateFile),
    base_label: options.baseLabel || options.base_label || path.basename(baseFile),
    candidate_label: options.candidateLabel || options.candidate_label || path.basename(candidateFile),
    top: options.top,
  });
}

export function compareFindingPackets(input = {}) {
  const top = positiveInteger(input.top, DEFAULT_TOP);
  const baseFindings = normalizeFindingPackets(input.base);
  const candidateFindings = normalizeFindingPackets(input.candidate);
  const dimensions = Object.fromEntries(DIMENSIONS.map(([key, field]) => [
    key,
    topDeltas(countBy(baseFindings, field), countBy(candidateFindings, field), top),
  ]));

  return {
    schema: 'static-site-importer/finding-packet-comparison/v1',
    base_label: input.base_label || input.baseLabel || 'base',
    candidate_label: input.candidate_label || input.candidateLabel || 'candidate',
    top,
    totals: {
      base: baseFindings.length,
      candidate: candidateFindings.length,
      delta: candidateFindings.length - baseFindings.length,
    },
    dimensions,
  };
}

export function normalizeFindingPackets(input) {
  const findings = unwrapFindings(input);
  return findings.map((finding) => normalizeFinding(finding));
}

export function selectorFamily(selector) {
  const value = String(selector || '').trim();
  if (!value) {
    return '(none)';
  }

  const firstToken = value.split(/\s+|\s*[>+~]\s*/).find(Boolean) || value;
  if (firstToken.startsWith('#')) {
    return `id:${firstToken.slice(1).split(/[:.#[\]]/)[0] || '(unknown)'}`;
  }
  if (firstToken.startsWith('.')) {
    return `class:${firstToken.slice(1).split(/[:.#[\]]/)[0] || '(unknown)'}`;
  }
  if (firstToken.startsWith('[')) {
    return `attr:${firstToken.slice(1).split(/[=\]]/)[0] || '(unknown)'}`;
  }

  return firstToken.split(/[:.#[\]]/)[0] || firstToken;
}

function normalizeFinding(finding) {
  const raw = finding && typeof finding === 'object' ? finding : { reason: String(finding || '') };
  const rawPayload = raw.raw && typeof raw.raw === 'object' ? raw.raw : {};
  const groupKey = stringValue(raw.group_key || rawPayload.group_key || raw.category || rawPayload.category, '(missing)');
  const bucket = stringValue(raw.repair_bucket || rawPayload.repair_bucket || groupKey, '(missing)');
  const selector = stringValue(raw.selector || rawPayload.selector, '');
  return {
    bucket,
    group_key: groupKey,
    kind: stringValue(raw.kind || raw.code || raw.type || rawPayload.kind || rawPayload.code || rawPayload.type, '(missing)'),
    fixture_id: stringValue(raw.fixture_id || rawPayload.fixture_id || raw.fixture || rawPayload.fixture, '(missing)'),
    candidate_repo: stringValue(raw.candidate_repo || rawPayload.candidate_repo || raw.owner || rawPayload.owner, '(missing)'),
    selector,
    selector_family: selectorFamily(selector),
  };
}

function unwrapFindings(input) {
  if (Array.isArray(input)) {
    return input;
  }
  if (!input || typeof input !== 'object') {
    return [];
  }
  if (Array.isArray(input.findings)) {
    return input.findings;
  }
  if (Array.isArray(input.packets)) {
    return input.packets;
  }
  if (Array.isArray(input.fanout_groups)) {
    return input.fanout_groups.flatMap((group) => Array.isArray(group.findings) ? group.findings : []);
  }
  return [];
}

function countBy(findings, field) {
  const counts = new Map();
  for (const finding of findings) {
    const key = finding[field] || '(missing)';
    counts.set(key, (counts.get(key) || 0) + 1);
  }
  return counts;
}

function topDeltas(baseCounts, candidateCounts, top) {
  const keys = new Set([...baseCounts.keys(), ...candidateCounts.keys()]);
  return [...keys].map((key) => {
    const base = baseCounts.get(key) || 0;
    const candidate = candidateCounts.get(key) || 0;
    return { key, base, candidate, delta: candidate - base };
  })
    .filter((row) => row.delta !== 0)
    .sort((left, right) => Math.abs(right.delta) - Math.abs(left.delta) || right.delta - left.delta || left.key.localeCompare(right.key))
    .slice(0, top);
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
      const value = rawValue === undefined ? args[index + 1] : rawValue;
      if (rawValue === undefined) {
        index += 1;
      }
      options[camelCase(rawKey)] = value;
      continue;
    }
    if (!options.base) {
      options.base = arg;
    } else if (!options.candidate) {
      options.candidate = arg;
    }
  }
  return options;
}

function printHelp() {
  process.stdout.write(`Compare finding-packet JSON artifacts and summarize count deltas.\n\nUsage:\n  node tools/compare-finding-packets.mjs --base base.json --candidate candidate.json [--top 20]\n\nOptions:\n  --base <file>             Baseline finding-packets JSON file.\n  --candidate <file>        Candidate finding-packets JSON file.\n  --base-label <label>      Label for the baseline run.\n  --candidate-label <label> Label for the candidate run.\n  --top <count>             Number of rows per dimension. Default: ${DEFAULT_TOP}.\n`);
}

function readJsonFile(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function requiredString(value, label) {
  if (!value || typeof value !== 'string') {
    throw new Error(`Missing required ${label}. Run with --help for usage.`);
  }
  return value;
}

function positiveInteger(value, fallback) {
  const parsed = Number.parseInt(value, 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

function stringValue(value, fallback) {
  if (value === undefined || value === null || value === '') {
    return fallback;
  }
  return String(value);
}

function camelCase(value) {
  return value.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
}
