import test from 'node:test';
import assert from 'node:assert/strict';
import { listViewEvidence } from '../tools/import-loop/list-view.mjs';

// Reproduces the flat list editor.mjs's walk records: document order, depth
// starting at 1, a `parent` index into the same array, and className/anchor
// only when the block carries them.
const page = [
  { name: 'core/paragraph', valid: true, depth: 1, parent: -1 },
  { name: 'core/group', valid: true, depth: 1, parent: -1, className: 'site-content', anchor: 'main' },
  { name: 'core/group', valid: true, depth: 2, parent: 1, className: 'content-column' },
  { name: 'core/paragraph', valid: true, depth: 3, parent: 2 },
  { name: 'core/group', valid: true, depth: 3, parent: 2 },
  { name: 'core/group', valid: true, depth: 4, parent: 4, anchor: 'nested' },
  { name: 'core/group', valid: true, depth: 5, parent: 5 },
  { name: 'core/group', valid: true, depth: 6, parent: 6, className: 'stack' },
  { name: 'core/group', valid: true, depth: 7, parent: 7 },
  { name: 'core/group', valid: true, depth: 8, parent: 8 },
  { name: 'core/group', valid: true, depth: 9, parent: 9, className: 'deepest' },
  { name: 'core/group', valid: true, depth: 5, parent: 2, className: 'sidebar' },
];

test('evidence names the counts and the deepest chain root to leaf', () => {
  const evidence = listViewEvidence(page);
  assert.equal(evidence.groups, 10);
  assert.equal(evidence.blocks, 12);
  assert.equal(evidence.depth, 9);
  // Nine levels from the site-content group down to the deepest leaf, not the
  // shallower paragraph or sidebar branches.
  assert.deepEqual(evidence.chain, [
    { name: 'core/group', className: 'site-content', anchor: 'main' },
    { name: 'core/group', className: 'content-column' },
    { name: 'core/group' },
    { name: 'core/group', anchor: 'nested' },
    { name: 'core/group' },
    { name: 'core/group', className: 'stack' },
    { name: 'core/group' },
    { name: 'core/group' },
    { name: 'core/group', className: 'deepest' },
  ]);
});

test('chain entries carry className or anchor only when present', () => {
  const evidence = listViewEvidence(page);
  for (const entry of evidence.chain) {
    assert.ok(!('className' in entry) || typeof entry.className === 'string');
    assert.ok(!('anchor' in entry) || typeof entry.anchor === 'string');
  }
  assert.ok(!('className' in evidence.chain[2]) && !('anchor' in evidence.chain[2]));
});

test('equally deep chains keep the first in document order', () => {
  const tie = [
    { name: 'core/group', valid: true, depth: 9, parent: -1, className: 'first' },
    { name: 'core/paragraph', valid: true, depth: 1, parent: -1 },
    { name: 'core/group', valid: true, depth: 9, parent: -1, className: 'second' },
  ];
  assert.equal(listViewEvidence(tie).chain[0].className, 'first');
});
