// Renders a candidate page through Blocks Engine and WordPress the way an
// imported front end shows it: the transformer's block markup through
// do_blocks() (dynamic blocks such as navigation render only there), styled by
// core's block library and the transformer's CSS assets.
//
// WORDPRESS_PATH names a WordPress install with the importer active; WP_CLI is
// the command that runs WP-CLI against it (default `wp`, e.g. `studio wp`).
import { execFileSync, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const transform = new URL('../../reduce-finding/proofs/transform.php', import.meta.url).pathname;

export function renderOutput(transformer, candidate) {
  const wordpress = process.env.WORDPRESS_PATH;
  if (!wordpress) throw new Error('render_output_requires_WORDPRESS_PATH');
  const out = spawnSync('php', ['-d', 'memory_limit=2G', transform, transformer, candidate], { encoding: 'utf8', maxBuffer: 512 << 20 });
  if (out.status !== 0) throw new Error(`transform_failed: ${out.stderr.slice(-300)}`);
  const result = JSON.parse(out.stdout);
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'render-output-'));
  try {
    const blocks = path.join(dir, 'blocks.html');
    fs.writeFileSync(blocks, String(result.serialized_blocks || ''));
    const [command, ...prefix] = (process.env.WP_CLI || 'wp').split(/\s+/);
    const html = execFileSync(command, [...prefix, `--path=${wordpress}`, 'eval', `echo do_blocks(file_get_contents(${JSON.stringify(blocks)}));`], { encoding: 'utf8', maxBuffer: 512 << 20, stdio: ['ignore', 'pipe', 'ignore'] });
    const css = [
      fs.readFileSync(path.join(wordpress, 'wp-includes', 'css', 'dist', 'block-library', 'style.css'), 'utf8'),
      ...(result.assets || []).filter((a) => a?.kind === 'css' && typeof a.content === 'string' && a.stylesheet_target !== 'editor').map((a) => a.content),
    ].join('\n');
    return `<!doctype html><html><head><style>${css}</style></head><body><div class="wp-site-blocks">${html}</div></body></html>`;
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
}
