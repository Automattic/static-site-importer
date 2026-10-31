import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, rm, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';
import { createServer } from 'node:net';
import { provisionWordPress } from '../lib/disposable-wordpress.mjs';

// A third-party social image the producer did not retain costs one metadata
// field, not the import; one on the source origin that never resolved is a fault.
test('an unretained third-party social image is a reported loss; an unresolved source-origin one fails', async () => {
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
    const run = async (slug, image) => {
      const artifact = {
        entrypoint: 'website/index.html',
        files: [{ path: 'website/index.html', content: `<!doctype html><html><head><title>Studio</title><meta property="og:image" content="${image}"></head><body><main><h1>Studio</h1><p>Paintings.</p></main></body></html>` }],
      };
      const script = join(workDir, `${slug}.php`);
      await writeFile(script, `<?php
require_once WP_PLUGIN_DIR . '/static-site-importer/vendor/autoload.php';
require_once WP_PLUGIN_DIR . '/static-site-importer/static-site-importer.php';
require_once WP_PLUGIN_DIR . '/static-site-importer/includes/class-static-site-importer-compilation-preparation.php';
require_once WP_PLUGIN_DIR . '/static-site-importer/includes/class-static-site-importer-wordpress-site-plan-materializer.php';
$artifact = json_decode(base64_decode('${Buffer.from(JSON.stringify(artifact)).toString('base64')}'), true);
$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact($artifact, array('slug' => '${slug}', 'activate' => true, 'overwrite' => true));
if (is_wp_error($compiled)) { throw new RuntimeException($compiled->get_error_message()); }
$compiled['args']['source_metadata']['source_path'] = 'https://studio.example/';
$result = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize($compiled['plan'], $compiled['args']);
echo wp_json_encode(array('status' => $result['status'] ?? '', 'error' => $result['errors'][0]['code'] ?? null, 'lost' => array_values(array_filter($result['diagnostics'] ?? array(), static fn($d) => 'route_social_image_not_retained' === ($d['reason_code'] ?? '')))));
`);
      return JSON.parse(runtime.wpRun(['eval-file', script]).stdout.trim().split('\n').pop());
    };
    const sourceOrigin = await run('social-source', 'https://studio.example/media/missing.jpg');
    assert.equal(sourceOrigin.error, 'route_social_image_unresolved', JSON.stringify(sourceOrigin));
    const image = 'https://images.cdn.example/photo.jpg?fm=jpg&q=85';
    const thirdParty = await run('social-third-party', image);
    assert.equal(thirdParty.status, 'completed', JSON.stringify(thirdParty));
    assert.deepEqual(thirdParty.lost, [{ reason_code: 'route_social_image_not_retained', source_path: 'website/index.html', key: 'og:image', url: image }], 'the loss is reported');
  } finally {
    await runtime?.close();
    await rm(workDir, { recursive: true, force: true });
  }
});
