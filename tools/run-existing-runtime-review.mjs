#!/usr/bin/env node

/**
 * Review an existing WordPress runtime without involving fixture-matrix or WP
 * Codebox. Studio remains responsible for the supplied auto-login endpoint.
 */
import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { chromium } from 'playwright';
import { compareVisualParityPngFiles } from '../lib/fixture-matrix/image-comparison.mjs';
import { captureEditorPresentation, normalizePresentationMap, presentationEvidencePassed } from '../lib/editor-presentation.mjs';

const DESKTOP = { name: 'desktop', width: 1440, height: 1000 };
const MOBILE = { name: 'mobile', width: 390, height: 844 };

export function normalizeExistingRuntimeReviewOptions(input = {}) {
  const required = ['sourceOrigin', 'candidateOrigin', 'route', 'postId', 'postType', 'editorId', 'authProvider', 'outputDirectory'];
  for (const key of required) {
    if (!String(input[key] ?? '').trim()) throw new Error(`--${toKebab(key)} is required`);
  }
  if (input.authProvider !== 'studio-auto-login') {
    throw new Error('--auth-provider must be studio-auto-login; SSI does not resolve runtime credentials.');
  }
  if (!/^\d+$/.test(String(input.postId))) throw new Error('--post-id must be a numeric WordPress post ID.');
  if (!/^\d+$/.test(String(input.editorId))) throw new Error('--editor-id must be a numeric WordPress user ID.');
  if (!/^[a-z0-9-]+$/i.test(String(input.postType))) throw new Error('--post-type must be a WordPress REST type token.');
  const sourceOrigin = normalizeOrigin(input.sourceOrigin, '--source-origin');
  const candidateOrigin = normalizeOrigin(input.candidateOrigin, '--candidate-origin');
  const route = normalizeRoute(input.route);
  return {
    source_origin: sourceOrigin,
    candidate_origin: candidateOrigin,
    route,
    source_url: new URL(route, sourceOrigin).toString(),
    candidate_url: new URL(route, candidateOrigin).toString(),
    post_id: Number(input.postId),
    post_type: String(input.postType),
    editor_id: Number(input.editorId),
    auth_provider: input.authProvider,
    output_directory: path.resolve(input.outputDirectory),
    presentation_map: input.presentationMap ? path.resolve(input.presentationMap) : null,
  };
}

function normalizeOrigin(value, label) {
  let url;
  try { url = new URL(String(value)); } catch { throw new Error(`${label} must be an absolute URL.`); }
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.pathname !== '/' || url.search || url.hash) {
    throw new Error(`${label} must be an HTTP(S) origin without a path, query, or fragment.`);
  }
  return url.origin;
}

function normalizeRoute(value) {
  const route = String(value).trim();
  if (!route.startsWith('/') || route.startsWith('//') || /[\\\x00-\x1f?#]/.test(route)) {
    throw new Error('--route must be one absolute site path without query or fragment.');
  }
  return route;
}

export function studioAutoLoginUrl(options, postId = options.post_id) {
  const url = new URL('/studio-auto-login', options.candidate_origin);
  url.searchParams.set('redirect_to', `/wp-admin/post.php?post=${postId}&action=edit`);
  return url.toString();
}

export function parseExistingRuntimeReviewArgs(args) {
  if (args[0] === '--acceptance-config' && args.length === 2) return { acceptance_config: path.resolve(args[1]) };
  const input = {};
  for (let index = 0; index < args.length; index += 1) {
    const arg = args[index];
    if (arg === '--help' || arg === '-h') return { help: true };
    if (!arg.startsWith('--')) throw new Error(`Unknown argument: ${arg}`);
    const [key, inline] = arg.slice(2).split('=', 2);
    const value = inline === undefined ? args[++index] : inline;
    if (!value) throw new Error(`--${key} requires a value.`);
    input[toCamel(key)] = value;
  }
  return normalizeExistingRuntimeReviewOptions(input);
}

async function captureComparison(browser, options, viewport) {
  const context = await browser.newContext({ viewport: { width: viewport.width, height: viewport.height }, deviceScaleFactor: 1 });
  try {
    const source = await context.newPage();
    const candidate = await context.newPage();
    await Promise.all([visit(source, options.source_url), visit(candidate, options.candidate_url)]);
    const base = `${viewport.name}-${viewport.width}x${viewport.height}`;
    const sourcePath = path.join(options.output_directory, `source-${base}.png`);
    const candidatePath = path.join(options.output_directory, `candidate-${base}.png`);
    await source.screenshot({ path: sourcePath, fullPage: true });
    await candidate.screenshot({ path: candidatePath, fullPage: true });
    const diffPath = path.join(options.output_directory, `diff-${base}.png`);
    return { viewport, source_screenshot: sourcePath, candidate_screenshot: candidatePath, diff_screenshot: diffPath, ...compareVisualParityPngFiles(sourcePath, candidatePath, diffPath) };
  } finally {
    await context.close();
  }
}

async function visit(page, url) {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60_000 });
  await page.addStyleTag({ content: '* { animation-duration: 0s !important; animation-delay: 0s !important; transition-duration: 0s !important; caret-color: transparent !important; }' });
  await page.evaluate(async () => {
    const wait = (promise, milliseconds) => Promise.race([promise, new Promise((resolve) => setTimeout(resolve, milliseconds))]);
    const height = Math.max(document.documentElement.scrollHeight, document.body?.scrollHeight || 0);
    for (let y = 0; y < height; y += window.innerHeight) window.scrollTo(0, y);
    window.scrollTo(0, 0);
    await wait(document.fonts?.ready, 5_000);
    await Promise.all([...document.images].map(async (image) => {
      image.loading = 'eager';
      if (!image.complete) await wait(new Promise((resolve) => image.addEventListener('load', resolve, { once: true })), 5_000);
      await wait(image.decode?.().catch(() => undefined), 5_000);
    }));
  });
}

async function validatePersistedPost(page, options, postId = options.post_id, expectedMarker = '') {
  await openEditor(page, options, postId);
  await page.waitForFunction(() => Boolean(window.wp?.blocks?.validateBlock && window.wp?.apiFetch), { timeout: 30_000 });
  const persisted = await page.evaluate(async ({ postType, postId }) => {
    const [post, user] = await Promise.all([
      window.wp.apiFetch({ path: `/wp/v2/${postType}/${postId}?context=edit` }),
      window.wp.apiFetch({ path: '/wp/v2/users/me?context=edit' }),
    ]);
    return { content: post.content?.raw || '', link: post.link, author: post.author, user: { id: user.id, slug: user.slug || '' } };
  }, { postType: options.post_type, postId });
  if (persisted.user.id !== options.editor_id) throw new Error(`Authenticated editor ${persisted.user.id} does not match requested editor ${options.editor_id}.`);
  const validation = await page.evaluate((content) => {
    const blocks = window.wp.blocks.parse(content);
    const results = [];
    const visitBlock = (block) => {
      const type = window.wp.blocks.getBlockType(block.name);
      const validation = type ? window.wp.blocks.validateBlock(block, type) : [false, [`Block type "${block.name}" is not registered.`]];
      const valid = Array.isArray(validation) ? validation[0] : validation.isValid;
      results.push({ name: block.name, is_valid: Boolean(valid) });
      for (const child of block.innerBlocks || []) visitBlock(child);
    };
    for (const block of blocks) visitBlock(block);
    return { schema: 'static-site-importer/runtime-editor-validation/v1', provider: 'playwright', validation_method: 'wp.blocks.validateBlock', content_source: 'rest-post-content-raw', total_blocks: results.length, valid_blocks: results.filter((row) => row.is_valid).length, invalid_blocks: results.filter((row) => !row.is_valid).length, results };
  }, persisted.content);
  if (validation.total_blocks === 0) throw new Error(`Persisted ${options.post_type}/${postId} contains zero blocks.`);
  return { ...validation, content_sha256: contentHash(persisted.content), link: persisted.link, ...(expectedMarker ? { marker_present: persisted.content.includes(expectedMarker) } : {}), author: persisted.author, editor: persisted.user };
}

async function openEditor(page, options, postId) {
  if (options.authenticate) {
    await options.authenticate({ page, candidateOrigin: options.candidate_origin, postId });
    await visit(page, new URL(`/wp-admin/post.php?post=${postId}&action=edit`, options.candidate_origin).href);
  } else await visit(page, studioAutoLoginUrl(options, postId));
}

/** Real registered block attributes + core/editor persistence, on a disposable draft. */
export async function reviewAcceptanceEditor(browser, options, scope, mapping) {
  fs.mkdirSync(options.output_directory, { recursive: true });
  // A shared authenticated context owns both editor and unpublished preview.
  const context = await browser.newContext();
  const page = await context.newPage();
  const result = { route: mapping.route, stage: 'editor', status: 'pending', cleanup: { status: 'pending' }, presentations: [] };
  let id;
  let original;
  let mediaBefore;
  let originalPostHash;
  const fatals = [];
  page.on('pageerror', () => fatals.push('uncaught_editor_error'));
  try {
    if (!mapping.text || !mapping.image?.attachmentId || !mapping.image.frontendSelector) { result.reason = 'editable_text_image_mapping_required'; return result; }
    original = await validatePersistedPost(page, options);
    result.original_validation = original;
    if (new URL(original.link).href !== new URL(mapping.route, options.candidate_origin).href) { result.reason = 'route_post_permalink_identity_mismatch'; return result; }
    if (original.invalid_blocks) throw new Error('Original Gutenberg validation failed.');
    const baseline = await page.evaluate(async ({ postType, postId, mediaId }) => {
      const source = await window.wp.apiFetch({ path: `/wp/v2/${postType}/${postId}?context=edit` });
      let media;
      try { media = await window.wp.apiFetch({ path: `/wp/v2/media/${mediaId}?context=edit` }); } catch { return { source, media: null }; }
      return { source, media };
    }, { postType: options.post_type, postId: options.post_id, mediaId: mapping.image?.attachmentId });
    if (!baseline.media?.source_url || !baseline.media.mime_type?.startsWith('image/')) { result.reason = 'existing_media_library_image_unavailable'; return result; }
    originalPostHash = contentHash(JSON.stringify(baseline.source));
    const mediaIds = await page.evaluate(({ content, image }) => {
      const ids = new Set();
      const walk = blocks => blocks.forEach(block => {
        if (Number.isInteger(block.attributes?.id) && window.wp.blocks.getBlockType(block.name)?.attributes?.url) ids.add(block.attributes.id);
        if (Number.isInteger(block.attributes?.mediaId) && window.wp.blocks.getBlockType(block.name)?.attributes?.mediaUrl) ids.add(block.attributes.mediaId);
        if (block.name === image.blockName && Number.isInteger(block.attributes?.[image.idAttribute])) ids.add(block.attributes[image.idAttribute]);
        walk(block.innerBlocks || []);
      });
      walk(window.wp.blocks.parse(content));
      return [...ids];
    }, { content: baseline.source.content.raw, image: mapping.image });
    mediaBefore = { [baseline.media.id]: contentHash(JSON.stringify(baseline.media)) };
    for (const mediaId of mediaIds) {
      const media = await page.evaluate(async id => window.wp.apiFetch({ path: `/wp/v2/media/${id}?context=edit` }), mediaId);
      mediaBefore[mediaId] = contentHash(JSON.stringify(media));
    }
    id = await page.evaluate(async ({ postType, content }) => (await window.wp.apiFetch({ path: `/wp/v2/${postType}`, method: 'POST', data: { title: 'SSI disposable acceptance review', status: 'draft', content } })).id, { postType: options.post_type, content: baseline.source.content.raw });
    result.draft_id = id;
    await validatePersistedPost(page, options, id);
    await page.waitForFunction(id => window.wp?.data?.select('core/editor')?.getCurrentPostId() === id && window.wp.data.select('core/block-editor').getBlocks().length > 0, id);
    assertEditorCanvasUsable(await captureEditorCanvas(page));
    const marker = `SSI acceptance ${Date.now()} ${id}`;
    const edit = await page.evaluate(async ({ text, image, marker, media }) => {
      const select = window.wp.data.select('core/block-editor');
      const blocks = [];
      const walk = rows => rows.forEach(block => { blocks.push(block); walk(block.innerBlocks || []); });
      walk(select.getBlocks());
      const find = spec => blocks.filter(block => block.name === spec?.blockName && (spec.attributeValue === undefined || block.attributes[spec.identityAttribute] === spec.attributeValue));
      const texts = find(text), images = find(image);
      if (texts.length !== 1 || images.length !== 1) return { status: 'pending', reason: 'missing_or_ambiguous_editable_text_image_surface' };
      const textBlock = texts[0], imageBlock = images[0];
      const textType = window.wp.blocks.getBlockType(textBlock.name), imageType = window.wp.blocks.getBlockType(imageBlock.name);
      if (!textType?.attributes?.[text.attribute] || !imageType?.attributes?.[image.urlAttribute] || !imageType?.attributes?.[image.idAttribute]) return { status: 'pending', reason: 'unregistered_editable_attributes' };
      const altAttribute = image.altAttribute || (image.blockName === 'core/image' ? 'alt' : null);
      if (altAttribute && !imageType.attributes[altAttribute]) return { status: 'pending', reason: 'unregistered_editable_alt_attribute' };
      if (imageBlock.attributes[image.idAttribute] === media.id || imageBlock.attributes[image.urlAttribute] === media.source_url) return { status: 'pending', reason: 'replacement_image_must_differ' };
      const dispatch = window.wp.data.dispatch('core/block-editor');
      dispatch.updateBlockAttributes(textBlock.clientId, { [text.attribute]: marker });
      dispatch.updateBlockAttributes(imageBlock.clientId, { [image.urlAttribute]: media.source_url, [image.idAttribute]: media.id, ...(altAttribute ? { [altAttribute]: `${marker} image alt` } : {}) });
      await window.wp.data.dispatch('core/editor').savePost();
      const editor = window.wp.data.select('core/editor');
      if (editor.isEditedPostDirty() || editor.didPostSaveRequestFail()) return { status: 'failed', reason: 'editor_save_failed' };
      return { status: 'passed', marker, text_index: blocks.indexOf(textBlock), image_index: blocks.indexOf(imageBlock) };
    }, { text: mapping.text, image: mapping.image, marker, media: baseline.media });
    result.edit = edit;
    if (edit.status !== 'passed') { result.status = edit.status; return result; }
    const reloaded = await validatePersistedPost(page, options, id, marker);
    await page.waitForFunction(id => window.wp?.data?.select('core/editor')?.getCurrentPostId() === id && window.wp.data.select('core/block-editor').getBlocks().length > 0, id);
    result.reloaded_validation = reloaded;
    const persisted = await page.evaluate(({ text, image, marker, media, textIndex, imageIndex }) => {
      const blocks = [];
      const walk = rows => rows.forEach(block => { blocks.push(block); walk(block.innerBlocks || []); });
      walk(window.wp.data.select('core/block-editor').getBlocks());
      const savedText = blocks[textIndex], savedImage = blocks[imageIndex];
      // Gutenberg may hydrate rich-text attributes as RichTextData after reload.
      // Compare their public string value, not object identity with the edit input.
      const altAttribute = image.altAttribute || (image.blockName === 'core/image' ? 'alt' : null);
      return { text: savedText?.name === text.blockName && String(savedText.attributes[text.attribute]) === marker, image: savedImage?.name === image.blockName && savedImage.attributes[image.idAttribute] === media.id && savedImage.attributes[image.urlAttribute] === media.source_url, alt: !altAttribute || savedImage?.attributes[altAttribute] === `${marker} image alt` };
    }, { text: mapping.text, image: mapping.image, marker, media: baseline.media, textIndex: edit.text_index, imageIndex: edit.image_index });
    result.persisted = persisted;
    if (!reloaded.marker_present || reloaded.invalid_blocks || !persisted.text || !persisted.image || !persisted.alt || original.content_sha256 === reloaded.content_sha256) throw new Error('Saved edits did not survive editor reload.');
    const preview = await page.context().newPage();
    try {
      // Same authenticated context; draft stays unpublished.
      const previewUrl = new URL(reloaded.link);
      if (previewUrl.origin !== options.candidate_origin) throw new Error('Draft preview leaves the candidate runtime.');
      previewUrl.searchParams.set('preview', 'true');
      await visit(preview, previewUrl.href);
      result.frontend = await preview.evaluate(({ marker, url, selector, checkAlt }) => {
        const images = [...document.querySelectorAll(selector)];
        return { text: document.body.innerText.includes(marker), image: images.length === 1 && images[0].tagName === 'IMG' && images[0].src === url && images[0].complete && images[0].naturalWidth > 0, alt: !checkAlt || (images.length === 1 && images[0].getAttribute('alt') === `${marker} image alt`) };
      }, { marker, url: baseline.media.source_url, selector: mapping.image.frontendSelector, checkAlt: Boolean(mapping.image.altAttribute || mapping.image.blockName === 'core/image') });
      if (!result.frontend.text || !result.frontend.image || !result.frontend.alt) throw new Error('Draft frontend does not render saved text/image edits.');
    } finally { await preview.close(); }
    // Reuse presentation/oracle against the frozen portable surface, never live origin.
    if (mapping.presentationMap && options.portable_origin) {
      await validatePersistedPost(page, options);
      const map = normalizePresentationMap(JSON.parse(fs.readFileSync(mapping.presentationMap, 'utf8')));
      for (const width of scope.widths) result.presentations.push(await captureEditorPresentation(browser, page, { ...options, source_url: new URL(mapping.route, options.portable_origin).href, candidate_url: new URL(mapping.route, options.candidate_origin).href }, map, { name: `width-${width}`, width, height: 1000 }));
      result.status = result.presentations.every(presentationEvidencePassed) ? 'passed' : result.presentations.some(row => row.findings?.some(finding => finding.kind === 'presentation_mismatch')) ? 'failed' : 'pending';
    } else { result.status = 'pending'; result.reason = 'frozen_editor_presentation_required'; }
  } catch (error) {
    result.status = error.name === 'TimeoutError' ? 'pending' : 'failed';
    result.reason = redactRuntimeError(error.message);
  } finally {
    try {
      if (id) {
        await openEditor(page, options, id);
        await page.waitForFunction(() => Boolean(window.wp?.apiFetch));
        await page.evaluate(async ({ id, type }) => {
          await window.wp.apiFetch({ path: `/wp/v2/${type}/${id}?force=true`, method: 'DELETE' });
          try { await window.wp.apiFetch({ path: `/wp/v2/${type}/${id}` }); throw new Error('Draft still exists'); } catch (error) { if (error?.data?.status !== 404 && error?.code !== 'rest_post_invalid_id') throw error; }
        }, { id, type: options.post_type });
      }
      if (original) {
        const after = await validatePersistedPost(page, options);
        assertReviewDraftLifecycle({ target_baseline_sha256: original.content_sha256, target_after_sha256: after.content_sha256, target: mapping.route });
        if (originalPostHash) {
          const afterPost = await page.evaluate(async ({ type, id }) => window.wp.apiFetch({ path: `/wp/v2/${type}/${id}?context=edit` }), { type: options.post_type, id: options.post_id });
          result.isolation = { original_before_sha256: originalPostHash, original_after_sha256: contentHash(JSON.stringify(afterPost)), media: [] };
          if (result.isolation.original_after_sha256 !== originalPostHash) throw new Error('Original post changed during review.');
        }
        if (mediaBefore) for (const [mediaId, beforeHash] of Object.entries(mediaBefore)) {
          const afterMedia = await page.evaluate(async mediaId => window.wp.apiFetch({ path: `/wp/v2/media/${mediaId}?context=edit` }), Number(mediaId));
          const afterHash = contentHash(JSON.stringify(afterMedia));
          result.isolation.media.push({ attachment_id: Number(mediaId), before_sha256: beforeHash, after_sha256: afterHash });
          if (afterHash !== beforeHash) throw new Error('Media attachment changed during review.');
        }
      }
      result.cleanup.status = 'passed';
    } catch (error) { result.cleanup = { status: 'failed', reason: redactRuntimeError(error.message) }; result.status = 'failed'; }
    if (fatals.length) { result.fatals = fatals; result.status = 'failed'; }
    try { await page.close(); } catch { result.cleanup.status = 'failed'; result.status = 'failed'; }
    try { await context.close(); } catch { result.cleanup.status = 'failed'; result.status = 'failed'; }
  }
  return result;
}

async function captureEditorCanvas(page) {
  const canvasFrame = page.locator('iframe[name="editor-canvas"]');
  await canvasFrame.waitFor({ state: 'attached', timeout: 30_000 });
  const canvas = page.frameLocator('iframe[name="editor-canvas"]');
  await canvas.locator('.editor-styles-wrapper').waitFor({ state: 'attached', timeout: 30_000 });
  const summary = await canvas.locator('[data-block]').evaluateAll((blocks) => {
    const visible = blocks.filter((block) => {
      const style = getComputedStyle(block);
      const rect = block.getBoundingClientRect();
      return rect.width > 0 && rect.height > 0 && style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
    });
    return {
      total_blocks: blocks.length,
      visible_blocks: visible.length,
      visible_text_blocks: visible.filter((block) => block.textContent.trim().length > 0).length,
    };
  });
  return { schema: 'static-site-importer/runtime-editor-canvas/v1', canvas_document_type: 'iframe', ...summary };
}

export function assertEditorCanvasUsable(canvas) {
  if (canvas?.canvas_document_type !== 'iframe') throw new Error('Gutenberg editor canvas was not rendered in its iframe.');
  if (!Number.isInteger(canvas.total_blocks) || canvas.total_blocks < 1) throw new Error('Gutenberg editor canvas contains zero blocks.');
  if (!Number.isInteger(canvas.visible_blocks) || canvas.visible_blocks < 1) throw new Error('Gutenberg editor canvas contains no visible blocks.');
  if (!Number.isInteger(canvas.visible_text_blocks) || canvas.visible_text_blocks < 1) throw new Error('Gutenberg editor canvas contains no visible editable content.');
}

async function reviewDraft(page, options, targetBaseline) {
  const marker = `ssi-existing-runtime-review-${Date.now()}`;
  let id = 0;
  try {
    await visit(page, studioAutoLoginUrl(options));
    id = await page.evaluate(async ({ marker, postType, postId }) => {
      const source = await window.wp.apiFetch({ path: `/wp/v2/${postType}/${postId}?context=edit` });
      const post = await window.wp.apiFetch({ path: `/wp/v2/${postType}`, method: 'POST', data: { title: marker, status: 'draft', content: source.content?.raw || '' } });
      return post.id;
    }, { marker, postType: options.post_type, postId: options.post_id });
    const initial = await validatePersistedPost(page, options, id);
    const initialCanvas = await captureEditorCanvas(page);
    assertEditorCanvasUsable(initialCanvas);
    await page.evaluate(async (marker) => {
      const editor = window.wp.data.dispatch('core/editor');
      const blockEditor = window.wp.data.dispatch('core/block-editor');
      blockEditor.insertBlocks(window.wp.blocks.createBlock('core/paragraph', { content: `${marker} saved` }));
      await editor.savePost();
    }, marker);
    const reloaded = await validatePersistedPost(page, options, id, `${marker} saved`);
    const reloadedCanvas = await captureEditorCanvas(page);
    assertEditorCanvasUsable(reloadedCanvas);
    assertReviewDraftLifecycle({ marker_present: reloaded.marker_present });
    return { status: initial.invalid_blocks === 0 && reloaded.invalid_blocks === 0 ? 'passed' : 'failed', marker, post_id: id, initial_validation: initial, reloaded_validation: reloaded, initial_canvas: initialCanvas, reloaded_canvas: reloadedCanvas, persisted: true };
  } finally {
    if (id) {
      await page.evaluate(async ({ id, postType }) => window.wp.apiFetch({ path: `/wp/v2/${postType}/${id}?force=true`, method: 'DELETE' }), { id, postType: options.post_type });
      const deleted = await page.evaluate(async ({ id, postType }) => {
        try { await window.wp.apiFetch({ path: `/wp/v2/${postType}/${id}?context=edit` }); return false; } catch (error) { return error?.code === 'rest_post_invalid_id' || error?.data?.status === 404; }
      }, { id, postType: options.post_type });
      const targetAfter = await validatePersistedPost(page, options);
      assertReviewDraftLifecycle({ deleted, target_baseline_sha256: targetBaseline.content_sha256, target_after_sha256: targetAfter.content_sha256, draft_id: id, target: `${options.post_type}/${options.post_id}` });
    }
  }
}

export async function runExistingRuntimeReview(options) {
  fs.mkdirSync(options.output_directory, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const result = { schema: 'static-site-importer/existing-runtime-review/v2', captured_at: new Date().toISOString(), runtime: { source_origin: options.source_origin, candidate_origin: options.candidate_origin, route: options.route, source_url: options.source_url, candidate_url: options.candidate_url, post_id: options.post_id, post_type: options.post_type, editor_id: options.editor_id, auth_provider: options.auth_provider }, comparisons: [] };
  try {
    result.comparisons.push(await captureComparison(browser, options, DESKTOP), await captureComparison(browser, options, MOBILE));
    result.visual_parity = {
      status: result.comparisons.every((comparison) => comparison.mismatch_ratio === 0 && !comparison.dimension_mismatch) ? 'passed' : 'failed',
      mismatch_ratio: Math.max(...result.comparisons.map((comparison) => comparison.mismatch_ratio)),
    };
    const editor = await browser.newPage();
    try {
      result.editor_validation = await validatePersistedPost(editor, options);
      result.editor_canvas = await captureEditorCanvas(editor);
      assertEditorCanvasUsable(result.editor_canvas);
      result.editor_presentation = { status: 'failed', comparisons: [] };
      if (!options.presentation_map) {
        result.editor_presentation.reason = 'An explicit --presentation-map is required to prove editor presentation.';
      } else {
        const map = normalizePresentationMap(JSON.parse(fs.readFileSync(options.presentation_map, 'utf8')));
        for (const viewport of [DESKTOP, MOBILE]) {
          result.editor_presentation.comparisons.push(await captureEditorPresentation(browser, editor, options, map, viewport));
        }
        result.editor_presentation.status = result.editor_presentation.comparisons.every(presentationEvidencePassed) ? 'passed' : 'failed';
      }
      result.review_draft = await reviewDraft(editor, options, result.editor_validation);
    } finally { await editor.close(); }
    result.status = existingRuntimeReviewPassed(result) ? 'passed' : 'failed';
  } catch (error) {
    result.status = 'failed';
    result.error = redactRuntimeError(error instanceof Error ? error.message : String(error));
  } finally { await browser.close(); }
  fs.writeFileSync(path.join(options.output_directory, 'existing-runtime-review.json'), `${JSON.stringify(result, null, 2)}\n`);
  return result;
}

function toCamel(value) { return value.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase()); }
function toKebab(value) { return value.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`); }
function contentHash(content) { return createHash('sha256').update(content).digest('hex'); }
export function existingRuntimeReviewPassed(result) {
  const comparisons = result.editor_presentation?.comparisons;
  return result.visual_parity?.status === 'passed' && result.editor_validation?.total_blocks > 0 && result.editor_validation.invalid_blocks === 0 && result.review_draft?.status === 'passed' && result.editor_presentation?.status === 'passed' && comparisons?.length === 2 && comparisons.map(row => row.viewport?.name).sort().join(',') === 'desktop,mobile' && comparisons.every(presentationEvidencePassed);
}
export function assertReviewDraftLifecycle(state) {
  if (state.marker_present === false) throw new Error('Dedicated review draft marker was not found in reloaded persisted content.');
  if (state.deleted === false) throw new Error(`Dedicated review draft ${state.draft_id} still exists after cleanup.`);
  if (state.target_baseline_sha256 && state.target_after_sha256 !== state.target_baseline_sha256) throw new Error(`Target ${state.target} content changed during review.`);
}
function redactRuntimeError(message) { return String(message).replace(/https?:\/\/[^\s/@]+(?::[^\s/@]*)?@/gi, (match) => match.slice(0, match.indexOf('//') + 2)).replace(/(authorization|cookie|token|password)=([^\s&]+)/gi, '$1=[redacted]'); }
function printHelp() { process.stdout.write('Unified acceptance: node tools/run-existing-runtime-review.mjs --acceptance-config <caller-config.mjs>\nLegacy review: node tools/run-existing-runtime-review.mjs --source-origin <url> --candidate-origin <url> --route </path> --post-id <id> --post-type <pages> --editor-id <id> --auth-provider studio-auto-login --presentation-map <json-file> --output-directory <dir>\n'); }

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const options = parseExistingRuntimeReviewArgs(process.argv.slice(2));
  if (options.help) printHelp(); else {
    let result;
    let artifact;
    if (options.acceptance_config) {
      const { runExistingRuntimeAcceptance } = await import('../lib/run-existing-runtime-acceptance.mjs');
      const config = (await import(pathToFileURL(options.acceptance_config))).default;
      result = await runExistingRuntimeAcceptance(config);
      artifact = path.resolve(config.outputDirectory, 'existing-runtime-acceptance.json');
    } else {
      result = await runExistingRuntimeReview(options);
      artifact = path.join(options.output_directory, 'existing-runtime-review.json');
    }
    process.stdout.write(`${JSON.stringify({ status: result.status, artifact })}\n`);
    if (!['passed', 'accepted'].includes(result.status)) process.exitCode = 1;
  }
}
