// editor_list_view fails a route whose blocks are mostly core/group nested
// deeper than 8, but its evidence used to be one fixed string, so an agent
// fixing the finding had no counts, path, or target (issue #2956). This
// derives the structured evidence: the group count, the total block count, the
// max depth, and the deepest root-to-leaf chain as block name plus
// className/anchor at each level. Those classNames and anchors are the source
// layout's own hooks, so the chain names the DOM chain to inspect in the
// capture. `blocks` is the flat list editor.mjs's walk records: document
// order, depth starting at 1, and a `parent` index into the same array.
export function listViewEvidence(blocks) {
  let at = 0;
  for (let i = 1; i < blocks.length; i++) if (blocks[i].depth > blocks[at].depth) at = i;
  const chain = [];
  for (let i = at; i >= 0; i = blocks[i].parent) {
    const { name, className, anchor } = blocks[i];
    chain.unshift({ name, ...(className ? { className } : {}), ...(anchor ? { anchor } : {}) });
  }
  return { groups: blocks.filter((b) => b.name === 'core/group').length, blocks: blocks.length, depth: blocks[at].depth, chain };
}
