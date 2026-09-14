import assert from 'node:assert/strict';
import test from 'node:test';
import { chromium } from 'playwright';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { measurePresentation, evaluateEditorPresentation, presentationEvidencePassed } from '../lib/editor-presentation.mjs';
import { compareVisualParityPngFiles } from '../lib/fixture-matrix/image-comparison.mjs';

const selectors = selector => Object.fromEntries(['source', 'frontend', 'editor'].map(surface => [surface, selector]));
const map = { schema: 'static-site-importer/editor-presentation-map/v1', targets: [
  { id: 'form', role: 'region', selectors: selectors('form'), containers: selectors('main') },
  { id: 'label', role: 'label', selectors: selectors('label'), containers: selectors('main') },
  { id: 'input', role: 'field', selectors: selectors('input'), containers: selectors('main') },
  { id: 'submit', role: 'submit', selectors: selectors('button'), containers: selectors('main') },
  { id: 'required-indicator', role: 'indicator', optional: true, selectors: selectors('.required'), containers: selectors('main') },
] };
const html = `<!doctype html><style>
body{margin:0;font:16px/24px Arial}main{padding:20px}form{width:400px;margin:auto;display:flex;flex-direction:column}
label{margin-bottom:12px}input{height:48px;box-sizing:border-box}button{width:100%;height:48px;margin-top:20px}
</style><main><form><label for="name">Your name</label><input id="name" placeholder="Name"><button>Send</button></form></main>`;

test('real browser geometry rejects the observed editor defects while the frontend stays correct', async () => {
  const browser = await chromium.launch();
  const output = await mkdtemp(path.join(tmpdir(), 'ssi-editor-presentation-'));
  try {
    const source = await browser.newPage({ viewport: { width: 600, height: 800 } });
    const frontend = await browser.newPage({ viewport: { width: 600, height: 800 } });
    const editor = await browser.newPage({ viewport: { width: 1000, height: 1000 } });
    await source.setContent(html);
    await frontend.setContent(html);
    await editor.setContent('<iframe name="editor-canvas" style="width:600px;height:800px;border:0"></iframe>');
    const frame = editor.frame('editor-canvas');
    await frame.setContent(html);
    const baseline = { source: await measurePresentation(source, map, 'source'), frontend: await measurePresentation(frontend, map, 'frontend'), editor: await measurePresentation(frame, map, 'editor') };
    assert.equal(baseline.editor.viewport.width, 600, 'measure the iframe, not the 1000px browser');
    assert.equal(evaluateEditorPresentation(map, baseline).status, 'passed');
    const regionMap = { ...map, targets: [map.targets[0]] };
    const proof = { ...evaluateEditorPresentation(regionMap, baseline), map: regionMap, measurements: baseline, selection_checks: [], artifacts: [] };
    for (const [surface, page] of Object.entries({ source, frontend, editor: frame })) {
      const file = path.join(output, `${surface}.png`);
      await page.locator('form').screenshot({ path: file });
      proof.artifacts.push({ surface, target: 'form', file });
    }
    proof.pixel_comparisons = ['frontend', 'editor'].map(surface => ({ target: 'form', surface, ...compareVisualParityPngFiles(path.join(output, 'source.png'), path.join(output, `${surface}.png`), path.join(output, `${surface}-diff.png`)) }));
    assert.equal(presentationEvidencePassed(proof), true);
    assert.equal(presentationEvidencePassed({ ...proof, artifacts: [] }), false);
    const contradictory = structuredClone(proof);
    contradictory.measurements.editor.targets[0].rect.width += 30;
    assert.equal(presentationEvidencePassed(contradictory), false, 'a passing status cannot conceal bad raw geometry');
    const badPixels = structuredClone(proof);
    badPixels.pixel_comparisons[1].mismatch_ratio = 0.1;
    assert.equal(presentationEvidencePassed(badPixels), false, 'pixel mismatch gates even with identical geometry');

    for (const [css, id, property] of [
      ['form{width:100%}', 'form', 'rect.width'],
      ['label{margin-bottom:-8px}', 'input', 'rect.y'],
      ['button{width:150px}', 'submit', 'rect.width'],
      ['label{font-weight:700}', 'label', 'styles.font-weight'],
    ]) {
      await frame.setContent(`${html}<style>${css}</style>`);
      const result = evaluateEditorPresentation(map, { ...baseline, editor: await measurePresentation(frame, map, 'editor') });
      assert.equal(result.status, 'failed', css);
      assert.ok(result.findings.some(f => f.target === id && f.property === property && f.surface === 'editor'), JSON.stringify(result.findings));
      assert.ok(!result.findings.some(f => f.surface === 'frontend'));
    }
    await frame.setContent(html.replace('</label>', '<span class="required">(required)</span></label>'));
    let result = evaluateEditorPresentation(map, { ...baseline, editor: await measurePresentation(frame, map, 'editor') });
    assert.ok(result.findings.some(f => f.target === 'required-indicator' && f.property === 'unique_match'));
    assert.ok(result.findings.some(f => f.target === 'label' && f.property === 'text'));

    await frame.setContent(`${html}<style>label::after{content:"(required)"}</style>`);
    result = evaluateEditorPresentation(map, { ...baseline, editor: await measurePresentation(frame, map, 'editor') });
    assert.ok(result.findings.some(f => f.target === 'label' && f.property === 'styles.after-content'));
    await frame.setContent(html.replace('</form>', '<span class="required" hidden>(required)</span></form>'));
    assert.equal(evaluateEditorPresentation(map, { ...baseline, editor: await measurePresentation(frame, map, 'editor') }).status, 'passed');

    for (const broken of [html.replace('<input id=', '<input hidden id='), html.replace('</form>', '<input></form>'), html.replace(/<input[^>]*>/, '')]) {
      await frame.setContent(broken);
      result = evaluateEditorPresentation(map, { ...baseline, editor: await measurePresentation(frame, map, 'editor') });
      assert.equal(result.status, 'failed');
      assert.ok(result.findings.some(f => f.target === 'input' && f.kind === 'evidence_gap'));
      assert.ok(result.coverage.matched < result.coverage.expected);
    }
    const wrongWidth = structuredClone(baseline);
    wrongWidth.editor.viewport.width = 1000;
    assert.equal(evaluateEditorPresentation(map, wrongWidth).status, 'failed');
  } finally { await browser.close(); await rm(output, { recursive: true, force: true }); }
});
