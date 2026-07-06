/**
 * External dependencies
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

/**
 * Internal dependencies
 */
import { buildFigIntentParityReport } from './fig-intent-parity-report.mjs';

test('fig intent parity report catches missing generated pages, assets, links, text, and responsive risks', () => {
  const root = fs.mkdtempSync(path.join(tmpdir(), 'fig-intent-parity-'));
  const artifactDir = path.join(root, 'site');
  fs.mkdirSync(artifactDir, { recursive: true });
  fs.writeFileSync(path.join(artifactDir, 'index.html'), '<h1>Hello</h1><img src="missing.png"><a href="missing.html">Missing</a>');
  fs.writeFileSync(path.join(artifactDir, 'style.css'), '.hero{width:2000px;background:url(missing-bg.png)}');

  const summary = {
    fixtures: [
      {
        id: 'demo',
        artifact_dir: artifactDir,
        inspect_path: path.join(root, 'demo-inspect.json'),
        result_path: path.join(root, 'demo-result.json'),
        selected_frame_ids: ['1:1', '1:2'],
        selected_frames: [{ id: '1:1', name: 'Home', width: 2000 }, { id: '1:2', name: 'Pricing', width: 1440 }],
        metrics: { page_count: 2, asset_reference_count: 3, text_node_count: 2, node_count: 5 },
      },
    ],
  };
  const result = {
    metrics: { page_count: 2, asset_reference_count: 3, text_node_count: 2, node_count: 5 },
    html: {
      pages: [
        { nodes: [{ type: 'TEXT', name: 'Hello', source_page_frame_id: '1:1' }, { type: 'TEXT', name: 'Dropped Copy', source_page_frame_id: '1:2' }] },
      ],
    },
  };
  fs.writeFileSync(path.join(root, 'summary.json'), JSON.stringify(summary));
  fs.writeFileSync(path.join(root, 'demo-result.json'), JSON.stringify(result));
  fs.writeFileSync(path.join(root, 'demo-inspect.json'), JSON.stringify({ node_count: 5, candidate_count: 2 }));

  const report = buildFigIntentParityReport({ summary: path.join(root, 'summary.json'), fixtureId: 'demo' });
  const codes = report.regressions.map((row) => row.code);

  assert.equal(report.status, 'failed');
  assert.ok(codes.includes('missing_generated_pages'));
  assert.ok(codes.includes('dropped_generated_images'));
  assert.ok(codes.includes('unresolved_generated_local_references'));
  assert.ok(codes.includes('missing_generated_text'));
  assert.ok(codes.includes('very_large_fixed_width'));
  assert.ok(codes.includes('missing_media_queries'));
});

test('fig intent parity report scopes WordPress pages to the active SSI manifest', () => {
  const root = fs.mkdtempSync(path.join(tmpdir(), 'fig-intent-parity-wp-'));
  const artifactDir = path.join(root, 'site');
  const wpRoot = path.join(root, 'wp');
  const themeDir = path.join(wpRoot, 'wp-content', 'themes', 'scoped-theme');
  fs.mkdirSync(artifactDir, { recursive: true });
  fs.mkdirSync(themeDir, { recursive: true });
  fs.writeFileSync(path.join(artifactDir, 'index.html'), '<h1>Home Copy</h1>');
  fs.writeFileSync(path.join(artifactDir, 'style.css'), '@media (max-width: 600px) { body { width:auto } }');
  fs.writeFileSync(path.join(themeDir, 'style.css'), 'body{}');
  fs.writeFileSync(path.join(themeDir, 'static-site-importer-manifest.json'), JSON.stringify({ desired: { pages: [{ slug: 'home', title: 'Home' }] } }));

  const fakeWpCli = path.join(root, 'fake-wp-cli.mjs');
  fs.writeFileSync(fakeWpCli, `
const args = process.argv.slice(2);
if (args.includes('post') && args.includes('list')) {
  process.stdout.write(JSON.stringify([
    { ID: 1, post_title: 'Home', post_name: 'home' },
    { ID: 2, post_title: 'Old Landing', post_name: 'old-landing' }
  ]));
} else if (args.includes('post') && args.includes('get') && args.includes('1')) {
  process.stdout.write('<!-- wp:paragraph --><p>Home Copy</p><!-- /wp:paragraph -->');
} else if (args.includes('post') && args.includes('get') && args.includes('2')) {
  process.stdout.write('<!-- wp:html --><div>Unrelated fallback</div><!-- /wp:html -->');
} else if (args.includes('option') && args.includes('get') && args.includes('stylesheet')) {
  process.stdout.write('scoped-theme\\n');
} else {
  process.exit(1);
}
`);

  const summary = { fixtures: [{ id: 'demo', artifact_dir: artifactDir, result_path: path.join(root, 'result.json'), metrics: { page_count: 1 } }] };
  const result = { metrics: { page_count: 1 }, html: { pages: [{ nodes: [{ type: 'TEXT', name: 'Home Copy' }] }] } };
  fs.writeFileSync(path.join(root, 'summary.json'), JSON.stringify(summary));
  fs.writeFileSync(path.join(root, 'result.json'), JSON.stringify(result));

  const report = buildFigIntentParityReport({ summary: path.join(root, 'summary.json'), fixtureId: 'demo', wpRoot, wpCli: `${process.execPath} ${fakeWpCli}` });

  assert.equal(report.wordpress.site_page_count, 2);
  assert.equal(report.wordpress.pages.length, 1);
  assert.equal(report.wordpress.fallback_block_count, 0);
  assert.deepEqual(report.comparisons.expected_wordpress_page_slugs, ['home']);
  assert.ok(!report.regressions.some((row) => row.code === 'missing_wordpress_pages' || row.code === 'fallback_blocks_present'));
});
