// Exit 0 when the Blocks Engine output of a page contains a generated companion
// block named <prefix>/<subject> whose editing surface is raw HTML: the source
// markup lands in the editor as escaped HTML instead of native blocks. This is
// the importer's raw_html_companion_block_count, measured on one page.
// Usage: node predicate.mjs <php-transformer dir> <candidate.html>
// PREDICATE_TARGET.subject is the block slug, for example "responsive-media".
import { spawnSync } from 'node:child_process';
const [transformer, candidate] = process.argv.slice(2);
const { subject } = JSON.parse(process.env.PREDICATE_TARGET || '{}');
if (!subject) process.exit(2);
const transform = new URL('../../reduce-finding/proofs/transform.php', import.meta.url).pathname;
const out = spawnSync('php', ['-d', 'memory_limit=2G', transform, transformer, candidate], { encoding: 'utf8', maxBuffer: 512 << 20 });
if (out.status !== 0) process.exit(2);
let r; try { r = JSON.parse(out.stdout); } catch { process.exit(2); }
const raw = (r.source_reports?.generated_blocks || []).map((b) => b.block_json || {}).filter((m) => m.blocksEngine?.editingSurface === 'raw_html' && String(m.name || '').endsWith(`/${subject}`)).map((m) => m.name);
const markup = String(r.serialized_blocks || '');
const used = raw.filter((name) => markup.includes(`<!-- wp:${name} `) || markup.includes(`<!-- wp:${name} -->`) || markup.includes(`<!-- wp:${name} /-->`));
if (process.env.PREDICATE_DEBUG) console.error(JSON.stringify({ raw, used }));
process.exit(used.length ? 0 : 1);
