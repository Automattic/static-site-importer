import { mkdir, readdir, readFile, writeFile } from 'node:fs/promises';
import { basename, dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

export const SCHEMA = 'static-site-importer/visual-parity-oracle-input/v1';
export const VIEWPORT = { width: 1440, height: 900 };

const EXTRACT_SECTIONS = () => {
  const viewport = { width: window.innerWidth, height: window.innerHeight };
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
    return { displayWidth: Math.round(box.width), displayHeight: Math.round(box.height) };
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
    const headings = [...el.querySelectorAll(headingSelector)].filter((heading) => heading.parentElement?.closest('header, main, section, footer') === el || heading.closest('header, main, section, footer') === el);
    const images = [...el.querySelectorAll('img')].filter(isVisible).map((img) => ({
      alt: img.getAttribute('alt') || '',
      url: img.currentSrc || img.src || '',
      selector: img.id ? `#${img.id}` : '',
      kind: 'img',
      ...displayBox(img),
    }));
    const forms = [...el.querySelectorAll('form')].map((form) => ({
      fields: [...form.querySelectorAll('input, select, textarea')].map((field) => ({
        kind: (field.getAttribute('type') || field.tagName).toLowerCase(),
        name: field.getAttribute('name') || '',
        label: field.getAttribute('aria-label') || field.id || '',
        tabindex: field.tabIndex,
        ariaHidden: field.getAttribute('aria-hidden') === 'true',
        ...displayBox(field),
      })),
    }));
    sections.push({
      sectionIndex: sections.length,
      selector: [el.tagName.toLowerCase(), el.id ? `#${el.id}` : '', el.className ? `.${String(el.className).trim().split(/\s+/).slice(0, 3).join('.')}` : ''].join(''),
      top: Math.round(box.top + window.scrollY),
      height: Math.round(box.height),
      headings: headings.map((heading) => (heading.textContent || '').trim()).filter(Boolean),
      headingSizes: headings.map((heading) => Math.round(Number.parseFloat(window.getComputedStyle(heading).fontSize) || 0)),
      images,
      forms,
    });
  }
  const landmarks = [...document.querySelectorAll('header, main, footer, [role="banner"], [role="main"], [role="contentinfo"]')]
    .filter(isVisible)
    .map((el) => {
      const box = el.getBoundingClientRect();
      const role = el.getAttribute('role') || ({ HEADER: 'header', MAIN: 'main', FOOTER: 'footer' }[el.tagName] || el.tagName.toLowerCase());
      return {
        role,
        tag: el.tagName.toLowerCase(),
        selector: el.tagName.toLowerCase() + (el.id ? `#${el.id}` : ''),
        top: Math.round(box.top + window.scrollY),
        height: Math.round(box.height),
        textLength: (el.textContent || '').trim().length,
        mediaCount: el.querySelectorAll('img, svg, video').length,
        linkCount: el.querySelectorAll('a[href]').length,
      };
    });
  return {
    schema: 9,
    sourceUrl: location.href,
    capturedAt: new Date().toISOString(),
    viewport,
    sections,
    landmarks,
  };
};

export async function loadSectionPages(directory) {
  const root = resolve(directory);
  const entries = await readdir(root);
  const pages = {};
  for (const name of entries.filter((entry) => entry.endsWith('.json')).sort()) {
    const parsed = JSON.parse(await readFile(join(root, name), 'utf8'));
    pages[basename(name, '.json')] = parsed;
  }
  return pages;
}

export function notVerifiedResult(reason) {
  return {
    schema: SCHEMA,
    status: 'not_verified',
    verification: 'not_verified',
    stage: 'import_vs_capture',
    reason,
    source_pages: {},
    imported_pages: {},
  };
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
    return page.evaluate(EXTRACT_SECTIONS);
  } finally {
    await browser.close();
  }
}

export async function buildOracleInput({ sourceSections, importedSections, importedOrigin, omissions, viewport = VIEWPORT } = {}) {
  const source_pages = sourceSections ? await loadSectionPages(sourceSections) : {};
  let imported_pages = importedSections ? await loadSectionPages(importedSections) : {};
  if (importedOrigin) {
    imported_pages = {};
    for (const [pageId, record] of Object.entries(source_pages)) {
      const route = record.sourceUrl ? new URL(record.sourceUrl).pathname : `/${pageId === 'index' || pageId === 'homepage' ? '' : pageId}`;
      imported_pages[pageId] = await extractImportedPage(importedOrigin, route, record.viewport || viewport);
    }
  }
  if (Object.keys(source_pages).length === 0 || Object.keys(imported_pages).length === 0) {
    return notVerifiedResult('Capture or imported section records were not provided, or a browser was unavailable.');
  }
  return {
    schema: SCHEMA,
    status: 'ready',
    verification: 'section_geometry',
    stage: 'import_vs_capture',
    viewport,
    source_pages,
    imported_pages,
    omissions: omissions || [],
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
    process.stdout.write('Usage: node tools/visual-parity-oracle.mjs [--source-sections dir] [--imported-sections dir] [--imported-origin url] [--omissions json] [--out file]\n');
    return 0;
  }
  let input;
  try {
    input = await buildOracleInput({
      sourceSections: args['source-sections'],
      importedSections: args['imported-sections'],
      importedOrigin: args['imported-origin'],
      omissions: args.omissions ? JSON.parse(await readFile(args.omissions, 'utf8')) : [],
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
  return input.status === 'not_verified' ? 0 : 0;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === resolve(process.argv[1])) {
  main().catch((error) => {
    process.stderr.write(`${error.stack || error}\n`);
    process.exit(1);
  });
}
