import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdir, mkdtemp, readFile, rm, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';
import { provisionWordPress } from '../lib/disposable-wordpress.mjs';

// One image the destination will not accept (here a host that does not admit
// WebP uploads) must not fail the whole import: the other images still become
// attachments, and the refused one is reported by its source path.
test('an image the Media Library refuses degrades alone and is reported', async () => {
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
    await writeFile(join(site, 'index.html'), '<!doctype html><html><head><title>Northwind</title></head><body><main><h1>Welcome</h1><img src="images/photo.png" alt="Workshop photo" width="40" height="30"><p>Hand-built furniture.</p><img src="images/banner.webp" alt="Showroom banner" width="40" height="30"></main></body></html>');
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
    const banner = state.content.match(/src="([^"]*banner[^"]*\.webp)"/)?.[1];
    assert.ok(banner && existsSync(join(runtime.wpDir, new URL(banner).pathname)), state.content);
    // The import report's loss ledger names the refused image.
    const report = JSON.parse(await readFile(receipt.response.result.response_artifacts.artifacts.import_report.path, 'utf8'));
    const finding = report.diagnostics?.find(row => row.code === 'media_library_asset_unavailable');
    assert.ok(finding, 'the refused image is reported');
    assert.match(finding.location, /banner\.webp$/);
    assert.deepEqual([finding.loss_class, finding.unit, finding.acceptability], ['degraded_unit', 'asset', 'accepted_reported_loss']);
  } finally {
    await runtime?.close();
    await rm(workDir, { recursive: true, force: true });
  }
});
