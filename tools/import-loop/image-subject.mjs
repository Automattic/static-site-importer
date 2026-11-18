// The name a saved-image Replace finding gives its failing image, in the
// finding's evidence (not its subject: upload paths change between imports),
// so a fix that moves a header (with its logo) into a template part can no
// longer shift the probe onto an unrelated image without the evidence saying
// which one failed.
//
// Prefer the image src's path on the site, then the anchor href (a data: or
// blob: src has no usable path), then the probe position among the page's
// non-inert images.
export const imageSubject = (base, identity, index) => {
  const src = identity?.src;
  if (src && !/^(?:data|blob):/i.test(src)) { try { return `img:${new URL(src, base).pathname}`; } catch { /* unparseable src */ } }
  if (identity?.anchor) return `a:${String(identity.anchor).slice(0, 180)}`;
  return `img#${index + 1}`;
};
