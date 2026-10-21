import test from 'node:test';
import assert from 'node:assert/strict';

import {
  attributeTargets,
  contentElements,
  editDocument,
  nodeAtPath,
  pageTextNodes,
  parseDocument,
  removableNodeLevels,
  serializeDocument,
  textContent,
  unwrapElement,
} from './html.mjs';

const RAW_TEXT_PAGE = [
  '<!DOCTYPE html><html><head>',
  '<title>a <b> & c</title>',
  '<style>a > b::after { content: "</p>"; }</style>',
  '<script>if (a < b && c > d) { document.write("</div><p>"); }</script>',
  '</head><body>',
  '<!-- a comment with <tags> -->',
  '<p data-rule="a > b" title=\'x<y\'>one<br>two<img src="i.png" alt="&quot;q&quot;"></p>',
  '<textarea name="t"><b>not bold</b></textarea>',
  '<template><span>inside template</span></template>',
  '</body></html>',
].join('');

function summary(document) {
  return contentElements(document).map(({ node }) => ({
    tag: node.tagName,
    attrs: node.attrs.map(({ name, value }) => `${name}=${value}`),
    text: ['script', 'style', 'textarea', 'title'].includes(node.tagName) ? textContent(node) : undefined,
  }));
}

test('raw-text elements, comments, void elements and attributes with > round trip', () => {
  const first = parseDocument(RAW_TEXT_PAGE);
  const serialized = serializeDocument(first);
  const second = parseDocument(serialized);
  assert.deepEqual(summary(second), summary(first));
  assert.equal(serializeDocument(second), serialized, 'serialization is idempotent');

  const elements = summary(first);
  assert.equal(elements.find((element) => element.tag === 'script').text, 'if (a < b && c > d) { document.write("</div><p>"); }');
  assert.equal(elements.find((element) => element.tag === 'style').text, 'a > b::after { content: "</p>"; }');
  assert.equal(elements.find((element) => element.tag === 'textarea').text, '<b>not bold</b>');
  assert.equal(elements.find((element) => element.tag === 'title').text, 'a <b> & c');
  assert.deepEqual(elements.find((element) => element.tag === 'p').attrs, ['data-rule=a > b', 'title=x<y']);
  assert.ok(elements.some((element) => element.tag === 'br'));
  assert.ok(elements.some((element) => element.tag === 'span'), 'template content is walked');
  assert.match(serialized, /<!-- a comment with <tags> -->/);
  assert.match(serialized, /<script>if \(a < b && c > d\) \{ document\.write\("<\/div><p>"\); \}<\/script>/);
});

test('removable nodes are grouped by depth below head and body', () => {
  const document = parseDocument('<head><style>x{}</style></head><body><!-- c --><main><section><p>t</p></section></main><footer></footer></body>');
  const levels = removableNodeLevels(document).map((level) => level.map((path) => {
    const node = nodeAtPath(document, path);
    return node.tagName || node.nodeName;
  }));
  assert.deepEqual(levels, [['style', '#comment', 'main', 'footer'], ['section'], ['p']]);
});

test('unwrap replaces an element with its children', () => {
  const html = '<body><div class="wrap"><p>a</p>text</div></body>';
  const [wrapper] = contentElements(parseDocument(html));
  const unwrapped = editDocument(html, (document) => unwrapElement(document, wrapper.path));
  assert.equal(unwrapped, '<html><head></head><body><p>a</p>text</body></html>');
});

test('page text excludes script and style code but keeps textarea and title', () => {
  const values = pageTextNodes(parseDocument(RAW_TEXT_PAGE)).map(({ node }) => node.value);
  assert.ok(values.includes('a <b> & c'));
  assert.ok(values.includes('<b>not bold</b>'));
  assert.ok(!values.some((value) => value.includes('document.write')));
  assert.ok(!values.some((value) => value.includes('content:')));
});

test('attribute targets list every attribute in document order', () => {
  const targets = attributeTargets(parseDocument('<html lang="en"><body><a href="/" class="x">a</a></body></html>'));
  assert.deepEqual(targets.map(({ name }) => name), ['lang', 'href', 'class']);
});
