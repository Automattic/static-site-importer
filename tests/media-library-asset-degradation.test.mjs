import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { mkdir, mkdtemp, readFile, rm, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';
import { provisionWordPress } from '../lib/disposable-wordpress.mjs';

const spinner = '<svg width="28" height="28" viewBox="0 0 38 38" xmlns="http://www.w3.org/2000/svg" stroke="#808080"><g fill="none"><circle cx="19" cy="19" r="18"/><path d="M37 19c0-9.94-8.06-18-18-18"><animateTransform attributeName="transform" type="rotate" from="0 19 19" to="360 19 19" dur="1s" repeatCount="indefinite"/></path></g></svg>';

// One image the destination will not accept (here a host that does not admit
// WebP uploads) must not fail the whole import: the other images still become
// attachments, and the refused one is reported by its source path.
for (const capturedSvg of [false, true]) {
test(capturedSvg ? 'an intact captured SMIL SVG degrades alone and is reported' : 'an image the Media Library refuses degrades alone and is reported', async () => {
  const workDir = await mkdtemp(join(tmpdir(), 'ssi-media-degrade-'));
  let runtime;
  try {
    runtime = await provisionWordPress({ workDir });
    await symlink(resolve('.'), join(runtime.wpDir, 'wp-content/plugins/static-site-importer'));
    runtime.wpRun(['plugin', 'activate', 'static-site-importer']);
    await mkdir(join(runtime.wpDir, 'wp-content/mu-plugins'), { recursive: true });
    await writeFile(join(runtime.wpDir, 'wp-content/mu-plugins/no-webp.php'), "<?php\nadd_filter( 'upload_mimes', static function ( $mimes ) { unset( $mimes['webp'] ); return $mimes; } );\n");
    const site = join(workDir, 'bundle/website');
    await mkdir(join(site, 'images'), { recursive: true });
    for (const [name, writer] of [['photo.png', 'imagepng'], ['banner.webp', 'imagewebp']]) {
      execFileSync('php', ['-r', `$i = imagecreatetruecolor(40, 30); imagefill($i, 0, 0, imagecolorallocate($i, 200, 80, 40)); ${writer}($i, $argv[1]);`, join(site, 'images', name)]);
    }
    if (capturedSvg) {
      await writeFile(join(site, 'images/infinite-loader-gray.svg'), spinner);
      // The same dedicated capture report contract as smoke-image-asset-records.php.
      const images = [];
      for (const [name, contentType, width, height] of [['photo.png', 'image/png', 40, 30], ['infinite-loader-gray.svg', 'image/svg+xml', 28, 28]]) {
        const sourceUrl = `https://example.org/images/${name}`;
        images.push({
          source_urls: [sourceUrl],
          original: { source_url: sourceUrl, content_type: contentType, width, height, alpha: false, sha256: createHash('sha256').update(await readFile(join(site, 'images', name))).digest('hex') },
          references: [{ kind: 'content', document_url: 'https://example.org/', location: 'img:0', source_url: sourceUrl }],
        });
      }
      await writeFile(join(site, 'image-assets.json'), JSON.stringify({ version: 1, images }));
    }
    await writeFile(join(site, 'index.html'), '<!doctype html><html><head><title>Northwind</title></head><body><main><h1>Welcome</h1><img src="images/photo.png" alt="Workshop photo" width="40" height="30"><p>Hand-built furniture.</p><img src="images/banner.webp" alt="Showroom banner" width="40" height="30"></main></body></html>');
    if (capturedSvg) {
      const html = await readFile(join(site, 'index.html'), 'utf8');
      await writeFile(join(site, 'index.html'), html.replace('images/banner.webp', 'images/infinite-loader-gray.svg'));
      await rm(join(site, 'images/banner.webp'));
    }
    const request = join(workDir, 'apply.json');
    await writeFile(request, JSON.stringify({ operation: 'apply', source: { type: 'files', ref: 'request-bundle:bundle' }, slug: 'northwind', name: 'northwind', activate: true }));
    let stdout;
    try { stdout = runtime.wpRun(['static-site-importer', 'import', `--request=${request}`]).stdout; } catch (error) { stdout = String(error.stdout ?? error.message); }
    const receipt = JSON.parse(stdout.trim().split('\n').at(-1));
    assert.equal(receipt.status, 'completed', JSON.stringify(receipt.response?.error ?? receipt).slice(0, 600));
    const state = JSON.parse(runtime.wpRun(['eval', `$home = get_post( (int) get_option( 'page_on_front' ) ); echo wp_json_encode( array( 'attachments' => array_map( fn( $p ) => $p->post_mime_type, get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1 ) ) ), 'content' => $home ? $home->post_content : '' ) );`]).stdout.trim());
    assert.deepEqual(state.attachments, ['image/png']);
    assert.match(state.content, /Workshop photo/);
    // The refused image is served from its theme destination, not a broken link.
    const banner = state.content.match(capturedSvg ? /src="([^"]*infinite-loader-gray[^" ]*\.svg)"/ : /src="([^"]*banner[^"]*\.webp)"/)?.[1];
    assert.ok(banner && existsSync(join(runtime.wpDir, new URL(banner).pathname)), state.content);
    // The import report's loss ledger names the refused image.
    const report = JSON.parse(await readFile(receipt.response.result.response_artifacts.artifacts.import_report.path, 'utf8'));
    const finding = report.diagnostics?.find(row => row.code === 'media_library_asset_unavailable');
    assert.ok(finding, 'the refused image is reported');
    assert.match(finding.location, capturedSvg ? /infinite-loader-gray\.svg$/ : /banner\.webp$/);
    if (capturedSvg) {
      assert.equal(await readFile(join(runtime.wpDir, new URL(banner).pathname), 'utf8'), spinner);
      assert.match(JSON.stringify(finding), /unsupported_svg_content/);
    }
    assert.deepEqual([finding.loss_class, finding.unit, finding.acceptability], ['degraded_unit', 'asset', 'accepted_reported_loss']);
  } finally {
    await runtime?.close();
    await rm(workDir, { recursive: true, force: true });
  }
});
}
