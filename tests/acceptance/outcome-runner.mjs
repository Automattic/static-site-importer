import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { runExistingRuntimeAcceptance } from '../../lib/run-existing-runtime-acceptance.mjs';
import { consumeExistingRuntimeAcceptance } from '../../lib/existing-runtime-acceptance.mjs';

// Run only against a fresh, caller-provisioned disposable WordPress runtime.
const cli = process.env.STATIC_SITE_IMPORTER_WP_CLI;
const candidateOrigin = process.env.SSI_OUTCOME_ORIGIN;
if (!cli || !candidateOrigin || process.env.SSI_OUTCOME_DISPOSABLE !== '1') throw new Error('Declare disposable WP CLI and SSI_OUTCOME_ORIGIN.');
const root = process.cwd();
const wp = (...args) => execFileSync(cli.split(' ')[0], [...cli.split(' ').slice(1), ...args], { encoding: 'utf8', env: { ...process.env, SSI_OUTCOME_REPOSITORY: root } });
wp('eval-file', 'tests/acceptance/outcome-wordpress.php');
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-outcomes-wordpress-'));
const manifest = path.join(output, 'manifest.json');
fs.writeFileSync(manifest, JSON.stringify({ routes: ['/'] }));
const collectInventory = async () => JSON.parse(wp('eval', `require_once ${JSON.stringify(`${root}/lib/outcome-inventory.php`)}; echo wp_json_encode(ssi_collect_outcome_inventory());`));
const config = { directory: output, outputDirectory: output, evidenceRoot: output, manifest, candidateOrigin, importRunId: 'outcome-real-fixture', authenticate: async () => {}, candidateInventory: async () => [], outcomes: { collectInventory, sourceHosts: ['source.example', 'cdn.source.example'], aliases: [], telemetryMarkers: ['source-analytics'] } };
const run = async () => {
  const report = await runExistingRuntimeAcceptance(config);
  return { report, outcomes: JSON.parse(fs.readFileSync(path.join(output, report.artifacts.outcomes.path), 'utf8')) };
};
const baseline = await run();
assert.equal(baseline.report.gates.outcomes.status, 'passed', JSON.stringify(baseline.outcomes));
// The missing fidelity/editor prerequisites remain pending, not fabricated passes.
assert.equal(baseline.report.status, 'pending');
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
