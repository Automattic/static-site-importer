/**
 * External dependencies
 */
import fs from 'node:fs';
import path from 'node:path';

/**
 * Internal dependencies
 */
import { normalizeArray, objectValue, numberValue, compactObject, countBy } from './shared/utils.mjs';

export const VISUAL_PARITY_EVIDENCE_REPORT_SCHEMA = 'static-site-importer/visual-parity-evidence-report/v1';

export function buildVisualParityEvidenceReport(input = {}) {
  const result = objectValue(input.result);
  const matrixFixtures = normalizeArray(input.matrix?.fixtures);
  const fixtureById = new Map(matrixFixtures.map((fixture) => [fixture.id, fixture]));
  const outputDirectory = typeof input.outputDirectory === 'string' ? input.outputDirectory : input.output_directory || '';
  const findingsByFixture = new Map();
  for (const finding of normalizeArray(result.findings)) {
    const fixtureId = finding.fixture_id || '';
    findingsByFixture.set(fixtureId, [...(findingsByFixture.get(fixtureId) || []), finding]);
  }

  const fixtures = normalizeArray(result.fixtures).map((fixture) => {
    const manifest = fixtureById.get(fixture.fixture_id) || {};
    return fixtureEvidenceRow({ fixture, manifest, findings: findingsByFixture.get(fixture.fixture_id) || [], outputDirectory });
  });
  const riskCounts = countBy(fixtures, (fixture) => fixture.risk.level);

  return {
    schema: VISUAL_PARITY_EVIDENCE_REPORT_SCHEMA,
    matrix_id: result.matrix_id || input.matrix?.id || '',
    summary: {
      fixture_count: fixtures.length,
      generated_artifact_fixture_count: fixtures.filter((fixture) => fixture.stages.generated_html_artifact.status === 'present').length,
      staged_source_fixture_count: fixtures.filter((fixture) => fixture.stages.staged_source_html.status === 'present').length,
      imported_snapshot_fixture_count: fixtures.filter((fixture) => fixture.stages.imported_wordpress_theme.status === 'present').length,
      visual_compare_fixture_count: fixtures.filter((fixture) => fixture.evidence.visual_compare.status === 'present').length,
      screenshot_fixture_count: fixtures.filter((fixture) => fixture.evidence.screenshots.status === 'present').length,
      viewport_evidence_fixture_count: fixtures.filter((fixture) => fixture.evidence.viewports.status === 'present').length,
      mobile_viewport_fixture_count: fixtures.filter((fixture) => fixture.evidence.viewports.has_mobile).length,
      live_wp_parity_fixture_count: fixtures.filter((fixture) => fixture.evidence.live_wp_parity.status === 'present').length,
      visual_attribution_fixture_count: fixtures.filter((fixture) => fixture.evidence.visual_attribution.status === 'available').length,
      limited_visual_attribution_fixture_count: fixtures.filter((fixture) => fixture.evidence.visual_attribution.status === 'limited').length,
      missing_asset_fixture_count: fixtures.filter((fixture) => fixture.asset_resolution.missing_asset_count > 0).length,
      core_html_fixture_count: fixtures.filter((fixture) => fixture.block_theme.core_html_block_count > 0).length,
      risk_counts: riskCounts,
      highest_risk_fixtures: fixtures
        .filter((fixture) => fixture.risk.score > 0)
        .sort((left, right) => right.risk.score - left.risk.score || left.fixture_id.localeCompare(right.fixture_id))
        .slice(0, 10)
        .map((fixture) => ({ fixture_id: fixture.fixture_id, score: fixture.risk.score, level: fixture.risk.level, reasons: fixture.risk.reasons })),
    },
    fixtures,
    limitations: [
      'This report scores deterministic evidence availability and known artifact risks; it is not a pixel-diff substitute.',
      'Viewport evidence is inferred from captured browser evidence metadata and screenshot refs; missing mobile evidence means no mobile capture was observed in the matrix artifacts.',
      'Imported WordPress theme evidence requires a captured browser snapshot or equivalent browser artifact from the run.',
    ],
  };
}

export function renderVisualParityEvidenceReportMarkdown(report) {
  const summary = objectValue(report.summary);
  const rows = normalizeArray(report.fixtures)
    .map((fixture) => `| ${fixture.fixture_id} | ${fixture.risk.level} (${fixture.risk.score}) | ${fixture.stages.generated_html_artifact.status} | ${fixture.stages.imported_wordpress_theme.status} | ${fixture.evidence.visual_compare.status} | ${fixture.evidence.screenshots.status} | ${fixture.evidence.viewports.status}${fixture.evidence.viewports.has_mobile ? ' + mobile' : ''} | ${fixture.block_theme.native_conversion_rate} | ${fixture.block_theme.core_html_block_count} | ${fixture.asset_resolution.missing_asset_count} |`)
    .join('\n');
  return [
    '# Visual Parity Evidence Report',
    '',
    `Matrix: \`${report.matrix_id || ''}\``,
    '',
    `Fixtures: ${summary.fixture_count || 0}`,
    `Imported snapshots: ${summary.imported_snapshot_fixture_count || 0}`,
    `Visual compare evidence: ${summary.visual_compare_fixture_count || 0}`,
    `Screenshot evidence: ${summary.screenshot_fixture_count || 0}`,
    `Viewport evidence: ${summary.viewport_evidence_fixture_count || 0}`,
    `Mobile viewport evidence: ${summary.mobile_viewport_fixture_count || 0}`,
    `Visual attribution: ${summary.visual_attribution_fixture_count || 0} available, ${summary.limited_visual_attribution_fixture_count || 0} limited`,
    `Fixtures with missing assets: ${summary.missing_asset_fixture_count || 0}`,
    `Fixtures with core/html blocks: ${summary.core_html_fixture_count || 0}`,
    '',
    '| Fixture | Risk | Generated HTML | Imported WP Snapshot | Visual Compare | Screenshots | Viewports | Native Rate | Core HTML | Missing Assets |',
    '|---|---:|---|---|---|---|---|---:|---:|---:|',
    rows || '| _none_ |  |  |  |  |  |  |  |  |  |',
    '',
    '## Limitations',
    '',
    ...normalizeArray(report.limitations).map((limitation) => `- ${limitation}`),
    '',
  ].join('\n');
}

function fixtureEvidenceRow({ fixture, manifest, findings, outputDirectory }) {
  const fixtureId = fixture.fixture_id || '';
  const artifactPath = fixturePath(outputDirectory, fixtureId, 'artifact.json');
  const sourcePath = fixturePath(outputDirectory, fixtureId, 'source', fixture.entrypoint || manifest.entrypoint || 'index.html');
  const artifacts = objectValue(fixture.visual_parity_artifacts);
  const visualArtifacts = objectValue(artifacts.artifacts);
  const visualMetrics = objectValue(artifacts.metrics || artifacts.comparison);
  const artifactRefs = normalizeArray(fixture.artifact_refs);
  const screenshotRefs = screenshotArtifacts(visualArtifacts, artifactRefs);
  const viewportRows = viewportEvidenceRows({ artifacts, artifactRefs });
  const missingAssets = [...normalizeArray(fixture.missing_assets), ...findings.filter((finding) => finding.loss_class === 'missing_asset' || finding.kind === 'missing_asset')];
  const blockComposition = objectValue(fixture.block_composition);
  const editorQuality = objectValue(fixture.editor_quality);
  const blockTotal = numberValue(editorQuality.block_total ?? blockComposition.block_total, 0);
  const nativeBlockCount = numberValue(editorQuality.native_block_count ?? blockComposition.native_block_count, 0);
  const coreHtmlBlockCount = numberValue(editorQuality.core_html_block_count ?? blockComposition.core_html_block_count, 0);
  const visualComparePresent = Object.keys(visualMetrics).length > 0 || Object.keys(visualArtifacts).length > 0 || artifactRefs.some((ref) => /visual-(compare|diff)|screenshot/i.test(`${ref.artifact_id || ''} ${ref.kind || ''} ${ref.path || ''}`));
  const importedSnapshotPresent = fileExists(fixturePath(outputDirectory, fixtureId, 'files', 'browser', 'snapshot.html')) || artifactRefs.some((ref) => /snapshot\.html|capture-html|browser.*html/i.test(`${ref.artifact_id || ''} ${ref.kind || ''} ${ref.path || ''}`));
  const risk = computeRisk({ artifactPath, sourcePath, importedSnapshotPresent, visualComparePresent, screenshotRefs, viewportRows, missingAssets, coreHtmlBlockCount, fixture });

  return {
    fixture_id: fixtureId,
    stages: {
      generated_html_artifact: stageStatus(fileExists(artifactPath), relativeRef(outputDirectory, artifactPath)),
      staged_source_html: stageStatus(fileExists(sourcePath), relativeRef(outputDirectory, sourcePath)),
      imported_wordpress_theme: stageStatus(importedSnapshotPresent, importedSnapshotPresent ? 'browser snapshot or HTML capture artifact' : ''),
    },
    evidence: {
      visual_compare: stageStatus(visualComparePresent, visualComparePresent ? 'visual parity metrics/artifacts' : ''),
      screenshots: {
        status: screenshotRefs.length ? 'present' : 'missing',
        count: screenshotRefs.length,
        refs: screenshotRefs.slice(0, 8),
      },
      viewports: {
        status: viewportRows.length ? 'present' : 'missing',
        count: viewportRows.length,
        has_mobile: viewportRows.some((row) => numberValue(row.width, 0) > 0 && numberValue(row.width, 0) <= 480),
        rows: viewportRows.slice(0, 8),
      },
      live_wp_parity: stageStatus(Boolean(fixture.live_wp_parity), fixture.live_wp_parity ? `score ${numberValue(fixture.live_wp_parity.score, 0)}` : ''),
      visual_attribution: visualAttributionEvidence(artifacts),
    },
    asset_resolution: {
      missing_asset_count: missingAssets.length,
      examples: missingAssets.slice(0, 5).map((item) => compactObject({ kind: item.kind, path: item.path || item.url || item.source_path, message: item.message || item.reason })),
    },
    block_theme: {
      block_total: blockTotal,
      native_block_count: nativeBlockCount,
      core_html_block_count: coreHtmlBlockCount,
      native_conversion_rate: blockTotal > 0 ? Number((nativeBlockCount / blockTotal).toFixed(4)) : 0,
      editor_invalid_count: numberValue(editorQuality.editor_invalid_count, 0),
    },
    risk,
  };
}

function visualAttributionEvidence(artifacts) {
  const summary = objectValue(artifacts.visual_attribution_summary || artifacts.visualAttributionSummary);
  if (Object.keys(summary).length === 0) {
    return { status: 'missing' };
  }
  return {
    ...summary,
    status: summary.status === 'available' ? 'available' : 'limited',
  };
}

function computeRisk({ artifactPath, sourcePath, importedSnapshotPresent, visualComparePresent, screenshotRefs, viewportRows, missingAssets, coreHtmlBlockCount, fixture }) {
  const reasons = [];
  let score = 0;
  const add = (points, reason) => {
    score += points;
    reasons.push(reason);
  };
  if (!fileExists(artifactPath)) add(20, 'missing generated artifact.json');
  if (!fileExists(sourcePath)) add(15, 'missing staged source HTML');
  if (!importedSnapshotPresent) add(20, 'missing imported WordPress browser HTML snapshot');
  if (!visualComparePresent) add(15, 'missing visual compare artifact');
  if (!screenshotRefs.length) add(10, 'missing screenshot refs');
  if (!viewportRows.length) add(8, 'missing viewport evidence');
  if (viewportRows.length && !viewportRows.some((row) => numberValue(row.width, 0) > 0 && numberValue(row.width, 0) <= 480)) add(4, 'missing mobile viewport evidence');
  if (missingAssets.length) add(Math.min(20, missingAssets.length * 5), `${missingAssets.length} missing asset signal(s)`);
  if (coreHtmlBlockCount > 0) add(Math.min(20, coreHtmlBlockCount * 4), `${coreHtmlBlockCount} core/html block(s)`);
  if (fixture.status === 'failed') add(10, 'fixture quality gate failed');

  return {
    score,
    level: score >= 40 ? 'high' : score >= 15 ? 'medium' : 'low',
    reasons,
  };
}

function screenshotArtifacts(visualArtifacts, artifactRefs) {
  const refs = [];
  for (const [key, value] of Object.entries(visualArtifacts)) {
    const ref = objectValue(value).ref || value;
    const haystack = `${key} ${objectValue(ref).kind || ''} ${objectValue(ref).path || ''}`;
    if (/screenshot|\.png$/i.test(haystack)) {
      refs.push(compactObject({ id: key, kind: objectValue(ref).kind, path: objectValue(ref).path || ref }));
    }
  }
  for (const ref of artifactRefs) {
    const haystack = `${ref.artifact_id || ''} ${ref.kind || ''} ${ref.path || ''}`;
    if (/screenshot|\.png$/i.test(haystack)) {
      refs.push(compactObject({ id: ref.artifact_id, kind: ref.kind, path: ref.path }));
    }
  }
  return dedupeByPath(refs);
}

function viewportEvidenceRows({ artifacts, artifactRefs }) {
  const rows = [];
  const pushViewport = (entry = {}) => {
    const viewport = objectValue(entry.viewport || entry.viewPort);
    const width = numberValue(viewport.width ?? entry.width, 0);
    const height = numberValue(viewport.height ?? entry.height, 0);
    if (width || height || entry.message) {
      rows.push(compactObject({ phase: entry.phase, width, height, message: entry.message }));
    }
  };
  const metrics = objectValue(artifacts.metrics || artifacts.comparison);
  pushViewport({
    phase: 'visual-compare',
    viewport: metrics.viewport,
    message: metrics.viewport ? `visual compare viewport${metrics.full_page === true ? ' (full-page capture)' : ''}` : '',
  });
  for (const diagnostic of normalizeArray(artifacts.capture_diagnostics || artifacts.captureDiagnostics || artifacts.visual_explanation?.capture_diagnostics || artifacts.visual_explanation?.captureDiagnostics)) {
    pushViewport(diagnostic);
  }
  for (const ref of artifactRefs) {
    const haystack = `${ref.artifact_id || ''} ${ref.kind || ''} ${ref.path || ''}`;
    if (/viewport|mobile|screenshot/i.test(haystack)) {
      pushViewport(ref);
    }
  }
  return rows;
}

function stageStatus(present, ref) {
  return compactObject({ status: present ? 'present' : 'missing', ref: present && ref ? ref : undefined });
}

function fixturePath(outputDirectory, fixtureId, ...segments) {
  return outputDirectory ? path.join(outputDirectory, fixtureId, ...segments) : '';
}

function fileExists(filePath) {
  return Boolean(filePath) && fs.existsSync(filePath);
}

function relativeRef(root, filePath) {
  return root && filePath ? path.relative(root, filePath) : filePath;
}

function dedupeByPath(refs) {
  const seen = new Set();
  return refs.filter((ref) => {
    const key = ref.path || ref.id || JSON.stringify(ref);
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  });
}
