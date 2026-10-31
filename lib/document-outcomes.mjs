// Browser-only collectors are serialized by Playwright; keep dependencies inside them.
async function readability() {
  const evidence = [];
  const layers = [...document.querySelectorAll('body *')].filter(el => getComputedStyle(el).position !== 'static' && el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true }));
  const selector = el => { const parts = []; for (; el && el !== document.documentElement; el = el.parentElement) parts.unshift(`${el.localName}:nth-child(${[...el.parentElement.children].indexOf(el) + 1})`); return parts.join(' > '); };
  // Computed colours keep their authored space (lab(), oklch(), color()); the
  // canvas converts any of them to the sRGB the contrast formula is defined on.
  const pixel = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
  const rgba = value => {
    const m = value.match(/^rgba?\(([^)]+)\)$/);
    if (m) return m[1].split(',').map(Number).concat(m[1].split(',').length === 3 ? [1] : []);
    if (!CSS.supports('color', value)) return null;
    pixel.clearRect(0, 0, 1, 1); pixel.fillStyle = value; pixel.fillRect(0, 0, 1, 1);
    const [r, g, b, a] = pixel.getImageData(0, 0, 1, 1).data;
    return [r, g, b, a / 255];
  };
  const over = (a, b) => a.slice(0, 3).map((v, i) => v * a[3] + b[i] * (1 - a[3])).concat(1);
  const luminance = c => c.slice(0, 3).map(v => { v /= 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }).reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
  const measure = async (el, text, kind) => {
    if (!el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true }) || !el.getClientRects().length) return;
    // Element text identifies the same words when the source splits them into several text nodes.
    const row = { selector: selector(el), text, kind, element_text: el.textContent.replace(/\s+/g, ' ').trim().slice(0, 500) };
    try {
      if (!text && !el.matches('input:not([type=checkbox]):not([type=radio]),textarea,select')) throw new Error('control_without_text_requires_nontext_contrast');
      if (el.namespaceURI !== 'http://www.w3.org/1999/xhtml') throw new Error('non_html_text_not_measurable');
      if (el.matches('input,textarea') && !el.value && el.getAttribute('placeholder')) throw new Error('placeholder_colour_not_measurable');
      const rect = el.getBoundingClientRect();
      if (layers.some(p => { if (p.contains(el) || el.contains(p)) return false; const r = p.getBoundingClientRect(); return r.width && r.height && r.left < rect.right && r.right > rect.left && r.top < rect.bottom && r.bottom > rect.top; })) throw new Error('overlapping_layer_not_measurable');
      const chain = []; for (let p = el; p; p = p.parentElement) chain.unshift(p);
      let bg = [255, 255, 255, 1];
      for (const p of chain) {
        const s = getComputedStyle(p);
        if (Number(s.opacity) !== 1 || s.filter !== 'none' || s.mixBlendMode !== 'normal' || s.backgroundClip === 'text') throw new Error('compositing_not_measurable');
        const color = rgba(s.backgroundColor); if (!color) throw new Error('background_colour_not_measurable');
        bg = over(color, bg);
        if (s.backgroundImage !== 'none') {
          // A uniform decoded image has an exact effective background regardless
          // of glyph position. Nonuniform images require glyph-level sampling.
          const match = s.backgroundImage.match(/^url\("?([^"()]+)"?\)$/);
          if (!match || s.backgroundRepeat !== 'repeat') throw new Error('background_image_geometry_not_measurable');
          const image = new Image(); image.crossOrigin = 'anonymous'; image.src = match[1]; await image.decode();
          if (image.width * image.height > 1000000) throw new Error('background_image_sampling_limit');
          const canvas = document.createElement('canvas'); canvas.width = image.width; canvas.height = image.height;
          const ctx = canvas.getContext('2d'); ctx.drawImage(image, 0, 0);
          const pixels = ctx.getImageData(0, 0, image.width, image.height).data;
          for (let i = 4; i < pixels.length; i++) if (pixels[i] !== pixels[i % 4]) throw new Error('nonuniform_background_image');
          bg = over([pixels[0], pixels[1], pixels[2], pixels[3] / 255], bg);
        }
      }
      const s = getComputedStyle(el), fg = rgba(s.color);
      if (!fg || s.textShadow !== 'none' || s.webkitTextFillColor !== s.color) throw new Error('foreground_not_measurable');
      if ([el, ...el.querySelectorAll('*')].some(p => ['::before', '::after'].some(pseudo => !['none', 'normal', '""'].includes(getComputedStyle(p, pseudo).content)))) throw new Error('generated_content_not_measurable');
      const effective = over(fg, bg), a = luminance(effective), b = luminance(bg);
      const ratio = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
      const large = parseFloat(s.fontSize) >= 24 || (parseFloat(s.fontSize) >= 18.6667 && parseInt(s.fontWeight) >= 700);
      const required = large ? 3 : 4.5;
      Object.assign(row, { foreground: effective, background: bg, ratio, required, status: ratio >= required ? 'pass' : 'fail' });
    } catch (error) { Object.assign(row, { status: 'unsupported', reason: error.message }); }
    evidence.push(row);
  };
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  while (walker.nextNode()) { const node = walker.currentNode; if (node.textContent.trim() && !node.parentElement.closest('script,style,noscript,template')) await measure(node.parentElement, node.textContent.trim(), 'text'); }
  for (const el of document.querySelectorAll('input:not([type=hidden]),textarea,select,button,[role=button],summary')) {
    await measure(el, el.value || el.textContent.trim() || el.getAttribute('aria-label') || '', 'control');
  }
  for (const el of document.querySelectorAll('body *')) {
    if (!el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true })) continue;
    if (el.matches('iframe,canvas') || el.shadowRoot) evidence.push({ selector: selector(el), status: 'unsupported', reason: 'embedded_or_shadow_content_not_measurable' });
    for (const pseudo of ['::before', '::after']) if (!['none', 'normal', '""'].includes(getComputedStyle(el, pseudo).content)) evidence.push({ selector: selector(el), pseudo, status: 'unsupported', reason: 'generated_content_not_measurable' });
  }
  return evidence;
}

/** Measure the same text in the retained source page, rendered with only its own resources. */
async function sourceReadability(browser, sourceUrl, width, scheme) {
  const page = await browser.newPage({ viewport: { width, height: 900 }, colorScheme: scheme });
  const origin = new URL(sourceUrl).origin;
  try {
    await page.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const response = await page.goto(sourceUrl, { waitUntil: 'load' });
    if (response?.status() !== 200) return null;
    await page.evaluate(() => document.fonts.ready);
    // Viewport entrances reveal content as it scrolls into view; measure the
    // settled state the visitor reads, not the pre-entrance opacity.
    await page.evaluate(async () => {
      const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
      for (let y = 0; y < document.documentElement.scrollHeight && y < 100000; y += innerHeight / 2) { scrollTo(0, y); await pause(50); }
      scrollTo(0, 0);
      await Promise.race([pause(5000), Promise.all(document.getAnimations().filter(animation => Number.isFinite(animation.effect?.getComputedTiming().endTime)).map(animation => animation.finished.catch(() => {})))]);
    });
    return await page.evaluate(readability);
  } catch { return null; } finally { await page.close(); }
}

/** Contrast is an import loss only where the import lowered it. Each measured
 * source occurrence of a text can justify one failing candidate occurrence that
 * renders no worse (within the margin); everything else, and every route without
 * a measurable source, keeps the absolute requirement. */
const SOURCE_RATIO_MARGIN = 1.1;
function compareWithSource(rows, sourceRows) {
  if (!sourceRows) return rows;
  // Text keys pair text nodes; element keys pair whole elements, so a source that
  // splits words across text nodes still pairs. One element is one occurrence.
  const pools = new Map();
  const add = (key, id, ratio) => {
    const pool = pools.get(key) ?? new Map(); pools.set(key, pool);
    pool.set(id, Math.min(ratio, pool.get(id) ?? Infinity));
  };
  for (const row of sourceRows) if (typeof row.ratio === 'number') {
    add(`${row.kind}\n${row.text}`, `${row.selector}\n${row.text}`, row.ratio);
    add(`${row.kind}\n\n${row.element_text}`, row.selector, row.ratio);
  }
  const result = [...rows];
  const failing = rows.flatMap((row, n) => row.status === 'fail' ? [n] : []).sort((a, b) => rows[a].ratio - rows[b].ratio);
  // Ascending candidates each take the lowest source occurrence they cover: a
  // greedy assignment that justifies as many failures as the source allows.
  for (const n of failing) {
    const row = rows[n];
    for (const key of [`${row.kind}\n${row.text}`, `${row.kind}\n\n${row.element_text}`]) {
      const pool = pools.get(key);
      const match = pool && [...pool].filter(([, ratio]) => ratio < row.required && row.ratio * SOURCE_RATIO_MARGIN >= ratio).sort((a, b) => a[1] - b[1])[0];
      if (!match) continue;
      pool.delete(match[0]);
      result[n] = { ...row, status: 'pass', reason: 'source_contrast_preserved', source_ratio: match[1] };
      break;
    }
  }
  return result;
}

function chrome({ contentSelector, sourceOrigin, candidateOrigin }) {
  const content = document.querySelectorAll(contentSelector);
  if (content.length !== 1) throw new Error('unique_content_boundary_required');
  const root = content[0], before = [], after = [];
  const normalize = value => { try { const url = new URL(value, sourceOrigin); if ([sourceOrigin, candidateOrigin].includes(url.origin)) return url.pathname + url.search + url.hash; return url.href; } catch { return value; } };
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT);
  while (walker.nextNode()) {
    const node = walker.currentNode, el = node.nodeType === 3 ? node.parentElement : node;
    if (root.contains(node) || el.closest('script,style,noscript,template')) continue;
    const target = root.compareDocumentPosition(node) & Node.DOCUMENT_POSITION_PRECEDING ? before : after;
    if (node.nodeType === 3) { const text = node.textContent.replace(/\s+/g, ' ').trim(); if (text) target.push({ text }); }
    else if (!node.contains(root)) {
      if (node.matches('a[href]')) target.push({ link: normalize(node.getAttribute('href')) });
      if (node.matches('img')) target.push({ image: normalize(node.getAttribute('src')), alt: node.getAttribute('alt') || '' });
      if (node.matches('header,footer,nav,aside,h1,h2,h3,h4,h5,h6,p,ul,ol,li,form,label,details,summary,input,button,select,textarea,[role],iframe,video,audio,canvas,svg') || (!node.children.length && !node.textContent.trim() && node.matches('div,span'))) target.push({ element: node.localName, role: node.getAttribute('role'), label: node.getAttribute('aria-label') });
    }
  }
  return { before, after };
}

const summarize = evidence => ({ status: evidence.some(row => row.status === 'fail') ? 'fail' : !evidence.length || evidence.some(row => row.status === 'unsupported') ? 'unsupported' : 'pass', evidence });
export async function documentOutcomes({ browser, candidateOrigin, routes, widths, config }) {
  const read = [], full = [];
  const schemes = config.colourSchemes;
  if (!Array.isArray(schemes) || !schemes.length || schemes.some(s => !['light', 'dark'].includes(s))) read.push({ status: 'unsupported', reason: 'declared_colour_schemes_required' });
  for (const route of routes) for (const width of widths) for (const scheme of Array.isArray(schemes) && schemes.length ? schemes : ['light']) {
    const probe = await browser.newPage({ viewport: { width, height: 900 }, colorScheme: scheme });
    const context = { route, width, colour_scheme: scheme };
    try {
      const response = await probe.goto(new URL(route, candidateOrigin).href, { waitUntil: 'load' });
      if (response?.status() !== 200 || new URL(probe.url()).origin !== candidateOrigin) throw new Error('route_unavailable');
      await probe.evaluate(() => document.fonts.ready);
      const source = config.documents?.[route];
      read.push(...compareWithSource(await probe.evaluate(readability), source?.sourceUrl ? await sourceReadability(browser, source.sourceUrl, width, scheme) : null).map(row => ({ ...context, ...row })));
      if (!source?.html || !source.sourceOrigin || !source.sourceContent || !source.candidateContent) throw new Error('source_document_and_content_boundaries_required');
      const args = { sourceOrigin: source.sourceOrigin, candidateOrigin };
      const actual = await probe.evaluate(chrome, { ...args, contentSelector: source.candidateContent });
      const sourcePage = await browser.newPage({ javaScriptEnabled: false });
      await sourcePage.route('**/*', request => request.abort());
      let expected;
      try { await sourcePage.setContent(source.html); expected = await sourcePage.evaluate(chrome, { ...args, contentSelector: source.sourceContent }); } finally { await sourcePage.close(); }
      const matches = JSON.stringify(actual) === JSON.stringify(expected);
      const decision = source.decisions?.find(d => d.reason?.trim() && JSON.stringify(d.expected) === JSON.stringify(expected) && JSON.stringify(d.actual) === JSON.stringify(actual));
      full.push({ ...context, status: matches || decision ? 'pass' : 'fail', expected, actual, ...(decision ? { decision } : {}) });
    } catch (error) {
      full.push({ ...context, status: 'unsupported', reason: error.message });
      if (!read.some(row => row.route === route && row.width === width && row.colour_scheme === scheme)) read.push({ ...context, status: 'unsupported', reason: error.message });
    } finally { await probe.close(); }
  }
  return { readability: summarize(read), full_document: summarize(full) };
}
