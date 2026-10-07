import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { chromium } from 'playwright';
import { createRequire } from 'node:module';
import vm from 'node:vm';
import { JSDOM, VirtualConsole } from 'jsdom';

const filename = process.argv[2];
if (!filename) throw new Error('Supply real WordPress form-boxes-wordpress.php evidence.');
const fixture = JSON.parse(readFileSync(filename, 'utf8'));
assert.ok(fixture.providerCss,'The actual Jetpack runtime stylesheet is required.');
const dom = new JSDOM('<!doctype html><html><body></body></html>',{virtualConsole:new VirtualConsole()});
globalThis.window = dom.window;
globalThis.document = dom.window.document;
Object.defineProperty(globalThis,'navigator',{value:dom.window.navigator,configurable:true});
for (const key of ['HTMLElement','Node','DOMParser','MutationObserver']) globalThis[key]=dom.window[key];
globalThis.getComputedStyle=dom.window.getComputedStyle;
globalThis.requestAnimationFrame=callback=>setTimeout(callback,0);
globalThis.cancelAnimationFrame=id=>clearTimeout(id);
const require=createRequire(import.meta.url);
const wpBlocks=require('@wordpress/blocks');
require('@wordpress/block-library').registerCoreBlocks();
for (const definition of fixture.forms.fragment.nativeDefinitions || []) {
    window.wp={blocks:{registerBlockType:(name,settings)=>wpBlocks.registerBlockType(name,{...definition.block_json,...settings})},blockEditor:require('@wordpress/block-editor'),components:require('@wordpress/components'),element:require('@wordpress/element'),richText:require('@wordpress/rich-text')};
    vm.runInThisContext(definition.assets['index.js']);
}
const validateTree=blocks=>{for(const block of blocks){assert.equal(wpBlocks.validateBlock(block)[0],true,`Gutenberg validates ${block.name}`);validateTree(block.innerBlocks);}};
validateTree(wpBlocks.parse(fixture.forms.fragment.markup));
for(const form of Object.values(fixture.forms)) for(const markup of form.coreButtons || []) validateTree(wpBlocks.parse(markup));
const browser = await chromium.launch({headless:true});
const measurements = [];
try {
    for (const width of [390, 768, 1440]) for (const [name, form] of Object.entries(fixture.forms)) {
        const page = await browser.newPage({ viewport:{width,height:900} });
        const observe = () => page.evaluate(() => {
            const box = e => { const r=e.getBoundingClientRect(); return {x:r.x,y:r.y,width:r.width,height:r.height}; };
            const input = document.querySelector('input:not([type=hidden])');
            const button = document.querySelector('button[type=submit]');
            const root = document.querySelector('.newsletter,.choice-form');
            return {root:box(root),input:box(input),button:button?box(button):null,caption:button?{size:getComputedStyle(button.querySelector('span')||button).fontSize}:null,checked:input.type==='checkbox'?input.checked:undefined};
        });
        await page.setContent(`<style>${form.sourceCss}</style>${form.source}`);
        const source = await observe();
        // These are Jetpack's real rendered elements. Load its shipped provider
        // styles, then the materialized source/overlay CSS in theme order.
        const providerCss = fixture.providerCss;
        await page.setContent(`<style>${providerCss}</style><style>.wp-block-button__link{height:100%;box-sizing:border-box}${form.css}</style>${form.html}`);
        const actual = await observe();
        measurements.push({name,width,source,actual});
        await page.screenshot({path:join(dirname(filename),`${name}-${width}.png`),fullPage:true});
        assert.deepEqual(actual,source,`${name} ${width}px source/provider geometry and state`);
        if (name==='fragment') { assert.equal(form.provider,false); assert.equal(await page.locator('button[type=submit]').count(),0); }
        if (inputType(name)==='checkbox') {
            await page.locator('input[type=checkbox]').click();
            assert.equal(await page.locator('input[type=checkbox]').isChecked(),false,'Native choice remains functional');
        } else {
            await page.locator('input[type=email]').fill('reader@example.test');
            assert.equal(await page.locator('input[type=email]').inputValue(),'reader@example.test');
            assert.equal(await page.locator('button').evaluate(e=>e.type),'submit');
        }
        await page.close();
    }
} finally {
    writeFileSync(join(dirname(filename),'geometry.json'),JSON.stringify(measurements,null,2));
    await browser.close();
}
function inputType(name) { return name==='newsletter'?'email':'checkbox'; }
console.log('Real WordPress/Jetpack source box parity passed at 390/768/1440px; Gutenberg validates native fields and Core submits, the fragment has no invented submit, and choices remain functional.');
