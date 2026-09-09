#!/usr/bin/env node

/**
 * Review an existing WordPress runtime without involving fixture-matrix or WP
 * Codebox. Studio remains responsible for the supplied auto-login endpoint.
 */
import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { compareVisualParityPngFiles } from '../lib/fixture-matrix/image-comparison.mjs';

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
  await visit(page, studioAutoLoginUrl(options, postId));
  await page.waitForFunction(() => Boolean(window.wp?.blocks?.validateBlock && window.wp?.apiFetch), { timeout: 30_000 });
  const persisted = await page.evaluate(async ({ postType, postId }) => {
    const [post, user] = await Promise.all([
      window.wp.apiFetch({ path: `/wp/v2/${postType}/${postId}?context=edit` }),
      window.wp.apiFetch({ path: '/wp/v2/users/me?context=edit' }),
    ]);
    return { content: post.content?.raw || '', author: post.author, user: { id: user.id, slug: user.slug || '' } };
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
  return { ...validation, content_sha256: contentHash(persisted.content), ...(expectedMarker ? { marker_present: persisted.content.includes(expectedMarker) } : {}), author: persisted.author, editor: persisted.user };
}

async function reviewDraft(page, options, targetBaseline) {
  const marker = `ssi-existing-runtime-review-${Date.now()}`;
  let id = 0;
  try {
    await visit(page, studioAutoLoginUrl(options));
    id = await page.evaluate(async ({ marker, postType }) => {
      const post = await window.wp.apiFetch({ path: `/wp/v2/${postType}`, method: 'POST', data: { title: marker, status: 'draft', content: `<!-- wp:paragraph --><p>${marker}</p><!-- /wp:paragraph -->` } });
      return post.id;
    }, { marker, postType: options.post_type });
    const initial = await validatePersistedPost(page, options, id);
    await page.evaluate(async (marker) => {
      const editor = window.wp.data.dispatch('core/editor');
      editor.editPost({ content: `<!-- wp:paragraph --><p>${marker} saved</p><!-- /wp:paragraph -->` });
      await editor.savePost();
    }, marker);
    const reloaded = await validatePersistedPost(page, options, id, `${marker} saved`);
    assertReviewDraftLifecycle({ marker_present: reloaded.marker_present });
    return { status: initial.invalid_blocks === 0 && reloaded.invalid_blocks === 0 ? 'passed' : 'failed', marker, post_id: id, initial_validation: initial, reloaded_validation: reloaded, persisted: true };
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
      result.review_draft = await reviewDraft(editor, options, result.editor_validation);
    } finally { await editor.close(); }
    result.status = result.visual_parity.status === 'passed' && result.editor_validation.invalid_blocks === 0 && result.review_draft.status === 'passed' ? 'passed' : 'failed';
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
export function assertReviewDraftLifecycle(state) {
  if (state.marker_present === false) throw new Error('Dedicated review draft marker was not found in reloaded persisted content.');
  if (state.deleted === false) throw new Error(`Dedicated review draft ${state.draft_id} still exists after cleanup.`);
  if (state.target_baseline_sha256 && state.target_after_sha256 !== state.target_baseline_sha256) throw new Error(`Target ${state.target} content changed during review.`);
}
function redactRuntimeError(message) { return String(message).replace(/https?:\/\/[^\s/@]+(?::[^\s/@]*)?@/gi, (match) => match.slice(0, match.indexOf('//') + 2)).replace(/(authorization|cookie|token|password)=([^\s&]+)/gi, '$1=[redacted]'); }
function printHelp() { process.stdout.write('Usage: node tools/run-existing-runtime-review.mjs --source-origin <url> --candidate-origin <url> --route </path> --post-id <id> --post-type <pages> --editor-id <id> --auth-provider studio-auto-login --output-directory <dir>\n'); }

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const options = parseExistingRuntimeReviewArgs(process.argv.slice(2));
  if (options.help) printHelp(); else {
    const result = await runExistingRuntimeReview(options);
    process.stdout.write(`${JSON.stringify({ status: result.status, artifact: path.join(options.output_directory, 'existing-runtime-review.json') })}\n`);
    if (result.status !== 'passed') process.exitCode = 1;
  }
}
