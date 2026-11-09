/**
 * HTML tree helpers for the finding reducer.
 *
 * Parsing and serialization use parse5, the spec-compliant parser jsdom is
 * built on, so attributes containing `>`, raw-text elements (`script`,
 * `style`, `textarea`, `title`), comments, void elements and `<template>`
 * content all round-trip the way a browser would see them.
 *
 * Nodes are addressed by paths: arrays of child indexes starting at the
 * document. Paths are stable for a given HTML string because parsing is
 * deterministic, so a reduction pass can collect paths from one parse and
 * apply an edit to a fresh parse of the same string.
 */

import { parse, serialize } from 'parse5';

export const RAW_TEXT_ELEMENTS = new Set(['script', 'style', 'textarea', 'title']);
export const STRUCTURAL_ELEMENTS = new Set(['html', 'head', 'body']);

/** Elements whose text content is code rather than page text. */
const CODE_TEXT_ELEMENTS = new Set(['script', 'style']);

export function parseDocument(html) {
  return parse(html);
}

export function serializeDocument(document) {
  return serialize(document);
}

/**
 * Parses `html`, lets `edit` mutate the document, and serializes the result.
 *
 * @param {string} html
 * @param {(document: object) => void} edit
 * @returns {string}
 */
export function editDocument(html, edit) {
  const document = parseDocument(html);
  edit(document);
  return serializeDocument(document);
}

export function isElement(node) {
  return typeof node.tagName === 'string';
}

export function isText(node) {
  return node.nodeName === '#text';
}

export function isComment(node) {
  return node.nodeName === '#comment';
}

/** Child nodes of `node`, reading through `<template>` content fragments. */
export function childNodesOf(node) {
  if (node.nodeName === 'template' && node.content) {
    return node.content.childNodes;
  }
  return node.childNodes || [];
}

export function nodeAtPath(document, path) {
  let node = document;
  for (const index of path) {
    node = childNodesOf(node)[index];
    if (!node) {
      throw new Error(`reduce_finding_path_missing: ${path.join('.')}`);
    }
  }
  return node;
}

/**
 * Visits every node in document order.
 *
 * @param {object} document
 * @param {(node: object, path: number[], ancestors: object[]) => void} visit
 */
export function walk(document, visit) {
  const stack = [{ node: document, path: [], ancestors: [] }];
  while (stack.length) {
    const { node, path, ancestors } = stack.pop();
    if (node !== document) {
      visit(node, path, ancestors);
    }
    const children = childNodesOf(node);
    const childAncestors = node === document ? ancestors : [...ancestors, node];
    for (let index = children.length - 1; index >= 0; index--) {
      stack.push({ node: children[index], path: [...path, index], ancestors: childAncestors });
    }
  }
}

/**
 * Groups removable nodes (elements and comments) by tree depth for
 * hierarchical delta debugging. `<html>`, `<head>` and `<body>` are passed
 * through because the parser recreates them anyway, so level 0 holds the
 * children of `<head>` and `<body>`.
 *
 * @returns {number[][][]} levels of node paths
 */
export function removableNodeLevels(document) {
  const levels = [];
  walk(document, (node, path, ancestors) => {
    if (!isElement(node) && !isComment(node)) {
      return;
    }
    if (isElement(node) && STRUCTURAL_ELEMENTS.has(node.tagName)) {
      return;
    }
    const depth = ancestors.filter((ancestor) => isElement(ancestor) && !STRUCTURAL_ELEMENTS.has(ancestor.tagName)).length;
    (levels[depth] ||= []).push(path);
  });
  return levels.map((level) => level || []);
}

/** Non-structural elements in document order. */
export function contentElements(document) {
  const elements = [];
  walk(document, (node, path) => {
    if (isElement(node) && !STRUCTURAL_ELEMENTS.has(node.tagName)) {
      elements.push({ node, path });
    }
  });
  return elements;
}

/** Elements that can be replaced by their children. */
export function unwrappableElements(document) {
  return contentElements(document).filter(({ node }) => (
    !RAW_TEXT_ELEMENTS.has(node.tagName) && childNodesOf(node).length > 0
  ));
}

/** Every `(element, attribute)` pair in document order. */
export function attributeTargets(document) {
  const targets = [];
  walk(document, (node, path) => {
    if (!isElement(node)) {
      return;
    }
    for (const attribute of node.attrs) {
      targets.push({ path, name: attribute.name });
    }
  });
  return targets;
}

/** Text nodes holding page text, excluding script and style code. */
export function pageTextNodes(document) {
  const nodes = [];
  walk(document, (node, path, ancestors) => {
    const parent = ancestors.at(-1);
    if (isText(node) && !(parent && CODE_TEXT_ELEMENTS.has(parent.tagName))) {
      nodes.push({ node, path });
    }
  });
  return nodes;
}

/** `<style>` elements in document order. */
export function styleElements(document) {
  const styles = [];
  walk(document, (node) => {
    if (isElement(node) && node.tagName === 'style') {
      styles.push(node);
    }
  });
  return styles;
}

export function textContent(node) {
  return childNodesOf(node).map((child) => (isText(child) ? child.value : '')).join('');
}

export function setTextContent(element, value) {
  element.childNodes = value === '' ? [] : [{ nodeName: '#text', value, parentNode: element }];
}

function siblingsOf(node) {
  return node.parentNode.childNodes;
}

/** Removes every node at `paths`; all paths refer to the unedited document. */
export function removeNodes(document, paths) {
  const nodes = paths.map((path) => nodeAtPath(document, path));
  for (const node of nodes) {
    const siblings = siblingsOf(node);
    siblings.splice(siblings.indexOf(node), 1);
  }
}

/** Replaces the element at `path` with its child nodes. */
export function unwrapElement(document, path) {
  const element = nodeAtPath(document, path);
  const siblings = siblingsOf(element);
  const children = childNodesOf(element);
  for (const child of children) {
    child.parentNode = element.parentNode;
  }
  siblings.splice(siblings.indexOf(element), 1, ...children);
}

export function removeAttribute(document, path, name) {
  const element = nodeAtPath(document, path);
  element.attrs = element.attrs.filter((attribute) => attribute.name !== name);
}

export function setTextValue(document, path, value) {
  const node = nodeAtPath(document, path);
  if (value === '') {
    removeNodes(document, [path]);
  } else {
    node.value = value;
  }
}
