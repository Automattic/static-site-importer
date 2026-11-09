// Inline local <link rel=stylesheet> files so a captured page is one standalone HTML file.
import fs from 'node:fs'; import path from 'node:path';
const [input, output] = process.argv.slice(2); const dir = path.dirname(input);
const root = dir.replace(/\/website(\/.*)?$/, '/website');
const html = fs.readFileSync(input, 'utf8').replace(/<link\b[^>]*rel=["']?stylesheet[^>]*>/gi, (tag) => {
  const href = (tag.match(/href=["']([^"']+)/) || [])[1]; if (!href || /^https?:/.test(href)) return tag;
  const file = href.startsWith('/') ? path.join(root, href) : path.join(dir, href);
  return fs.existsSync(file) ? `<style>${fs.readFileSync(file, 'utf8')}</style>` : tag;
}).replace(/<script\b[\s\S]*?<\/script>/gi, '');
fs.writeFileSync(output, html); console.log(input, '->', output, html.length);
