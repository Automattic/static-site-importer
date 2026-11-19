import assert from 'node:assert/strict';
import test from 'node:test';
import { ARCHIVE_ITEM_LANDMARKS, compareAuthoringLandmarks } from '../lib/authoring-parity.mjs';

const landmarks = { title: 1, date: 1, author: 1, terms: 2, sidebar: 2, related: 2, comments: 1, contentLeft: 180, contentWidth: 760 };
for (const [name, changes, missing, presence = [], base = {}] of [
  ['matching landmarks', {}, []],
  ['missing title', { title: 0 }, ['title']],
  ['missing sidebar', { sidebar: 1 }, ['sidebar']],
  ['missing related query', { related: 1 }, ['related']],
  ['within tolerance', { contentLeft: 184, contentWidth: 756 }, []],
  ['outside tolerance', { contentWidth: 765 }, ['contentWidth']],
  ['missing column', { contentLeft: null, contentWidth: null }, ['contentLeft', 'contentWidth']],
  ['no column on either', { contentLeft: null, contentWidth: null }, [], [], { contentLeft: null, contentWidth: null }],
  ['archive with fewer items', { date: 1, author: 1, terms: 1 }, [], ARCHIVE_ITEM_LANDMARKS],
  ['archive without dates', { date: 0 }, ['date'], ARCHIVE_ITEM_LANDMARKS],
]) {
  test(`authoring parity: ${name}`, () => {
    assert.deepEqual(compareAuthoringLandmarks({ ...landmarks, date: 9, ...base }, { ...landmarks, date: 9, ...changes }, { presence }), { pass: !missing.length, differences: missing });
  });
}
