// Live-WP parity collector for the Static Site Importer fixture matrix.
//
// Host-side companion to ../steps/live-wp-parity-step.mjs. The capture step writes
// the imported candidate's RENDERED DOM HTML to `files/browser/snapshot.html`
// (via `wordpress.capture-html`). This module feeds that snapshot, together with
// the staged fixture source, to the EXISTING blocks-engine deterministic comparator
// through its CLI entry (`composer live-wp-parity` ->
// php-transformer/tools/live-wp-parity/run.php), then normalizes the report into
// the same parity-result shape the matrix already surfaces for the render-free gate.
//
// Determinism: the comparator is pure PHP (no browser/network/rasterization), and
// the candidate (snapshot.html) was captured with external requests blocked, so
// given a fixed snapshot the report is byte-identical run to run. With --with-proxy
// the CLI also reports the render-free proxy score so callers can read the live-WP
// vs proxy delta directly.

import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

import { VISUAL_PARITY_SOURCE_SUBDIR } from '../shared/constants.mjs';
import { objectValue, finiteNumber, compactObject, isTruthySignal } from '../shared/utils.mjs';

// Run the blocks-engine live-wp-parity CLI against a captured rendered-DOM snapshot.
//
// @param {object} input
//   - sourceHtmlPath: path to the fixture source HTML (required)
//   - candidateHtmlPath: path to the captured rendered-DOM snapshot.html (required)
//   - blocksEnginePhpTransformerPath: php-transformer package root (required;
//       resolve via resolveBlocksEnginePhpTransformerPath)
//   - sourceCssPath: optional explicit source CSS file; otherwise the CLI
//       auto-extracts author CSS from the source's own <style>/linked stylesheets
//   - candidateCssPath: optional extra CSS for the candidate side (default none —
//       the rendered DOM carries its own WP-emitted styles)
//   - withProxy: also compute the render-free proxy score + delta (default true)
//   - exec: injectable command runner (defaults to spawnSync) for testing
// @returns {object} normalized live-WP parity result (see normalizeLiveWpParityReport)
export function runLiveWpParity(input = {}) {
  const sourceHtmlPath = requirePath(input.sourceHtmlPath, 'sourceHtmlPath');
  const candidateHtmlPath = requirePath(input.candidateHtmlPath, 'candidateHtmlPath');
  const packagePath = requirePath(input.blocksEnginePhpTransformerPath, 'blocksEnginePhpTransformerPath');
  const withProxy = input.withProxy !== false;
  const exec = typeof input.exec === 'function' ? input.exec : defaultExec;

  const runPhp = path.join(packagePath, 'tools', 'live-wp-parity', 'run.php');
  const args = [
    runPhp,
    '--source', sourceHtmlPath,
    '--candidate', candidateHtmlPath,
    ...(input.sourceCssPath ? ['--source-css', input.sourceCssPath] : []),
    ...(input.candidateCssPath ? ['--candidate-css', input.candidateCssPath] : []),
    ...(withProxy ? ['--with-proxy'] : []),
    '--json',
  ];

  const result = exec('php', args, { cwd: packagePath });
  const status = finiteNumber(result?.status, result?.status === 0 ? 0 : 1);
  const stdout = String(result?.stdout ?? '');
  const stderr = String(result?.stderr ?? '');
  if (status !== 0) {
    throw new Error(`live-wp-parity CLI failed (status ${status}): ${stderr.trim() || stdout.trim()}`);
  }

  let parsed;
  try {
    parsed = JSON.parse(stdout);
  } catch (error) {
    throw new Error(`live-wp-parity CLI emitted non-JSON output: ${error.message}`);
  }

  return normalizeLiveWpParityReport(parsed);
}

// Normalize the matrix-facing live-WP parity collector options into a stable
// shape. `enabled` is the opt-in toggle (off by default), `blocksEnginePhpTransformerPath`
// points at the comparator package, `withProxy` (default true) also computes the
// render-free proxy score + delta, and `exec` is an injectable runner for tests.
export function normalizeLiveWpParityCollectorOptions(options = {}) {
  const source = objectValue(options);
  return {
    enabled: isTruthySignal(source.enabled ?? source.liveWpParity ?? source.live_wp_parity),
    blocksEnginePhpTransformerPath: firstStringOption([
      source.blocksEnginePhpTransformerPath,
      source.blocks_engine_php_transformer_path,
    ]),
    withProxy: source.withProxy !== false && source.with_proxy !== false,
    exec: typeof source.exec === 'function' ? source.exec : undefined,
  };
}

// Per-fixture host-side live-WP parity collection. Resolves the captured rendered
// DOM snapshot (files/browser/snapshot.html, written by the capture step) and the
// staged fixture source, then runs the blocks-engine comparator via runLiveWpParity.
//
// Lane isolation: this is best-effort and NEVER throws. When the toggle is off,
// the comparator package path is unknown, the snapshot/source artifacts are absent
// (capture did not run), or the comparator itself fails, it returns null so a
// live-WP miss can not sink the fixture lane. Returns the normalized
// `static-site-importer/live-wp-parity-result/v1` result (live-WP score, proxy
// score, and live-vs-proxy delta) when the comparison succeeds.
export function collectLiveWpParity(input = {}) {
  const options = normalizeLiveWpParityCollectorOptions(input.options || input);
  if (!options.enabled || !options.blocksEnginePhpTransformerPath) {
    return null;
  }
  const fixtureArtifactsDirectory = input.fixtureArtifactsDirectory || input.fixture_artifacts_directory;
  if (typeof fixtureArtifactsDirectory !== 'string' || fixtureArtifactsDirectory.trim() === '') {
    return null;
  }
  const entrypoint = input.entrypoint || input.fixture?.entrypoint || 'index.html';
  const candidateHtmlPath = path.join(fixtureArtifactsDirectory, 'files', 'browser', 'snapshot.html');
  const sourceHtmlPath = path.join(fixtureArtifactsDirectory, VISUAL_PARITY_SOURCE_SUBDIR, entrypoint);
  // No capture/source on disk => the candidate render or source staging did not
  // happen for this fixture. Skip silently rather than fail the lane.
  if (!fs.existsSync(candidateHtmlPath) || !fs.existsSync(sourceHtmlPath)) {
    return null;
  }
  try {
    return runLiveWpParity({
      sourceHtmlPath,
      candidateHtmlPath,
      blocksEnginePhpTransformerPath: options.blocksEnginePhpTransformerPath,
      withProxy: options.withProxy,
      exec: options.exec,
    });
  } catch {
    // Lane isolation: a comparator failure is recorded as "no live-WP result"
    // for this fixture, never an aborted lane.
    return null;
  }
}

function firstStringOption(values) {
  for (const value of values) {
    if (typeof value === 'string' && value.trim() !== '') {
      return value;
    }
  }
  return '';
}

function defaultExec(command, args, options) {
  // The full report carries every per-element / per-property delta, which for a
  // large fixture exceeds spawnSync's default 1 MiB stdout buffer and would
  // otherwise truncate the JSON. Raise the ceiling so the report is captured whole.
  return spawnSync(command, args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, ...options });
}

// Normalize the CLI's `blocks-engine/php-transformer/live-wp-parity-report/v1`
// payload into the matrix-facing live-WP parity result: the live-WP score + status,
// a bounded per-property diff for evidence, and the render-free proxy comparison
// when present. Keeps the same `parity`/`summary` vocabulary the render-free gate
// already reports so both signals read side by side.
export function normalizeLiveWpParityReport(report, options = {}) {
  const payload = objectValue(report);
  const live = objectValue(payload.live_wp);
  const parity = objectValue(live.parity);
  const summary = objectValue(live.summary);
  const comparison = objectValue(payload.comparison);
  const diffLimit = finiteNumber(options.diffLimit ?? options.diff_limit, 25);

  return compactObject({
    schema: 'static-site-importer/live-wp-parity-result/v1',
    owner: 'codebox_runtime',
    source: payload.source,
    candidate: payload.candidate,
    status: live.status,
    score: finiteNumber(parity.score, 0),
    property_parity: finiteNumber(parity.property_parity, 0),
    coverage: finiteNumber(parity.coverage, 0),
    matched_total: finiteNumber(summary.matched_total, 0),
    source_total: finiteNumber(summary.source_total, 0),
    finding_total: finiteNumber(summary.finding_total, 0),
    property_diffs: collectPropertyDiffs(live, diffLimit),
    comparison: Object.keys(comparison).length > 0
      ? compactObject({
          live_wp_score: finiteNumber(comparison.live_wp_score, 0),
          proxy_score: finiteNumber(comparison.proxy_score, 0),
          // Positive delta => the live WP render matched the source BETTER than the
          // render-free proxy; negative => WP's render/global-styles diverged where
          // the proxy did not (the layer the proxy cannot see).
          delta: finiteNumber(comparison.delta, 0),
        })
      : undefined,
  });
}

// Flatten the comparator's per-element style deltas into a bounded list naming the
// exact diverged CSS property on the exact selector — the same per-property signal
// the render-free gate surfaces, now for real WP output.
function collectPropertyDiffs(liveReport, limit) {
  const diffs = [];
  for (const match of asArray(objectValue(liveReport).matches)) {
    const record = objectValue(match);
    for (const delta of asArray(record.style_deltas)) {
      const entry = objectValue(delta);
      diffs.push(compactObject({
        source_selector: record.source_selector,
        target_selector: record.target_selector,
        property: entry.property,
        source: entry.source,
        target: entry.target,
      }));
      if (diffs.length >= limit) {
        return diffs;
      }
    }
  }
  return diffs;
}

function asArray(value) {
  return Array.isArray(value) ? value : [];
}

function requirePath(value, name) {
  if (typeof value !== 'string' || value.trim() === '') {
    throw new Error(`runLiveWpParity requires ${name}.`);
  }
  return value;
}
