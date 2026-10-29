import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { runExistingRuntimeAcceptance } from '../../lib/run-existing-runtime-acceptance.mjs';
import { interactiveConfig } from './outcome-interactive-config.mjs';
import { consumeExistingRuntimeAcceptance } from '../../lib/existing-runtime-acceptance.mjs';

// Run only against a fresh, caller-provisioned disposable WordPress runtime.
const cli = process.env.STATIC_SITE_IMPORTER_WP_CLI;
const candidateOrigin = process.env.SSI_OUTCOME_ORIGIN;
if (!cli || !candidateOrigin || process.env.SSI_OUTCOME_DISPOSABLE !== '1') throw new Error('Declare disposable WP CLI and SSI_OUTCOME_ORIGIN.');
const root = process.cwd();
const wp = (...args) => execFileSync(cli.split(' ')[0], [...cli.split(' ').slice(1), ...args], { encoding: 'utf8', env: { ...process.env, SSI_OUTCOME_REPOSITORY: root } });
const imported = JSON.parse(wp('eval-file', 'tests/acceptance/outcome-wordpress.php'));
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-outcomes-wordpress-'));
const manifest = path.join(output, 'manifest.json');
fs.writeFileSync(manifest, JSON.stringify({ routes: ['/'] }));
const collectInventory = async () => JSON.parse(wp('eval', `require_once ${JSON.stringify(`${root}/lib/outcome-inventory.php`)}; echo wp_json_encode(ssi_collect_outcome_inventory());`));
const config = { directory: output, outputDirectory: output, evidenceRoot: output, manifest, candidateOrigin, importRunId: 'outcome-real-fixture', authenticate: async () => {}, candidateInventory: async () => [], outcomes: { ...interactiveConfig, collectInventory, sourceHosts: ['source.example', 'cdn.source.example'], aliases: [], telemetryMarkers: ['source-analytics'] } };
config.outcomes.documents['/'].html = imported.source_html;
config.outcomes.documents['/'].decisions = [{
  reason: 'Retain the WordPress accessibility skip link before the source content.',
  expected: { before: [], after: [] },
  actual: { before: [{ link: '/#wp--skip-link--target' }, { text: 'Skip to content' }], after: [] },
}];
const run = async () => {
  const report = await runExistingRuntimeAcceptance(config);
  return { report, outcomes: JSON.parse(fs.readFileSync(path.join(output, report.artifacts.outcomes.path), 'utf8')) };
};
const baseline = await run();
assert.equal(baseline.report.gates.outcomes.status, 'passed', JSON.stringify(baseline.outcomes));
assert.equal(baseline.outcomes.checks.readability.status, 'pass');
assert.equal(baseline.outcomes.checks.full_document.status, 'pass');
assert.equal(baseline.outcomes.checks.full_document.evidence.length, 6, 'all three widths and both schemes');
// The missing fidelity/editor prerequisites remain pending, not fabricated passes.
assert.equal(baseline.report.status, 'pending');
const front = Number(wp('option', 'get', 'page_on_front').trim());
const original = wp('post', 'get', String(front), '--field=post_content');
for (const [mutation, check] of [
  [original.replace('<summary>', '<summary onclick="event.preventDefault()">'), 'control_inventory'],
  [original.replace('Imported WordPress content.', '<span style="color:#eee;background-color:#fff">Low contrast text</span>'), 'readability'],
  [original.replace('<button type="submit"', '<button style="color:#fff;background-color:#fff" type="submit"'), 'readability'],
  [original.replace('action="/wp-admin/admin-post.php"', 'action=""'), 'forms'],
]) {
  assert.notEqual(mutation, original, 'mutation must change imported content');
  try {
    wp('post', 'update', String(front), `--post_content=${mutation}`);
    const { report, outcomes } = await run();
    assert.equal(outcomes.checks[check].status, 'fail', JSON.stringify(outcomes));
    assert.equal(outcomes.checks[check === 'forms' ? 'control_inventory' : 'forms'].status, 'pass');
    if (check === 'readability') assert.equal(outcomes.checks.full_document.status, 'pass');
    assert.equal(report.status, 'failed');
    assert.equal(consumeExistingRuntimeAcceptance(output, report).status, 'failed');
  } finally { wp('post', 'update', String(front), `--post_content=${original}`); }
}
// A platform addition outside the imported post content must not be hidden by
// content-only visual/editor gates. Restore the exact theme bytes afterwards.
const functionsPath = wp('eval', 'echo get_stylesheet_directory() . "/functions.php";').trim();
const functions = fs.readFileSync(functionsPath, 'utf8');
try {
  fs.appendFileSync(functionsPath, '\nadd_action("wp_footer", function() { echo \'<div id="injected-footer">Unrelated platform banner</div>\'; });');
  await new Promise(resolve => setTimeout(resolve, 3000));
  const { report, outcomes } = await run();
  assert.equal(outcomes.checks.full_document.status, 'fail', JSON.stringify(outcomes));
  assert.equal(outcomes.checks.readability.status, 'pass');
  assert.equal(report.status, 'failed');
  assert.equal(consumeExistingRuntimeAcceptance(output, report).status, 'failed');
} finally { fs.writeFileSync(functionsPath, functions); }
const navigationId = wp('post', 'list', '--post_type=wp_navigation', '--format=ids').trim().split(/\s+/)[0];
const navigation = wp('post', 'get', navigationId, '--field=post_content');
const wrongNavigation = navigation.replaceAll('/#fixture-panel', '/wrong-route/');
assert.notEqual(wrongNavigation, navigation);
try {
  wp('post', 'update', navigationId, `--post_content=${wrongNavigation}`);
  const { report, outcomes } = await run();
  assert.equal(outcomes.checks.control_inventory.status, 'fail');
  assert.equal(outcomes.checks.forms.status, 'pass');
  assert.equal(report.status, 'failed');
  assert.equal(consumeExistingRuntimeAcceptance(output, report).status, 'failed');
} finally { wp('post', 'update', navigationId, `--post_content=${navigation}`); }
const id = Number(wp('post', 'create', '--post_type=wp_navigation', '--post_status=publish', '--post_title=Outcome regression', '--porcelain').trim());
try {
  for (const [content, check] of [['<!-- wp:html --><a href="/?page_id=42">Bad link</a><!-- /wp:html -->', 'link_graph'], ['<!-- wp:paragraph --><p>Broken</p><!-- / -->', 'block_validity']]) {
    wp('post', 'update', String(id), `--post_content=${content}`);
    const { report, outcomes } = await run();
    assert.equal(outcomes.checks[check].status, 'fail');
    assert.equal(report.status, 'failed');
    assert.equal(consumeExistingRuntimeAcceptance(output, report).status, 'failed');
  }
  wp('post', 'update', String(id), '--post_content=');
  const mutation = Buffer.from('\nadd_action("wp_head", function() { echo \'<link rel="canonical" href="https://source.example/">\'; });').toString('base64');
  wp('eval', `file_put_contents(get_stylesheet_directory() . '/functions.php', base64_decode('${mutation}'), FILE_APPEND);`);
  // PHP opcache may retain the pre-mutation fixture for its revalidation interval.
  await new Promise(resolve => setTimeout(resolve, 3000));
  const { report, outcomes } = await run();
  assert.equal(outcomes.checks.head_singletons.status, 'fail');
  assert.equal(report.status, 'failed');
  assert.equal(consumeExistingRuntimeAcceptance(output, report).status, 'failed');
} finally { wp('post', 'delete', String(id), '--force'); }
console.log(`Real imported WordPress outcome evidence: ${output}`);
