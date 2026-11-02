import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { build } from 'esbuild';
const require = createRequire(import.meta.url);
const { ensureRuntime, registerValidationBlockType, validateMarkup } = require('../lib/gutenberg-block-validation.cjs');
ensureRuntime();
const metadata = JSON.parse(readFileSync(new URL('./fixtures/consent-banner/jetpack/block.json', import.meta.url)));
const bundle = (await build({ stdin: { contents: readFileSync('tests/fixtures/consent-banner/jetpack/save.jsx.txt', 'utf8'), resolveDir: process.cwd(), loader: 'jsx' }, plugins: [{ name: 'jetpack-constants', setup(build) { build.onResolve({ filter: /^\.\/constants$/ }, () => ({ path: 'constants', namespace: 'jetpack' })); build.onLoad({ filter: /.*/, namespace: 'jetpack' }, () => ({ contents: readFileSync('tests/fixtures/consent-banner/jetpack/constants.js.txt', 'utf8'), loader: 'js', resolveDir: process.cwd() })); } }], bundle: true, write: false, format: 'cjs', platform: 'node', packages: 'external', jsx: 'automatic' })).outputFiles[0].text;
const saved = { exports: {} };
new Function('require', 'module', 'exports', bundle)(require, saved, saved.exports);
registerValidationBlockType(metadata.name, { ...metadata, save: saved.exports.default });
test('consent intent plans Jetpack through the provider dependency lifecycle', () => {
 assert.match(execFileSync('php', ['tests/fixtures/consent-banner/lifecycle.php'], { encoding: 'utf8' }), /Consent dependency planned/);
});
test('consent intent serializes against actual Jetpack save and core buttons', () => {
 const markup = execFileSync('php', ['tests/fixtures/consent-banner/markup.php'], { encoding: 'utf8' });
 const result = validateMarkup(markup);
 assert.equal(result.ok, true, JSON.stringify(result.failures));
 assert.equal(result.blocksChecked, 3);
 const upstream = validateMarkup(readFileSync('tests/fixtures/consent-banner/jetpack/serialized.html', 'utf8'));
 assert.equal(upstream.ok, true, JSON.stringify(upstream.failures));
});
