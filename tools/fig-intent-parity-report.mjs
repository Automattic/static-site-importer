#!/usr/bin/env node

/**
 * Compare Figma transformer intent evidence, generated static artifacts, and an
 * optional imported WordPress site. This is intentionally file/CLI based so a
 * prior transform/import run can be audited deterministically without a browser.
 */
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const packageRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));

if (import.meta.url === `file://${process.argv[1]}`) {
  const options = parseArgs(process.argv.slice(2));
  if (options.help) {
    printHelp();
    process.exit(0);
  }

  const report = buildFigIntentParityReport(options);
  const outputDirectory = path.resolve(options.outputDir || options.outputDirectory || path.join(process.cwd(), 'artifacts', 'fig-intent-parity'));
  fs.mkdirSync(outputDirectory, { recursive: true });
  const jsonPath = path.join(outputDirectory, `${report.fixture.id}-fig-intent-parity.json`);
  const markdownPath = path.join(outputDirectory, `${report.fixture.id}-fig-intent-parity.md`);
  fs.writeFileSync(jsonPath, `${JSON.stringify(report, null, 2)}\n`);
  fs.writeFileSync(markdownPath, renderMarkdown(report));
  process.stdout.write(`${JSON.stringify({ status: report.status, json_path: jsonPath, markdown_path: markdownPath, regressions: report.regressions.length }, null, 2)}\n`);
}

export function buildFigIntentParityReport(options = {}) {
  const summaryPath = path.resolve(requiredOption(options, 'summary'));
  const summary = readJson(summaryPath);
  const fixture = findFixture(summary, options.fixtureId);
  const artifactDir = path.resolve(options.artifactDir || fixture.artifact_dir || fixture.artifactDir || path.join(path.dirname(summaryPath), fixture.id));
  const inspectPath = path.resolve(options.inspect || fixture.inspect_path || path.join(path.dirname(summaryPath), `${fixture.id}-inspect.json`));
  const resultPath = path.resolve(options.result || fixture.result_path || path.join(path.dirname(summaryPath), `${fixture.id}-result.json`));
  const inspect = fs.existsSync(inspectPath) ? readJson(inspectPath) : null;
  const result = fs.existsSync(resultPath) ? readJson(resultPath) : null;
  const generated = inspectGeneratedArtifacts(artifactDir);
  const intentText = extractIntentText(result, new Set(fixture.selected_frame_ids || []));
  const generatedText = normalizeText(generated.text).toLowerCase();
  const missingGeneratedText = intentText.values.filter((text) => !generatedText.includes(normalizeText(text).toLowerCase()));
  const wordpress = options.wpRoot ? inspectWordPressSite(path.resolve(options.wpRoot), options) : null;
  const expectedPageSlugs = expectedWordPressPageSlugs(generated.html_files);
  const missingWordPressPageSlugs = wordpress ? missingPageSlugs(expectedPageSlugs, wordpress.pages) : [];
  const regressions = collectRegressions({ fixture, result, generated, intentText, missingGeneratedText, wordpress, missingWordPressPageSlugs });

  return {
    schema: 'static-site-importer/fig-intent-parity-report/v1',
    status: regressions.some((row) => row.severity === 'error') ? 'failed' : regressions.length ? 'warning' : 'passed',
    generated_at: new Date().toISOString(),
    paths: { summary: summaryPath, inspect: inspectPath, result: resultPath, artifact_dir: artifactDir, wp_root: options.wpRoot ? path.resolve(options.wpRoot) : null },
    fixture: {
      id: fixture.id,
      fig_path: fixture.path || null,
      selected_frame_ids: fixture.selected_frame_ids || [],
      selected_frames: fixture.selected_frames || [],
      entry_frame_id: fixture.entry_frame_id || null,
    },
    figma_intent: {
      decoded_node_count: result?.metrics?.node_count ?? fixture.metrics?.node_count ?? null,
      inspected_node_count: inspect?.node_count ?? fixture.inspection?.node_count ?? null,
      candidate_count: inspect?.candidate_count ?? fixture.inspection?.candidate_count ?? null,
      selected_frame_count: (fixture.selected_frame_ids || []).length,
      selected_frame_widths: (fixture.selected_frames || []).map((frame) => ({ id: frame.id, name: frame.name, width: frame.width, device_hint: frame.device_hint || null })),
      text_node_count: result?.metrics?.text_node_count ?? fixture.metrics?.text_node_count ?? null,
      extracted_text_count: intentText.values.length,
      extracted_text_sample: intentText.values.slice(0, 25),
      asset_reference_count: result?.metrics?.asset_reference_count ?? fixture.metrics?.asset_reference_count ?? null,
      embedded_asset_count: result?.metrics?.embedded_asset_count ?? fixture.metrics?.embedded_asset_count ?? null,
      diagnostic_codes: fixture.diagnostic_codes || result?.diagnostic_codes || {},
    },
    generated_artifacts: generated,
    wordpress,
    comparisons: {
      expected_page_count: result?.metrics?.page_count ?? fixture.metrics?.page_count ?? (fixture.selected_frame_ids || []).length,
      expected_wordpress_page_slugs: expectedPageSlugs,
      generated_page_count: generated.html_files.length,
      wordpress_page_count: wordpress?.pages.length ?? null,
      missing_wordpress_page_slugs: missingWordPressPageSlugs,
      missing_generated_text_count: missingGeneratedText.length,
      missing_generated_text_sample: missingGeneratedText.slice(0, 25),
      missing_wordpress_text_count: wordpress ? intentText.values.filter((text) => !wordpress.normalized_text.includes(normalizeText(text).toLowerCase())).length : null,
      missing_wordpress_text_sample: wordpress ? intentText.values.filter((text) => !wordpress.normalized_text.includes(normalizeText(text).toLowerCase())).slice(0, 25) : [],
    },
    regressions,
  };
}

function inspectGeneratedArtifacts(artifactDir) {
  const files = listFiles(artifactDir);
  const htmlFiles = files.filter((file) => /\.html?$/i.test(file));
  const cssFiles = files.filter((file) => /\.css$/i.test(file));
  const assetFiles = files.filter((file) => /\.(avif|gif|jpe?g|png|svg|webp|woff2?|ttf|otf)$/i.test(file));
  const assetSet = new Set(files.map((file) => file.split(path.sep).join('/')));
  const links = [];
  let text = '';

  for (const file of htmlFiles) {
    const fullPath = path.join(artifactDir, file);
    const html = fs.readFileSync(fullPath, 'utf8');
    text += ` ${extractVisibleText(html)}`;
    for (const url of extractHtmlUrls(html)) {
      links.push(linkStatus({ source: file, url, artifactDir, assetSet }));
    }
  }

  for (const file of cssFiles) {
    const css = fs.readFileSync(path.join(artifactDir, file), 'utf8');
    for (const url of extractCssUrls(css)) {
      links.push(linkStatus({ source: file, url, artifactDir, assetSet }));
    }
  }

  return {
    html_files: htmlFiles,
    css_files: cssFiles,
    asset_files: assetFiles,
    image_asset_count: assetFiles.filter((file) => /\.(avif|gif|jpe?g|png|svg|webp)$/i.test(file)).length,
    text_character_count: normalizeText(text).length,
    local_reference_count: links.length,
    unresolved_local_references: links.filter((link) => !link.exists),
    max_declared_width_px: maxCssPixelWidth(cssFiles.map((file) => fs.readFileSync(path.join(artifactDir, file), 'utf8')).join('\n')),
    responsive_warnings: responsiveWarnings(cssFiles.map((file) => fs.readFileSync(path.join(artifactDir, file), 'utf8')).join('\n')),
    text,
  };
}

function inspectWordPressSite(wpRoot, options) {
  const wpCli = splitCommand(options.wpCli || 'studio wp');
  const baseArgs = [`--path=${wpRoot}`];
  const pageList = runJsonCommand(wpCli, [...baseArgs, 'post', 'list', '--post_type=page', '--post_status=any', '--fields=ID,post_title,post_name', '--format=json']);
  const pages = pageList.map((page) => {
    const content = runTextCommand(wpCli, [...baseArgs, 'post', 'get', String(page.ID), '--field=post_content']);
    const blockNames = parseSerializedBlockNames(content);
    return {
      id: Number(page.ID),
      title: page.post_title,
      slug: page.post_name,
      block_count: blockNames.length,
      fallback_block_count: blockNames.filter((name) => name === 'core/html' || name === 'core/freeform').length,
      block_type_counts: counts(blockNames),
      text_character_count: normalizeText(extractVisibleText(content)).length,
      unresolved_local_references: extractHtmlUrls(content).filter((url) => isLocalUrl(url)).map((url) => ({ url })),
      content,
    };
  });
  const stylesheet = runTextCommand(wpCli, [...baseArgs, 'option', 'get', 'stylesheet'], { optional: true }).trim();
  const themeDir = stylesheet ? path.join(wpRoot, 'wp-content', 'themes', stylesheet) : null;
  const themeFiles = themeDir && fs.existsSync(themeDir) ? listFiles(themeDir) : [];
  const normalizedText = normalizeText(pages.map((page) => extractVisibleText(page.content)).join(' ')).toLowerCase();

  return {
    wp_root: wpRoot,
    stylesheet: stylesheet || null,
    theme_asset_count: themeFiles.filter((file) => /\.(avif|gif|jpe?g|png|svg|webp|woff2?|ttf|otf|css)$/i.test(file)).length,
    theme_css_files: themeFiles.filter((file) => /\.css$/i.test(file)),
    pages: pages.map(({ content, ...page }) => page),
    fallback_block_count: pages.reduce((total, page) => total + page.fallback_block_count, 0),
    block_count: pages.reduce((total, page) => total + page.block_count, 0),
    normalized_text: normalizedText,
  };
}

function collectRegressions({ fixture, result, generated, intentText, missingGeneratedText, wordpress, missingWordPressPageSlugs = [] }) {
  const regressions = [];
  const expectedPages = result?.metrics?.page_count ?? fixture.metrics?.page_count ?? (fixture.selected_frame_ids || []).length;
  const assetReferenceCount = result?.metrics?.asset_reference_count ?? fixture.metrics?.asset_reference_count ?? 0;
  const selectedWidths = (fixture.selected_frames || []).map((frame) => Number(frame.width || 0)).filter(Boolean);

  if (expectedPages && generated.html_files.length < expectedPages) {
    regressions.push(regression('error', 'missing_generated_pages', `Generated ${generated.html_files.length} HTML pages for ${expectedPages} selected Figma pages.`));
  }
  if (assetReferenceCount > 0 && generated.image_asset_count === 0) {
    regressions.push(regression('error', 'dropped_generated_images', `${assetReferenceCount} Figma asset references but no generated image assets.`));
  }
  if (generated.unresolved_local_references.length) {
    regressions.push(regression('error', 'unresolved_generated_local_references', `${generated.unresolved_local_references.length} generated HTML/CSS local references do not resolve.`));
  }
  if (intentText.values.length && missingGeneratedText.length / intentText.values.length > 0.1) {
    regressions.push(regression('warning', 'missing_generated_text', `${missingGeneratedText.length}/${intentText.values.length} extracted Figma text strings were not found in generated HTML.`));
  }
  if (selectedWidths.some((width) => width > 1600) && generated.max_declared_width_px > 1600) {
    regressions.push(regression('warning', 'large_fixed_width', `Generated CSS declares max width ${generated.max_declared_width_px}px for wide Figma frames; verify responsive behavior.`));
  }
  for (const warning of generated.responsive_warnings) {
    regressions.push(regression('warning', warning.code, warning.message));
  }

  if (wordpress) {
    if (expectedPages && wordpress.pages.length < expectedPages) {
      regressions.push(regression('error', 'missing_wordpress_pages', `Imported WordPress site has ${wordpress.pages.length} pages for ${expectedPages} selected Figma pages.`));
    }
    if (missingWordPressPageSlugs.length) {
      regressions.push(regression('error', 'missing_wordpress_page_slugs', `Imported WordPress site is missing expected generated page slugs: ${missingWordPressPageSlugs.join(', ')}.`));
    }
    if (wordpress.theme_css_files.length === 0) {
      regressions.push(regression('error', 'missing_wordpress_theme_css', 'Active WordPress theme has no CSS files.'));
    }
    if (wordpress.fallback_block_count > 0) {
      regressions.push(regression('error', 'fallback_blocks_present', `Imported WordPress pages contain ${wordpress.fallback_block_count} core/html or core/freeform fallback blocks.`));
    }
    const missingWpText = intentText.values.filter((text) => !wordpress.normalized_text.includes(normalizeText(text).toLowerCase()));
    if (intentText.values.length && missingWpText.length / intentText.values.length > 0.1) {
      regressions.push(regression('warning', 'missing_wordpress_text', `${missingWpText.length}/${intentText.values.length} extracted Figma text strings were not found in WordPress page content.`));
    }
  }

  return regressions;
}

function expectedWordPressPageSlugs(htmlFiles) {
  return htmlFiles
    .map((file) => path.basename(file).replace(/\.html?$/i, ''))
    .map((slug) => (slug === 'index' ? 'home' : slug))
    .filter(Boolean)
    .sort();
}

function missingPageSlugs(expectedSlugs, pages) {
  const actual = new Set();
  for (const page of pages) {
    actual.add(slugify(page.slug));
    actual.add(slugify(page.title));
  }
  return expectedSlugs.filter((slug) => !actual.has(slugify(slug)));
}

function slugify(value) {
  return String(value || '')
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

function regression(severity, code, message) {
  return { severity, code, message };
}

function extractIntentText(result, selectedFrameIds = new Set()) {
  const values = [];
  const seen = new Set();
  visit(result, (node) => {
    if (!node || typeof node !== 'object' || node.type !== 'TEXT' || typeof node.name !== 'string') {
      return;
    }
    if (selectedFrameIds.size && node.source_page_frame_id && !selectedFrameIds.has(node.source_page_frame_id)) {
      return;
    }
    const text = normalizeText(node.name);
    if (text.length < 2 || /^text$/i.test(text) || seen.has(text.toLowerCase())) {
      return;
    }
    seen.add(text.toLowerCase());
    values.push(text);
  });
  return { values };
}

function findFixture(summary, fixtureId) {
  const fixtures = Array.isArray(summary.fixtures) ? summary.fixtures : [];
  if (!fixtureId && fixtures.length === 1) {
    return fixtures[0];
  }
  const fixture = fixtures.find((row) => row.id === fixtureId);
  if (!fixture) {
    throw new Error(`Fixture not found in summary: ${fixtureId || '(missing --fixture-id)'}`);
  }
  return fixture;
}

function renderMarkdown(report) {
  const lines = [];
  lines.push(`# Figma Intent Parity: ${report.fixture.id}`);
  lines.push('');
  lines.push(`Status: **${report.status}**`);
  lines.push('');
  lines.push('## Counts');
  lines.push(`- Figma decoded nodes: ${report.figma_intent.decoded_node_count ?? 'unknown'}`);
  lines.push(`- Figma selected frames/pages: ${report.figma_intent.selected_frame_count}`);
  lines.push(`- Figma asset references: ${report.figma_intent.asset_reference_count ?? 'unknown'}`);
  lines.push(`- Figma extracted text strings: ${report.figma_intent.extracted_text_count}`);
  lines.push(`- Generated HTML pages: ${report.generated_artifacts.html_files.length}`);
  lines.push(`- Generated CSS files: ${report.generated_artifacts.css_files.length}`);
  lines.push(`- Generated image assets: ${report.generated_artifacts.image_asset_count}`);
  if (report.wordpress) {
    lines.push(`- WordPress pages: ${report.wordpress.pages.length}`);
    lines.push(`- WordPress blocks: ${report.wordpress.block_count}`);
    lines.push(`- WordPress fallback blocks: ${report.wordpress.fallback_block_count}`);
    lines.push(`- WordPress theme CSS files: ${report.wordpress.theme_css_files.length}`);
  }
  lines.push('');
  lines.push('## Regressions');
  if (!report.regressions.length) {
    lines.push('- None detected by this report.');
  } else {
    for (const row of report.regressions) {
      lines.push(`- ${row.severity.toUpperCase()} ${row.code}: ${row.message}`);
    }
  }
  lines.push('');
  lines.push('## Missing Text Samples');
  for (const text of report.comparisons.missing_generated_text_sample.slice(0, 10)) {
    lines.push(`- Generated HTML missing: ${text}`);
  }
  for (const text of report.comparisons.missing_wordpress_text_sample.slice(0, 10)) {
    lines.push(`- WordPress missing: ${text}`);
  }
  lines.push('');
  return `${lines.join('\n')}\n`;
}

function parseArgs(args) {
  const options = {};
  for (let index = 0; index < args.length; index += 1) {
    const arg = args[index];
    if (arg === '--help' || arg === '-h') {
      options.help = true;
      continue;
    }
    if (!arg.startsWith('--')) {
      throw new Error(`Unexpected argument: ${arg}`);
    }
    const [rawKey, inlineValue] = arg.slice(2).split('=');
    const key = rawKey.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
    options[key] = inlineValue ?? args[++index];
  }
  return options;
}

function printHelp() {
  process.stdout.write(`Usage: node tools/fig-intent-parity-report.mjs --summary <summary.json> --fixture-id <id> [options]\n\nOptions:\n  --artifact-dir <path>  Generated HTML/CSS/assets directory. Defaults to fixture artifact_dir.\n  --inspect <path>       Transformer inspect JSON. Defaults to fixture inspect_path.\n  --result <path>        Transformer result JSON. Defaults to fixture result_path.\n  --wp-root <path>       Optional imported WordPress root to inspect via WP-CLI.\n  --wp-cli <command>     WP-CLI command prefix. Defaults to \"studio wp\".\n  --output-dir <path>    Directory for JSON and markdown report artifacts.\n`);
}

function requiredOption(options, key) {
  if (!options[key]) {
    throw new Error(`Missing required option --${key.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`)}`);
  }
  return options[key];
}

function readJson(filePath) {
  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function listFiles(root) {
  if (!fs.existsSync(root)) {
    return [];
  }
  const files = [];
  const stack = [''];
  while (stack.length) {
    const relative = stack.pop();
    const absolute = path.join(root, relative);
    for (const entry of fs.readdirSync(absolute, { withFileTypes: true })) {
      const child = path.join(relative, entry.name);
      if (entry.isDirectory()) {
        stack.push(child);
      } else if (entry.isFile()) {
        files.push(child.split(path.sep).join('/'));
      }
    }
  }
  return files.sort();
}

function extractHtmlUrls(html) {
  return [...html.matchAll(/\b(?:src|href)=["']([^"']+)["']/gi)].map((match) => match[1]);
}

function extractCssUrls(css) {
  return [...css.matchAll(/url\(["']?([^"')]+)["']?\)/gi)].map((match) => match[1]);
}

function linkStatus({ source, url, artifactDir, assetSet }) {
  if (!isLocalUrl(url)) {
    return { source, url, exists: true, skipped: true };
  }
  const cleanUrl = url.split('#')[0].split('?')[0];
  if (!cleanUrl || cleanUrl.startsWith('#')) {
    return { source, url, exists: true, skipped: true };
  }
  const sourceDir = path.dirname(source);
  const resolved = path.normalize(path.join(sourceDir, cleanUrl)).split(path.sep).join('/').replace(/^\.\//, '');
  const exists = assetSet.has(resolved) || fs.existsSync(path.join(artifactDir, resolved));
  return { source, url, resolved_path: resolved, exists };
}

function isLocalUrl(url) {
  return !/^(?:[a-z]+:)?\/\//i.test(url) && !/^(?:mailto|tel|data):/i.test(url);
}

function extractVisibleText(html) {
  return normalizeText(String(html || '')
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, ' ')
    .replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, ' ')
    .replace(/<!--([\s\S]*?)-->/g, ' ')
    .replace(/<[^>]+>/g, ' '));
}

function normalizeText(value) {
  return String(value || '').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
}

function maxCssPixelWidth(css) {
  const widths = [...css.matchAll(/(?:width|max-width|min-width)\s*:\s*(\d+(?:\.\d+)?)px/gi)].map((match) => Number(match[1]));
  return widths.length ? Math.max(...widths) : 0;
}

function responsiveWarnings(css) {
  const warnings = [];
  if (!/@media\b/i.test(css)) {
    warnings.push({ code: 'missing_media_queries', message: 'Generated CSS contains no media queries.' });
  }
  if (/width\s*:\s*(?:19\d{2}|[2-9]\d{3,})px/i.test(css)) {
    warnings.push({ code: 'very_large_fixed_width', message: 'Generated CSS contains fixed widths at or above 1900px.' });
  }
  return warnings;
}

function splitCommand(command) {
  return String(command).split(/\s+/).filter(Boolean);
}

function runJsonCommand(command, args) {
  const text = runTextCommand(command, args);
  return JSON.parse(text || '[]');
}

function runTextCommand(command, args, options = {}) {
  const [bin, ...prefix] = command;
  const result = spawnSync(bin, [...prefix, ...args], { encoding: 'utf8', cwd: packageRoot });
  if (result.status !== 0 && !options.optional) {
    throw new Error(`Command failed: ${[bin, ...prefix, ...args].join(' ')}\n${result.stderr || result.stdout}`);
  }
  return result.stdout || '';
}

function counts(values) {
  const out = {};
  for (const value of values) {
    out[value] = (out[value] || 0) + 1;
  }
  return out;
}

function parseSerializedBlockNames(markup) {
  if (!markup) {
    return [];
  }
  return [...String(markup).matchAll(/<!--\s+wp:([^\s{/]+(?:\/[^\s{]+)?)/g)].map((match) => {
    const name = match[1];
    return name.includes('/') ? name : `core/${name}`;
  });
}

function visit(value, callback) {
  callback(value);
  if (Array.isArray(value)) {
    for (const child of value) {
      visit(child, callback);
    }
  } else if (value && typeof value === 'object') {
    for (const child of Object.values(value)) {
      visit(child, callback);
    }
  }
}
