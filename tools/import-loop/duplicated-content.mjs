// Content a builder ships twice in one document and hides per breakpoint with
// CSS (a `.desktop-only` and a `.mobile-only` copy). When the copies survive
// the import, the editor shows the same heading, text or image twice, and
// editing the visible copy leaves the hidden one stale at the other width.
//
// The saved post content says what was duplicated; the rendered frontend at
// each evaluated width says which copy shows where. A duplicate is content
// saved more than once whose copies show at disjoint widths. Copies visible at
// the same widths (cards, testimonials) are repeated structure, which
// `block_repeated_structure` covers, not duplication.

export const MIN_TEXT = 12;

const ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ', '#39': "'" };
const decode = (s) => s.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+\d*);/gi, (m, e) => {
  if (e[0] === '#') { const code = e[1] === 'x' || e[1] === 'X' ? parseInt(e.slice(2), 16) : Number(e.slice(1)); return Number.isFinite(code) ? String.fromCodePoint(code) : m; }
  return ENTITIES[e.toLowerCase()] ?? m;
});
export const normalizeText = (s) => decode(String(s || '')).replace(/\s+/g, ' ').trim().toLowerCase();
// Matching ignores whitespace: saved markup and rendered textContent space
// inline tags differently (`<strong>a</strong>b`).
const compact = (s) => s.replace(/\s+/g, '');

// An upload by its source: WordPress serves one upload as size variants
// (`-300x200`, `-scaled`) and may make the URL absolute or relative.
export const imageSource = (src) => {
  let pathname;
  try { pathname = new URL(decode(String(src || '')), 'http://x/').pathname; } catch { return null; }
  if (!pathname || pathname === '/') return null;
  return pathname.replace(/-(?:\d+x\d+|scaled)(\.[a-z0-9]+)$/i, '$1');
};

const BLOCK = /<!--\s+(\/)?wp:([a-z0-9_-]+(?:\/[a-z0-9_-]+)?)(?:\s+\{[^]*?\})?\s*(\/)?-->/g;
const SKIP = /<(script|style|noscript|template)\b[\s\S]*?<\/\1>/gi;

// Text runs and images in saved post content, in document order. A text run is
// the text a block holds itself (not its inner blocks'), one per block; an
// image is each <img> a block holds. Each records its block path.
export function contentOccurrences(markup) {
  const out = [];
  const stack = [];
  const own = (frame) => frame.html.join('');
  const close = (frame) => {
    const path = [...stack.map((f) => f.name), frame.name].join(' > ');
    const html = own(frame).replace(SKIP, ' ');
    const text = normalizeText(html.replace(/<[^>]+>/g, ' '));
    if (text.length >= MIN_TEXT) out.push({ kind: 'text', key: compact(text), text, block_path: path, order: frame.order });
    for (const [, src] of html.matchAll(/<img\b[^>]*?\ssrc\s*=\s*["']([^"']+)["']/gi)) {
      const key = imageSource(src);
      if (key) out.push({ kind: 'image', key, block_path: path, order: frame.order });
    }
  };
  let at = 0, order = 0;
  const text = String(markup || '');
  for (const m of text.matchAll(BLOCK)) {
    if (stack.length) stack[stack.length - 1].html.push(text.slice(at, m.index));
    at = m.index + m[0].length;
    const [, closing, rawName, selfClosing] = m;
    const name = rawName.includes('/') ? rawName : `core/${rawName}`;
    if (closing) {
      // Unbalanced markup: close back to the matching opener.
      const index = stack.map((f) => f.name).lastIndexOf(name);
      if (index < 0) continue;
      while (stack.length > index) close(stack.pop());
    } else if (selfClosing) close({ name, html: [], order: order++ });
    else stack.push({ name, html: [], order: order++ });
  }
  while (stack.length) close(stack.pop());
  // Document order is the order blocks opened.
  return out.sort((a, b) => a.order - b.order).map(({ order: _, ...o }) => o);
}

// The keys saved more than once: only these need rendering.
export function repeatedKeys(occurrences) {
  const count = new Map();
  for (const o of occurrences) count.set(`${o.kind}\n${o.key}`, (count.get(`${o.kind}\n${o.key}`) || 0) + 1);
  return [...count].filter(([, n]) => n > 1).map(([k]) => { const [kind, key] = k.split('\n'); return { kind, key }; });
}

// Runs in the frontend page: for each key, whether each rendered copy inside
// the post content shows, in document order. Text matches the deepest element
// whose normalized text is the key; an image matches by upload source.
export function sampleVisibility({ keys }) {
  const root = document.querySelector('.wp-block-post-content, .entry-content') || document.querySelector('main') || document.body;
  const norm = (s) => String(s || '').replace(/\s+/g, '').toLowerCase();
  const source = (src) => { try { return new URL(src, location.href).pathname.replace(/-(?:\d+x\d+|scaled)(\.[a-z0-9]+)$/i, '$1'); } catch { return null; } };
  const shows = (el) => {
    if (typeof el.checkVisibility === 'function' && !el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true })) return false;
    const rect = el.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
  };
  const wanted = new Set(keys.filter((k) => k.kind === 'text').map((k) => k.key));
  const elements = [...root.querySelectorAll('*')];
  const texts = new Map();
  for (const el of elements) {
    if (/^(SCRIPT|STYLE|NOSCRIPT|TEMPLATE)$/.test(el.tagName)) continue;
    const t = norm(el.textContent);
    if (!wanted.has(t)) continue;
    // Deepest: no child element carries the same text.
    if ([...el.children].some((c) => norm(c.textContent) === t)) continue;
    if (!texts.has(t)) texts.set(t, []);
    texts.get(t).push(el);
  }
  const images = new Map();
  for (const img of root.querySelectorAll('img')) {
    const key = source(img.getAttribute('src'));
    if (!key) continue;
    if (!images.has(key)) images.set(key, []);
    images.get(key).push(img);
  }
  return keys.map(({ kind, key }) => ({ kind, key, visible: ((kind === 'text' ? texts : images).get(key) || []).map(shows) }));
}

// Copies whose visible widths do not overlap, each visible somewhere: one is
// hidden wherever the other shows. `samples` maps width to the
// sampleVisibility result at that width. A key whose rendered copy count
// differs from its saved count cannot be matched copy to copy and is skipped.
export function duplicatedContent(occurrences, samples) {
  const duplicates = [];
  for (const { kind, key } of repeatedKeys(occurrences)) {
    const saved = occurrences.filter((o) => o.kind === kind && o.key === key);
    const widths = Object.keys(samples).map(Number).sort((a, b) => a - b);
    const per = widths.map((w) => samples[w].find((s) => s.kind === kind && s.key === key)?.visible || []);
    if (!widths.length || per.some((v) => v.length !== saved.length)) continue;
    const copies = saved.map((o, i) => ({ block_path: o.block_path, visible_widths: widths.filter((_, wi) => per[wi][i]) }));
    const shown = copies.filter((c) => c.visible_widths.length);
    const disjoint = shown.some((a, i) => shown.some((b, j) => j > i && !a.visible_widths.some((w) => b.visible_widths.includes(w))));
    if (!disjoint) continue;
    duplicates.push({ [kind === 'text' ? 'text' : 'src']: kind === 'text' ? saved[0].text : key, occurrences: saved.length, copies });
  }
  return duplicates;
}

// Render `url` at each width in a fresh page of `browser` and return the
// duplicates in `postContent`.
export async function duplicatedContentOnPage(browser, url, postContent, widths) {
  const occurrences = contentOccurrences(postContent);
  const keys = repeatedKeys(occurrences);
  if (!keys.length) return [];
  const samples = {};
  const page = await browser.newPage();
  try {
    for (const width of widths) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(url, { waitUntil: 'load' });
      samples[width] = await page.evaluate(sampleVisibility, { keys });
    }
  } finally { await page.close(); }
  return duplicatedContent(occurrences, samples);
}
