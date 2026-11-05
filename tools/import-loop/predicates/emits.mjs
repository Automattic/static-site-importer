// Shared by block predicates: exit 0 while the Blocks Engine output of the
// candidate page still emits a block named <namespace>/<subject>, 1 once it
// does not, 2 when the transform fails. The block is matched by slug because
// materialization renames the namespace per site. Re-encoding the content it
// carries (as a string, a node tree, or anything else) or changing its declared
// editing surface keeps the block, so the defect still reproduces.
// Usage: node <predicate>.mjs <php-transformer dir> <candidate.html>
// PREDICATE_TARGET.subject is the block slug, for example "layout-shell"; a
// slug stem also matches the hashed slugs it names (block → block-<sha256>).
import { spawnSync } from 'node:child_process';

export function exitWhileEmitted() {
  const [transformer, candidate] = process.argv.slice(2);
  const { subject } = JSON.parse(process.env.PREDICATE_TARGET || '{}');
  if (!subject) process.exit(2);
  const transform = new URL('../../reduce-finding/proofs/transform.php', import.meta.url).pathname;
  const out = spawnSync('php', ['-d', 'memory_limit=2G', transform, transformer, candidate], { encoding: 'utf8', maxBuffer: 512 << 20 });
  if (out.status !== 0) process.exit(2);
  let result;
  try { result = JSON.parse(out.stdout); } catch { process.exit(2); }
  const emitted = [...String(result.serialized_blocks || '').matchAll(/<!-- wp:([a-z0-9-]+\/[a-z0-9-]+)( \{.*?\})? \/?-->/g)].filter((m) => { const slug = m[1].split('/').pop(); return slug === subject || slug.replace(/-[0-9a-f]{64}$/, '') === subject; });
  // What still reproduces: each remaining block's attributes, so a fix can
  // tell which source structures it has not reached yet.
  if (process.env.PREDICATE_DEBUG) console.error(JSON.stringify({ subject, emitted: emitted.length, remaining: emitted.slice(0, 8).map((m) => (m[2] || '').trim().slice(0, 300)) }));
  process.exit(emitted.length ? 0 : 1);
}
