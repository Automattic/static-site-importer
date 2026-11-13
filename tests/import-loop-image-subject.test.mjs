import test from 'node:test';
import assert from 'node:assert/strict';
import { imageSubject } from '../tools/import-loop/image-subject.mjs';

const wp = 'http://127.0.0.1:8955';

test('a failing image is named by its src path on the site', () => {
  assert.equal(imageSubject(wp, { src: `${wp}/wp-content/uploads/2026/11/logo.png`, anchor: `${wp}/` }, 0), 'img:/wp-content/uploads/2026/11/logo.png');
  assert.equal(imageSubject(wp, { src: '/wp-content/uploads/logo-v2.png' }, 4), 'img:/wp-content/uploads/logo-v2.png');
});

test('an opaque src falls back to the anchor href', () => {
  assert.equal(imageSubject(wp, { src: 'data:image/gif;base64,R0lGOD', anchor: `${wp}/about/` }, 1), 'a:http://127.0.0.1:8955/about/');
  assert.equal(imageSubject(wp, { anchor: 'https://source.example/hero' }, 1), 'a:https://source.example/hero');
});

test('an image with neither src nor anchor keeps its probe position', () => {
  assert.equal(imageSubject(wp, { src: null, anchor: null }, 2), 'img#3');
  assert.equal(imageSubject(wp, {}, 0), 'img#1');
  assert.equal(imageSubject(wp, null, 0), 'img#1');
});
