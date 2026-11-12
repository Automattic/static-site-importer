import { mkdtemp, symlink, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { provisionWordPress } from '../lib/disposable-wordpress.mjs';

const test = process.argv[2];
if (!test) throw new Error('Usage: run-wordpress-runtime-test.mjs <test path>');
const workDir = await mkdtemp(join(tmpdir(), 'ssi-runtime-test-'));
let runtime;
try {
  runtime = await provisionWordPress({ workDir });
  await symlink(resolve('.'), join(runtime.wpDir, 'wp-content/plugins/static-site-importer'));
  runtime.wpRun(['plugin', 'activate', 'static-site-importer']);
  const result = runtime.wpRun(['eval-file', resolve(test)]);
  process.stdout.write(result.stdout);
  process.stderr.write(result.stderr);
} catch (error) {
  console.error(error.stdout || '', error.stderr || '', error.message);
  process.exitCode = 1;
} finally {
  await runtime?.close();
  await rm(workDir, { recursive: true, force: true });
}
