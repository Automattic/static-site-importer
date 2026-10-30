import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { cp, mkdir, readFile, rm, stat, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

// Immutable consumer runtime inputs shared by release and outcome gates.
export const PINNED = Object.freeze({
  wordpressVersion: '7.1',
  wordpressUrl: 'https://wordpress.org/wordpress-7.1.tar.gz',
  wordpressSha256: '05a5f89138f632b7329f1202f2a0553c5f7fe4daf8e4b9ca7ebae9b9466b9e86',
  wpCliVersion: '2.12.0',
  wpCliUrl: 'https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar',
  wpCliSha256: 'ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c',
  // The WordPress.org archive carries db.copy; GitHub monorepo tags do not.
  sqliteIntegrationVersion: '3.0.1',
  sqliteIntegrationUrl: 'https://downloads.wordpress.org/plugin/sqlite-database-integration.3.0.1.zip',
  sqliteIntegrationSha256: '8703c196d3c666be9e60ced60cafabc726b23193179bfa99147e2d9feb38aecb',
});

export function wpPhpArgs(wpCliPath, args) {
  return ['-d', 'display_errors=0', '-d', 'log_errors=0', wpCliPath, ...args];
}

const sha256 = bytes => createHash('sha256').update(bytes).digest('hex');
async function pathExists(file) {
  try { await stat(file); return true; } catch { return false; }
}

async function downloadPinned(url, expected, destination, label) {
  const response = await fetch(url);
  if (!response.ok) throw new Error(`Downloading ${label} failed: HTTP ${response.status} for ${url}`);
  const bytes = Buffer.from(await response.arrayBuffer());
  const actual = sha256(bytes);
  if (actual !== expected) throw new Error(`${label} sha256 mismatch: expected ${expected}, got ${actual}. Refusing to gate with unpinned inputs.`);
  await writeFile(destination, bytes);
}

/** Provision a fresh SQLite-backed WordPress. The caller owns workDir and any
 * retained downloads; close removes the runtime, including its database.
 * Provisioning failures remove partial runtimes before rejecting.
 */
export async function provisionWordPress({ workDir, origin = 'http://localhost:8090', title = 'SSI Release Import Gate', phpBinary = 'php', log = () => {} }) {
  const runtimeDir = join(workDir, 'runtime');
  const downloadsDir = join(workDir, 'downloads');
  if (await pathExists(runtimeDir)) throw new Error(`Runtime directory must be fresh: ${runtimeDir}`);
  await mkdir(runtimeDir, { recursive: true });
  const close = () => rm(runtimeDir, { recursive: true, force: true });
  try {
    await mkdir(downloadsDir, { recursive: true });
    const wordpressArchive = join(downloadsDir, `wordpress-${PINNED.wordpressVersion}.tar.gz`);
    const wpCliPath = join(downloadsDir, 'wp-cli.phar');
    const sqliteArchive = join(downloadsDir, `sqlite-database-integration-${PINNED.sqliteIntegrationVersion}.zip`);
    for (const [url, hash, destination, label] of [
      [PINNED.wordpressUrl, PINNED.wordpressSha256, wordpressArchive, `WordPress ${PINNED.wordpressVersion}`],
      [PINNED.wpCliUrl, PINNED.wpCliSha256, wpCliPath, `WP-CLI ${PINNED.wpCliVersion}`],
      [PINNED.sqliteIntegrationUrl, PINNED.sqliteIntegrationSha256, sqliteArchive, `SQLite integration ${PINNED.sqliteIntegrationVersion}`],
    ]) {
      if (!await pathExists(destination) || sha256(await readFile(destination)) !== hash) await downloadPinned(url, hash, destination, label);
      log(`OK ${label} pinned (${hash.slice(0, 12)}...)`);
    }
    const wpDir = join(runtimeDir, 'wordpress');
    await mkdir(wpDir, { recursive: true });
    const run = (command, args, cwd) => ({ stdout: execFileSync(command, args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }), stderr: '' });
    run('tar', ['-xzf', wordpressArchive, '-C', wpDir, '--strip-components=1']);
    run('unzip', ['-q', sqliteArchive, '-d', join(wpDir, 'wp-content', 'plugins')]);
    await cp(join(wpDir, 'wp-content', 'plugins', 'sqlite-database-integration', 'db.copy'), join(wpDir, 'wp-content', 'db.php'));
    const wpRun = args => run(phpBinary, wpPhpArgs(wpCliPath, args), wpDir);
    // The SQLite drop-in owns the database only after WordPress boots.
    wpRun(['config', 'create', '--skip-check', '--dbname=wordpress', '--dbuser=wordpress', '--dbpass=wordpress', '--dbhost=localhost']);
    wpRun(['core', 'install', `--url=${origin}`, `--title=${title}`, '--admin_user=admin', '--admin_password=password', '--admin_email=admin@example.test', '--skip-email']);
    const wordpress = wpRun(['core', 'version']).stdout.trim();
    const database = wpRun(['eval', 'if ( ! defined( "SQLITE_DB_DROPIN_VERSION" ) ) { echo "mysql"; return; } $plugin = get_file_data( WP_CONTENT_DIR . "/plugins/sqlite-database-integration/load.php", array( "Version" => "Version" ) ); echo "sqlite plugin " . $plugin["Version"] . " (drop-in " . SQLITE_DB_DROPIN_VERSION . ")";']).stdout.trim();
    if (!/sqlite/i.test(database)) throw new Error(`expected the SQLite drop-in to own the database, wp db engine reports: ${database}`);
    return { wpDir, wpCliPath, wpRun, wordpress, database, close };
  } catch (error) {
    await close();
    throw error;
  }
}
