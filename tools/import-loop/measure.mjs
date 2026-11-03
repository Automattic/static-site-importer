// The visitor-visible measure of a rendered page, shared by the evaluator and
// the predicates so a finding and its reproduction agree. Runs in the page.
//
// What a visitor can see: text whose box is on top at its own centre, images
// likewise, and the element that is on top across most of a grid of points.
export const measure = ({ coverShare, exclusions, geometryOnly }) => {
  const onTop = (el) => {
    const r = el.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) return false;
    if (geometryOnly) return true;
    const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    return !!hit && (hit === el || el.contains(hit) || hit.contains(el));
  };
  const style = (el) => getComputedStyle(el);
  // Every text node counts, whatever element carries it (an imported <span>
  // may become a RichText <mark>); its own box must be on top.
  let visibleText = 0;
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    const text = node.nodeValue.replace(/\s+/g, ' ').trim();
    const el = node.parentElement;
    if (!text || !el || el.closest('script,style,noscript,template')) continue;
    if (!geometryOnly && !el.checkVisibility({ opacityProperty: true, visibilityProperty: true, contentVisibilityAuto: true })) continue;
    const range = document.createRange();
    range.selectNodeContents(node);
    const r = range.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) continue;
    const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    if (geometryOnly || (hit && (hit === el || el.contains(hit) || hit.contains(el)))) visibleText += text.length;
  }
  // Visible media keyed so the same image matches across source and import:
  // raster media by normalized file name, SVG by its link target or label.
  const excluded = (el) => exclusions.some(({ selector, hosts }) => {
    const match = el.closest(selector);
    if (!match || !hosts) return !!match;
    const link = el.closest('a[href]');
    return !!link && hosts.some((h) => link.hostname === h || link.hostname.endsWith(`.${h}`));
  });
  const fileKey = (url) => {
    if (!url || url.startsWith('data:')) return null;
    const name = decodeURIComponent(new URL(url, location.href).pathname.split('/').pop() || '').toLowerCase().replace(/\.[a-z0-9]+$/, '').replace(/-(?:\d+x\d+|scaled)$/, '');
    return name && `img:${name.replace(/[^a-z0-9]/g, '')}`;
  };
  // A CSS-painted image: the url() of its background, if any.
  const background = (el) => /url\(["']?([^"')]+)/.exec(getComputedStyle(el).backgroundImage)?.[1] || '';
  const mediaKey = (el) => {
    const src = el.currentSrc || el.src || el.poster || background(el);
    if (el.tagName !== 'svg' && el.tagName !== 'SVG' && !/\/inline-svg-[^/]*\.svg|^data:image\/svg/.test(src)) return fileKey(src);
    // An icon is identified by where it links. Only the registrable host is
    // stable: sites rewrite link paths and subdomains as they hydrate.
    const link = el.closest('a[href]');
    if (link && !link.getAttribute('href').startsWith('#')) return `link:${link.hostname.split('.').slice(-2).join('.')}`;
    const label = el.getAttribute('aria-label') || el.querySelector?.('title')?.textContent || el.getAttribute('alt') || '';
    return label.trim() ? `svg:${label.trim().toLowerCase()}` : null;
  };
  const media = [...document.querySelectorAll('img,svg,video'), ...[...document.body.querySelectorAll('*')].filter(background)]
    .filter((el) => !el.parentElement?.closest('svg') && onTop(el) && !excluded(el))
    .map((el) => {
      const r = el.getBoundingClientRect();
      // Icons in a labelled group (a social bar) are one finding, not one per link.
      const group = el.closest('a[href]')?.parentElement?.closest('[aria-label]')?.getAttribute('aria-label')?.trim().toLowerCase();
      return { key: mediaKey(el), group: group ? `group:${group}` : null, w: Math.round(r.width), h: Math.round(r.height), style: (el.getAttribute('style') || '').slice(0, 160) };
    })
    .filter((m) => m.key);
  const counts = new Map();
  const { innerWidth: w, innerHeight: h } = window;
  let points = 0;
  for (let x = 0.1; x < 1; x += 0.2) for (let y = 0.05; y < 1; y += 0.1) {
    points++;
    const link = document.elementFromPoint(w * x, h * y)?.closest('a,button');
    if (link) counts.set(link, (counts.get(link) || 0) + 1);
  }
  const [cover] = [...counts].filter(([, n]) => n / points >= coverShare).sort((a, b) => b[1] - a[1]);
  const covering = cover && {
    tag: cover[0].tagName.toLowerCase(),
    text: cover[0].textContent.replace(/\s+/g, ' ').trim().slice(0, 60),
    classes: (cover[0].closest('[class*="wp-block-"]') || cover[0]).className.toString().split(/\s+/).filter((c) => !/^blocks-engine-|^be-/.test(c)).slice(0, 6).join(' '),
    share: cover[1] / points,
    height: Math.round(cover[0].getBoundingClientRect().height),
  };
  return { visibleText, media, covering, height: document.documentElement.scrollHeight, title: document.title };
};

// Lays a page out at a width with the viewport as tall as the page, so every
// element can be hit-tested where it sits.
export async function fitViewport(page, width) {
  await page.setViewportSize({ width, height: 900 });
  const height = Math.min(await page.evaluate(() => document.documentElement.scrollHeight), 12_000);
  await page.setViewportSize({ width, height: Math.max(height, 900) });
  await page.waitForTimeout(300);
}
