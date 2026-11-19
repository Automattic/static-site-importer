import test from 'node:test';
import assert from 'node:assert/strict';
import { canvasParityMismatches, canvasParityEvidence, templatePartsRendered } from '../tools/import-loop/canvas-parity.mjs';

const el = (text, tag = 'P', color = 'rgb(0, 0, 0)') => ({ text, tag, color, font: 'Inter' });

// Issue #3140: the page template places a floating WhatsApp link (an empty
// paragraph) after the footer template part. The front end renders 40
// elements before it; the editor, sampled before the footer part loaded,
// renders the 30 content elements and then the link.
const content = Array.from({ length: 30 }, (_, i) => el(`Content ${i}`));
const footer = Array.from({ length: 10 }, (_, i) => el(`Footer ${i}`, 'P', 'rgb(250, 248, 245)'));
const whatsapp = el('', 'P', 'rgb(255, 255, 255)');

test('chrome after a footer past the 40th element still matches the front end', () => {
  const frontend = [...content, ...footer, whatsapp];
  const editor = [...content, whatsapp];
  assert.deepEqual(canvasParityMismatches(editor, frontend), []);
  assert.deepEqual(canvasParityMismatches([...content, ...footer, whatsapp], frontend), []);
});

test('an editor element absent from the front end is still reported', () => {
  const frontend = [...content, ...footer];
  const missing = el('Only in the editor', 'H2');
  assert.deepEqual(canvasParityMismatches([...content, missing], frontend), [missing]);
  assert.deepEqual(canvasParityMismatches([el('Content 0', 'P', 'rgb(1, 2, 3)')], frontend), [el('Content 0', 'P', 'rgb(1, 2, 3)')]);
  assert.equal(JSON.parse(canvasParityEvidence([missing], frontend, [missing])).missing[0].text, 'Only in the editor');
});

test('an empty editor canvas is a mismatch', () => {
  assert.equal(canvasParityMismatches([], content).length, 1);
});

test('template parts count as rendered only once each has blocks', () => {
  const blocks = { a: { innerBlocks: [{}] }, b: { innerBlocks: [] } };
  globalThis.window = { wp: { data: { select: () => ({ getBlocksByName: () => ['a', 'b'], getBlock: (id) => blocks[id] }) } } };
  try {
    assert.equal(templatePartsRendered(), false);
    blocks.b.innerBlocks.push({});
    assert.equal(templatePartsRendered(), true);
  } finally { delete globalThis.window; }
});
