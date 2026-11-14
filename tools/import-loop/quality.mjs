// What a developer building the site by hand would get right beyond parity and
// editability: few layout wrappers, design tokens from the theme rather than
// literal values on blocks, and a repeated layout built once. Reported as
// advisories until imports show where their thresholds belong.
//
// `blocks` is the flat list editor.mjs's walk records: document order, depth
// starting at 1, and a `parent` index into the same array.

// Groups outnumbering the content they hold, or wrapping a single child, are
// structure an editor has to click through without it meaning anything.
export function wrapperWeight(blocks) {
  const children = new Map();
  blocks.forEach((b) => children.set(b.parent, (children.get(b.parent) || 0) + 1));
  const groups = blocks.map((b, i) => i).filter((i) => blocks[i].name === 'core/group');
  const leaves = blocks.filter((b, i) => !children.has(i)).length;
  return { groups: groups.length, leaves, single_child_groups: groups.filter((i) => children.get(i) === 1).length, heavy: groups.length > leaves };
}

// The same block tree, three blocks or larger, appearing three or more times on
// a page: a card or row a developer would build once as a pattern or a query
// loop. Only the outermost repeated tree is reported, not its repeated parts.
export function repeatedStructures(blocks, min = 3) {
  const kids = blocks.map(() => []);
  blocks.forEach((b, i) => { if (b.parent >= 0) kids[b.parent].push(i); });
  const shape = [];
  for (let i = blocks.length - 1; i >= 0; i--) {
    shape[i] = { tree: `${blocks[i].name.replace(/^core\//, '')}${kids[i].length ? `(${kids[i].map((k) => shape[k].tree).join(',')})` : ''}`, size: 1 + kids[i].reduce((n, k) => n + shape[k].size, 0) };
  }
  const count = new Map();
  shape.forEach(({ tree, size }) => { if (size >= 3) count.set(tree, (count.get(tree) || 0) + 1); });
  const repeated = new Set([...count].filter(([, n]) => n >= min).map(([tree]) => tree));
  return [...repeated]
    .filter((tree) => shape.some((s, i) => s.tree === tree && !(blocks[i].parent >= 0 && repeated.has(shape[blocks[i].parent].tree))))
    .map((tree) => ({ tree, count: count.get(tree), blocks: shape.find((s) => s.tree === tree).size }));
}

// Colors and font settings on blocks: a preset slug or `var:preset|` reference
// follows the theme when Global Styles change; a literal value does not.
const PRESET_ATTRIBUTES = new Set(['textColor', 'backgroundColor', 'gradient', 'borderColor', 'fontSize', 'fontFamily']);
const TOKEN_PATH = /^style\.(?:.*\.)?(?:color\.[a-zA-Z]+|border\.color|typography\.font(?:Size|Family))$/;
export function styleTokens(markup) {
  let presets = 0;
  const literals = new Map();
  for (const [, json] of markup.matchAll(/<!-- wp:[a-z0-9/-]+ (\{[^]*?\}) \/?-->/g)) {
    let attributes;
    try { attributes = JSON.parse(json); } catch { continue; }
    for (const key of Object.keys(attributes)) if (PRESET_ATTRIBUTES.has(key)) presets++;
    const walk = (value, path) => {
      if (value && typeof value === 'object') { for (const [key, child] of Object.entries(value)) walk(child, `${path}.${key}`); return; }
      if (typeof value !== 'string' || !TOKEN_PATH.test(path)) return;
      if (/^var:preset\||var\(--wp--preset--/.test(value)) presets++;
      else literals.set(value, (literals.get(value) || 0) + 1);
    };
    walk(attributes.style, 'style');
  }
  const literal = [...literals.values()].reduce((n, c) => n + c, 0);
  return { presets, literal, values: [...literals].sort((a, b) => b[1] - a[1]).slice(0, 10).map(([value, count]) => ({ value, count })) };
}
