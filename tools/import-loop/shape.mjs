// The source shape a generated block preserves: the tag and attribute names of
// each wrapper it keeps, outermost first, e.g. `div[data-image-info]>picture`.
// One generated block slug can come from several unrelated source structures,
// each its own defect; a finding about the block names one shape so its fix
// and its acceptance are about one cause. Attribute values and the
// per-element `class`, `id` and `style` are left out so instances group.
const PER_ELEMENT = new Set(['class', 'id', 'style']);
export function blockShape(attrs) {
  const wrappers = Array.isArray(attrs?.wrappers) ? attrs.wrappers : [];
  return wrappers.map((w) => `${w?.tagName || '?'}${Object.keys(w?.attributes || {}).filter((n) => !PER_ELEMENT.has(n)).sort().map((n) => `[${n}]`).join('')}`).join('>');
}
// A block subject is `<slug>` or `<slug>@<shape>`.
export const blockSubject = (slug, shape) => (shape ? `${slug}@${shape}` : slug);
export function parseBlockSubject(subject) {
  const at = subject.indexOf('@');
  return at < 0 ? { slug: subject, shape: null } : { slug: subject.slice(0, at), shape: subject.slice(at + 1) };
}
