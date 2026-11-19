// editor_canvas_parity: the text an editor sees in the canvas must be the text
// a visitor sees on the front end, in the same color and font.
//
// Both sides are sampled whole. The check used to cut each list at 40
// elements, which compared lists cut at different blocks: when the footer
// template part had not rendered in the editor yet, the editor list stopped
// short of the footer and kept the inline chrome a template places after it
// (a floating WhatsApp link, issue #3140), while the front-end list, with its
// footer, lost that same chrome past the cut. A block present on both sides
// was then reported as missing from the front end.

// Runs in the page: every visible heading, paragraph and figure image under
// `root`, in document order.
export function sampleCanvas(root) {
  return [...root.querySelectorAll('h1,h2,p,figure img')]
    .filter((e) => e.getBoundingClientRect().width > 0)
    .map((e) => ({ text: e.textContent.trim(), tag: e.tagName, color: getComputedStyle(e).color, font: getComputedStyle(e).fontFamily }));
}

const same = (a, b) => a.tag === b.tag && a.text === b.text && a.color === b.color && a.font === b.font;

// Editor elements with no front-end element of the same tag, text, color and
// font. An empty editor sample is itself a mismatch: the canvas shows nothing.
export function canvasParityMismatches(editor, frontend) {
  if (!editor.length) return [{ reason: 'empty_editor_canvas' }];
  return editor.filter((e) => !frontend.some((f) => same(e, f)));
}

// Evidence leads with what is missing, so a truncated report still names it.
export function canvasParityEvidence(editor, frontend, missing) {
  return JSON.stringify({ missing, editor_count: editor.length, frontend_count: frontend.length, editor, frontend });
}

// Runs in the page: true once every template part in the editor has its
// blocks. The post editor shows the page's template around the post, and the
// template's own blocks render at editor-ready while each template part loads
// its entity afterwards; sampling before then misses the header or footer.
export function templatePartsRendered() {
  const select = window.wp?.data?.select?.('core/block-editor');
  if (!select?.getBlocksByName) return true;
  return select.getBlocksByName('core/template-part').every((id) => (select.getBlock(id)?.innerBlocks?.length ?? 0) > 0);
}
