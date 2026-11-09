/**
 * Stable failure signatures for reduced findings.
 *
 * A signature names the owning stage, the failing check and the blocks or
 * recognizer involved, plus a digest of the features left in the reduced
 * page. Features are structural only: element tags, CSS property names,
 * at-rule kinds, selector features and value functions. Class names, ids,
 * custom property names, text and literal values are ignored, so the same
 * defect found on two sites produces the same signature.
 */

import crypto from 'node:crypto';
import { parseStylesheet, walkStylesheet } from './css.mjs';
import { contentElements, parseDocument, styleElements, textContent } from './html.mjs';

export const FINDING_SIGNATURE_SCHEMA = 'static-site-importer/finding-signature/v1';
export const FINDING_STAGES = ['capture', 'materialization', 'editability', 'theme'];

const SELECTOR_FEATURES = [
  ['child', />/],
  ['adjacent', /\+/],
  ['sibling', /~(?!=)/],
  ['descendant', /[\w\])*-]\s+[\w.#[*:]/],
  ['attribute', /\[/],
  ['universal', /(^|[\s>+~(,])\*/],
];
const VALUE_FUNCTION = /([a-z-]+)\(/gi;
const PSEUDO = /(::?)([a-z-]+)/gi;

/**
 * @param {string} html Reduced page.
 * @param {object} [finding] `stage`, `check` (or `code`, `diagnostic_code`,
 *   `metric`), `block_names` and `recognizer`.
 */
export function failureSignature(html, finding = {}) {
  const fields = {
    schema: FINDING_SIGNATURE_SCHEMA,
    stage: FINDING_STAGES.includes(finding.stage) ? finding.stage : 'materialization',
    check: String(finding.check || finding.code || finding.diagnostic_code || finding.metric || 'unknown'),
    blocks: sortedUnique([...(finding.block_names || []), ...(finding.recognizer ? [finding.recognizer] : [])]),
    features: pageFeatures(html),
  };
  return { ...fields, sha256: crypto.createHash('sha256').update(JSON.stringify(fields)).digest('hex') };
}

/** Structural features of a page, each a sorted list of names. */
export function pageFeatures(html) {
  const document = parseDocument(html);
  const features = {
    element_tags: contentElements(document).map(({ node }) => node.tagName),
    css_properties: [],
    at_rule_kinds: [],
    selector_features: [],
    value_functions: [],
  };
  for (const style of styleElements(document)) {
    walkStylesheet(parseStylesheet(textContent(style)), (item) => collectItemFeatures(item, features));
  }
  return Object.fromEntries(Object.entries(features).map(([key, values]) => [key, sortedUnique(values)]));
}

function collectItemFeatures(item, features) {
  if (item.type === 'declaration') {
    features.css_properties.push(normalizeProperty(item.property));
    features.value_functions.push(...functionNames(item.text.slice(item.property.length + 1)));
    return;
  }
  if (item.type === 'at-rule') {
    features.at_rule_kinds.push(item.name);
    return;
  }
  features.selector_features.push(...selectorFeatures(item.selector));
}

function normalizeProperty(property) {
  return property.startsWith('--') ? '--*' : property.toLowerCase();
}

function functionNames(value) {
  return [...value.matchAll(VALUE_FUNCTION)].map((match) => match[1].toLowerCase());
}

function selectorFeatures(selector) {
  const withoutStrings = selector.replace(/"[^"]*"|'[^']*'/g, '""');
  const found = SELECTOR_FEATURES.filter(([, pattern]) => pattern.test(withoutStrings)).map(([name]) => name);
  for (const [, colons, name] of withoutStrings.matchAll(PSEUDO)) {
    found.push(`${colons}${name.toLowerCase()}`);
  }
  return found;
}

function sortedUnique(values) {
  return [...new Set(values.filter(Boolean))].sort();
}
