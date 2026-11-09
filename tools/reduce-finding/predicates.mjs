/**
 * Reproduction predicates for the finding reducer.
 *
 * A predicate answers one question for a candidate page: does it still show
 * the finding? It is either a function `(html) => boolean | Promise<boolean>`
 * or a command that receives the candidate file and exits 0 to reproduce.
 *
 * Findings are not only visual. `reportPredicate` evaluates any JSON report
 * the caller produces for a candidate (an editability report, a visual parity
 * report, a theme audit) and checks one value in it, so editor and theme
 * findings reduce the same way visual ones do.
 */

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

export const EDITABILITY_REPORT_SCHEMA_PREFIX = 'blocks-engine/php-transformer/editability-report/';
export const VISUAL_PARITY_REPORT_SCHEMA = 'blocks-engine/php-transformer/visual-parity-report/v1';

const SEVERITY_ORDER = ['none', 'info', 'warning', 'error', 'critical'];

/**
 * Normalizes a predicate argument into an async function.
 *
 * @param {Function|string|string[]} predicate
 * @returns {(html: string) => Promise<boolean>}
 */
export function resolvePredicate(predicate) {
  if (typeof predicate === 'function') {
    return async (html) => (await predicate(html)) === true;
  }
  if (typeof predicate === 'string' || Array.isArray(predicate)) {
    return commandPredicate(predicate);
  }
  throw new Error('reduce_finding_predicate_invalid: expected a function or a command');
}

/**
 * Runs `command` against a temp file holding the candidate. `{candidate}` is
 * replaced with the file path; a string runs through `sh -c`, an argv array
 * runs directly. Exit status 0 means the candidate reproduces.
 */
export function commandPredicate(command, { timeoutMs } = {}) {
  return async (html) => withCandidateFile(html, (file) => {
    const result = runCommand(command, { candidate: file }, { stdio: 'ignore', timeout: timeoutMs });
    return result.status === 0;
  });
}

/**
 * Runs `command` against the candidate and parses its stdout as JSON.
 * Useful as the `evaluate` step of `reportPredicate`.
 */
export function commandReport(command, { timeoutMs } = {}) {
  return async (html) => withCandidateFile(html, (file) => {
    const result = runCommand(command, { candidate: file }, { encoding: 'utf8', timeout: timeoutMs, maxBuffer: 64 * 1024 * 1024 });
    if (result.status !== 0) {
      return null;
    }
    try {
      return JSON.parse(result.stdout);
    } catch {
      return null;
    }
  });
}

/**
 * Builds a predicate from a report producer and a check.
 *
 * @param {object} options
 * @param {(html: string) => object|Promise<object>} options.evaluate Produces the report for a candidate.
 * @param {Function|{path: string, op: string, value: unknown}} options.check
 *   A function of the report, or a declarative comparison such as
 *   `{ path: 'metrics.raw_html_block_count', op: '>', value: 0 }`.
 */
export function reportPredicate({ evaluate, check }) {
  const matches = typeof check === 'function' ? check : (report) => compareReportValue(report, check);
  return async (html) => {
    const report = await evaluate(html);
    return report !== null && report !== undefined && matches(report) === true;
  };
}

/**
 * Compares the value at a dotted `path` in `report` with `value`.
 * Supported operators: `>`, `>=`, `<`, `<=`, `==`, `!=`, `includes`.
 */
export function compareReportValue(report, { path: valuePath, op, value }) {
  const actual = valueAtPath(report, valuePath);
  switch (op) {
    case '>': return typeof actual === 'number' && actual > value;
    case '>=': return typeof actual === 'number' && actual >= value;
    case '<': return typeof actual === 'number' && actual < value;
    case '<=': return typeof actual === 'number' && actual <= value;
    case '==': return JSON.stringify(actual) === JSON.stringify(value);
    case '!=': return actual !== undefined && JSON.stringify(actual) !== JSON.stringify(value);
    case 'includes': return (Array.isArray(actual) || typeof actual === 'string') && actual.includes(value);
    default: throw new Error(`reduce_finding_check_operator_unknown: ${op}`);
  }
}

/**
 * Parses a CLI check such as `metrics.raw_html_block_count>0` or
 * `findings.0.reason_code == "grid_track_mismatch"`.
 */
export function parseCheck(expression) {
  const match = /^\s*([\w.$-]+)\s*(>=|<=|==|!=|>|<|\bincludes\b)\s*(.+?)\s*$/.exec(expression);
  if (!match) {
    throw new Error(`reduce_finding_check_invalid: ${expression}`);
  }
  let value = match[3];
  try {
    value = JSON.parse(value);
  } catch {
    // Unquoted strings are compared as strings.
  }
  return { path: match[1], op: match[2], value };
}

export function valueAtPath(object, valuePath) {
  return valuePath.split('.').reduce((value, key) => (value === null || value === undefined ? undefined : value[key]), object);
}

/**
 * Example editability predicate: the candidate still materializes with a
 * Blocks Engine editability report metric above a bound.
 *
 * `evaluate` returns either a `blocks-engine/php-transformer/editability-report/v2`
 * report or an object carrying one under `editability_report`. Useful metrics
 * include `raw_html_block_count` (any `core/html` or `core/freeform` block),
 * `max_nesting_depth`, `empty_wrapper_count` and `wrapper_to_content_ratio`.
 *
 * @example
 * // Reduce a page that still produces a raw HTML block.
 * editabilityPredicate({ evaluate: commandReport('bin/editability-report {candidate}') });
 * // Reduce a page whose block tree nests deeper than 8.
 * editabilityPredicate({ evaluate, metric: 'max_nesting_depth', above: 8 });
 */
export function editabilityPredicate({ evaluate, metric = 'raw_html_block_count', above = 0 }) {
  return reportPredicate({
    evaluate: async (html) => editabilityReportOf(await evaluate(html)),
    check: { path: `metrics.${metric}`, op: '>', value: above },
  });
}

function editabilityReportOf(report) {
  const candidate = report?.editability_report ?? report;
  return typeof candidate?.schema === 'string' && candidate.schema.startsWith(EDITABILITY_REPORT_SCHEMA_PREFIX) ? candidate : null;
}

/**
 * Example visual predicate: the candidate's Blocks Engine visual parity
 * report still contains a finding with `reasonCode` at `minSeverity` or above.
 */
export function visualParityPredicate({ evaluate, reasonCode, minSeverity = 'warning' }) {
  const floor = SEVERITY_ORDER.indexOf(minSeverity);
  return reportPredicate({
    evaluate,
    check: (report) => report.schema === VISUAL_PARITY_REPORT_SCHEMA
      && Array.isArray(report.findings)
      && report.findings.some((finding) => finding.reason_code === reasonCode && SEVERITY_ORDER.indexOf(finding.severity) >= floor),
  });
}

async function withCandidateFile(html, run) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-reduce-'));
  const file = path.join(directory, 'candidate.html');
  try {
    fs.writeFileSync(file, html);
    return await run(file);
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
}

/**
 * Runs a shell string or argv array with `{name}` placeholders substituted.
 */
export function runCommand(command, placeholders, spawnOptions = {}) {
  const substitute = (text) => Object.entries(placeholders).reduce((result, [name, value]) => result.replaceAll(`{${name}}`, value), text);
  if (Array.isArray(command)) {
    const argv = command.map(substitute);
    return spawnSync(argv[0], argv.slice(1), spawnOptions);
  }
  return spawnSync('sh', ['-c', substitute(command)], spawnOptions);
}
