import path from 'node:path';
import { compareVisualParityPngFiles } from './fixture-matrix/image-comparison.mjs';
import { captureAlignedRegion } from './aligned-region-capture.mjs';

const SURFACES = ['source', 'frontend', 'editor'];
const STYLES = ['font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'text-align', 'color', 'background-color', 'border-top-width', 'border-bottom-width', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left'];

/** Selectors come from the caller's source/provider mapping, not text matching. */
export function normalizePresentationMap(map) {
  if (map?.schema !== 'static-site-importer/editor-presentation-map/v1' || !Array.isArray(map.targets) || map.targets.length < 1 || map.targets.length > 128) {
    throw new Error('A bounded editor-presentation-map/v1 with 1-128 targets is required.');
  }
  const ids = new Set();
  for (const target of map.targets) {
    if (!/^[a-z0-9][a-z0-9-]{0,79}$/.test(target.id || '') || ids.has(target.id)) throw new Error('Presentation target IDs must be unique safe identifiers.');
    ids.add(target.id);
    if (!['region', 'heading', 'text', 'label', 'field', 'submit', 'image', 'indicator'].includes(target.role)) throw new Error(`Unsupported presentation role: ${target.role}`);
    if (target.optional !== undefined && typeof target.optional !== 'boolean') throw new Error('Presentation optional must be boolean.');
    if (target.optional === true && target.role !== 'indicator') throw new Error('Only an indicator can be optional; content regions and elements are required.');
    for (const surface of SURFACES) {
      for (const group of ['selectors', 'containers']) {
        const selector = target[group]?.[surface];
        if (typeof selector !== 'string' || !selector.trim() || selector.length > 512) throw new Error(`${target.id}: ${group}.${surface} must be a bounded CSS selector.`);
      }
    }
  }
  if (map.targets.every(target => target.optional === true)) throw new Error('At least one presentation target must be required.');
  if (!map.targets.some(target => target.role === 'region' && target.optional !== true)) throw new Error('At least one required content region must have screenshot parity coverage.');
  return { schema: map.schema, targets: map.targets.map(target => ({ id: target.id, role: target.role, optional: target.optional === true, selectors: { ...target.selectors }, containers: { ...target.containers } })) };
}

/** Runs in either a page or the actual editor frame. Never reads field values. */
export async function measurePresentation(frame, map, surface) {
  return frame.evaluate(({ targets, surface, styles }) => {
    const isVisible = element => {
      const box = element.getBoundingClientRect();
      if (box.width <= 0 || box.height <= 0) return false;
      for (let node = element; node; node = node.parentElement) {
        const style = getComputedStyle(node);
        if (style.display === 'none' || style.visibility !== 'visible' || Number(style.opacity) === 0) return false;
      }
      return true;
    };
    const measure = (selector, containerSelector, role) => {
      const nodes = [...document.querySelectorAll(selector)].filter(element => role !== 'indicator' || isVisible(element));
      const containers = [...document.querySelectorAll(containerSelector)];
      if (nodes.length !== 1 || containers.length !== 1) return { count: nodes.length, container_count: containers.length };
      const element = nodes[0];
      const container = containers[0];
      if (!container.contains(element)) return { count: 1, container_count: 1, error: 'target_outside_container' };
      const box = element.getBoundingClientRect();
      const parent = container.getBoundingClientRect();
      const computed = getComputedStyle(element);
      const visible = isVisible(element);
      const text = ['heading', 'text', 'label', 'submit', 'indicator'].includes(role) ? element.innerText.replace(/\s+/g, ' ').trim() : null;
      if (text?.length > 4096) return { count: 1, container_count: 1, error: 'target_text_budget_exceeded' };
      // Text wrappers may legitimately be block-level on one surface and inline
      // on another. Compare rendered text geometry, not unused wrapper width.
      let bounds = box;
      if (text && ['heading', 'text', 'label'].includes(role)) {
        const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
        const rects = [];
        while (walker.nextNode()) {
          const node = walker.currentNode;
          if (!node.textContent.trim() || !isVisible(node.parentElement)) continue;
          // RichText uses pre-wrap; invisible trailing line spaces are not ink.
          for (const word of node.textContent.matchAll(/\S+/g)) {
            const range = document.createRange();
            range.setStart(node, word.index);
            range.setEnd(node, word.index + word[0].length);
            rects.push(...range.getClientRects());
          }
        }
        if (rects.length) {
          const x = Math.min(...rects.map(rect => rect.x));
          const y = Math.min(...rects.map(rect => rect.y));
          bounds = { x, y, width: Math.max(...rects.map(rect => rect.right)) - x, height: Math.max(...rects.map(rect => rect.bottom)) - y };
        }
      }
      const result = { count: 1, container_count: 1, visible, text, rect: { x: bounds.x - parent.x, y: bounds.y - parent.y, width: bounds.width, height: bounds.height }, styles: Object.fromEntries(styles.map(key => [key, computed.getPropertyValue(key)])) };
      if (text !== null) {
        for (const pseudo of ['before', 'after']) {
          const style = getComputedStyle(element, `::${pseudo}`);
          result.styles[`${pseudo}-content`] = style.display === 'none' || style.visibility !== 'visible' || Number(style.opacity) === 0 || ['none', 'normal'].includes(style.content) ? '' : style.content;
        }
      }
      if (role === 'field') {
        result.placeholder = element.getAttribute('placeholder') || '';
        const placeholder = getComputedStyle(element, '::placeholder');
        result.styles['placeholder-color'] = placeholder.color;
        result.styles['placeholder-opacity'] = placeholder.opacity;
      }
      return result;
    };
    return {
      viewport: { width: window.innerWidth, height: window.innerHeight },
      targets: targets.map(target => {
        try { return { id: target.id, ...measure(target.selectors[surface], target.containers[surface], target.role) }; }
        catch (error) { return { id: target.id, error: error.message }; }
      }),
    };
  }, { targets: map.targets, surface, styles: STYLES });
}

/** Missing or ambiguous evidence fails independently from measured divergence. */
export function evaluateEditorPresentation(map, measurements) {
  map = normalizePresentationMap(map);
  const findings = [];
  const add = (target, surface, property, expected, actual, kind = 'presentation_mismatch') => findings.push({ target, surface, kind, property, expected, actual });
  let matched = 0;
  for (const surface of SURFACES) {
    const value = measurements[surface];
    for (const dimension of ['width', 'height']) {
      const expected = measurements.source?.viewport?.[dimension];
      if (!Number.isFinite(expected) || expected <= 0 || value?.viewport?.[dimension] !== expected) add('*', surface, `viewport.${dimension}`, expected, value?.viewport?.[dimension] ?? null, 'evidence_gap');
    }
  }
  for (const target of map.targets) {
    const rows = Object.fromEntries(SURFACES.map(surface => {
      const matches = measurements[surface]?.targets?.filter(row => row.id === target.id) || [];
      return [surface, matches.length === 1 ? matches[0] : null];
    }));
    const absent = target.optional && rows.source?.count === 0;
    let complete = true;
    for (const surface of SURFACES) {
      const row = rows[surface];
      if (!row || row.error || row.container_count !== 1 || row.count !== (absent ? 0 : 1)) {
        add(target.id, surface, 'unique_match', absent ? 0 : 1, row ?? null, 'evidence_gap');
        complete = false;
      } else if (!absent && (!row.visible || !['x', 'y', 'width', 'height'].every(key => Number.isFinite(row.rect?.[key])) || !STYLES.every(key => typeof row.styles?.[key] === 'string' && row.styles[key] !== ''))) {
        add(target.id, surface, 'visible_complete_measurement', true, row, 'evidence_gap');
        complete = false;
      }
    }
    if (!complete) continue;
    matched += 1;
    if (absent) continue;
    for (const surface of ['frontend', 'editor']) {
      for (const property of ['x', 'y', 'width', 'height']) {
        if (Math.abs(rows.source.rect[property] - rows[surface].rect[property]) > 1) add(target.id, surface, `rect.${property}`, rows.source.rect[property], rows[surface].rect[property]);
      }
      for (const property of Object.keys(rows.source.styles)) {
        if (rows.source.styles[property] !== rows[surface].styles[property]) add(target.id, surface, `styles.${property}`, rows.source.styles[property], rows[surface].styles[property] ?? null);
      }
      for (const property of ['text', 'placeholder']) {
        if (rows.source[property] !== rows[surface][property]) add(target.id, surface, property, rows.source[property] ?? null, rows[surface][property] ?? null);
      }
    }
  }
  return { schema: 'static-site-importer/semantic-editor-presentation/v1', status: findings.length ? 'failed' : 'passed', coverage: { scope: 'declared-targets', expected: map.targets.length, matched }, tolerances: { geometry_px: 1, styles: 'exact', text: 'whitespace-normalized' }, findings };
}

export function presentationEvidencePassed(result) {
  try {
    if (result?.schema !== 'static-site-importer/semantic-editor-presentation/v1' || result.status !== 'passed' || result.findings?.length !== 0 || evaluateEditorPresentation(result.map, result.measurements).status !== 'passed') return false;
    const selectedTargets = result.map.targets.filter(target => ['heading', 'label', 'field', 'submit'].includes(target.role));
    if (!Array.isArray(result.selection_checks) || result.selection_checks.length !== selectedTargets.length) return false;
    for (const target of selectedTargets) {
      const checks = result.selection_checks.filter(check => check.target === target.id);
      if (checks.length !== 1 || checks[0].status !== 'passed' || evaluateEditorPresentation(result.map, { ...result.measurements, editor: checks[0].measurements }).status !== 'passed') return false;
    }
    for (const target of result.map.targets.filter(target => target.role === 'region')) {
      for (const surface of ['frontend', 'editor']) {
        const comparisons = result.pixel_comparisons?.filter(row => row.target === target.id && row.surface === surface) || [];
        if (comparisons.length !== 1 || comparisons[0].mismatch_ratio !== 0 || comparisons[0].dimension_mismatch !== false) return false;
      }
    }
    return result.map.targets.every(target => SURFACES.every(surface => {
      const row = result.measurements[surface].targets.find(item => item.id === target.id);
      return row.count === 0 || result.artifacts?.some(artifact => artifact.surface === surface && artifact.target === target.id && typeof artifact.file === 'string' && artifact.file.length > 0);
    }));
  } catch { return false; }
}

/** Readiness errors are evidence failures, never successful empty screenshots. */
async function ready(frame) {
  await frame.waitForFunction(() => document.readyState === 'complete' && document.fonts.status === 'loaded' && [...document.images].every(image => image.complete && image.naturalWidth > 0), null, { timeout: 30_000 });
  const missing = await frame.evaluate(() => [...document.querySelectorAll('link[rel="stylesheet"]')].filter(link => !link.disabled && !link.sheet).map(link => new URL(link.href).pathname));
  if (missing.length) throw new Error(`Stylesheets unavailable: ${missing.join(', ')}`);
}

export async function captureEditorPresentation(browser, editorPage, options, map, viewport) {
  map = normalizePresentationMap(map);
  const result = { schema: 'static-site-importer/semantic-editor-presentation/v1', status: 'failed', viewport, browser_version: browser.version(), map, measurements: {}, artifacts: [], findings: [] };
  const context = await browser.newContext({ deviceScaleFactor: 1 });
  try {
    await editorPage.setViewportSize({ width: viewport.width, height: viewport.height });
    await editorPage.evaluate(() => window.wp.data.dispatch('core/block-editor').clearSelectedBlock());
    await editorPage.mouse.move(0, 0);
    // Only the known onboarding preference is dismissed; arbitrary dialogs fail.
    await editorPage.evaluate(() => {
      const preferences = window.wp?.data?.select('core/preferences');
      if (preferences?.get('core/edit-post', 'welcomeGuide')) window.wp.data.dispatch('core/preferences').set('core/edit-post', 'welcomeGuide', false);
    });
    await editorPage.locator('.edit-post-welcome-guide').waitFor({ state: 'hidden', timeout: 10_000 });
    if (await editorPage.locator('[role="dialog"]:visible').count()) throw new Error('A dialog obscures the editor canvas.');
    const iframe = editorPage.locator('iframe[name="editor-canvas"]');
    await iframe.waitFor({ state: 'visible', timeout: 30_000 });
    const frame = await (await iframe.elementHandle()).contentFrame();
    await ready(frame);
    // A locator screenshot cannot paint content outside an iframe's viewport.
    // Fit declared regions before measuring; reference surfaces use this same
    // effective viewport, including its height, so vh-dependent layout agrees.
    const fit = await frame.evaluate(targets => ({ height: innerHeight, required: Math.ceil(Math.max(0, ...targets.filter(target => target.role === 'region').map(target => document.querySelector(target.selectors.editor)?.getBoundingClientRect().height || 0))) + 32 }), map.targets);
    if (fit.required > fit.height) {
      const height = viewport.height + fit.required - fit.height;
      if (height > 8192) throw new Error('Editor region exceeds the bounded screenshot viewport.');
      await editorPage.setViewportSize({ width: viewport.width, height });
      await ready(frame);
    }
    result.effective_browser_viewport = editorPage.viewportSize();
    // Gutenberg can poll indefinitely. Asset readiness, not global network idle,
    // determines when the canvas is ready for presentation measurements.
    await frame.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    const canvas = await frame.evaluate(() => ({ width: innerWidth, height: innerHeight }));
    const source = await context.newPage();
    const frontend = await context.newPage();
    for (const page of [source, frontend]) await page.setViewportSize(canvas);
    await Promise.all([source.goto(options.source_url, { waitUntil: 'load', timeout: 60_000 }), frontend.goto(options.candidate_url, { waitUntil: 'load', timeout: 60_000 })]);
    const frames = { source, frontend, editor: frame };
    for (const surface of SURFACES) {
      await ready(frames[surface]);
      result.measurements[surface] = await measurePresentation(frames[surface], map, surface);
      for (const target of map.targets) {
        const row = result.measurements[surface].targets.find(row => row.id === target.id);
        if (row?.count !== 1 || !row.visible) continue;
        const file = `${viewport.name}-${surface}-${target.id}.png`;
        const locator = frames[surface].locator(target.selectors[surface]);
        if (target.role === 'region') {
          const capture = await captureAlignedRegion(locator, { path: path.join(options.output_directory, file), timeout: 10_000 });
          result.artifacts.push({ surface, target: target.id, file, raster_origin: capture.evidence });
        } else {
          await locator.screenshot({ path: path.join(options.output_directory, file), animations: 'disabled', timeout: 10_000 });
          result.artifacts.push({ surface, target: target.id, file });
        }
      }
    }
    Object.assign(result, evaluateEditorPresentation(map, result.measurements));
    result.pixel_comparisons = [];
    for (const target of map.targets.filter(target => target.role === 'region')) {
      for (const surface of ['frontend', 'editor']) {
        const sourceFile = `${viewport.name}-source-${target.id}.png`;
        const candidateFile = `${viewport.name}-${surface}-${target.id}.png`;
        const diffFile = `${viewport.name}-${surface}-${target.id}-diff.png`;
        const comparison = compareVisualParityPngFiles(path.join(options.output_directory, sourceFile), path.join(options.output_directory, candidateFile), path.join(options.output_directory, diffFile));
        result.pixel_comparisons.push({ target: target.id, surface, ...comparison });
        result.artifacts.push({ surface, target: target.id, kind: 'visual_diff', file: diffFile });
        if (comparison.mismatch_ratio !== 0 || comparison.dimension_mismatch) {
          result.status = 'failed';
          result.findings.push({ target: target.id, surface, kind: 'presentation_mismatch', property: 'region_pixels', expected: 0, actual: comparison.mismatch_ratio, dimension_mismatch: comparison.dimension_mismatch });
        }
      }
    }
    result.selection_checks = [];
    for (const target of map.targets.filter(target => ['heading', 'label', 'field', 'submit'].includes(target.role))) {
      const block = frame.locator(target.selectors.editor).locator('xpath=ancestor-or-self::*[@data-block][1]');
      if (await block.count() !== 1) throw new Error(`${target.id}: editable block identity is missing or ambiguous.`);
      const clientId = await block.getAttribute('data-block');
      await block.click({ position: { x: 1, y: 1 }, timeout: 10_000 });
      await editorPage.waitForFunction(id => window.wp.data.select('core/block-editor').getSelectedBlockClientId() === id, clientId, { timeout: 10_000 });
      await frame.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
      const selected = await measurePresentation(frame, map, 'editor');
      const check = evaluateEditorPresentation(map, { ...result.measurements, editor: selected });
      result.selection_checks.push({ target: target.id, ...check, measurements: selected });
      if (check.status !== 'passed') result.status = 'failed';
      const file = `${viewport.name}-editor-selected-${target.id}.png`;
      await block.screenshot({ path: path.join(options.output_directory, file), animations: 'disabled' });
      result.artifacts.push({ surface: 'editor', target: target.id, state: 'selected', file });
    }
    await editorPage.evaluate(() => window.wp.data.dispatch('core/block-editor').clearSelectedBlock());
    const canvasFile = `${viewport.name}-editor-canvas.png`;
    await iframe.screenshot({ path: path.join(options.output_directory, canvasFile) });
    result.artifacts.push({ surface: 'editor', file: canvasFile });
  } catch (error) {
    result.status = 'failed';
    result.findings.push({ kind: 'evidence_gap', property: 'capture', actual: error.message });
  } finally {
    await context.close();
  }
  return result;
}
