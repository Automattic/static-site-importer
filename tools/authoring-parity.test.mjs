import assert from 'node:assert/strict';
import test from 'node:test';
import { compareAuthoringLandmarks } from '../lib/authoring-parity.mjs';

const landmarks = { title: 1, date: 1, author: 1, terms: 2, sidebar: 2, related: 2, comments: 1, contentLeft: 180, contentWidth: 760 };
for (const [name, changes, missing] of [
  ['matching landmarks', {}, []],
  ['missing title', { title: 0 }, ['title']],
  ['missing sidebar', { sidebar: 1 }, ['sidebar']],
  ['missing related query', { related: 1 }, ['related']],
  ['within tolerance', { contentLeft: 184, contentWidth: 756 }, []],
  ['outside tolerance', { contentWidth: 765 }, ['contentWidth']],
  ['missing column', { contentLeft: null, contentWidth: null }, ['contentLeft', 'contentWidth']],
]) {
  test(`authoring parity: ${name}`, () => {
    assert.deepEqual(compareAuthoringLandmarks(landmarks, { ...landmarks, ...changes }), { pass: !missing.length, differences: missing });
  });
}
