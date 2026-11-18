import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, rm, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';
import { createServer } from 'node:net';
import { provisionWordPress } from '../lib/disposable-wordpress.mjs';

// Unattachable or unretained social images cost one metadata field, not the
// import; a source-origin reference that never resolved is still a fault.
test('optional social image losses are reported; unresolved source-origin references fail', async () => {
  const workDir = await mkdtemp(join(tmpdir(), 'ssi-social-'));
  const port = await new Promise(resolvePort => {
    const socket = createServer();
    socket.listen(0, '127.0.0.1', () => {
      const { port } = socket.address();
      socket.close(() => resolvePort(port));
    });
  });
  const origin = `http://127.0.0.1:${port}`;
  let runtime;
  try {
    runtime = await provisionWordPress({ workDir, origin });
    await symlink(resolve('.'), join(runtime.wpDir, 'wp-content/plugins/static-site-importer'));
    const run = async (slug, image, files = [], markup = `<!doctype html><html><head><title>Studio</title><meta property="og:image" content="${image}"></head><body><main><h1>Studio</h1><p>Paintings.</p></main></body></html>`) => {
      const artifact = {
        entrypoint: 'website/index.html',
        files: [{ path: 'website/index.html', content: markup }, ...files],
      };
      const script = join(workDir, `${slug}.php`);
      await writeFile(script, `<?php
require_once WP_PLUGIN_DIR . '/static-site-importer/vendor/autoload.php';
require_once WP_PLUGIN_DIR . '/static-site-importer/static-site-importer.php';
require_once WP_PLUGIN_DIR . '/static-site-importer/includes/class-static-site-importer-compilation-preparation.php';
require_once WP_PLUGIN_DIR . '/static-site-importer/includes/class-static-site-importer-wordpress-site-plan-materializer.php';
$artifact = json_decode(base64_decode('${Buffer.from(JSON.stringify(artifact)).toString('base64')}'), true);
$source = static_site_importer_source_runtime($artifact);
if (is_wp_error($source)) { throw new RuntimeException($source->get_error_message()); }
$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact($source['artifact'], array('slug' => '${slug}', 'activate' => true, 'overwrite' => true, 'source_metadata' => $source['source_metadata']));
if (is_wp_error($compiled)) { throw new RuntimeException($compiled->get_error_message()); }
$compiled['args']['source_metadata']['source_path'] = 'https://studio.example/';
$result = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize($compiled['plan'], $compiled['args']);
$page = get_page_by_path('home');
echo wp_json_encode(array('thumbnail' => $page ? (int) get_post_thumbnail_id($page->ID) : null, 'status' => $result['status'] ?? '', 'error' => $result['errors'][0]['code'] ?? null, 'lost' => array_values(array_filter($result['diagnostics'] ?? array(), static fn($d) => 'route_social_image_not_retained' === ($d['reason_code'] ?? '')))));
`);
      return JSON.parse(runtime.wpRun(['eval-file', script]).stdout.trim().split('\n').pop());
    };
    // Embedded rasters are now supported upstream; percentage dimensions keep
    // this source SVG unattachable and exercise the optional-metadata fallback.
    const socialSvg = await run('social-svg', '/media/social.svg', [{
      path: 'website/media/social.svg',
      content: '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="100%" height="100%"><image width="1" height="1" xlink:href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="/></svg>',
    }], '<!doctype html><html><head><meta charset="utf-8"><title>Social SVG</title><meta content="/media/social.svg" property="og:image"></head><body><h1>Heading</h1><p>Text.</p></body></html>');
    assert.equal(socialSvg.status, 'completed', JSON.stringify(socialSvg));
    assert.equal(socialSvg.thumbnail, 0, 'the route imports without a thumbnail');
    assert.equal(socialSvg.lost.length, 1, JSON.stringify(socialSvg));
    assert.equal(socialSvg.lost[0].source_path, 'website/index.html');
    assert.equal(socialSvg.lost[0].key, 'og:image');
    assert.match(socialSvg.lost[0].url, /\/media\/social\.svg$/);
    const sourceOrigin = await run('social-source', 'https://studio.example/media/missing.jpg');
    assert.equal(sourceOrigin.error, 'route_social_image_unresolved', JSON.stringify(sourceOrigin));
    const omittedUrl = 'https://studio.example/default-og-image.png';
    const declaredLoss = await run('social-declared-loss', omittedUrl, [{
      path: 'website/source-findings.json',
      content: JSON.stringify({
        schema: 'static-site-importer/source-findings/v1',
        findings: [{ code: 'image_resource_unresolved', severity: 'error', unit: 'asset', loss_class: 'omitted_unit', location: { url: omittedUrl }, message: 'Error: HTTP 404', owning_layer: 'data-liberation-agent' }],
      }),
    }]);
    assert.equal(declaredLoss.status, 'completed', JSON.stringify(declaredLoss));
    assert.equal(declaredLoss.thumbnail, 0, 'a declared source loss imports without a thumbnail');
    assert.deepEqual(declaredLoss.lost, [{ reason_code: 'route_social_image_not_retained', source_path: 'website/index.html', key: 'og:image', url: omittedUrl }]);
    const image = 'https://images.cdn.example/photo.jpg?fm=jpg&q=85';
    const thirdParty = await run('social-third-party', image);
    assert.equal(thirdParty.status, 'completed', JSON.stringify(thirdParty));
    assert.deepEqual(thirdParty.lost, [{ reason_code: 'route_social_image_not_retained', source_path: 'website/index.html', key: 'og:image', url: image }], 'the loss is reported');
  } finally {
    await runtime?.close();
    await rm(workDir, { recursive: true, force: true });
  }
});
