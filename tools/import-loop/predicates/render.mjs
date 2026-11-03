// Renders a candidate page through Blocks Engine and WordPress the way an
// imported front end shows it: the transformer's block markup through
// do_blocks() (dynamic blocks such as navigation render only there), styled by
// core's block library and the transformer's CSS assets.
//
// The transformer names its runtime blocks custom/<name>; materialization
// registers them under the site's own namespace, so each unregistered
// custom/<name> renders as the registered <namespace>/<name>.
//
// WORDPRESS_PATH names a WordPress install with the importer active; WP_CLI is
// the command that runs WP-CLI against it (default `wp`, e.g. `studio wp`).
//
// With PREDICATE_SITE_URL set, the output is the route of a site the whole
// import materialized (gates/reimport.sh): site-wide planning, shared template
// parts and route styles included, which a single-page render cannot show.
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
    const php = `$registry = WP_Block_Type_Registry::get_instance();
$names = array();
foreach ( array_keys( $registry->get_all_registered() ) as $name ) $names[ substr( $name, strpos( $name, '/' ) + 1 ) ][] = $name;
echo do_blocks( preg_replace_callback( '#(<!-- /?wp:)custom/([a-z0-9-]+)#', static function ( $m ) use ( $registry, $names ) {
  $name = 'custom/' . $m[2];
  return $m[1] . ( ! $registry->is_registered( $name ) && 1 === count( $names[ $m[2] ] ?? array() ) ? $names[ $m[2] ][0] : $name );
}, file_get_contents( ${JSON.stringify(blocks)} ) ) );`;
    const html = execFileSync(command, [...prefix, `--path=${wordpress}`, 'eval', php], { encoding: 'utf8', maxBuffer: 512 << 20, stdio: ['ignore', 'pipe', 'ignore'] });
    const css = [
      fs.readFileSync(path.join(wordpress, 'wp-includes', 'css', 'dist', 'block-library', 'style.css'), 'utf8'),
      ...(result.assets || []).filter((a) => a?.kind === 'css' && typeof a.content === 'string' && a.stylesheet_target !== 'editor').map((a) => a.content),
    ].join('\n');
    return `<!doctype html><html><head><style>${css}</style></head><body><div class="wp-site-blocks">${html}</div></body></html>`;
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
}

// Load the imported output of `candidate` into a Playwright page.
export async function loadOutput(page, transformer, candidate, route = '/') {
  if (process.env.PREDICATE_SITE_URL) await page.goto(new URL(route, process.env.PREDICATE_SITE_URL).href, { waitUntil: 'load' });
  else await page.setContent(renderOutput(transformer, candidate), { waitUntil: 'load' });
}
