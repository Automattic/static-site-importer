// Shared by block predicates: exit 0 while the Blocks Engine output of the
// candidate page still emits a block named <namespace>/<subject>, 1 once it
// does not, 2 when the transform fails. The block is matched by slug because
// materialization renames the namespace per site. Re-encoding the content it
// carries (as a string, a node tree, or anything else) or changing its declared
// editing surface keeps the block, so the defect still reproduces.
// Usage: node <predicate>.mjs <php-transformer dir> <candidate.html>
// PREDICATE_TARGET.subject is the block slug, for example "layout-shell"; a
// slug stem also matches the hashed slugs it names (block → block-<sha256>).
// A subject `<slug>@<shape>` matches only blocks of that source shape.
import { spawnSync } from 'node:child_process';
import { blockShape, parseBlockSubject } from '../shape.mjs';

export function exitWhileEmitted() {
  const [transformer, candidate] = process.argv.slice(2);
  const { subject } = JSON.parse(process.env.PREDICATE_TARGET || '{}');
  if (!subject) process.exit(2);
  const { slug: target, shape } = parseBlockSubject(subject);
  const attrs = (m) => { try { return JSON.parse(m[2] || '{}'); } catch { return {}; } };
  const transform = new URL('../../reduce-finding/proofs/transform.php', import.meta.url).pathname;
  const emittedBy = (...mode) => {
    const out = spawnSync('php', ['-d', 'memory_limit=4G', transform, transformer, candidate, ...mode], { encoding: 'utf8', maxBuffer: 512 << 20 });
    if (out.status !== 0) process.exit(2);
    let result;
    try { result = JSON.parse(out.stdout); } catch { process.exit(2); }
    return matching(String(result.serialized_blocks || ''));
  };
  const matching = (markup) => [...markup.matchAll(/<!-- wp:([a-z0-9-]+\/[a-z0-9-]+)( \{.*?\})? \/?-->/g)].filter((m) => { const slug = m[1].split('/').pop(); return (slug === target || slug.replace(/-[0-9a-f]{64}$/, '') === target) && (shape === null || blockShape(attrs(m)) === shape); });
  // Blocks built from capture evidence (captured dialogs and the controls that
  // open them) exist only when the page compiles with that evidence, as the
  // importer compiles it. PREDICATE_CAPTURE names the capture directory.
  const { route = '/' } = JSON.parse(process.env.PREDICATE_TARGET || '{}');
  let emitted = emittedBy();
  if (!emitted.length && process.env.PREDICATE_CAPTURE) emitted = emittedBy('capture', process.env.PREDICATE_CAPTURE, route);
  // Forms reach the editor through the importer's form materialization (the
  // engine hands each whole form over as core/html), so a block the importer
  // emits inside a materialized form is checked there. Needs an imported site
  // with the importer active (WORDPRESS_PATH, WP_CLI).
  if (!emitted.length && process.env.WORDPRESS_PATH) {
    const [command, ...prefix] = (process.env.WP_CLI || 'wp').split(/\s+/);
    const helper = new URL('./materialized-forms.php', import.meta.url).pathname;
    const out = spawnSync(command, [...prefix, `--path=${process.env.WORDPRESS_PATH}`, 'eval-file', helper, transformer, candidate], { encoding: 'utf8', maxBuffer: 512 << 20 });
    if (out.status !== 0) process.exit(2);
    let forms;
    try { forms = JSON.parse(out.stdout); } catch { process.exit(2); }
    emitted = matching(forms.join('\n'));
  }
  // What still reproduces: each remaining block's attributes, so a fix can
  // tell which source structures it has not reached yet.
  if (process.env.PREDICATE_DEBUG) console.error(JSON.stringify({ subject, emitted: emitted.length, remaining: emitted.slice(0, 8).map((m) => (m[2] || '').trim().slice(0, 300)) }));
  process.exit(emitted.length ? 0 : 1);
}
