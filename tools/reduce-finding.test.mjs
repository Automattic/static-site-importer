import test from 'node:test';
import assert from 'node:assert/strict';
import { reduceFinding, failureSignature, materializationFixture } from './reduce-finding.mjs';

const page='<html><head><style>figure > img{display:block}.unused{color:red}</style></head><body><p>noise</p><figure><img src="a" alt="x"></figure><aside>noise</aside></body></html>';
const predicate=html=>html.includes('figure > img')&&/<figure\b[^>]*>[\s\S]*?<img\b/.test(html);
test('reduces elements and CSS while preserving failure',async()=>{const r=await reduceFinding(page,predicate);assert.match(r.html,/<figure><img/);assert.doesNotMatch(r.html,/<p|<aside/);assert.match(r.html,/figure > img/);assert.doesNotMatch(r.html,/\.unused/);});
test('budget and deterministic results',async()=>{const r=await reduceFinding(page,predicate,{maxCalls:1});assert.equal(r.stopped,'budget');const a=await reduceFinding(page,predicate),b=await reduceFinding(page,predicate);assert.deepEqual(a,b);});
test('signature ignores names and literal content, but tracks CSS properties',()=>{const a=failureSignature('<figure class="a"><img id="x">hello</figure><style>figure > img{display:block;color:red}</style>',{stage:'materialization',code:'m'});const b=failureSignature('<figure class="b"><img id="y">world</figure><style>figure > img{color:blue;display:none}</style>',{stage:'materialization',code:'m'});assert.equal(a.sha256,b.sha256);const c=failureSignature('<figure><img></figure><style>figure > img{width:2px}</style>',{stage:'materialization',code:'m'});assert.notEqual(a.sha256,c.sha256);});
test('materialization fixture uses visual parity contract shape',()=>{const f=materializationFixture('<p>x</p>');assert.equal(f.schema,'blocks-engine/php-transformer/visual-parity-fixture/v1');assert.deepEqual(Object.keys(f).sort(),['description','name','schema','source','target','thresholds','viewports'].sort());assert.equal(f.viewports[0].width,1440);});
