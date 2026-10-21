/**
 * Block-aware CSS parser for the finding reducer.
 *
 * The parser splits a stylesheet into a tree of items without interpreting
 * selectors or values:
 *
 * - `rule`: a qualified rule, `selector { items }`.
 * - `at-rule`: `@name prelude { items }`, or a statement such as
 *   `@import url(x);` when `items` is null.
 * - `declaration`: `property: value`.
 *
 * Blocks can contain both declarations and nested rules, so `@media`,
 * `@supports`, `@layer`, `@container`, `@keyframes`, `@font-face` and CSS
 * nesting share one shape. The scanner respects strings, escapes, comments
 * and parentheses, so `;`, `{` or `}` inside `url(...)`, `content: "}"` or
 * `[data-x="{"]` do not end an item. Comments are dropped and whitespace
 * outside strings is collapsed; serialization is otherwise lossless.
 */

/**
 * @param {string} source
 * @returns {{ type: 'stylesheet', items: object[] }}
 */
export function parseStylesheet(source) {
  return { type: 'stylesheet', items: parseItems(source, 0, false).items };
}

export function serializeStylesheet(sheet) {
  return sheet.items.map(serializeItem).join('\n');
}

export function serializeItem(item) {
  if (item.type === 'declaration') {
    return `${item.text};`;
  }
  const head = item.type === 'rule' ? item.selector : `@${item.name}${item.prelude ? ` ${item.prelude}` : ''}`;
  if (item.items === null) {
    return `${head};`;
  }
  const body = item.items.map(serializeItem).join(' ');
  return body ? `${head} { ${body} }` : `${head} {}`;
}

export function isRuleLike(item) {
  return item.type === 'rule' || item.type === 'at-rule';
}

function parseItems(source, start, nested) {
  const items = [];
  let index = start;
  while (index < source.length) {
    index = skipWhitespaceAndComments(source, index);
    if (index >= source.length) {
      break;
    }
    if (source[index] === '}') {
      if (nested) {
        return { items, end: index + 1 };
      }
      index++;
      continue;
    }
    const { text, end, stop } = readPrelude(source, index);
    if (stop === '{') {
      const block = parseItems(source, end + 1, true);
      items.push(blockItem(text, block.items));
      index = block.end;
      continue;
    }
    if (text) {
      items.push(statementItem(text));
    }
    index = stop === ';' ? end + 1 : end;
  }
  return { items, end: index };
}

/**
 * Reads until a top-level `;`, `{` or `}` and returns the collapsed text.
 * `stop` is the character that ended the read, or '' at end of input.
 */
function readPrelude(source, start) {
  let text = '';
  let depth = 0;
  let index = start;
  while (index < source.length) {
    const char = source[index];
    if (char === '/' && source[index + 1] === '*') {
      index = skipComment(source, index);
      text = appendSpace(text);
      continue;
    }
    if (char === '"' || char === "'") {
      const end = skipString(source, index);
      text += source.slice(index, end);
      index = end;
      continue;
    }
    if (char === '\\') {
      text += source.slice(index, index + 2);
      index += 2;
      continue;
    }
    if (char === '(' || char === '[') {
      depth++;
    } else if ((char === ')' || char === ']') && depth > 0) {
      depth--;
    } else if (depth === 0 && (char === ';' || char === '{' || char === '}')) {
      return { text: text.trim(), end: index, stop: char };
    }
    text = /\s/.test(char) ? appendSpace(text) : text + char;
    index++;
  }
  return { text: text.trim(), end: index, stop: '' };
}

function appendSpace(text) {
  return text === '' || text.endsWith(' ') ? text : `${text} `;
}

function skipWhitespaceAndComments(source, start) {
  let index = start;
  while (index < source.length) {
    if (/\s/.test(source[index])) {
      index++;
    } else if (source[index] === '/' && source[index + 1] === '*') {
      index = skipComment(source, index);
    } else {
      break;
    }
  }
  return index;
}

function skipComment(source, start) {
  const end = source.indexOf('*/', start + 2);
  return end === -1 ? source.length : end + 2;
}

function skipString(source, start) {
  const quote = source[start];
  let index = start + 1;
  while (index < source.length) {
    if (source[index] === '\\') {
      index += 2;
      continue;
    }
    if (source[index] === quote || source[index] === '\n') {
      return index + 1;
    }
    index++;
  }
  return index;
}

function splitAtRule(text) {
  const match = /^@([\w-]+)\s*([\s\S]*)$/.exec(text);
  return match ? { name: match[1].toLowerCase(), prelude: match[2].trim() } : { name: '', prelude: text.slice(1) };
}

function blockItem(prelude, items) {
  if (prelude.startsWith('@')) {
    return { type: 'at-rule', ...splitAtRule(prelude), items };
  }
  return { type: 'rule', selector: prelude, items };
}

function statementItem(text) {
  if (text.startsWith('@')) {
    return { type: 'at-rule', ...splitAtRule(text), items: null };
  }
  const colon = text.indexOf(':');
  if (colon === -1) {
    return { type: 'declaration', property: '', text };
  }
  const property = text.slice(0, colon).trim();
  return { type: 'declaration', property, text: `${property}: ${text.slice(colon + 1).trim()}` };
}

/**
 * Visits every item with its path (indexes into nested `items` arrays) and
 * its depth in rule-like ancestors.
 */
export function walkStylesheet(sheet, visit) {
  const visitItems = (items, path, depth) => {
    items.forEach((item, index) => {
      const itemPath = [...path, index];
      visit(item, itemPath, depth);
      if (item.items) {
        visitItems(item.items, itemPath, depth + 1);
      }
    });
  };
  visitItems(sheet.items, [], 0);
}

/**
 * Removes the items at `paths` (all relative to the unedited sheet).
 */
export function removeStylesheetItems(sheet, paths) {
  const targets = paths.map((path) => {
    let parent = sheet;
    for (const index of path.slice(0, -1)) {
      parent = parent.items[index];
    }
    return { parent, item: parent.items[path.at(-1)] };
  });
  for (const { parent, item } of targets) {
    parent.items.splice(parent.items.indexOf(item), 1);
  }
}
