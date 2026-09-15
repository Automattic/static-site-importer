/**
 * Capture a content region at a stable raster origin. This changes only its
 * temporary paint position, restores its inline style, and records the shift.
 * Semantic geometry is measured separately, before this capture adjustment.
 */
export async function captureAlignedRegion(locator, screenshotOptions = {}) {
  await locator.scrollIntoViewIfNeeded();
  const before = await locator.evaluate(element => {
    const rect = element.getBoundingClientRect();
    const computed = getComputedStyle(element);
    if (window.frameElement && (rect.height + 2 > innerHeight || rect.width > innerWidth)) {
      throw new Error('Editor region is clipped by its iframe viewport; capture at a larger matched viewport.');
    }
    if (computed.transform !== 'none' || !['static', 'relative'].includes(computed.position)) {
      throw new Error('Raster-origin alignment requires an untransformed static or relative region.');
    }
    return { style: element.getAttribute('style'), rect: { x: rect.x, y: rect.y, width: rect.width, height: rect.height }, position: computed.position, left: computed.left, top: computed.top };
  });
  const offset = { x: Math.ceil(before.rect.x) - before.rect.x, y: Math.ceil(before.rect.y) - before.rect.y };
  try {
    await locator.evaluate((element, { before, offset }) => {
      const pixel = value => value === 'auto' ? 0 : Number.parseFloat(value);
      // Relative positioning changes the layout coordinate used for text
      // rasterization; a compositor transform merely moves already-painted text.
      element.style.setProperty('position', 'relative', 'important');
      element.style.setProperty('left', `${(before.position === 'relative' ? pixel(before.left) : 0) + offset.x}px`, 'important');
      element.style.setProperty('top', `${(before.position === 'relative' ? pixel(before.top) : 0) + offset.y}px`, 'important');
    }, { before, offset });
    const aligned = await locator.evaluate(element => {
      const r = element.getBoundingClientRect();
      return { x: r.x, y: r.y, width: r.width, height: r.height };
    });
    if (Math.abs(aligned.width - before.rect.width) > 0.01 || Math.abs(aligned.height - before.rect.height) > 0.01 || Math.abs(aligned.x - Math.round(aligned.x)) > 0.01 || Math.abs(aligned.y - Math.round(aligned.y)) > 0.01) {
      throw new Error('Raster-origin alignment changed region geometry or did not reach an integer origin.');
    }
    const png = await locator.screenshot({ ...screenshotOptions, animations: 'disabled' });
    return { png, evidence: { schema: 'static-site-importer/raster-origin/v1', original: before.rect, aligned, offset, method: 'temporary-relative-position', restored: true } };
  } finally {
    await locator.evaluate((element, style) => {
      if (style === null) element.removeAttribute('style');
      else element.setAttribute('style', style);
    }, before.style);
  }
}
