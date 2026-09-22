// SSI release import gate (issue #1808).
//
// Proves that the html-site-import runtime-profile zip — the artifact the
// downstream WordPress.com import runner installs — imports a real fixture
// through the exact command that runner drives:
//
//   wp static-site-importer import --request=<file>   (operation: plan)
//   wp static-site-importer import --request=<file>   (operation: apply)
//
// against a request-bundle: zip source, asserting the
// static-site-importer/import-cli-receipt/v1 receipt contract on the last
// stdout line of each command.
//
// The gate runs on a real PHP CLI (proc_open works), so the default host loop
// that re-executes WP-CLI as a fresh runtime, and the compile worker fanout,
// are both actually exercised. WordPress Playground / php-wasm cannot run
// that path: proc_open exists there but spawning is non-functional (see the
// coverage statement this script prints and records in its evidence). The
// fixture is a five-page site so the compile step shards into more than one
// worker.
//
// Usage: node tools/run-release-import-gate.mjs --zip <built zip> --output <evidence dir>
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { cpSync, readdirSync } from 'node:fs';
import { cp, mkdir, mkdtemp, readFile, rm, stat, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { basename, dirname as pathDirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const RECEIPT_SCHEMA = 'static-site-importer/import-cli-receipt/v1';
export const PROFILE_NAME = 'html-site-import';
export const FANOUT_MIN_BATCHES = 2;

const ROOT = resolve(pathDirname(fileURLToPath(import.meta.url)), '..');

// The runtime a downstream consumer builds SSI into: WordPress, WP-CLI, and the
// SQLite drop-in at the same versions. Every input is an immutable URL pinned
// by digest, so the gate fails on a changed artifact rather than drifting.
export const PINNED = Object.freeze({
  wordpressVersion: '7.1',
  wordpressUrl: 'https://wordpress.org/wordpress-7.1.tar.gz',
  wordpressSha256: '05a5f89138f632b7329f1202f2a0553c5f7fe4daf8e4b9ca7ebae9b9466b9e86',
  wpCliVersion: '2.12.0',
  wpCliUrl: 'https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar',
  wpCliSha256: 'ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c',
  // The WordPress.org build, not a GitHub tag: the wordpress.org zip ships the
  // db.copy drop-in, while the GitHub tags are a monorepo without it.
  sqliteIntegrationVersion: '3.0.1',
  sqliteIntegrationUrl: 'https://downloads.wordpress.org/plugin/sqlite-database-integration.3.0.1.zip',
  sqliteIntegrationSha256: '8703c196d3c666be9e60ced60cafabc726b23193179bfa99147e2d9feb38aecb',
});

export const PLAN_REQUEST = Object.freeze({
  operation: 'plan',
  source: { type: 'zip', ref: 'request-bundle:website.zip' },
  slug: 'imported-site',
  name: 'Imported Site',
});

export const APPLY_REQUEST = Object.freeze({
  operation: 'apply',
  source: { type: 'zip', ref: 'request-bundle:website.zip' },
  slug: 'imported-site',
  name: 'Imported Site',
  site_title: 'Imported Site',
  activate: true,
  remove_default_content: true,
  materialize_dependencies: true,
  theme_materialization: 'block',
});

export function parseReceipt(stdout) {
  const lines = String(stdout ?? '').split(/\r?\n/).filter((line) => line.trim() !== '');
  if (lines.length === 0) return null;
  let decoded;
  try {
    decoded = JSON.parse(lines[lines.length - 1]);
  } catch {
    return null;
  }
  return decoded && typeof decoded === 'object' && !Array.isArray(decoded) ? decoded : null;
}

export function receiptContractFailures(receipt, operation) {
  if (!receipt) return ['last stdout line does not parse as a JSON object'];
  const payload = receipt.response && typeof receipt.response === 'object' && !Array.isArray(receipt.response) ? receipt.response : null;
  const failures = [];
  if (receipt.schema !== RECEIPT_SCHEMA) failures.push(`schema is ${JSON.stringify(receipt.schema) ?? '<none>'}, expected ${RECEIPT_SCHEMA}`);
  if (receipt.status !== 'completed') failures.push(`status is ${JSON.stringify(receipt.status) ?? '<none>'}, expected "completed"`);
  if (!payload || payload.success !== true) failures.push('response.success is not true');
  const section = payload ? payload[operation] : undefined;
  if (typeof section !== 'object' || section === null || Array.isArray(section)) failures.push(`response.${operation} is not an object`);
  return failures;
}

export function zipManifestFailures(zipListing, manifest, profileName = PROFILE_NAME) {
  if (!manifest || typeof manifest !== 'object' || typeof manifest.profiles !== 'object' || manifest.profiles === null) {
    return ['packaged runtime-package-manifest.json is not a runtime package manifest'];
  }
  const profile = manifest.profiles[profileName];
  if (!profile) return [`profile ${profileName} is missing from the packaged runtime-package-manifest.json`];
  const packageRoot = String(manifest.package_root ?? '').replace(/^\/+|\/+$/g, '');
  const files = new Set(zipListing.map((entry) => String(entry).replace(/\\/g, '/')).filter((entry) => !entry.endsWith('/')));
  const presentUnderRoot = (relative) => files.has(packageRoot ? `${packageRoot}/${relative}` : relative);
  const failures = [];
  for (const selector of profile.selectors ?? []) {
    if (selector?.type === 'file' && !presentUnderRoot(selector.path)) failures.push(`profile selector file missing from zip: ${selector.path}`);
  }
  for (const required of profile.required_files ?? []) {
    if (!presentUnderRoot(required)) failures.push(`profile required_files entry missing from zip: ${required}`);
  }
  return failures;
}

export function coverageStatement({ compileBatches, applySteps, planSteps }) {
  return {
    schema: 'static-site-importer/release-import-gate-coverage/v1',
    exercised: [
      `Default import host loop: wp static-site-importer import --request=<file> on a real PHP CLI (plan steps=${planSteps}, apply steps=${applySteps}); steps > 1 proves the fresh-runtime WP-CLI re-exec ran.`,
      `Compile worker fanout: ${compileBatches} worker batches spawned through proc_open (real subprocess re-exec of the WP-CLI phar).`,
      `Receipt contract ${RECEIPT_SCHEMA} asserted completed, response.success, and response.plan / response.result objects.`,
    ],
    not_exercised: [
      'WordPress Playground / php-wasm cannot run this contract: proc_open exists there but spawning is non-functional (probe: proc_open returns a resource, produces no output, exits 127; PHP_BINARY empty; $_SERVER[argv][0] unset), so the default host loop fails there with "A fresh import runtime exited with code 1 without a JSON response". Under php-wasm SSI auto-disables compile fanout ($_SERVER[argv][0] unset) and subprocess-less hosts drive import --single-step --state=<file> instead.',
      'The WP Codebox fixture-matrix gate (solved-site-promotion) continues to cover the php-wasm path through lower-level commands; it cannot prove this consumer contract.',
      'Dotcom infrastructure differences (MariaDB, multi-site) are out of scope: this gate uses a disposable single-site WordPress with the SQLite integration drop-in.',
    ],
    statements: [
      `Import path exercised: real PHP CLI, default host loop (fresh-runtime re-exec), compile worker fanout (${compileBatches} batches).`,
      'Path NOT exercised: php-wasm/Playground spawn-free runtime (cannot spawn; covered by the fixture-matrix gate at lower-level commands).',
      'Database: disposable SQLite-backed single-site WordPress, not dotcom MariaDB.',
    ],
  };
}

export function parseArguments(argv) {
  const options = {
    zip: '',
    output: '',
    workDir: '',
    fixture: join(ROOT, 'tests', 'fixtures', 'wordpress-is-dead'),
    keep: false,
  };
  for (let index = 0; index < argv.length; index += 1) {
    const value = argv[index];
    if (value === '--zip') options.zip = requiredValue(argv, ++index, value);
    else if (value === '--output') options.output = requiredValue(argv, ++index, value);
    else if (value === '--work-dir') options.workDir = requiredValue(argv, ++index, value);
    else if (value === '--fixture') options.fixture = requiredValue(argv, ++index, value);
    else if (value === '--keep') options.keep = true;
    else if (value === '--help') options.help = true;
    else throw new Error(`Unknown argument: ${value}`);
  }
  if (!options.help) {
    if (!options.zip) throw new Error('--zip=<runtime profile zip> is required');
    if (!options.output) throw new Error('--output=<evidence directory> is required');
  }
  return options;
}

function requiredValue(argv, index, flag) {
  if (!argv[index] || argv[index].startsWith('--')) throw new Error(`${flag} requires a value`);
  return argv[index];
}

function run(command, args, options = {}) {
  const label = `${command} ${args.join(' ')}`;
  try {
    return { stdout: execFileSync(command, args, { encoding: 'utf8', cwd: options.cwd, stdio: ['ignore', 'pipe', 'pipe'] }), stderr: '' };
  } catch (error) {
    const detail = [bounded(error.stdout), bounded(error.stderr), error.message].filter(Boolean).join('\n');
    throw new Error(`${label} failed (exit ${error.status ?? '?'}):\n${bounded(detail, 8000)}`);
  }
}

// A failing import command is evidence, not a crash: the receipt itself
// reports the failure, so the gate parses it and fails on the contract.
function runImportEvidentially(phpBinary, wpCliPath, args, cwd) {
  try {
    return { stdout: execFileSync(phpBinary, wpPhpArgs(wpCliPath, args), { encoding: 'utf8', cwd, stdio: ['ignore', 'pipe', 'pipe'] }), stderr: '' };
  } catch (error) {
    return { stdout: String(error.stdout ?? ''), stderr: String(error.stderr ?? error.message) };
  }
}

// PHP-level diagnostics would corrupt receipt parsing and drown job logs;
// WP-CLI renders its own errors, so the display channel can stay off.
function wpPhpArgs(wpCliPath, args) {
  return ['-d', 'display_errors=0', '-d', 'log_errors=0', wpCliPath, ...args];
}

function bounded(value, limit = 4000) {
  const text = String(value ?? '').trim();
  if (!text) return '';
  return text.length <= limit ? text : `${text.slice(0, limit)}\n...[truncated ${text.length - limit} characters]`;
}

function sha256(bytes) {
  return createHash('sha256').update(bytes).digest('hex');
}

async function pathExists(path) {
  try {
    await stat(path);
    return true;
  } catch {
    return false;
  }
}

async function downloadPinned(url, expectedSha256, destination, label) {
  const response = await fetch(url);
  if (!response.ok) throw new Error(`Downloading ${label} failed: HTTP ${response.status} for ${url}`);
  const bytes = Buffer.from(await response.arrayBuffer());
  const actual = sha256(bytes);
  if (actual !== expectedSha256) {
    throw new Error(`${label} sha256 mismatch: expected ${expectedSha256}, got ${actual}. Refusing to gate with unpinned inputs.`);
  }
  await writeFile(destination, bytes);
}

export function buildWebsiteBundle(fixtureDirectory, stagingRoot) {
  const denied = new Set(['fixture.json', 'generated-artifact-metadata.json', '.DS_Store']);
  const website = join(stagingRoot, 'website');
  const entries = readdirSync(fixtureDirectory);
  const copied = [];
  for (const entry of entries) {
    if (denied.has(entry)) continue;
    cpSync(join(fixtureDirectory, entry), join(website, entry));
    copied.push(entry);
  }
  if (!copied.includes('index.html')) throw new Error(`Fixture ${fixtureDirectory} has no index.html to serve as the site entrypoint.`);
  return { website, copied };
}

function zipList(zipPath) {
  return run('unzip', ['-Z1', zipPath]).stdout.split(/\r?\n/).filter((line) => line.trim() !== '');
}

function zipReadEntry(zipPath, entry) {
  return execFileSync('unzip', ['-p', zipPath, entry], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}

export async function runGate(options, dependencies = {}) {
  const log = dependencies.log ?? ((message) => process.stdout.write(`${message}\n`));
  const failures = [];
  const evidence = {
    schema: 'static-site-importer/release-import-gate-evidence/v1',
    zip: { path: resolve(options.zip), sha256: sha256(await readFile(options.zip)) },
    fixture: { directory: resolve(options.fixture) },
    requests: { plan: PLAN_REQUEST, apply: APPLY_REQUEST },
    runtime: { pinned: PINNED },
    checks: {},
  };

  const workDir = options.workDir || await (dependencies.mkdtemp ?? mkdtemp)(join(tmpdir(), 'ssi-release-import-gate-'));
  const runtimeDir = join(workDir, 'runtime');
  const downloadsDir = join(workDir, 'downloads');
  const requestsDir = join(workDir, 'requests');
  const bundleDir = join(workDir, 'bundle');
  await mkdir(runtimeDir, { recursive: true });
  await mkdir(downloadsDir, { recursive: true });
  await mkdir(requestsDir, { recursive: true });

  // 1. Artifact contract: the zip must carry every file the html-site-import
  //    profile declares, per the manifest packaged inside the zip itself.
  log('== Artifact contract ==');
  const listing = zipList(options.zip);
  const packageRoot = zipPackageRoot(options.zip);
  const manifest = JSON.parse(zipReadEntry(options.zip, join(packageRoot, 'runtime-package-manifest.json')));
  const manifestFailures = zipManifestFailures(listing, manifest, PROFILE_NAME);
  evidence.checks.zip_manifest = { failures: manifestFailures, required_files: manifest.profiles?.[PROFILE_NAME]?.required_files?.length ?? 0 };
  if (manifestFailures.length > 0) {
    manifestFailures.forEach((failure) => log(`FAIL ${failure}`));
    return finish(log, options, workDir, evidence, [...failures, ...manifestFailures.map((failure) => `zip: ${failure}`)]);
  }
  log(`OK packaged profile ${PROFILE_NAME}: ${manifest.profiles[PROFILE_NAME].required_files.length} required_files present (${listing.length} zip entries)`);

  // 2. Stage a disposable real PHP WordPress runtime from pinned inputs.
  log('== Runtime staging ==');
  const wordpressArchive = join(downloadsDir, `wordpress-${PINNED.wordpressVersion}.tar.gz`);
  const wpCliPath = join(downloadsDir, 'wp-cli.phar');
  const sqliteArchive = join(downloadsDir, `sqlite-database-integration-${PINNED.sqliteIntegrationVersion}.zip`);
  for (const [url, hash, destination, label] of [
    [PINNED.wordpressUrl, PINNED.wordpressSha256, wordpressArchive, `WordPress ${PINNED.wordpressVersion}`],
    [PINNED.wpCliUrl, PINNED.wpCliSha256, wpCliPath, `WP-CLI ${PINNED.wpCliVersion}`],
    [PINNED.sqliteIntegrationUrl, PINNED.sqliteIntegrationSha256, sqliteArchive, `SQLite integration ${PINNED.sqliteIntegrationVersion}`],
  ]) {
    if (!await pathExists(destination) || sha256(await readFile(destination)) !== hash) {
      await downloadPinned(url, hash, destination, label);
    }
    log(`OK ${label} pinned (${hash.slice(0, 12)}...)`);
  }

  const wpDir = join(runtimeDir, 'wordpress');
  await mkdir(wpDir, { recursive: true });
  run('tar', ['-xzf', wordpressArchive, '-C', wpDir, '--strip-components=1']);
  // The wordpress.org zip unpacks to sqlite-database-integration/ directly.
  run('unzip', ['-q', sqliteArchive, '-d', join(wpDir, 'wp-content', 'plugins')]);
  const sqlitePlugin = join(wpDir, 'wp-content', 'plugins', 'sqlite-database-integration');
  await cp(join(sqlitePlugin, 'db.copy'), join(wpDir, 'wp-content', 'db.php'));

  const php = dependencies.phpBinary ?? 'php';
  const wpRun = (args) => run(php, wpPhpArgs(wpCliPath, args), { cwd: wpDir });

  // --skip-check: the drop-in owns the database only after WordPress boots,
  // so wp-cli's pre-boot mysqli check is meaningless here (and hostile on
  // machines that happen to run a local MySQL).
  wpRun(['config', 'create', '--skip-check', '--dbname=wordpress', '--dbuser=wordpress', '--dbpass=wordpress', '--dbhost=localhost']);
  wpRun(['core', 'install', '--url=http://localhost:8090', '--title=SSI Release Import Gate', '--admin_user=admin', '--admin_password=password', '--admin_email=admin@example.test', '--skip-email']);
  evidence.runtime.wordpress = wpRun(['core', 'version']).stdout.trim();
  evidence.runtime.database = wpRun(['eval', 'echo defined("SQLITE_DB_DROPIN_VERSION") ? "sqlite " . SQLITE_DB_DROPIN_VERSION : "mysql";']).stdout.trim();
  if (!/sqlite/i.test(evidence.runtime.database)) {
    return finish(log, options, workDir, evidence, [...failures, `expected the SQLite drop-in to own the database, wp db engine reports: ${evidence.runtime.database}`]);
  }
  log(`OK WordPress ${evidence.runtime.wordpress} installed (${evidence.runtime.database})`);

  // 3. Install the artifact under test the way the consumer does.
  log('== Artifact install ==');
  wpRun(['plugin', 'install', resolve(options.zip), '--activate']);
  const activePlugins = wpRun(['plugin', 'list', '--status=active', '--field=name']).stdout.trim().split('\n');
  evidence.checks.plugin_install = { active: activePlugins.includes('static-site-importer') };
  if (!activePlugins.includes('static-site-importer')) {
    return finish(log, options, workDir, evidence, [...failures, `static-site-importer is not active after installing ${basename(options.zip)}`]);
  }
  evidence.runtime.plugin_version = wpRun(['plugin', 'get', 'static-site-importer', '--field=version']).stdout.trim();
  log(`OK static-site-importer ${evidence.runtime.plugin_version} installed from the built zip and active`);

  // 4. Stage the consumer requests beside a request-bundle website.zip.
  const bundle = buildWebsiteBundle(options.fixture, bundleDir);
  const websiteZip = join(requestsDir, 'website.zip');
  run('zip', ['-rq', websiteZip, '.'], { cwd: bundleDir });
  await writeFile(join(requestsDir, 'plan-request.json'), `${JSON.stringify(PLAN_REQUEST)}\n`);
  await writeFile(join(requestsDir, 'apply-request.json'), `${JSON.stringify(APPLY_REQUEST)}\n`);
  evidence.fixture.files = bundle.copied;
  evidence.fixture.website_zip_sha256 = sha256(await readFile(websiteZip));
  log(`OK request bundle built from ${basename(options.fixture)} (${bundle.copied.length} files)`);

  const importCommand = (requestName) => ['static-site-importer', 'import', `--request=${join(requestsDir, requestName)}`];
  const importFailures = [];
  evidence.checks.imports = {};

  // 5. Plan through the exact consumer command.
  log('== Import (plan) ==');
  const planRun = runImportEvidentially(php, wpCliPath, importCommand('plan-request.json'), wpDir);
  const planReceipt = parseReceipt(planRun.stdout);
  const planFailures = receiptContractFailures(planReceipt, 'plan');
  evidence.checks.imports.plan = receiptEvidence(planReceipt, planRun, planFailures);
  if (planFailures.length > 0) {
    importFailures.push(...planFailures.map((failure) => `plan: ${failure}`));
    log(`FAIL plan receipt contract: ${planFailures.join('; ')}`);
    log(bounded(planRun.stdout));
    log(bounded(planRun.stderr));
  } else {
    log(`OK plan receipt completed (steps=${planReceipt.steps}, plan pages=${planReceipt.response.plan.pages?.length ?? 0})`);
  }

  // 6. Apply through the exact consumer command.
  log('== Import (apply) ==');
  const applyRun = runImportEvidentially(php, wpCliPath, importCommand('apply-request.json'), wpDir);
  const applyReceipt = parseReceipt(applyRun.stdout);
  const applyFailures = receiptContractFailures(applyReceipt, 'result');
  const compileBatches = applyReceipt?.response?.artifact_run?.work?.compile_batches ?? 0;
  evidence.checks.imports.apply = receiptEvidence(applyReceipt, applyRun, applyFailures);
  evidence.checks.imports.apply.compile_batches = compileBatches;
  if (compileBatches < FANOUT_MIN_BATCHES) {
    applyFailures.push(`compile_batches=${compileBatches}, expected >= ${FANOUT_MIN_BATCHES}: the multi-page fixture must shard the compile across worker subprocesses, otherwise the gate silently skips the worker path`);
  }
  if (applyFailures.length > 0) {
    importFailures.push(...applyFailures.map((failure) => `apply: ${failure}`));
    log(`FAIL apply receipt contract: ${applyFailures.join('; ')}`);
    log(bounded(applyRun.stdout));
    log(bounded(applyRun.stderr));
  } else {
    log(`OK apply receipt completed (steps=${applyReceipt.steps}, result pages=${applyReceipt.response.result.page_count}, compile worker batches=${compileBatches})`);
  }

  // 7. The import must have actually materialized the site.
  const themeSlug = applyReceipt?.response?.result?.theme_slug ?? '';
  if (themeSlug !== '') {
    const materialized = await pathExists(join(wpDir, 'wp-content', 'themes', themeSlug, 'style.css'));
    evidence.checks.materialization = { theme_slug: themeSlug, style_css_present: materialized };
    if (!materialized) {
      importFailures.push(`apply: materialized theme ${themeSlug} has no style.css under wp-content/themes`);
      log(`FAIL materialized theme ${themeSlug} is missing style.css`);
    } else {
      log(`OK materialized theme ${themeSlug} exists under wp-content/themes`);
    }
  }

  failures.push(...importFailures);

  // 8. Coverage statement: say exactly which path ran and what did not.
  const coverage = coverageStatement({
    compileBatches: failures.some((failure) => failure.startsWith('apply:')) && compileBatches === 0 ? 0 : compileBatches,
    applySteps: applyReceipt?.steps ?? 0,
    planSteps: planReceipt?.steps ?? 0,
  });
  evidence.coverage = coverage;
  log('== Coverage statement ==');
  coverage.statements.forEach((statement) => log(statement));

  return finish(log, options, workDir, evidence, failures);
}

async function finish(log, options, workDir, evidence, failures) {
  await mkdir(options.output, { recursive: true });
  const evidencePath = join(options.output, 'release-import-gate-evidence.json');
  await writeFile(evidencePath, `${JSON.stringify(evidence, null, 2)}\n`);
  log(`Evidence written to ${evidencePath}`);
  if (failures.length > 0) {
    log(`GATE FAIL (${failures.length} failure${failures.length === 1 ? '' : 's'})`);
  } else {
    log('GATE PASS: the html-site-import zip imports through the consumer import --request contract.');
  }
  if (!options.keep) {
    await rm(workDir, { recursive: true, force: true });
  } else {
    log(`Sandbox retained at ${workDir}`);
  }
  if (failures.length > 0) process.exitCode = 1;
  return { failures, evidence };
}

function zipPackageRoot(zipPath) {
  const listing = zipList(zipPath);
  const manifestEntry = listing.find((entry) => entry === 'runtime-package-manifest.json' || entry.endsWith('/runtime-package-manifest.json'));
  if (!manifestEntry) throw new Error('The candidate zip does not contain runtime-package-manifest.json.');
  const root = manifestEntry.slice(0, -'runtime-package-manifest.json'.length).replace(/\/+$/, '');
  return root;
}

function receiptEvidence(receipt, runResult, failures) {
  return {
    failures,
    ...(failures.length === 0 ? { receipt } : {}),
    stdout_bytes: runResult.stdout.length,
  };
}

const isMain = process.argv[1] === fileURLToPath(import.meta.url);
if (isMain) {
  const options = parseArguments(process.argv.slice(2));
  if (options.help) {
    console.log('Usage: node tools/run-release-import-gate.mjs --zip <runtime profile zip> --output <evidence dir> [--work-dir <dir>] [--fixture <dir>] [--keep]');
  } else {
    runGate(options).catch(async (error) => {
      console.error(`Release import gate crashed: ${error.message}`);
      try {
        await mkdir(options.output, { recursive: true });
        await writeFile(
          join(options.output, 'release-import-gate-evidence.json'),
          `${JSON.stringify({ schema: 'static-site-importer/release-import-gate-evidence/v1', crashed: true, error: bounded(error.message, 8000), zip: options.zip }, null, 2)}\n`
        );
      } catch {
        // Evidence persistence is best effort; the crash itself already failed the job.
      }
      process.exit(1);
    });
  }
}
