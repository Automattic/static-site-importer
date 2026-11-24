import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { importFailure } from '../tools/import-loop/corpus.mjs';
import { trendRow } from '../tools/import-loop/corpus-metrics.mjs';

const repo = path.resolve(new URL('..', import.meta.url).pathname);
const gate = path.join(repo, 'tools', 'import-loop', 'gates', 'reimport.sh');

// reimport.sh against a stand-in WordPress: the fake WP-CLI's import leaves
// what FAKE_IMPORT_MODE names, and the served index.php mimics wp_maintenance().
function fixture() {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'reimport-refusal-'));
  const bare = path.join(root, 'bare-wp');
  fs.mkdirSync(bare);
  fs.mkdirSync(path.join(bare, 'wp-content', 'plugins'), { recursive: true });
  fs.writeFileSync(path.join(bare, 'bootstrap.php'), '<?php\n');
  fs.writeFileSync(path.join(bare, 'wp-cli.phar'), `<?php
$args = array_slice( $argv, 1 );
$path = '';
foreach ( $args as $arg ) { if ( str_starts_with( $arg, '--path=' ) ) { $path = substr( $arg, 7 ); } }
$command = implode( ' ', array_filter( $args, fn ( $arg ) => ! str_starts_with( $arg, '--' ) ) );
if ( str_starts_with( $command, 'static-site-importer import' ) ) {
  $mode = getenv( 'FAKE_IMPORT_MODE' );
  if ( 'maintenance' === $mode ) { file_put_contents( $path . '/.maintenance', '<?php $upgrading = ' . time() . '; ?>' ); }
  if ( 'maintenance-screen' === $mode ) { touch( $path . '/.fake-maintenance-screen' ); }
  echo "{\\"status\\":\\"imported\\"}\\n";
}
`);
  fs.writeFileSync(path.join(bare, 'index.php'), `<?php
if ( file_exists( __DIR__ . '/.fake-maintenance-screen' ) ) { http_response_code( 503 ); header( 'Retry-After: 600' ); echo 'Maintenance'; return; }
echo 'home';
`);
  const transformer = path.join(root, 'php-transformer');
  for (const dir of ['src', 'resources']) fs.mkdirSync(path.join(transformer, dir), { recursive: true });
  for (const file of ['php-transformer.php', 'VERSION', 'composer.json']) fs.writeFileSync(path.join(transformer, file), '');
  const capture = path.join(root, 'capture');
  fs.mkdirSync(capture);
  fs.writeFileSync(path.join(capture, 'capture-receipt.json'), JSON.stringify({ title: 'Fixture' }));
  const plugin = path.join(root, 'zip', 'static-site-importer', 'vendor', 'composer');
  fs.mkdirSync(plugin, { recursive: true });
  for (const file of ['autoload_classmap.php', 'autoload_static.php']) fs.writeFileSync(path.join(plugin, file), '<?php\n');
  const zip = path.join(root, 'ssi.zip');
  assert.equal(spawnSync('zip', ['-qr', zip, 'static-site-importer'], { cwd: path.join(root, 'zip') }).status, 0);
  return { root, bare, transformer, capture, zip, site: path.join(root, 'site') };
}

function reimport(mode) {
  const f = fixture();
  const run = spawnSync('bash', [gate, f.transformer, f.capture, f.site], { encoding: 'utf8', env: { ...process.env, BARE_WP: f.bare, SSI_ZIP: f.zip, SSI_SOURCE: '', FAKE_IMPORT_MODE: mode } });
  const pidFile = path.join(f.site, '.server.pid');
  if (fs.existsSync(pidFile)) try { process.kill(-Number(fs.readFileSync(pidFile, 'utf8'))); } catch {}
  fs.rmSync(f.root, { recursive: true, force: true });
  return run;
}

test('reimport.sh refuses a site left with .maintenance before it is served', () => {
  const run = reimport('maintenance');
  assert.notEqual(run.status, 0);
  assert.equal(run.stdout.trim(), '');
  assert.match(run.stderr, /site_not_serving: .*\.maintenance exists after import/);
  assert.match(run.stderr, /"status":"imported"/, 'prints the receipt tail');
  assert.match(importFailure(run.stderr, 'reimport.log'), /^import refused \(reimport\.log\): site_not_serving: /);
});

test('reimport.sh refuses a home URL serving the Maintenance screen', () => {
  const run = reimport('maintenance-screen');
  assert.notEqual(run.status, 0);
  assert.match(run.stderr, /site_not_serving: .* answers 503 with Retry-After/);
});

test('reimport.sh serves a healthy import and prints its URL', () => {
  const run = reimport('');
  assert.equal(run.status, 0, run.stderr);
  assert.match(run.stdout.trim(), /^http:\/\/127\.0\.0\.1:\d+$/);
});

test('a refused site is unmeasured in the trend, not 0 verified cells', () => {
  const row = trendRow({ date: 'd', trunk: 't', release: 'r' }, { site: 'x', cells: [], unmeasured: [importFailure('site_not_serving: maintenance', 'log')] });
  assert.equal(row.verified, null);
  assert.equal(row.passing, null);
  assert.match(row.unmeasured[0], /site_not_serving/);
});
