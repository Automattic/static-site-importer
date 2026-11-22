// Measure the frozen import input, never the live source site. Use decoded
// text length, just as themeChecks does for the imported stylesheets.
import fs from 'node:fs';
import path from 'node:path';
import { parse } from 'parse5';
import { themeCss, QUALITY } from './quality.mjs';

export function sourceCss(capture, route) {
  const unmeasured = { source_kb: null, source_status: 'unmeasured' };
  if (!capture) return unmeasured;
  const root = path.resolve(capture, 'website');
  const local = (href, base) => {
    const url = new URL(href, base);
    if (url.origin !== 'https://capture.invalid') throw new Error('Uncaptured stylesheet');
    const file = path.resolve(root, '.' + decodeURIComponent(url.pathname));
    if (file !== root && !file.startsWith(root + path.sep)) throw new Error('Outside capture');
    return file;
  };
  try {
    const base = new URL(route, 'https://capture.invalid');
    let file = local(base.href, base);
    if (fs.statSync(file).isDirectory()) file = path.join(file, 'index.html');
    // Captures can factor shared chrome into server-side includes.
    const include = (file, depth = 0) => fs.readFileSync(file, 'utf8').replace(/<!--#include\s+virtual=["']([^"']+)["']\s*-->/gi, (_, href) => {
      if (depth >= 8) throw new Error('Recursive capture include');
      return include(local(href, new URL(path.relative(root, file), 'https://capture.invalid/')), depth + 1);
    });
    const sheets = [];
    const visit = (node) => {
      const attrs = Object.fromEntries((node.attrs || []).map(({ name, value }) => [name, value]));
      if (node.tagName === 'style') sheets.push({ bytes: (node.childNodes || []).map((n) => n.value || '').join('').length });
      if (node.tagName === 'link' && (attrs.rel || '').toLowerCase().split(/\s+/).includes('stylesheet') && attrs.href) {
        sheets.push({ bytes: fs.readFileSync(local(attrs.href, base), 'utf8').length });
      }
      for (const child of node.childNodes || []) visit(child);
    };
    visit(parse(include(file)));
    if (!sheets.length) return unmeasured;
    // All source styles count, including vendor CSS. Mapping them to inline
    // entries avoids the WordPress-only ownership filter in themeCss.
    return { source_kb: themeCss(sheets).kb, source_status: 'measured' };
  } catch {
    // A partial capture is not a trustworthy allowance for extra CSS.
    return unmeasured;
  }
}

export function themeCssWeight(css, source) {
  const limit = Math.max(QUALITY.theme_css_kb, source.source_kb ?? 0);
  return { ...css, ...source, limit_kb: limit, measure: { value: css.kb, limit } };
}
