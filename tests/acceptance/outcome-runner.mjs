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
// Paths inside the disposable runtime are the runtime's own plugin directory,
// not this checkout's working directory.
const runtimePlugin = wp('plugin', 'path', 'static-site-importer', '--dir').trim();
const imported = JSON.parse(wp('eval-file', `${runtimePlugin}/tests/acceptance/outcome-wordpress.php`));
// Acceptance scope follows the real imported permalinks, not assumptions about
// the materializer; only importer-provenance pages belong to the scope.
const importedPages = JSON.parse(wp('eval', `echo wp_json_encode(array_map(static function ($page) { return array('route' => wp_parse_url(get_permalink($page), PHP_URL_PATH)); }, get_posts(array('post_type' => 'page', 'numberposts' => -1, 'meta_key' => '_static_site_importer_provenance', 'orderby' => 'ID', 'order' => 'ASC'))));`));
const routes = importedPages.map(page => page.route).sort();
assert.equal(routes.length, 2, `Two imported routes expected, got ${JSON.stringify(routes)}`);
const frontRoute = routes.find(route => route === '/');
const aboutRoute = routes.find(route => route !== '/');
assert.ok(frontRoute && aboutRoute, 'One imported route must own the front page.');
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-outcomes-wordpress-'));
const manifest = path.join(output, 'manifest.json');
fs.writeFileSync(manifest, JSON.stringify({ routes }));
const collectInventory = async () => JSON.parse(wp('eval', `require_once ${JSON.stringify(`${runtimePlugin}/lib/outcome-inventory.php`)}; echo wp_json_encode(ssi_collect_outcome_inventory());`));
// Theme independence (#2430) drives the shipped snapshot/switch/restore phases
// in this disposable runtime, exactly like the inventory collector.
const themeLib = `${runtimePlugin}/lib/theme-independence.php`;
const themeEval = code => wp('eval', `require_once ${JSON.stringify(themeLib)}; ${code}`);
const defaultTheme = (() => {
  try { wp('theme', 'install', 'twentytwentyfive', '--force'); } catch { /* offline runtimes use an already installed default. */ }
  for (const slug of ['twentytwentyfive', 'twentytwentyfour', 'twentytwentythree']) {
    try {
      if (wp('theme', 'list', '--field=name', '--status=inactive').trim().split('\n').includes(slug)) return slug;
    } catch { /* keep probing. */ }
  }
  throw new Error('No installed default core theme is available for the theme-independence probe.');
})();
const skipLinkDecision = {
  reason: 'Retain the WordPress accessibility skip link before the source content.',
  expected: { before: [], after: [] },
  actual: { before: [{ link: '/#wp--skip-link--target' }, { text: 'Skip to content' }], after: [] },
};
const outcomes = structuredClone(interactiveConfig);
outcomes.documents = {
  [frontRoute]: { sourceOrigin: 'https://source.example', html: imported.source_html, sourceContent: 'main', candidateContent: '.wp-block-post-content', decisions: [skipLinkDecision] },
  [aboutRoute]: { sourceOrigin: 'https://source.example', html: imported.source_html_about, sourceContent: 'main', candidateContent: '.wp-block-post-content', decisions: [skipLinkDecision] },
};
outcomes.capture.pages = [
  { route: frontRoute, sourceUrl: 'https://source.example/', interactionStates: { schema: 'blocks-engine-capture/interaction-states/v2', states: [{ id: 'disclosure', kind: 'disclosure', status: 'captured' }] }, navigation: [{ id: 'information', destination: '/#fixture-panel' }], forms: [{ id: 'contact' }] },
  { route: aboutRoute, sourceUrl: 'https://source.example/about.html', interactionStates: { schema: 'blocks-engine-capture/interaction-states/v2', states: [] }, navigation: [{ id: 'information', destination: '/#fixture-panel' }], forms: [] },
];
outcomes.collectInventory = collectInventory;
outcomes.sourceHosts = ['source.example', 'cdn.source.example'];
outcomes.aliases = [];
outcomes.telemetryMarkers = ['source-analytics'];
outcomes.themeIndependence = {
  theme: defaultTheme,
  snapshot: async () => JSON.parse(themeEval('echo wp_json_encode(ssi_theme_owner_snapshot());')),
  activate: async () => themeEval(`ssi_theme_independence_activate(${JSON.stringify(defaultTheme)});`),
  restore: async () => themeEval('ssi_theme_independence_restore();'),
};
const config = { directory: output, outputDirectory: output, evidenceRoot: output, manifest, candidateOrigin, importRunId: 'outcome-real-fixture', authenticate: async () => {}, candidateInventory: async () => [], outcomes };
const run = async () => {
  const report = await runExistingRuntimeAcceptance(config);
  return { report, outcomes: JSON.parse(fs.readFileSync(path.join(output, report.artifacts.outcomes.path), 'utf8')) };
};
const baseline = await run();
assert.equal(baseline.report.gates.outcomes.status, 'passed', JSON.stringify(baseline.outcomes));
assert.equal(baseline.outcomes.checks.readability.status, 'pass');
assert.equal(baseline.outcomes.checks.full_document.status, 'pass');
assert.equal(baseline.outcomes.checks.full_document.evidence.length, 12, 'both routes, all three widths and both schemes');
assert.equal(baseline.outcomes.checks.link_graph.status, 'pass');
assert.ok(baseline.outcomes.checks.link_graph.evidence.some(row => row.surface?.startsWith('css:')), 'rendered stylesheets enter the link graph');
assert.ok(baseline.outcomes.checks.link_graph.evidence.some(row => row.surface === 'chrome:wp_navigation' || row.surface?.startsWith('chrome:wp_navigation:')), 'navigation anchors are checked');
assert.equal(baseline.outcomes.checks.theme_independence.status, 'pass', JSON.stringify(baseline.outcomes.checks.theme_independence));
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
// A title owned by theme code changes when the theme switches; the outcome
// check must catch it through the native-owner snapshot, not rendered bytes.
try {
  fs.appendFileSync(functionsPath, `\nadd_filter('pre_option_blogname', static function () { return 'Theme Owned Title'; });`);
  await new Promise(resolve => setTimeout(resolve, 3000));
  const { report, outcomes } = await run();
  assert.equal(outcomes.checks.theme_independence.status, 'fail', JSON.stringify(outcomes.checks.theme_independence));
  assert.ok(outcomes.checks.theme_independence.failures.some(row => row.reason === 'native_owner_changed_under_switch' && row.differences?.includes('settings.blogname')), JSON.stringify(outcomes.checks.theme_independence.failures));
  assert.equal(outcomes.checks.link_graph.status, 'pass');
  assert.equal(outcomes.checks.block_validity.status, 'pass');
  assert.equal(report.status, 'failed');
  assert.equal(consumeExistingRuntimeAcceptance(output, report).status, 'failed');
} finally { fs.writeFileSync(functionsPath, functions); }
// Source-host urls inside rendered theme CSS are link-graph regressions even
// though no markup changed.
const stylesheetPath = wp('eval', 'echo get_stylesheet_directory() . "/assets/site.css";').trim();
const stylesheet = fs.readFileSync(stylesheetPath, 'utf8');
try {
  fs.appendFileSync(stylesheetPath, 'body { background-image: url(https://cdn.source.example/texture.png); }\n');
  const { report, outcomes } = await run();
  assert.equal(outcomes.checks.link_graph.status, 'fail', JSON.stringify(outcomes.checks.link_graph));
  assert.ok(outcomes.checks.link_graph.failures.some(row => row.surface?.startsWith('css:') && row.violations?.includes('source_host_url')), JSON.stringify(outcomes.checks.link_graph.failures));
  assert.equal(outcomes.checks.theme_independence.status, 'pass');
  assert.equal(outcomes.checks.block_validity.status, 'pass');
  assert.equal(report.status, 'failed');
  assert.equal(consumeExistingRuntimeAcceptance(output, report).status, 'failed');
} finally { fs.writeFileSync(stylesheetPath, stylesheet); }
const navigationId = wp('post', 'list', '--post_type=wp_navigation', '--format=ids').trim().split(/\s+/)[0];
const navigation = wp('post', 'get', navigationId, '--field=post_content');
// A bare shared-chrome anchor resolves only on the route that owns the target;
// it must fail the link graph on every other route that renders the chrome.
const bareAnchorNavigation = navigation.replaceAll('/#fixture-panel', '#fixture-panel');
assert.notEqual(bareAnchorNavigation, navigation);
try {
  wp('post', 'update', navigationId, `--post_content=${bareAnchorNavigation}`);
  const { report, outcomes } = await run();
  assert.equal(outcomes.checks.link_graph.status, 'fail', JSON.stringify(outcomes.checks.link_graph));
  assert.ok(outcomes.checks.link_graph.failures.some(row => row.surface?.startsWith('chrome:') && row.route === aboutRoute && row.status === 'fail'), JSON.stringify(outcomes.checks.link_graph.failures));
  assert.equal(outcomes.checks.theme_independence.status, 'pass');
  assert.equal(outcomes.checks.block_validity.status, 'pass');
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
