import test from 'node:test';
import assert from 'node:assert/strict';
import { wrapperWeight, repeatedStructures, styleTokens, themeCss } from '../tools/import-loop/quality.mjs';

// The flat list editor.mjs's walk records: document order, depth from 1, and a
// `parent` index. Three product cards, each a group wrapping a group around an
// image, a heading and a paragraph: nine content blocks inside seven groups.
const card = (at) => [
  { name: 'core/group', depth: 2, parent: 0 },
  { name: 'core/group', depth: 3, parent: at },
  { name: 'core/image', depth: 4, parent: at + 1 },
  { name: 'core/heading', depth: 4, parent: at + 1 },
  { name: 'core/paragraph', depth: 4, parent: at + 1 },
];
const products = [{ name: 'core/group', depth: 1, parent: -1 }, ...card(1), ...card(6), ...card(11)];

test('wrapper weight, repeated structures and literal styles are measured from real shapes', () => {
  assert.deepEqual(wrapperWeight(products), { groups: 7, leaves: 9, single_child_groups: 3, heavy: false });
  // Heavy past 1.1 groups per content block, on a page of 25 or more blocks:
  // eight products, each inside four nested groups, are 33 groups around 24 leaves.
  const wrapped = [{ name: 'core/group', depth: 1, parent: -1 }];
  for (let n = 0; n < 8; n++) {
    let parent = 0;
    for (let level = 2; level <= 5; level++) parent = wrapped.push({ name: 'core/group', depth: level, parent }) - 1;
    for (const name of ['core/image', 'core/heading', 'core/paragraph']) wrapped.push({ name, depth: 6, parent });
  }
  assert.deepEqual(wrapperWeight(wrapped), { groups: 33, leaves: 24, single_child_groups: 24, heavy: true });
  // The card is reported once, as its outermost tree, not again as the inner group.
  assert.deepEqual(repeatedStructures(products), [{ tree: 'group(group(image,heading,paragraph))', count: 3, blocks: 5 }]);
  const markup = [
    '<!-- wp:heading {"textColor":"primary","style":{"typography":{"fontSize":"2.5rem"}}} -->',
    '<!-- wp:group {"style":{"color":{"background":"#e6a756","text":"var:preset|color|base"},"elements":{"link":{"color":{"text":"#e6a756"}}},"spacing":{"padding":"1rem"}}} -->',
    '<!-- wp:paragraph {"style":{"color":{"text":"inherit"}}} /-->',
  ].join('\n');
  // A CSS-wide keyword is neither a preset nor a literal design value.
  assert.deepEqual(styleTokens(markup), { presets: 2, literal: 3, share: 0.6, values: [{ value: '#e6a756', count: 2 }, { value: '2.5rem', count: 1 }] });
});

test('theme CSS counts theme stylesheets and non-core inline styles, not theme.json or core', () => {
  const kb = 1024;
  assert.deepEqual(themeCss([
    { href: 'http://site.test/wp-content/themes/imported/assets/styles.css?ver=1', bytes: 120 * kb },
    { href: 'http://site.test/wp-content/plugins/forms/forms.css', bytes: 50 * kb },
    { href: 'http://site.test/wp-includes/css/dist/block-library/style.min.css', bytes: 90 * kb },
    { id: 'global-styles-inline-css', bytes: 40 * kb },
    { id: 'core-block-supports-inline-css', bytes: 4 * kb },
    { id: 'blocks-engine-dialog-inline-css', bytes: 6 * kb },
  ]), { kb: 126, largest: [{ source: '/wp-content/themes/imported/assets/styles.css', kb: 120 }, { source: '<style id="blocks-engine-dialog-inline-css">', kb: 6 }] });
});
