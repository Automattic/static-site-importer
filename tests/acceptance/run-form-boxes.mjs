import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const consumerRoot = resolve(process.env.SSI_FORM_BOXES_CONSUMER_ROOT || root);
const { WP_CODEBOX_CLI: cli, STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH: compiler, SSI_FORM_BOXES_JETPACK_ROOT: jetpack } = process.env;
if (!cli || !compiler || !jetpack) throw new Error('Declare WP_CODEBOX_CLI, STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH and SSI_FORM_BOXES_JETPACK_ROOT.');
const evidence = process.env.SSI_FORM_BOXES_EVIDENCE || mkdtempSync(join(tmpdir(), 'ssi-form-boxes-'));
const input = join(evidence, 'workload.json');
writeFileSync(input, JSON.stringify({
    schema: 'wp-codebox/wordpress-workload-run/v1', wordpress_version: 'latest',
    blueprint: { steps: [{ step: 'defineWpConfigConsts', consts: { SSI_FORM_BOXES_DISPOSABLE: true } }] },
    mounts: [
        { source: consumerRoot, target: '/wordpress/wp-content/plugins/static-site-importer', mode: 'readonly' },
        { source: join(root, 'tests'), target: '/wordpress/form-acceptance', mode: 'readonly' },
        { source: resolve(compiler), target: '/wordpress/wp-content/plugins/owning-compiler', mode: 'readonly' },
        { source: resolve(jetpack), target: '/wordpress/wp-content/plugins/jetpack', mode: 'readonly' },
    ],
    steps: [{ command: 'wordpress.run-php', args: [`code-file=${join(root, 'tests/acceptance/form-boxes-wordpress.php')}`] }],
}));
const run = spawnSync(process.execPath, [cli, 'run-wordpress-workload', '--input-file', input, '--artifacts', join(evidence, 'artifacts'), '--format=json'], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024, timeout: 300_000 });
writeFileSync(join(evidence, 'runtime-result.json'), run.stdout || run.stderr);
if (run.error) throw run.error;
const result = JSON.parse(run.stdout);
if (run.status || result.success !== true) throw new Error(result.stepFailures?.[0]?.error?.message?.slice(0,5000) || result.error?.message || 'Codebox workload failed; inspect runtime-result.json.');
const output = (result.executions || []).map(step => step.stdout).find(text => text?.includes('"forms"'));
if (!output) throw new Error('Real WordPress workload emitted no rendered form evidence.');
writeFileSync(join(evidence, 'rendered.json'), JSON.stringify(JSON.parse(output), null, 2));
console.log(evidence);
const browser = spawnSync(process.execPath, ['tests/acceptance/form-boxes-browser.mjs', join(evidence, 'rendered.json')], { cwd: root, stdio: 'inherit', env: process.env });
if (browser.status) process.exitCode = browser.status;
