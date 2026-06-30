// Generic, domain-agnostic helpers for the Static Site Importer fixture matrix.
//
// Extracted verbatim from the former `lib/fixture-matrix.mjs` monolith as part
// of the matrix modularization (Refs #242). Pure utilities only — no behavior.

import fs from 'node:fs';
import path from 'node:path';

import { DEFAULT_VISUAL_PARITY_PIXEL_THRESHOLD } from './constants.mjs';

export function pushUnique(values, value, limit) {
  if (!value || values.includes(value) || values.length >= limit) {
    return;
  }
  values.push(value);
}

export function countBy(values, keyCallback) {
  return values.reduce((counts, value) => {
    const key = keyCallback(value);
    counts[key] = (counts[key] || 0) + 1;
    return counts;
  }, {});
}

export function clampRatio(value) {
  if (!Number.isFinite(value) || value < 0) {
    return DEFAULT_VISUAL_PARITY_PIXEL_THRESHOLD;
  }
  return value > 1 ? 1 : value;
}

export function qualityRatio(part, total) {
  return total > 0 ? Number((numberValue(part) / total).toFixed(4)) : null;
}

export function isTruthySignal(value) {
  if (value === undefined || value === null) {
    return false;
  }
  if (typeof value === 'string') {
    const normalized = value.trim().toLowerCase();
    return !['', 'false', '0', 'no', 'none', 'null', 'undefined'].includes(normalized);
  }
  if (typeof value === 'number') {
    return Number.isFinite(value) && value !== 0;
  }
  if (Array.isArray(value)) {
    return value.length > 0;
  }
  if (typeof value === 'object') {
    return Object.keys(value).length > 0;
  }
  return Boolean(value);
}

export function fileType(filePath) {
  const extension = path.extname(filePath).toLowerCase();
  if (extension === '.html' || extension === '.htm') return 'text/html';
  if (extension === '.css') return 'text/css';
  if (extension === '.js' || extension === '.mjs') return 'application/javascript';
  if (extension === '.svg') return 'image/svg+xml';
  if (extension === '.png') return 'image/png';
  if (extension === '.jpg' || extension === '.jpeg') return 'image/jpeg';
  if (extension === '.webp') return 'image/webp';
  return 'application/octet-stream';
}

export function isImagePath(filePath) {
  return /\.(png|jpe?g|gif|webp|svg)$/i.test(filePath);
}

export function isTextPayloadType(type) {
  return typeof type === 'string' && (type.startsWith('text/') || type === 'application/javascript' || type === 'application/json' || type === 'image/svg+xml');
}

export function normalizeArray(value) {
  if (Array.isArray(value)) return value;
  if (value === undefined || value === null || value === '') return [];
  return [value];
}

export function mergeObjects(values) {
  return values.reduce((merged, value) => deepMerge(merged, value && typeof value === 'object' && !Array.isArray(value) ? value : {}), {});
}

export function deepMerge(left, right) {
  const output = { ...left };
  for (const [key, value] of Object.entries(right)) {
    if (Array.isArray(value)) {
      output[key] = [...normalizeArray(output[key]), ...value];
    } else if (value && typeof value === 'object' && !Array.isArray(value) && output[key] && typeof output[key] === 'object' && !Array.isArray(output[key])) {
      output[key] = deepMerge(output[key], value);
    } else if (value !== undefined && value !== null && value !== '') {
      output[key] = value;
    }
  }
  return output;
}

export function compactObject(value) {
  return Object.fromEntries(Object.entries(value || {}).filter(([, item]) => item !== undefined && item !== null && item !== ''));
}

export function objectValue(value) {
  return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
}

export function numberValue(value) {
  const number = Number(value);
  return Number.isFinite(number) ? number : 0;
}

export function firstNumber(values) {
  for (const value of values) {
    if (value === undefined || value === null || value === '') {
      continue;
    }
    const number = Number(value);
    if (Number.isFinite(number)) {
      return number;
    }
  }
  return NaN;
}

export function firstString(values) {
  return values.map((value) => String(value || '').trim()).find(Boolean) || '';
}

export function diagnosticMessage(value) {
  if (typeof value === 'string') {
    return value;
  }
  return value?.message || value?.reason || value?.detail || value?.path || value?.target || value?.selector || '';
}

export function readJsonFileIfExists(filePath) {
  if (!filePath || !fs.existsSync(filePath) || !fs.statSync(filePath).isFile()) {
    return null;
  }
  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch (error) {
    return {
      status: 'failed',
      error: `Unable to parse JSON artifact ${filePath}: ${error.message}`,
      artifact_refs: [artifactRef('unparseable-json', filePath, 'diagnostic')],
    };
  }
}

export function parseJsonPayloadsFromText(text) {
  if (typeof text !== 'string' || !text.trim()) {
    return [];
  }
  const payloads = [];
  const trimmed = text.trim();
  const candidates = new Set([trimmed, ...text.split(/\r?\n/).map((line) => line.trim())]);
  const firstObject = trimmed.indexOf('{');
  const lastObject = trimmed.lastIndexOf('}');
  if (firstObject >= 0 && lastObject > firstObject) {
    candidates.add(trimmed.slice(firstObject, lastObject + 1));
  }
  for (const candidate of candidates) {
    if (!candidate || !candidate.startsWith('{')) continue;
    try {
      const parsed = JSON.parse(candidate);
      if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
        payloads.push(parsed);
      }
    } catch {
      // WP-CLI output may mix human text and JSON; non-JSON lines are ignored.
    }
  }
  return payloads;
}

export function requiredString(value, name) {
  if (typeof value !== 'string' || value.trim() === '') {
    throw new TypeError(`${name} must be a non-empty string.`);
  }
  return value;
}

export function requiredDirectory(value, name) {
  const directory = requiredString(value, name);
  if (!fs.existsSync(directory) || !fs.statSync(directory).isDirectory()) {
    throw new Error(`${name} must be an existing directory: ${directory}`);
  }
  return path.resolve(directory);
}

export function finiteNumber(value, fallback) {
  const number = Number(value);
  return Number.isFinite(number) ? number : fallback;
}

export function writeJsonFile(filePath, payload) {
  fs.writeFileSync(filePath, `${JSON.stringify(payload, null, 2)}\n`);
}

export function artifactRef(artifact_id, filePath, kind) {
  return { schema: 'homeboy/artifact-ref/v1', artifact_id, kind, path: filePath };
}

export function slug(value) {
  return String(value || 'fixture')
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '-')
    .replace(/^-+|-+$/g, '') || 'fixture';
}

export function shellToken(value) {
  const text = String(value || '');
  return /^[A-Za-z0-9_./:@=-]+$/.test(text) ? text : `'${text.replace(/'/g, "'\\''")}'`;
}
