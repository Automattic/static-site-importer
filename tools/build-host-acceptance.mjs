import { build } from 'esbuild';
import { createHash } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('..', import.meta.url));
export const HOST_ACCEPTANCE_API = 'static-site-importer/host-acceptance/v1';

/** The WordPress build runs npm assets before production dependency pruning. */
export async function buildHostAcceptance({ outputDirectory = path.join(root, 'assets/host-acceptance') } = {}) {
  const plugin = await readFile(path.join(root, 'static-site-importer.php'), 'utf8');
  const version = plugin.match(/^\s*\* Version:\s*([0-9.]+)/m)?.[1];
  if (!version) throw new Error('SSI version header is missing.');
  await mkdir(outputDirectory, { recursive: true });
  const entry = path.join(outputDirectory, 'index.mjs');
  await build({
    absWorkingDir: root,
    entryPoints: ['lib/host-acceptance.mjs'],
    outfile: entry,
    bundle: true,
    platform: 'node',
    target: 'node22',
    format: 'esm',
    external: ['playwright'],
    // Bundled CommonJS dependencies retain their native Node require semantics.
    banner: { js: 'import { createRequire as __ssiCreateRequire } from "node:module"; const require = __ssiCreateRequire(import.meta.url);' },
  });
  const bytes = await readFile(entry);
  const manifest = {
    schema: HOST_ACCEPTANCE_API,
    version,
    entry: 'index.mjs',
    sha256: createHash('sha256').update(bytes).digest('hex'),
    node: '>=22',
    peerDependencies: { playwright: '>=1.52' },
    exports: ['runExistingRuntimeAcceptance', 'consumeExistingRuntimeAcceptance', 'ACCEPTANCE_SCHEMA', 'HOST_ACCEPTANCE_API'],
  };
  await writeFile(path.join(outputDirectory, 'manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`);
  return manifest;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const manifest = await buildHostAcceptance();
  console.log(`Built SSI ${manifest.version} host acceptance ${manifest.sha256}`);
}
