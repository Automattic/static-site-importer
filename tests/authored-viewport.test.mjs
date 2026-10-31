import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, rm, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { JSDOM } from 'jsdom';
import { provisionWordPress } from '../lib/disposable-wordpress.mjs';

test('imported site renders one source-owned viewport per route, replayed head or not', async () => {
  const workDir = await mkdtemp(join(tmpdir(), 'ssi-viewport-'));
  const port = await new Promise(resolvePort => {
    const socket = createServer();
    socket.listen(0, '127.0.0.1', () => {
      const { port } = socket.address();
      socket.close(() => resolvePort(port));
    });
  });
  const origin = `http://127.0.0.1:${port}`;
  let runtime, server;
  try {
    runtime = await provisionWordPress({ workDir, origin });
    await symlink(resolve('.'), join(runtime.wpDir, 'wp-content/plugins/static-site-importer'));
    const sourceValue = 'width=device-width, initial-scale=1, viewport-fit=cover';
    const artifact = {
      entrypoint: 'website/index.html',
      // The index head carries a selector, so the document-head replay owns it;
      // the about head is plain, so the authored viewport bootstrap owns it.
      files: [['index', ' id="authored-viewport"'], ['about', '']].map(([name, selector]) => ({
        path: `website/${name}.html`,
        content: `<!doctype html><html><head><title>${name}</title><meta${selector} name="viewport" content="${sourceValue}"></head><body><main><h1>${name}</h1><a href="about.html">About</a></main></body></html>`,
      })),
    };
    const script = join(workDir, 'import.php');
    await writeFile(script, `<?php
require_once WP_PLUGIN_DIR . '/static-site-importer/vendor/autoload.php';
require_once WP_PLUGIN_DIR . '/static-site-importer/static-site-importer.php';
require_once WP_PLUGIN_DIR . '/static-site-importer/includes/class-static-site-importer-compilation-preparation.php';
require_once WP_PLUGIN_DIR . '/static-site-importer/includes/class-static-site-importer-wordpress-site-plan-materializer.php';
$artifact = json_decode(base64_decode('${Buffer.from(JSON.stringify(artifact)).toString('base64')}'), true);
$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact($artifact, array('slug' => 'viewport-proof', 'activate' => true));
if (is_wp_error($compiled)) { throw new RuntimeException($compiled->get_error_message()); }
$result = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize($compiled['plan'], $compiled['args']);
if ('completed' !== ($result['status'] ?? '')) { throw new RuntimeException(wp_json_encode($result)); }
echo wp_json_encode(array_map('get_permalink', get_posts(array('post_type' => 'page', 'numberposts' => -1, 'post_status' => 'publish'))));
`);
    const output = runtime.wpRun(['eval-file', script]).stdout;
    const routes = JSON.parse(output.trim());
    assert.equal(routes.length, 2, 'both imported routes exist');
    server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', runtime.wpDir], { stdio: 'ignore' });
    for (let attempt = 0; ; attempt++) {
      try { await fetch(origin); break; } catch (error) {
        if (attempt === 100) throw error;
        await new Promise(resolveWait => setTimeout(resolveWait, 50));
      }
    }
    for (const route of routes) {
      const response = await fetch(route);
      assert.equal(response.status, 200, route);
      const dom = new JSDOM(await response.text());
      const viewports = dom.window.document.querySelectorAll('meta[name="viewport"]');
      assert.equal(viewports.length, 1, `${route} has exactly one viewport`);
      assert.equal(viewports[0].getAttribute('content'), sourceValue, 'source declaration replaces the core default');
      dom.window.close();
    }
  } finally {
    if (server) {
      server.kill();
      await new Promise(resolveExit => server.once('exit', resolveExit));
    }
    await runtime?.close();
    await rm(workDir, { recursive: true, force: true });
  }
});
