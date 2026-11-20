import test from 'node:test';
import assert from 'node:assert/strict';
import { shellFindings } from '../tools/import-loop/theme.mjs';

const part = (slug, area) => ({ slug, area });
const ref = (slug, area) => `<!-- wp:template-part {"slug":"${slug}","area":"${area}","tagName":"div"} /-->`;
const template = (slug, ...refs) => ({ slug, content: { raw: refs.join('<!-- wp:post-content /-->') } });
const page = (raw) => ({ content: { raw } });

test('a route variant part per area passes when every template references one part of each area', () => {
  // A landing page with its own header and footer beside legal pages sharing theirs.
  const parts = [part('header', 'header'), part('footer', 'footer'), part('header-913785255e1d', 'header'), part('footer-913785255e1d', 'footer')];
  const templates = [
    template('front-page', ref('header-913785255e1d', 'header'), ref('footer-913785255e1d', 'footer')),
    template('page', ref('header', 'header'), ref('footer', 'footer')),
    template('index', ref('header', 'header'), ref('footer', 'footer')),
  ];
  assert.deepEqual(shellFindings(templates, parts, [page('<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->')]), []);
});

test('a template without a part of an area, an unreferenced part, or a page shell copy fails', () => {
  const parts = [part('header', 'header'), part('footer', 'footer'), part('footer-legacy', 'footer')];
  const templates = [template('page', ref('header', 'header'), ref('footer', 'footer')), template('index', ref('header', 'header'))];
  assert.deepEqual(shellFindings(templates, parts, [page('<footer class="wp-block-group">x</footer>')]), [
    ['theme_shared_shell', { area: 'footer', parts: ['footer', 'footer-legacy'], templates_missing: ['index'], unreferenced: ['footer-legacy'] }],
    ['theme_page_shell_copy', 'footer'],
  ]);
  assert.deepEqual(shellFindings([template('page')], [], []).map(([check, evidence]) => [check, evidence.area]), [['theme_shared_shell', 'header'], ['theme_shared_shell', 'footer']]);
});
