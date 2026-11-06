import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, symlinkSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { execFileSync } from 'node:child_process';
import { provisionWordPress } from '../lib/disposable-wordpress.mjs';

const work = mkdtempSync(join(tmpdir(), 'ssi-receipt-verdict-'));
let runtime;
try {
  for (const [name, count] of [['small', 2], ['large', 2]]) {
    runtime = await provisionWordPress({ workDir: work });
    symlinkSync(resolve('.'), join(runtime.wpDir, 'wp-content/plugins/static-site-importer'));
    runtime.wpRun(['plugin', 'activate', 'static-site-importer']);
    if (name === 'large') {
      mkdirSync(join(runtime.wpDir, 'wp-content/mu-plugins'), { recursive: true });
      symlinkSync(resolve('tests/fixtures/receipt-verdict-diagnostics.php'), join(runtime.wpDir, 'wp-content/mu-plugins/receipt-diagnostics.php'));
    }
    const bundle = join(work, name);
    const website = join(bundle, 'website');
    mkdirSync(website, { recursive: true });
    writeFileSync(join(website, 'style.css'), 'body { color: #123456; }');
    writeFileSync(join(website, 'image.png'), Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aXioAAAAASUVORK5CYII=', 'base64'));
    for (let i = 0; i < count; i++) {
      writeFileSync(join(website, i === 0 ? 'index.html' : `menu-${i}.html`), `<!doctype html><html><head><title>Receipt ${i}</title><link rel="stylesheet" href="style.css"></head><body><main><h1>Menu ${i}</h1><p>Fresh food</p><img src="image.png" alt="Food"><a href="index.html">Home</a></main></body></html>`);
    }
    execFileSync('zip', ['-qr', join(bundle, 'website.zip'), '.'], { cwd: website });
    const request = join(bundle, 'request.json');
    writeFileSync(request, JSON.stringify({ operation: 'apply', source: { type: 'zip', ref: 'request-bundle:website.zip' }, slug: `receipt-${name}`, activate: true, remove_default_content: true, materialize_dependencies: true, theme_materialization: 'block' }));
    let output;
    try {
      output = execFileSync('php', ['-d', 'memory_limit=1G', runtime.wpCliPath, 'static-site-importer', 'import', `--request=${request}`], { cwd: runtime.wpDir, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });
    } catch (error) {
      throw new Error(error.stdout?.trim().split('\n').at(-1) || error.message);
    }
    const line = output.trim().split('\n').at(-1);
    const receipt = JSON.parse(line);
    assert.equal(receipt.status, 'completed');
    const result = receipt.response.result;
    const report = JSON.parse(readFileSync(result.response_artifacts.artifacts.import_report.path, 'utf8'));
    const details = JSON.parse(readFileSync(result.response_artifacts.artifacts.result_details.path, 'utf8'));
    const validation = report.import_validation_result;
    assert.deepEqual(validation, details.import_validation_result);
    // Reproduce the old shared-budget projection from the full artifact, so the
    // size assertion measures the old transport allowance plus complete verdict.
    let remaining = 200;
    const bound = (value, depth = 0) => {
      if (remaining <= 0 || depth >= 8) return null;
      remaining--;
      if (typeof value === 'string') return value.slice(0, 256);
      if (value === null || typeof value !== 'object') return value;
      const bounded = Array.isArray(value) ? [] : {};
      for (const [key, item] of Object.entries(value)) {
        if (remaining <= 0) break;
        bounded[key] = bound(item, depth + 1);
      }
      return bounded;
    };
    const oldInline = { materialization_receipt_summary: bound(result.materialization_receipt_summary) };
    for (const key of ['theme_slug', 'theme_name', 'theme_dir', 'report_path', 'validation_result_path', 'finding_packets_path', 'external_report_path', 'external_validation_result_path', 'external_finding_packets_path', 'manifest_path', 'import_id', 'import_run_id', 'status', 'import_report_summary', 'import_validation_result', 'fixture_diagnostics', 'quality', 'progress_events']) {
      if (Object.hasOwn(details, key)) oldInline[key] = bound(details[key]);
    }
    if (name === 'small') console.log(`Previous budget verdict projection: ${JSON.stringify(oldInline.import_validation_result)}`);
    const newInline = Object.fromEntries(Object.keys(oldInline).map(key => [key, result[key]]));
    const bytes = value => Buffer.byteLength(JSON.stringify(value));
    assert.ok(bytes(newInline) <= bytes(oldInline) + bytes(validation) + 100, 'old inline budget plus verdict fields');
    for (const key of ['quality_pass', 'fail_import', 'failure_reasons', 'counts', 'diagnostic_summary']) {
      assert.deepEqual(result.import_validation_result[key], validation[key], `${name}: ${key}`);
    }
    assert.ok(report.quality.block_count > 0);
    assert.equal(result.fixture_diagnostics.quality_counts.block_count, report.quality.block_count);
    if (name === 'large') {
      assert.ok(validation.counts.diagnostics > 200, `fixture must yield well over 200 diagnostics: ${JSON.stringify(validation.counts)}`);
      for (const key of ['diagnostics', 'diagnostic_refs', 'quality_gates', 'visual_parity_artifacts']) {
        assert.ok((result.import_validation_result[key]?.length ?? 0) <= 200, key);
      }
      assert.ok(line.length < 40000, `bounded receipt: ${line.length} bytes`);
      assert.ok((receipt.response.diagnostics?.length ?? 0) <= 25);
    }
    console.log(`${name} verdict: ${JSON.stringify({ validation: result.import_validation_result, block_count: result.fixture_diagnostics.quality_counts.block_count })}`);
    await runtime.close();
  }
} finally {
  await runtime?.close();
  rmSync(work, { recursive: true, force: true });
}
console.log('CLI receipt verdict smoke passed.');
