// Exit 0 when the Blocks Engine output of a page contains a generated companion
// block named <prefix>/<subject> that carries raw source markup: it declares the
// raw HTML editing surface, or any attribute holds an HTML element. Either way
// the content is not native blocks. Checking the markup, not only the
// declaration, keeps a fix from passing by relabeling the block.
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
const declared = new Set((r.source_reports?.generated_blocks || []).map((b) => b.block_json || {}).filter((m) => m.blocksEngine?.editingSurface === 'raw_html').map((m) => m.name));
const element = /<[a-z][a-z0-9-]*[\s>\/]/i;
const carries = (attrs) => Object.values(attrs || {}).some((v) => (typeof v === 'string' ? element.test(v) : v && typeof v === 'object' && carries(v)));
const used = [];
// Block comment delimiters: <!-- wp:ns/name {json} /--> or <!-- wp:ns/name {json} -->
for (const m of String(r.serialized_blocks || '').matchAll(/<!-- wp:([a-z0-9-]+\/[a-z0-9-]+) (\{.*?\}) ?\/?-->|<!-- wp:([a-z0-9-]+\/[a-z0-9-]+) \/?-->/g)) {
  const name = m[1] || m[3];
  if (!name.endsWith(`/${subject}`)) continue;
  let attrs = {}; try { attrs = m[2] ? JSON.parse(m[2]) : {}; } catch { attrs = { unparsed: m[2] }; }
  if (declared.has(name) || carries(attrs)) used.push(name);
}
if (process.env.PREDICATE_DEBUG) console.error(JSON.stringify({ declared: [...declared], used: used.length }));
process.exit(used.length ? 0 : 1);
