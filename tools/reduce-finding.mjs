#!/usr/bin/env node

/**
 * Reduces a URL-loop finding to a minimal standalone page.
 *
 * The reducer runs hierarchical delta debugging over a page: it removes
 * element subtrees level by level, unwraps elements, drops attributes,
 * shortens text, then removes CSS rules (including whole at-rule blocks and
 * rules nested in them) and declarations. A change is kept only when the
 * caller's predicate still reproduces the finding. Passes repeat until a
 * full round changes nothing or the call budget or deadline runs out.
 */

/**
 * External dependencies
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Internal dependencies
 */
import { isRuleLike, parseStylesheet, removeStylesheetItems, serializeStylesheet, walkStylesheet } from './reduce-finding/css.mjs';
import { ddmin } from './reduce-finding/ddmin.mjs';
import { materializationFixture, SOURCE_HTML_PATH, TARGET_HTML_PATH } from './reduce-finding/fixture.mjs';
import {
  attributeTargets,
  contentElements,
  editDocument,
  pageTextNodes,
  parseDocument,
  removableNodeLevels,
  removeAttribute,
  removeNodes,
  serializeDocument,
  setTextContent,
  setTextValue,
  styleElements,
  textContent,
  unwrapElement,
  unwrappableElements,
} from './reduce-finding/html.mjs';
import { commandReport, parseCheck, reportPredicate, resolvePredicate, runCommand } from './reduce-finding/predicates.mjs';
import { failureSignature } from './reduce-finding/signature.mjs';

export { failureSignature } from './reduce-finding/signature.mjs';
export { materializationFixture } from './reduce-finding/fixture.mjs';
export {
  commandPredicate,
  commandReport,
  compareReportValue,
  editabilityPredicate,
  reportPredicate,
  visualParityPredicate,
} from './reduce-finding/predicates.mjs';

export const REDUCTION_SCHEMA = 'static-site-importer/finding-reduction/v1';
export const DEFAULT_MAX_CALLS = 1000;
export const DEFAULT_MAX_ROUNDS = 8;

const PASSES = [
  reduceElementLevels,
  unwrapElements,
  dropAttributes,
  shortenText,
  reduceCssRules,
  reduceCssDeclarations,
];

/**
 * @param {string} input HTML, or a path to an HTML file.
 * @param {Function|string|string[]} predicate Function of the candidate HTML,
 *   or a command with a `{candidate}` placeholder that exits 0 to reproduce.
 * @param {object} [options]
 * @param {number} [options.maxCalls] Predicate call budget.
 * @param {number} [options.deadline] Epoch milliseconds after which no call starts.
 * @param {number} [options.timeoutMs] Relative alternative to `deadline`.
 * @param {number} [options.maxRounds] Upper bound on fixpoint rounds.
 * @param {() => number} [options.now] Clock, for tests.
 */
export async function reduceFinding(input, predicate, options = {}) {
  const original = readInput(input);
  const session = createSession(resolvePredicate(predicate), options);
  const normalized = normalizePage(original);
  const result = (status, html, rounds) => ({
    schema: REDUCTION_SCHEMA,
    status,
    stopped: stopReason(session, status, rounds),
    html,
    rounds,
    predicate_calls: session.calls,
    counts_before: pageCounts(original),
    counts_after: pageCounts(html),
  });

  if (!(await session.test(normalized))) {
    return result(session.stopped ? 'incomplete' : 'not_reproducible', original, 0);
  }

  let html = normalized;
  let rounds = 0;
  while (!session.stopped && rounds < session.maxRounds) {
    rounds++;
    const before = html;
    for (const pass of PASSES) {
      html = await pass(html, session);
      if (session.stopped) {
        break;
      }
    }
    if (html === before) {
      break;
    }
  }
  return result('reduced', html, rounds);
}

function stopReason(session, status, rounds) {
  if (session.stopped) {
    return session.stopped;
  }
  if (status !== 'reduced') {
    return null;
  }
  return rounds >= session.maxRounds ? 'max_rounds' : 'fixpoint';
}

/** Accepts HTML (anything containing `<`) or a path to an HTML file. */
function readInput(input) {
  if (typeof input !== 'string') {
    throw new Error('reduce_finding_input_invalid: expected HTML or a file path');
  }
  return input.includes('<') ? input : fs.readFileSync(input, 'utf8');
}

/**
 * Wraps the predicate with a result cache and the call budget. Once the
 * budget or deadline is spent, every test returns false so passes unwind.
 */
function createSession(predicate, options) {
  const now = options.now || Date.now;
  const deadline = options.deadline ?? (options.timeoutMs === undefined ? Infinity : now() + options.timeoutMs);
  const maxCalls = options.maxCalls ?? DEFAULT_MAX_CALLS;
  const cache = new Map();
  const session = {
    calls: 0,
    stopped: null,
    maxRounds: options.maxRounds ?? DEFAULT_MAX_ROUNDS,
    shouldStop: () => session.stopped !== null,
    async test(html) {
      if (cache.has(html)) {
        return cache.get(html);
      }
      if (session.stopped) {
        return false;
      }
      if (session.calls >= maxCalls) {
        session.stopped = 'budget';
        return false;
      }
      if (now() >= deadline) {
        session.stopped = 'deadline';
        return false;
      }
      session.calls++;
      const reproduces = await predicate(html);
      cache.set(html, reproduces);
      return reproduces;
    },
  };
  return session;
}

/** Re-serializes the page through parse5 and the CSS serializer. */
export function normalizePage(html) {
  return editStylesheets(serializeDocument(parseDocument(html)), () => {});
}

/**
 * Parses each `<style>` element, lets `edit` mutate the sheets, and writes
 * them back.
 */
function editStylesheets(html, edit) {
  return editDocument(html, (document) => {
    const styles = styleElements(document);
    const sheets = styles.map((style) => parseStylesheet(textContent(style)));
    edit(sheets);
    styles.forEach((style, index) => setTextContent(style, serializeStylesheet(sheets[index])));
  });
}

function readStylesheets(html) {
  return styleElements(parseDocument(html)).map((style) => parseStylesheet(textContent(style)));
}

/**
 * Runs ddmin over each level of a tree in turn. `levelsOf(html)` returns the
 * levels for the current page and `removeAt(html, paths)` returns the page
 * without the nodes at `paths`.
 */
async function reduceLevels(html, session, levelsOf, removeAt) {
  let current = html;
  for (let depth = 0; !session.stopped; depth++) {
    const levels = levelsOf(current);
    if (depth >= levels.length) {
      break;
    }
    const paths = levels[depth];
    const without = (kept) => removeAt(current, paths.filter((_, index) => !kept.includes(index)));
    const kept = await ddmin(paths.map((_, index) => index), (subset) => session.test(without(subset)), session);
    if (kept.length < paths.length) {
      current = without(kept);
    }
  }
  return current;
}

/** Hierarchical delta debugging over element and comment nodes. */
function reduceElementLevels(html, session) {
  return reduceLevels(
    html,
    session,
    (current) => removableNodeLevels(parseDocument(current)),
    (current, paths) => editDocument(current, (document) => removeNodes(document, paths)),
  );
}

/** Rules and at-rule blocks, level by level across every `<style>`. */
function reduceCssRules(html, session) {
  return reduceLevels(
    html,
    session,
    (current) => {
      const levels = [];
      readStylesheets(current).forEach((sheet, sheetIndex) => walkStylesheet(sheet, (item, itemPath, depth) => {
        if (isRuleLike(item)) {
          (levels[depth] ||= []).push([sheetIndex, ...itemPath]);
        }
      }));
      return levels.map((level) => level || []);
    },
    removeCssItems,
  );
}

/** Declarations across every rule as one ddmin set. */
function reduceCssDeclarations(html, session) {
  return reduceLevels(
    html,
    session,
    (current) => {
      const declarations = [];
      readStylesheets(current).forEach((sheet, sheetIndex) => walkStylesheet(sheet, (item, itemPath) => {
        if (item.type === 'declaration') {
          declarations.push([sheetIndex, ...itemPath]);
        }
      }));
      return [declarations];
    },
    removeCssItems,
  );
}

function removeCssItems(html, paths) {
  return editStylesheets(html, (sheets) => {
    sheets.forEach((sheet, sheetIndex) => {
      const sheetPaths = paths.filter((itemPath) => itemPath[0] === sheetIndex).map((itemPath) => itemPath.slice(1));
      removeStylesheetItems(sheet, sheetPaths);
    });
  });
}

/**
 * Tries one edit per target in document order. `targetsOf(html)` lists the
 * targets for the current page and `candidatesFor(html, target)` yields the
 * edited pages to try, best first. After an accepted edit the same index is
 * retried against the new page, since earlier targets are unchanged.
 */
async function reduceTargets(html, session, targetsOf, candidatesFor) {
  let current = html;
  let index = 0;
  while (!session.stopped) {
    const targets = targetsOf(current);
    if (index >= targets.length) {
      break;
    }
    let accepted = false;
    for (const candidate of candidatesFor(current, targets[index])) {
      if (candidate !== current && await session.test(candidate)) {
        current = candidate;
        accepted = true;
        break;
      }
    }
    if (!accepted) {
      index++;
    }
  }
  return current;
}

/** Replaces an element with its children. */
function unwrapElements(html, session) {
  return reduceTargets(
    html,
    session,
    (current) => unwrappableElements(parseDocument(current)),
    (current, { path: elementPath }) => [editDocument(current, (document) => unwrapElement(document, elementPath))],
  );
}

/** Drops attributes one at a time. */
function dropAttributes(html, session) {
  return reduceTargets(
    html,
    session,
    (current) => attributeTargets(parseDocument(current)),
    (current, { path: elementPath, name }) => [editDocument(current, (document) => removeAttribute(document, elementPath, name))],
  );
}

/** Removes a text node, or keeps its first half. */
function shortenText(html, session) {
  return reduceTargets(
    html,
    session,
    (current) => pageTextNodes(parseDocument(current)),
    (current, { node, path: textPath }) => {
      const values = [''];
      if (node.value.length > 1) {
        values.push(node.value.slice(0, Math.ceil(node.value.length / 2)));
      }
      return values.map((value) => editDocument(current, (document) => setTextValue(document, textPath, value)));
    },
  );
}

/** Element, CSS rule and declaration counts for a page. */
export function pageCounts(html) {
  const document = parseDocument(html);
  const counts = { elements: contentElements(document).length, css_rules: 0, css_declarations: 0, bytes: Buffer.byteLength(html) };
  for (const style of styleElements(document)) {
    walkStylesheet(parseStylesheet(textContent(style)), (item) => {
      if (item.type === 'declaration') {
        counts.css_declarations++;
      } else {
        counts.css_rules++;
      }
    });
  }
  return counts;
}

/**
 * Reduces `options.input` and writes the case to `options.output`:
 * `source.html`, `fixture.json`, `reduction.json` and, with a
 * target command, `target.html`.
 */
export async function runCli(options) {
  if (!options.input || !options.output || (!options.predicate && !options['report-command'])) {
    throw new Error(usage());
  }
  const predicate = options['report-command']
    ? reportPredicate({ evaluate: commandReport(options['report-command']), check: parseCheck(requireOption(options, 'check')) })
    : options.predicate;
  const reduction = await reduceFinding(options.input, predicate, {
    maxCalls: options['max-calls'] === undefined ? undefined : Number(options['max-calls']),
    timeoutMs: options['timeout-ms'] === undefined ? undefined : Number(options['timeout-ms']),
  });
  if (reduction.status !== 'reduced') {
    throw new Error(`reduce_finding_${reduction.status}: the predicate does not reproduce on the normalized input`);
  }

  const signature = failureSignature(reduction.html, {
    stage: options.stage,
    check: options['finding-check'] || options.check,
    block_names: options.blocks ? options.blocks.split(',') : [],
  });
  fs.mkdirSync(options.output, { recursive: true });
  const sourceFile = path.join(options.output, SOURCE_HTML_PATH);
  fs.writeFileSync(sourceFile, reduction.html);
  const targetProvided = Boolean(options['target-command']) && writeTarget(options['target-command'], sourceFile, path.join(options.output, TARGET_HTML_PATH));
  const fixture = materializationFixture({ name: options.name || `reduced-${signature.sha256.slice(0, 12)}`, signature, targetProvided });
  writeJson(path.join(options.output, 'fixture.json'), fixture);
  const { html, ...summary } = reduction;
  writeJson(path.join(options.output, 'reduction.json'), { ...summary, signature });
  return { ...summary, signature };
}

function writeTarget(command, sourceFile, targetFile) {
  const result = runCommand(command, { source: sourceFile }, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  if (result.status !== 0) {
    throw new Error(`reduce_finding_target_command_failed: exit ${result.status}`);
  }
  fs.writeFileSync(targetFile, result.stdout);
  return true;
}

function writeJson(file, value) {
  fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`);
}

function requireOption(options, name) {
  if (!options[name]) {
    throw new Error(`reduce_finding_option_missing: --${name}\n${usage()}`);
  }
  return options[name];
}

function usage() {
  return [
    'usage: node tools/reduce-finding.mjs --input <page.html> --output <dir>',
    '  (--predicate \'<command {candidate}>\' | --report-command \'<command {candidate}>\' --check \'<path> <op> <value>\')',
    '  [--stage capture|materialization|editability|theme] [--finding-check <code>] [--blocks a,b]',
    '  [--target-command \'<command {source}>\'] [--max-calls N] [--timeout-ms N] [--name <fixture name>]',
  ].join('\n');
}

export function parseArgs(argv) {
  const options = {};
  for (let index = 0; index < argv.length; index++) {
    if (!argv[index].startsWith('--')) {
      throw new Error(`reduce_finding_argument_unexpected: ${argv[index]}`);
    }
    options[argv[index].slice(2)] = argv[index + 1];
    index++;
  }
  return options;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  runCli(parseArgs(process.argv.slice(2)))
    .then((summary) => process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`))
    .catch((error) => {
      process.stderr.write(`${error.message}\n`);
      process.exitCode = 1;
    });
}
