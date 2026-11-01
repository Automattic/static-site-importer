import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, rm, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';
import { createServer } from 'node:net';
import { spawn } from 'node:child_process';
import { provisionWordPress } from '../lib/disposable-wordpress.mjs';

test('internal links are destination permalinks without importer or generated theme', async () => {
  const workDir = await mkdtemp(join(tmpdir(), 'ssi-links-'));
  const port = await new Promise(done => {
    const socket = createServer();
    socket.listen(0, '127.0.0.1', () => {
      const port = socket.address().port;
      socket.close(() => done(port));
    });
  });
  let runtime, server;
  try {
    const origin = `http://127.0.0.1:${port}`;
    runtime = await provisionWordPress({ workDir, origin });
    await symlink(resolve('.'), join(runtime.wpDir, 'wp-content/plugins/static-site-importer'));
    runtime.wpRun(['plugin', 'activate', 'static-site-importer']);
    const header = '<header><nav><a href="/">Home</a><a href="/about/">About</a><a href="/contact/">Contact</a></nav></header>';
    const artifact = {
      entrypoint: 'website/index.html',
      files: ['index.html', 'about/index.html', 'contact/index.html'].map((path, i) => ({
        path: `website/${path}`,
        content: `<!doctype html><html><head><title>${['Home', 'About', 'Contact'][i]}</title></head><body>${header}<main><h1>${['Home', 'About', 'Contact'][i]}</h1><p><a href="/about/?ref=body#team">Meet our team</a> and <a href="/contact/">contact us</a>.</p></main><footer><p><a href="/about/#team">Footer team</a></p></footer></body></html>`,
      })),
    };
    const script = join(workDir, 'import.php');
    await writeFile(script, `<?php
require_once WP_PLUGIN_DIR . '/static-site-importer/vendor/autoload.php';
require_once WP_PLUGIN_DIR . '/static-site-importer/includes/class-static-site-importer-compilation-preparation.php';
require_once WP_PLUGIN_DIR . '/static-site-importer/includes/class-static-site-importer-wordpress-site-plan-materializer.php';
$artifact = json_decode(base64_decode('${Buffer.from(JSON.stringify(artifact)).toString('base64')}'), true);
$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact($artifact, array('slug' => 'import-links', 'activate' => true, 'overwrite' => true));
if (is_wp_error($compiled)) { throw new RuntimeException($compiled->get_error_message()); }
$result = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize($compiled['plan'], $compiled['args']);
if ('completed' !== ($result['status'] ?? '')) { throw new RuntimeException(wp_json_encode($result)); }
$posts = get_posts(array('post_type' => array('page', 'post', 'wp_navigation', 'wp_template_part'), 'posts_per_page' => -1));
$stored = array_map(static fn($p) => array('type' => $p->post_type, 'content' => $p->post_content), $posts);
$parts = array();
foreach (glob(get_stylesheet_directory() . '/parts/*.html') as $file) { $parts[] = file_get_contents($file); }
echo wp_json_encode(array('stored' => $stored, 'parts' => $parts, 'about' => wp_make_link_relative(get_permalink(get_page_by_path('about')->ID)), 'contact' => wp_make_link_relative(get_permalink(get_page_by_path('contact')->ID))));
`);
    const result = JSON.parse(runtime.wpRun(['eval-file', script]).stdout.trim().split('\n').pop());
    for (const document of [...result.stored.map(p => p.content), ...result.parts]) {
      assert.doesNotMatch(document, /(?:page_id=|\?p=)/);
      // Stored links carry no host, so they survive a domain change.
      assert.ok(!document.includes(origin), document);
    }
    const pages = result.stored.filter(p => p.type === 'page' && p.content.includes('Meet our team'));
    assert.equal(pages.length, 3);
    for (const page of pages) {
      assert.ok(page.content.includes(`${result.about}?ref=body#team`), page.content);
      assert.ok(page.content.includes(result.contact), page.content);
    }
    const navigation = result.stored.filter(p => p.type === 'wp_navigation');
    assert.ok(navigation.length > 0, 'fixture creates navigation posts');
    assert.ok(navigation.some(p => p.content.includes(result.about)), JSON.stringify(navigation));
    assert.ok(result.parts.length > 0, 'fixture creates shared template parts');
    assert.ok(result.parts.some(part => part.includes(`${result.about}#team`)), 'shared footer stores the destination permalink');
    const exportScript = join(workDir, 'export.php');
    await writeFile(exportScript, `<?php require_once WP_PLUGIN_DIR . '/static-site-importer/includes/class-static-site-importer-theme-exporter.php'; $r = Static_Site_Importer_Theme_Exporter::export_theme(); if (is_wp_error($r)) { throw new RuntimeException($r->get_error_message()); } echo wp_json_encode($r);`);
    const exported = runtime.wpRun(['eval-file', exportScript]).stdout;
    assert.doesNotMatch(exported, /(?:page_id=|\?p=)/);
    assert.doesNotMatch(exported, new RegExp(origin.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '/about/'), 'exported links use root-relative source paths');
    runtime.wpRun(['plugin', 'deactivate', 'static-site-importer']);
    runtime.wpRun(['theme', 'activate', 'twentytwentyfive']);
    const router = join(workDir, 'router.php');
    await writeFile(router, `<?php $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); if ($path !== '/' && is_file($_SERVER['DOCUMENT_ROOT'] . $path)) { return false; } require $_SERVER['DOCUMENT_ROOT'] . '/index.php';`);
    server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', runtime.wpDir, router], { stdio: 'ignore' });
    let response;
    for (let retry = 0; retry < 60; retry++) {
      try { response = await fetch(new URL(result.about, origin)); break; } catch { await new Promise(r => setTimeout(r, 100)); }
    }
    assert.equal(response?.status, 200);
    const html = await response.text();
    assert.ok(html.includes('Meet our team'));
    for (const target of [result.about, result.contact]) {
      assert.ok(html.includes(`href="${target}`), `rendered link ${target}`);
      const linkedResponse = await fetch(new URL(target, origin));
      // Finish reading before teardown closes the PHP server's sockets.
      await linkedResponse.arrayBuffer();
      assert.equal(linkedResponse.status, 200);
    }
  } finally {
    server?.kill();
    await runtime?.close();
    await rm(workDir, { recursive: true, force: true });
  }
});
