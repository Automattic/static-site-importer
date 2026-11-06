import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, symlinkSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { execFileSync } from 'node:child_process';
import { provisionWordPress } from '../lib/disposable-wordpress.mjs';

// A ZIP import through the public CLI reports each distinct missing link target once.
const work = mkdtempSync(join(tmpdir(), 'ssi-unresolved-links-'));
let runtime;
try {
  for (const resolved of [false, true]) {
    runtime = await provisionWordPress({ workDir: work });
    symlinkSync(resolve('.'), join(runtime.wpDir, 'wp-content/plugins/static-site-importer'));
    runtime.wpRun(['plugin', 'activate', 'static-site-importer']);
    const bundle = join(work, resolved ? 'resolved' : 'unresolved');
    const website = join(bundle, 'website');
    mkdirSync(website, { recursive: true });
    // /pricing is linked from the header and the body of both pages; #contact resolves on the
    // same page; about.html#team names an id the about page lacks unless the site is resolved.
    const header = '<header><nav><a href="/pricing">Pricing</a><a href="about.html">About</a></nav></header>';
    const body = '<main><h1>Welcome</h1><a href="/pricing">Pricing</a><a href="#contact">Contact</a><p id="contact">Contact us</p><a href="about.html#team">Team</a></main>';
    for (const page of ['index.html', 'about.html']) {
      writeFileSync(join(website, page), `<!doctype html><html><head><title>Links</title></head><body>${header}${body}${resolved ? '<p id="team">Team</p>' : ''}</body></html>`);
    }
    if (resolved) {
      writeFileSync(join(website, 'pricing.html'), '<!doctype html><html><head><title>Pricing</title></head><body><main><h1>Pricing</h1></main></body></html>');
    }
    execFileSync('zip', ['-qr', join(bundle, 'website.zip'), '.'], { cwd: website });
    const request = join(bundle, 'request.json');
    writeFileSync(request, JSON.stringify({ operation: 'apply', source: { type: 'zip', ref: 'request-bundle:website.zip' }, slug: 'links', activate: true, remove_default_content: true, write_theme_report_artifacts: true }));
    let output;
    try {
      output = execFileSync('php', ['-d', 'memory_limit=1G', runtime.wpCliPath, 'static-site-importer', 'import', `--request=${request}`], { cwd: runtime.wpDir, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });
    } catch (error) {
      throw new Error(error.stdout?.trim().split('\n').at(-1) || error.message);
    }
    const receipt = JSON.parse(output.trim().split('\n').at(-1));
    assert.equal(receipt.status, 'completed');
    const result = receipt.response.result;
    const validation = result.import_validation_result;
    const expected = resolved ? 0 : 1;
    assert.equal(validation.counts.unresolved_internal_links, expected, JSON.stringify(validation.counts));
    assert.equal(validation.counts.unresolved_fragment_links, expected, JSON.stringify(validation.counts));
    assert.equal(validation.quality_pass, true, 'Link findings are reported, not gated.');
    assert.equal(validation.fail_import, false);
    const report = JSON.parse(readFileSync(result.report_path, 'utf8'));
    const rows = type => (report.diagnostics ?? []).filter(row => type === row.reason_code);
    assert.equal(rows('unresolved_internal_link').length, expected);
    assert.equal(rows('unresolved_fragment_link').length, expected);
    if (!resolved) {
      const [internal] = rows('unresolved_internal_link');
      const [fragment] = rows('unresolved_fragment_link');
      assert.equal(internal.route, '/pricing');
      assert.ok(internal.source_path);
      assert.equal(fragment.route, '/about');
      assert.equal(fragment.fragment, 'team');
      assert.ok(fragment.source_path);
    }
    await runtime.close();
    runtime = undefined;
  }
} finally {
  await runtime?.close();
  rmSync(work, { recursive: true, force: true });
}
console.log('CLI unresolved links smoke passed.');
