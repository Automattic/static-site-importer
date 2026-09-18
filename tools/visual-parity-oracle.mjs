import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

export const SCHEMA = 'static-site-importer/layout-baseline/v1';
export const COMPILER_REPORT_PATH = 'source_reports.layout_baseline';
export const VIEWPORT = { width: 1440, height: 900 };

// Exported so downstream consumers (e.g. Studio's `cli site create --from`) can measure a
// live-rendered page with the exact same selection heuristic the oracle itself uses, instead
// of re-implementing it. Self-contained on purpose — no references to anything outside this
// function body (only DOM/browser globals) — so it stays safe to serialize with
// `Function.prototype.toString()` (e.g. Playwright's `page.evaluate(EXTRACT_LAYOUT, ...)`) even
// from inside a bundled caller. Do not introduce a closure over module scope here.
export const EXTRACT_LAYOUT = ({ viewport }) => {
  const headingSelector = 'h1,h2,h3,h4,h5,h6,[role="heading"]';
  const isVisible = (el) => {
    const style = window.getComputedStyle(el);
    if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) === 0) {
      return false;
    }
    const box = el.getBoundingClientRect();
    return box.width > 0 && box.height > 0;
  };
  const displayBox = (el) => {
    const box = el.getBoundingClientRect();
    return { display_width: Math.round(box.width), display_height: Math.round(box.height) };
  };
  const mediaOf = (root) => [...root.querySelectorAll('img, svg, video')].filter(isVisible).map((node) => ({
    id: node.id || '',
    role: node.getAttribute('role') || node.tagName.toLowerCase(),
    ...displayBox(node),
  }));
  const paddingOf = (el) => {
    const style = window.getComputedStyle(el);
    return {
      top: Math.round(Number.parseFloat(style.paddingTop) || 0),
      right: Math.round(Number.parseFloat(style.paddingRight) || 0),
      bottom: Math.round(Number.parseFloat(style.paddingBottom) || 0),
      left: Math.round(Number.parseFloat(style.paddingLeft) || 0),
    };
  };
  const sectionNodes = [...document.querySelectorAll('header, main, section, footer, [role="banner"], [role="main"], [role="contentinfo"]')]
    .filter((el) => isVisible(el) && el.getBoundingClientRect().height >= 32);
  const seen = new Set();
  const sections = [];
  for (const el of sectionNodes) {
    if ([...seen].some((kept) => kept.contains(el))) {
      continue;
    }
    seen.add(el);
    const box = el.getBoundingClientRect();
    const headings = [...el.querySelectorAll(headingSelector)].filter((heading) => heading.closest('header, main, section, footer') === el);
    const body = [...el.querySelectorAll('p')].filter((node) => node.closest('header, main, section, footer') === el && isVisible(node));
    sections.push({
      id: el.id || `${el.tagName.toLowerCase()}-${sections.length}`,
      order: sections.length,
      offset: { top: Math.round(box.top + window.scrollY) },
      height: Math.round(box.height),
      headings: headings.map((heading) => {
        const style = window.getComputedStyle(heading);
        return {
          text: (heading.textContent || '').trim(),
          font_size: Math.round(Number.parseFloat(style.fontSize) || 0),
          font_family: style.fontFamily || '',
          line_height: style.lineHeight || '',
        };
      }),
      body: body.slice(0, 3).map((node) => {
        const style = window.getComputedStyle(node);
        return {
          font_size: Math.round(Number.parseFloat(style.fontSize) || 0),
          font_family: style.fontFamily || '',
          line_height: style.lineHeight || '',
        };
      }),
      media: mediaOf(el),
      forms: [...el.querySelectorAll('form')].map((form, formIndex) => ({
        id: form.id || `form-${formIndex}`,
        padding: paddingOf(form),
        fields: [...form.querySelectorAll('input, select, textarea')].map((field, fieldIndex) => ({
          id: field.id || field.getAttribute('name') || `field-${fieldIndex}`,
          name: field.getAttribute('name') || '',
          padding: paddingOf(field),
          ...displayBox(field),
        })),
      })),
    });
  }
  const landmarks = [...document.querySelectorAll('header, main, footer, [role="banner"], [role="main"], [role="contentinfo"]')]
    .filter(isVisible)
    .map((el, landmarkIndex) => {
      const box = el.getBoundingClientRect();
      const role = el.getAttribute('role') || ({ HEADER: 'banner', MAIN: 'main', FOOTER: 'contentinfo' }[el.tagName] || el.tagName.toLowerCase());
      return {
        id: el.id || `${role}-${landmarkIndex}`,
        role,
        offset: { top: Math.round(box.top + window.scrollY) },
        height: Math.round(box.height),
        media: mediaOf(el),
      };
    });
  const pageViewport = viewport || { width: window.innerWidth, height: window.innerHeight };
  return {
    schema: 'static-site-importer/layout-baseline/v1',
    viewports: [pageViewport],
    pages: [{
      id: (location.pathname.replace(/\/+$/, '') || '/').replace(/^\//, '') || 'index',
      viewport: pageViewport,
      sections,
      landmarks,
    }],
    intentional_omissions: [],
  };
};

export function notVerifiedResult(reason, missing = []) {
  return {
    schema: SCHEMA,
    status: 'not_verified',
    verification: 'not_verified',
    stage: 'import_vs_baseline',
    reason,
    missing_data_contract: missing,
    compiler_report_path: COMPILER_REPORT_PATH,
    expected_schema: SCHEMA,
  };
}

export async function loadJson(path) {
  return JSON.parse(await readFile(resolve(path), 'utf8'));
}

export async function extractImportedPage(origin, route = '/', viewport = VIEWPORT) {
  let playwright;
  try {
    playwright = await import('playwright');
  } catch {
    throw Object.assign(new Error('Playwright is not installed.'), { code: 'browser_unavailable' });
  }
  const browser = await playwright.chromium.launch({ headless: true });
  try {
    const page = await browser.newPage({ viewport });
    const target = origin.startsWith('file:') || origin.startsWith('http')
      ? new URL(route, origin.endsWith('/') ? origin : `${origin}/`).href
      : pathToFileURL(resolve(origin)).href;
    await page.goto(target, { waitUntil: 'networkidle', timeout: 30000 });
    return page.evaluate(EXTRACT_LAYOUT, { viewport });
  } finally {
    await browser.close();
  }
}

function pageRoute(page, fallbackId) {
  const id = page.id || fallbackId || 'index';
  if (id === 'index' || id === 'home' || id === 'homepage') {
    return '/';
  }
  return `/${id}`;
}

function viewportOf(page, fallback = VIEWPORT) {
  const viewport = page.viewport || fallback;
  return {
    width: Number(viewport.width) || fallback.width,
    height: Number(viewport.height) || fallback.height,
  };
}

export async function buildOracleInput({ baseline, importedRender, importedOrigin, viewport = VIEWPORT } = {}) {
  const baselineDoc = baseline
    ? (typeof baseline === 'string' ? await loadJson(baseline) : baseline)
    : null;
  if (!baselineDoc || baselineDoc.schema !== SCHEMA || !Array.isArray(baselineDoc.pages)) {
    return notVerifiedResult(
      'Layout baseline contract is absent or malformed.',
      [
        `${COMPILER_REPORT_PATH} schema ${SCHEMA}`,
        `${COMPILER_REPORT_PATH}.viewports`,
        `${COMPILER_REPORT_PATH}.pages`,
        `${COMPILER_REPORT_PATH}.intentional_omissions`,
      ],
    );
  }

  let importedDoc = importedRender
    ? (typeof importedRender === 'string' ? await loadJson(importedRender) : importedRender)
    : null;
  if (!importedDoc && importedOrigin) {
    const pages = [];
    for (const page of baselineDoc.pages) {
      const extracted = await extractImportedPage(
        importedOrigin,
        pageRoute(page, page.id),
        viewportOf(page, baselineDoc.viewports?.[0] || viewport),
      );
      pages.push(...(extracted.pages || []));
    }
    importedDoc = {
      schema: SCHEMA,
      viewports: baselineDoc.viewports || [viewport],
      pages,
      intentional_omissions: [],
    };
  }
  if (!importedDoc || !Array.isArray(importedDoc.pages) || importedDoc.pages.length === 0) {
    return notVerifiedResult('Imported layout record was not provided, or a browser was unavailable.');
  }

  return {
    schema: SCHEMA,
    status: 'ready',
    verification: 'section_geometry',
    stage: 'import_vs_baseline',
    compiler_report_path: COMPILER_REPORT_PATH,
    expected_schema: SCHEMA,
    source_reports: {
      layout_baseline: baselineDoc,
    },
    imported_render: importedDoc,
  };
}

function parseArgs(argv) {
  const args = {};
  for (let index = 0; index < argv.length; index += 1) {
    const token = argv[index];
    if (!token.startsWith('--')) {
      continue;
    }
    const key = token.slice(2);
    const value = argv[index + 1] && !argv[index + 1].startsWith('--') ? argv[++index] : true;
    args[key] = value;
  }
  return args;
}

async function main(argv = process.argv.slice(2)) {
  const args = parseArgs(argv);
  if (args.help) {
    process.stdout.write('Usage: node tools/visual-parity-oracle.mjs [--baseline file] [--imported-render file] [--imported-origin url] [--out file]\n');
    return 0;
  }
  let input;
  try {
    input = await buildOracleInput({
      baseline: args.baseline,
      importedRender: args['imported-render'],
      importedOrigin: args['imported-origin'],
    });
  } catch (error) {
    if (error && (error.code === 'browser_unavailable' || /browser/i.test(String(error)))) {
      input = notVerifiedResult(error.message || 'Browser rendering is unavailable.');
    } else {
      throw error;
    }
  }
  const encoded = `${JSON.stringify(input, null, 2)}\n`;
  if (args.out) {
    await mkdir(dirname(resolve(args.out)), { recursive: true });
    await writeFile(resolve(args.out), encoded);
  } else {
    process.stdout.write(encoded);
  }
  return 0;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === resolve(process.argv[1])) {
  main().catch((error) => {
    process.stderr.write(`${error.stack || error}\n`);
    process.exit(1);
  });
}
