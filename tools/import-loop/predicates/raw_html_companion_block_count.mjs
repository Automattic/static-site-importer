// Exit 0 while the Blocks Engine output of a page still emits the generated
// companion block <prefix>/<subject>. The importer counts that block as a raw
// HTML companion: captured markup the editor cannot edit as native blocks. The
// defect is gone only when the content converts to native blocks and the
// companion is no longer emitted. Re-encoding its markup (as a string, a node
// tree, or anything else) or changing its declared editing surface keeps the
// block, so it still reproduces.
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
const emitted = [...String(r.serialized_blocks || '').matchAll(/<!-- wp:([a-z0-9-]+\/[a-z0-9-]+)[ \/]/g)].filter((m) => m[1].endsWith(`/${subject}`)).length;
if (process.env.PREDICATE_DEBUG) console.error(JSON.stringify({ subject, emitted }));
process.exit(emitted ? 0 : 1);
