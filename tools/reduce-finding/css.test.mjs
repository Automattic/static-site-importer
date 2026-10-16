import test from 'node:test';
import assert from 'node:assert/strict';

import { parseStylesheet, removeStylesheetItems, serializeStylesheet, walkStylesheet } from './css.mjs';

function shape(sheet) {
  const items = [];
  walkStylesheet(sheet, (item, path, depth) => {
    items.push(`${depth}:${item.type}:${item.selector ?? item.name ?? item.property}`);
  });
  return items;
}

test('nested at-rules keep their rules as children', () => {
  const sheet = parseStylesheet(`
    .a { color: red }
    @media (min-width: 600px) {
      .b { color: blue; }
      @supports (display: grid) { .c { display: grid } }
    }
    @layer base { .d { margin: 0 } }
    @layer reset, base;
  `);
  assert.deepEqual(shape(sheet), [
    '0:rule:.a',
    '1:declaration:color',
    '0:at-rule:media',
    '1:rule:.b',
    '2:declaration:color',
    '1:at-rule:supports',
    '2:rule:.c',
    '3:declaration:display',
    '0:at-rule:layer',
    '1:rule:.d',
    '2:declaration:margin',
    '0:at-rule:layer',
  ]);
  assert.equal(sheet.items[3].items, null, '@layer statement has no block');
});

test('strings, comments, escapes and parentheses do not end items', () => {
  const sheet = parseStylesheet([
    '/* { comment } */',
    '.a::before { content: "}{;"; background: url(data:image/png;base64,AAA=) }',
    '[data-x="{"] { color: red /* ; */ }',
    '.b\\:hover { color: blue }',
  ].join('\n'));
  assert.deepEqual(sheet.items.map((item) => item.selector), ['.a::before', '[data-x="{"]', '.b\\:hover']);
  assert.deepEqual(sheet.items[0].items.map((item) => item.text), [
    'content: "}{;"',
    'background: url(data:image/png;base64,AAA=)',
  ]);
  assert.deepEqual(sheet.items[1].items.map((item) => item.text), ['color: red']);
});

test('at-rules with declaration blocks and statements round trip', () => {
  const source = [
    '@import url("a.css") screen;',
    '@font-face { font-family: X; src: url(x.woff2) }',
    '@keyframes spin { from { rotate: 0deg } to { rotate: 1turn } }',
    '.card { color: red; &:hover { color: blue } }',
  ].join('\n');
  const serialized = serializeStylesheet(parseStylesheet(source));
  assert.equal(serialized, [
    '@import url("a.css") screen;',
    '@font-face { font-family: X; src: url(x.woff2); }',
    '@keyframes spin { from { rotate: 0deg; } to { rotate: 1turn; } }',
    '.card { color: red; &:hover { color: blue; } }',
  ].join('\n'));
  assert.equal(serializeStylesheet(parseStylesheet(serialized)), serialized, 'serialization is idempotent');
});

test('removing items by path addresses the unedited sheet', () => {
  const sheet = parseStylesheet('.a { color: red } @media print { .b { color: blue } .c { color: green } }');
  removeStylesheetItems(sheet, [[0], [1, 0]]);
  assert.equal(serializeStylesheet(sheet), '@media print { .c { color: green; } }');
});

test('unterminated input does not throw', () => {
  assert.equal(serializeStylesheet(parseStylesheet('.a { color: "red')), '.a { color: "red; }');
  assert.equal(serializeStylesheet(parseStylesheet('}} .a { color: red')), '.a { color: red; }');
});
