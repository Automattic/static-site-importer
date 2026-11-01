import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync, spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import net from 'node:net';
import { chromium } from 'playwright';
import { deriveOutcomeBindings, readOutcomeCapture } from './outcome-capture-bindings.mjs';
import { provisionWordPress } from './disposable-wordpress.mjs';
import { materializeGeneratedArtifactFixtures } from './artifact-intake.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const json = file => JSON.parse(fs.readFileSync(file, 'utf8'));
const run = (command, args, cwd = ROOT) => execFileSync(command, args, { cwd, encoding: 'utf8' }).trim();
const hashPattern = /^(?:[a-f0-9]{40}|[a-f0-9]{64})$/i;

export function installedIdentities(root, producer) {
  const packed = json(path.join(producer, 'package.json'));
  const provenancePath = path.join(producer, 'provenance.json');
  if (!fs.existsSync(provenancePath)) throw new Error('Capture producer release predates installed-artifact provenance (provenance.json required)');
  const provenance = json(provenancePath);
  if (provenance.name !== packed.name || provenance.version !== packed.version || !hashPattern.test(provenance.commit ?? '')) throw new Error('Capture producer provenance.json identity does not match package.json or has an invalid commit');
  const manifest = json(path.join(root, 'composer.json'));
  const engine = path.join(root, 'vendor/automattic/blocks-engine-php-transformer');
  const version = fs.readFileSync(path.join(engine, 'VERSION'), 'utf8').trim();
  if (manifest.require['automattic/blocks-engine-php-transformer'].replace(/^v/, '') !== version) throw new Error('Installed Blocks Engine VERSION differs from SSI dependency pin');
  const installed = json(path.join(root, 'vendor/composer/installed.json'));
  const dependency = (installed.packages ?? installed).find(item => item.name === 'automattic/blocks-engine-php-transformer');
  if (!dependency || dependency.version.replace(/^v/, '') !== version) throw new Error('Installed Composer Blocks Engine identity differs from VERSION');
  // Composer metadata is retained as a declaration, not proof of a commit in
  // the installed dist. The distributed transformer currently carries VERSION only.
  const engineProvenance = path.join(engine, 'provenance.json');
  let commit = 'unverified';
  if (fs.existsSync(engineProvenance)) {
    const identity = json(engineProvenance);
    if (identity.version.replace(/^v/, '') !== version || !hashPattern.test(identity.commit ?? '')) throw new Error('Blocks Engine installed provenance mismatch');
    commit = identity.commit;
  }
  return {
    capture: provenance,
    ssi: { version: fs.readFileSync(path.join(root, 'static-site-importer.php'), 'utf8').match(/Version:\s*(\S+)/)[1], commit: run('git', ['rev-parse', 'HEAD'], root), dirty: Boolean(run('git', ['status', '--porcelain', '--untracked-files=normal'], root)) },
    blocks_engine: { version, commit, artifact_identity: { VERSION: version }, composer_identity: dependency },
  };
}

export async function createDefaultToolchain({ output, producerRepository, producerVersion, producerTarball, root = ROOT }) {
  const work = path.resolve(`${output}.toolchain`);
  fs.mkdirSync(work, { recursive: true });
  let archive = producerTarball && path.resolve(producerTarball);
  if (!archive) {
    // The paired capture producer is operator configuration, not importer knowledge.
    if (!/^[\w.-]+\/[\w.-]+$/.test(producerRepository ?? '')) throw new Error('Declare --producer-repository <owner/repo> or --producer-tarball <path>');
    const release = JSON.parse(run('gh', ['api', `repos/${producerRepository}/releases/${producerVersion ? `tags/v${producerVersion.replace(/^v/, '')}` : 'latest'}`]));
    const assets = release.assets.filter(asset => /\.tgz$/.test(asset.name));
    if (assets.length !== 1) throw new Error('Capture producer release must contain exactly one .tgz package');
    const asset = assets[0];
    archive = path.join(work, asset.name);
    const response = await fetch(asset.browser_download_url);
    if (!response.ok) throw new Error(`Capture producer download failed: HTTP ${response.status}`);
    const bytes = Buffer.from(await response.arrayBuffer());
    if (asset.digest && asset.digest !== `sha256:${createHash('sha256').update(bytes).digest('hex')}`) throw new Error('Capture producer release sha256 digest mismatch');
    fs.writeFileSync(archive, bytes);
  }
  const unpack = path.join(work, 'producer');
  fs.mkdirSync(unpack);
  const entries = run('tar', ['-tzf', archive]).split('\n');
  if (entries.some(entry => !entry.startsWith('package/') || entry.split('/').includes('..'))) throw new Error('Unsafe capture producer tarball paths');
  run('tar', ['-xzf', archive, '-C', unpack]);
  const producer = path.join(unpack, 'package');
  const identities = installedIdentities(root, producer);
  if (producerVersion && identities.capture.version !== producerVersion.replace(/^v/, '')) throw new Error('Requested capture producer version differs from installed artifact');
  const bin = json(path.join(producer, 'package.json')).bin;
  const cliEntry = typeof bin === 'string' ? bin : Object.values(bin ?? {}).length === 1 ? Object.values(bin)[0] : null;
  if (!cliEntry) throw new Error('Capture producer package must declare exactly one bin entry');
  const cli = path.resolve(producer, cliEntry);
  if (!cli.startsWith(producer + path.sep)) throw new Error('Capture producer bin escapes its package');
  return {
    identities,
    async importSite({ site, directory, manifestPath }) {
      let artifactRoot;
      if (site.artifact_directory) artifactRoot = path.resolve(path.dirname(path.resolve(manifestPath)), site.artifact_directory);
      else {
        artifactRoot = path.join(directory, 'capture');
        run(process.execPath, [cli, site.source_url, '--output', artifactRoot]);
      }
      const intake = materializeGeneratedArtifactFixtures({ artifactRoot, fixtureRoot: path.join(directory, 'fixtures/websites') });
      if (intake.count !== 1) throw new Error('Each corpus entry must contain exactly one captured site');
      return { fixture: intake.fixtures[0], failures: [] };
    },
    async openRuntime({ site, directory, imported }) {
      const freePort = () => new Promise((resolve, reject) => {
        const server = net.createServer();
        server.on('error', reject);
        server.listen(0, '127.0.0.1', () => { const port = server.address().port; server.close(() => resolve(port)); });
      });
      const port = await freePort();
      const origin = `http://127.0.0.1:${port}`;
      const runtime = await provisionWordPress({ workDir: directory, origin });
      let server, sourceServer;
      const stop = async child => {
        if (child && child.exitCode === null) await new Promise(resolve => { child.once('exit', resolve); child.kill(); });
      };
      const close = async () => {
        await stop(server);
        await stop(sourceServer);
        await runtime.close();
      };
      try {
        const { wpRun, wpDir } = runtime;
        fs.symlinkSync(root, path.join(wpDir, 'wp-content/plugins/static-site-importer'), 'dir');
        wpRun(['plugin', 'activate', 'static-site-importer']);
        const bundle = path.join(directory, 'website.zip');
        run('zip', ['-qr', bundle, '.'], imported.fixture.directory);
        const request = path.join(directory, 'apply.json');
        fs.writeFileSync(request, JSON.stringify({ operation: 'apply', source: { type: 'zip', ref: 'request-bundle:website.zip' }, slug: site.id, name: site.id, activate: true, remove_default_content: true, materialize_dependencies: true, theme_materialization: 'block' }));
        let stdout;
        try {
          stdout = wpRun(['static-site-importer', 'import', `--request=${request}`]).stdout;
        } catch (error) {
          // A failed import exits non-zero; its receipt on stdout carries the actual error.
          stdout = String(error.stdout ?? '');
        }
        fs.writeFileSync(path.join(directory, 'import-receipt.txt'), stdout);
        let receipt = null;
        try { receipt = JSON.parse(stdout.trim().split('\n').at(-1)); } catch {}
        if (receipt?.status !== 'completed' || receipt.response?.success !== true) {
          const error = receipt?.response?.error ?? receipt?.error;
          throw new Error(`Disposable WordPress import failed: ${error ? JSON.stringify(error) : 'no import receipt'}; see import-receipt.txt`);
        }
        const evalJson = code => JSON.parse(wpRun(['eval', code]).stdout.trim());
        const inventory = () => evalJson(`require ${JSON.stringify(path.join(root, 'lib/outcome-inventory.php'))}; echo wp_json_encode(ssi_collect_outcome_inventory());`);
        const mappings = evalJson('echo wp_json_encode(array_map(function($p){return array("route"=>wp_parse_url(get_permalink($p), PHP_URL_PATH),"postId"=>$p->ID,"postType"=>$p->post_type,"sourceRoute"=>(string)get_post_meta($p->ID,"_static_site_importer_source_route",true));}, get_posts(array("post_type"=>array("post","page"),"numberposts"=>-1))));');
        const routes = site.routes ?? mappings.map(row => row.route);
        const activeTheme = wpRun(['option', 'get', 'stylesheet']).stdout.trim();
        const themeCode = `require ${JSON.stringify(path.join(root, 'lib/theme-independence.php'))}; `;
        const bindingsPath = path.join(imported.fixture.directory, 'outcome-bindings.json');
        const bindings = fs.existsSync(bindingsPath) ? json(bindingsPath) : {};
        // Each imported page names the source file it came from; that retained
        // source document is the authority for what its head authored.
        const fixtureRoot = path.resolve(imported.fixture.directory);
        // The retained source site, served with its own CSS, is what candidate
        // readability is compared against.
        const sourceOrigin = `http://127.0.0.1:${await freePort()}`;
        sourceServer = spawn('php', ['-S', sourceOrigin.slice(7), '-t', fixtureRoot, path.join(root, 'lib/source-include-router.php')], { stdio: 'ignore' });
        sourceServer.on('error', () => {});
        const captureReceipt = readOutcomeCapture(imported.fixture).receipt;
        const manifest = path.join(directory, 'routes.json');
        fs.writeFileSync(manifest, JSON.stringify({ routes }));
        server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', wpDir], { stdio: 'ignore' });
        server.on('error', () => {});
        let ready = false;
        for (let attempt = 0; attempt < 100; attempt++) {
          try { await fetch(`${origin}/wp-login.php`); ready = true; break; } catch { await new Promise(resolve => setTimeout(resolve, 100)); }
        }
        for (let attempt = 0; ready && attempt < 100; attempt++) {
          try { await fetch(sourceOrigin); break; } catch { if (attempt === 99) ready = false; await new Promise(resolve => setTimeout(resolve, 100)); }
        }
        if (!ready) throw new Error('Disposable PHP server did not start');
        // The served source expands shared parts, so it is the document a visitor saw.
        const documents = Object.fromEntries((await Promise.all(mappings.map(async ({ route, sourceRoute }) => {
          const file = sourceRoute ? path.resolve(fixtureRoot, sourceRoute) : '';
          if (!file.startsWith(fixtureRoot + path.sep) || !fs.existsSync(file)) return null;
          const sourceUrl = `${sourceOrigin}/${path.relative(fixtureRoot, file).split(path.sep).map(encodeURIComponent).join('/')}`;
          const response = await fetch(sourceUrl);
          if (!response.ok) return null;
          return [route, { html: await response.text(), sourceUrl, originalUrl: captureReceipt?.routes?.find(row => row.path?.replace(/^website\//, '') === sourceRoute.replace(/^\//, ''))?.url }];
        }))).filter(Boolean));
        const bindingBrowser = await chromium.launch();
        let derived;
        try {
          derived = await deriveOutcomeBindings({ fixture: imported.fixture, documents, sourceOrigin, candidateOrigin: origin, browser: bindingBrowser, bindings });
        } finally { await bindingBrowser.close(); }
        fs.writeFileSync(manifest, JSON.stringify({ routes, ...(derived.widths ? { widths: derived.widths } : {}) }));
        return { close, config: {
          directory, manifest, evidenceRoot: directory, candidateOrigin: origin,
          ...(derived.widths ? { widths: derived.widths } : {}),
          runtimeIdentity: `WordPress ${runtime.wordpress}; ${runtime.database}`,
          authenticate: async ({ page }) => {
            await page.goto(`${origin}/wp-login.php`);
            await page.locator('#user_login').fill('admin');
            await page.locator('#user_pass').fill('password');
            await Promise.all([page.waitForURL('**/wp-admin/**'), page.locator('#wp-submit').click()]);
          },
          routeToPost: mappings, candidateInventory: async () => mappings,
          outcomes: {
            ...derived, collectInventory: inventory,
            sourceHosts: derived.sourceHosts ?? (site.source_url ? [new URL(site.source_url).host] : []),
            themeIndependence: {
              theme: 'twentytwentyfive',
              snapshot: async () => evalJson(`${themeCode}echo wp_json_encode(ssi_theme_owner_snapshot());`),
              activate: async () => wpRun(['theme', 'activate', 'twentytwentyfive']),
              restore: async () => wpRun(['theme', 'activate', activeTheme]),
            },
          },
        } };
      } catch (error) { await close(); throw error; }
    },
  };
}
