import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { build } from 'esbuild';
const require = createRequire(import.meta.url);
const { ensureRuntime, registerValidationBlockType, validateMarkup } = require('../lib/gutenberg-block-validation.cjs');
ensureRuntime();
const metadata = JSON.parse(readFileSync(new URL('./fixtures/consent-banner/jetpack/block.json', import.meta.url)));
const bundle = (await build({ stdin: { contents: readFileSync('tests/fixtures/consent-banner/jetpack/save.jsx.txt', 'utf8'), resolveDir: process.cwd(), loader: 'jsx' }, plugins: [{ name: 'jetpack-constants', setup(build) { build.onResolve({ filter: /^\.\/constants$/ }, () => ({ path: 'constants', namespace: 'jetpack' })); build.onLoad({ filter: /.*/, namespace: 'jetpack' }, () => ({ contents: readFileSync('tests/fixtures/consent-banner/jetpack/constants.js.txt', 'utf8'), loader: 'js', resolveDir: process.cwd() })); } }], bundle: true, write: false, format: 'cjs', platform: 'node', packages: 'external', jsx: 'automatic' })).outputFiles[0].text;
const saved = { exports: {} };
new Function('require', 'module', 'exports', bundle)(require, saved, saved.exports);
registerValidationBlockType(metadata.name, { ...metadata, save: saved.exports.default });
test('consent intent plans Jetpack through the provider dependency lifecycle', () => {
 assert.match(execFileSync('php', ['tests/fixtures/consent-banner/lifecycle.php'], { encoding: 'utf8' }), /Consent dependency planned/);
});
test('presentation carries neutral geometry and typography without accepting source CSS', () => {
 const run = mode => execFileSync('php', ['tests/fixtures/consent-banner/markup.php', mode], { encoding: 'utf8' });
 const markup = run('presentation');
 const result = validateMarkup(markup);
 assert.equal(result.ok, true, JSON.stringify(result.failures));
 assert.equal(markup, readFileSync('tests/fixtures/consent-banner/jetpack/presentation.html', 'utf8'));
 assert.match(markup, /"align":"full"/);
 assert.match(markup, /"className":"ssi-consent-banner"/);
 assert.match(markup, /"spacing":\{"padding":"12px"\}/);
 assert.match(markup, /"border":\{"radius":"5px","width":"2px","style":"solid","color":"#777777"\}/);
 assert.match(markup, /has-border-color/);
 assert.match(markup, /border-width:2px;border-style:solid;border-color:#777777/);
 assert.equal(run('legacy-markup'), readFileSync('tests/fixtures/consent-banner/jetpack/legacy-presentation.html', 'utf8'));
 assert.match(markup, /"spacing":\{"padding":"8px 40px"\}/);
 assert.match(markup, /"typography":\{"fontSize":"13.5px","lineHeight":"16.2px"\}/);
 assert.match(markup, /line-height:16\.2px/);
 const selector = '.wp-block-jetpack-cookie-consent.ssi-consent-banner.ssi-consent-banner:not([role="document"])';
 const css = run('css');
 assert.ok(css.includes(`${selector}{bottom:0px;top:auto;font-family:Calibri, sans-serif;font-size:15px;line-height:18px;flex-wrap:nowrap;}`));
 assert.ok(css.includes(`${selector} > p{flex:1 1 auto;margin:0px;}`));
 assert.ok(css.includes(`@media (max-width:767px){${selector}{flex-direction:column;}${selector} > p{margin:0 0 15px;}${selector} > .wp-block-buttons{align-self:flex-end;margin-left:auto;margin-right:0;}}`));
 assert.ok(css.includes(`@media (min-width:768px){${selector}{flex-direction:row;align-items:center;}${selector} > p{margin:0 15px 0 0;}}`));
 assert.ok(run('top').includes(`${selector}{top:0px;`));
 assert.doesNotMatch(run('top'), /bottom:[^a]/);
 assert.doesNotMatch(run('hostile'), /flex-direction|flex-wrap|margin:|line-height:/);
 assert.equal(run('legacy'), '\n.wp-block-jetpack-cookie-consent.ssi-consent-banner.ssi-consent-banner:not([role="document"]){bottom:0px;top:auto;font-family:Calibri, sans-serif;font-size:15px;line-height:18px;}\n');
 assert.doesNotMatch(run('hostile'), /display:none|body\{|has-border-color|border-width:|border-style:|border-color:/);
 assert.ok(css.indexOf(' > p{flex:1 1 auto;margin:0px;}') < css.indexOf('@media'));
});
test('the policy link keeps its captured colour through block link support', () => {
 const run = color => execFileSync('php', ['tests/fixtures/consent-banner/markup.php', 'link-color', color], { encoding: 'utf8' });
 const markup = run('rgb(255, 255, 255)');
 const result = validateMarkup(markup);
 assert.equal(result.ok, true, JSON.stringify(result.failures));
 assert.match(markup, /"elements":\{"link":\{"color":\{"text":"#ffffff"\}\}\}/);
 assert.match(run('rgba(46, 67, 82, 0.5)'), /"text":"#2e435280"/);
 assert.doesNotMatch(run('red;background:url(x)'), /"elements"/);
});
test('an inset card keeps its title, edge, width and stretched control per breakpoint', () => {
 const [markup, css] = execFileSync('php', ['tests/fixtures/consent-banner/markup.php', 'card'], { encoding: 'utf8' }).split('\n---\n');
 const result = validateMarkup(markup);
 assert.equal(result.ok, true, JSON.stringify(result.failures));
 assert.match(markup, /"align":"right"/);
 assert.ok(markup.includes('<p><strong class="ssi-consent-banner__title">This website uses cookies.</strong> We use cookies'));
 const selector = '.wp-block-jetpack-cookie-consent.ssi-consent-banner.ssi-consent-banner:not([role="document"])';
 assert.ok(css.includes(`${selector} > p > .ssi-consent-banner__title{display:block;font-size:22px;line-height:27.5px;font-weight:400;margin:0px;padding:0px 0px 8px;}`));
 assert.match(markup, /"typography":\{"fontSize":"12px","fontWeight":"700","letterSpacing":"0.852px"\}/);
 assert.ok(css.includes('box-shadow:rgba(0, 0, 0, 0.3) 0px 2px 6px 0px;'));
 assert.ok(css.includes(`${selector} > .wp-block-buttons{margin:24px 0px 4px}`) && css.includes('min-height:40px'));
 const stretch = `${selector} > .wp-block-buttons{align-self:stretch;margin-left:0;margin-right:0;}`;
 assert.ok(css.includes(`@media (max-width:767px){${selector}{flex-direction:column;}${selector} > p{margin:0px;}${selector}{bottom:0px;}${selector}{left:0!important;right:0!important;width:auto!important;max-width:none!important;margin:0!important;}${stretch}`));
 assert.ok(css.includes(`@media (min-width:768px){${selector}{flex-direction:column;}${selector} > p{margin:0px;}${selector}{bottom:24px;}${selector}{border-radius:7px;}${selector}{right:24px!important;left:auto!important;margin:0!important;width:400px!important;max-width:none!important;}${stretch}`));
});
test('a centered modal card rests mid-viewport at its content width, not as an edge bar', () => {
 const css = execFileSync('php', ['tests/fixtures/consent-banner/markup.php', 'modal'], { encoding: 'utf8' });
 const selector = '.wp-block-jetpack-cookie-consent.ssi-consent-banner.ssi-consent-banner:not([role="document"])';
 assert.ok(css.includes(`${selector}{top:50%;bottom:auto;transform:translateY(-50%);`), css);
 assert.doesNotMatch(css, /bottom:\d/);
 assert.ok(css.includes(`${selector}{left:0!important;right:0!important;margin:0 auto!important;width:fit-content!important;max-width:calc(100% - 20px)!important;}`), css);
 // An edge-anchored card keeps its edge and recorded width.
 const card = execFileSync('php', ['tests/fixtures/consent-banner/markup.php', 'card'], { encoding: 'utf8' });
 assert.doesNotMatch(card, /translateY|fit-content/);
});
test('presentation gap accepts one or two typed lengths in row-column order', () => {
 const run = gap => execFileSync('php', ['tests/fixtures/consent-banner/markup.php', 'gap', JSON.stringify(gap)], { encoding: 'utf8' });
 const selector = '.wp-block-jetpack-cookie-consent.ssi-consent-banner.ssi-consent-banner:not([role="document"])';
 for (const gap of ['0px', '4px 1rem', ' 0px 2px ', '0']) {
  const output = run(gap);
  assert.ok(output.endsWith(`\n${selector}{gap:${gap.trim()};}\n`));
  const markup = output.slice(0, output.indexOf(`\n${selector}`));
  assert.match(markup, /"className":"ssi-consent-banner"/);
  const result = validateMarkup(markup);
  assert.equal(result.ok, true, JSON.stringify(result.failures));
 }
 for (const gap of ['1px 2px 3px', 'normal', '-1px', '10', 'calc(1px + 2px)', '0px;}body{display:none', '', 0, null, ['0px']]) {
  const output = run(gap);
  assert.doesNotMatch(output, /gap:|ssi-consent-banner|display:none/);
 }
});
test('policy links render inline or standalone and use imported routes only for the source origin', () => {
 const sentence = 'We use cookies on our website to see how you interact with it. By accepting, you agree to our use of such cookies.';
 for (const { text, paragraph } of [
  { text: `${sentence}Privacy Policy`, paragraph: anchor => `${sentence}${anchor}` },
  { text: sentence, paragraph: anchor => `${sentence} ${anchor}` },
  { text: 'Privacy Policy & Privacy Policy', paragraph: anchor => `${anchor} &amp; Privacy Policy` },
 ]) {
  for (const { href, target } of [
   { href: 'https://www.jccollaborative.com/privacy', target: '/imported-privacy/' },
   { href: 'https://external.example/privacy', target: 'https://external.example/privacy' },
   // The capture localizes a same-site policy link to its captured route.
   { href: '/privacy.html', target: '/imported-privacy/' },
  ]) {
   const fact = { text, accept_label: 'Accept', policy_link: { label: 'Privacy Policy', href } };
   const markup = execFileSync('php', ['tests/fixtures/consent-banner/markup.php', 'policy-link', JSON.stringify(fact)], { encoding: 'utf8' });
   assert.ok(markup.includes(`<p>${paragraph(`<a href="${target}">Privacy Policy</a>`)}</p>`), markup);
   assert.equal((markup.match(/>Privacy Policy<\/a>/g) || []).length, 1);
   const result = validateMarkup(markup);
   assert.equal(result.ok, true, JSON.stringify(result.failures));
  }
 }
});
test('consent intent serializes against actual Jetpack save and core buttons', () => {
 const markup = execFileSync('php', ['tests/fixtures/consent-banner/markup.php'], { encoding: 'utf8' });
 const result = validateMarkup(markup);
 assert.equal(result.ok, true, JSON.stringify(result.failures));
 assert.equal(result.blocksChecked, 3);
 const upstream = validateMarkup(readFileSync('tests/fixtures/consent-banner/jetpack/serialized.html', 'utf8'));
 assert.equal(upstream.ok, true, JSON.stringify(upstream.failures));
});
test('consent geometry outranks Jetpack alignment rules loaded after the theme stylesheet', () => {
 // view.css is Jetpack's cookie-consent frontend stylesheet; WordPress enqueues it after style.css,
 // so an equal-specificity Jetpack rule (e.g. `.alignwide{width:100vw!important}`) would win.
 const specificity = selector => {
  let s = selector.replace(/:not\(([^)]*)\)/g, ' $1 ');
  const attrs = (s.match(/\[[^\]]*\]/g) || []).length; s = s.replace(/\[[^\]]*\]/g, ' ');
  const classes = (s.match(/[.:][\w-]+/g) || []).length; s = s.replace(/[.:][\w-]+/g, ' ');
  return [(s.match(/#[\w-]+/g) || []).length, attrs + classes, (s.match(/(^|[\s>+~])[a-z][\w-]*/gi) || []).length];
 };
 const compare = (a, b) => a[0] - b[0] || a[1] - b[1] || a[2] - b[2];
 const jetpack = readFileSync('tests/fixtures/consent-banner/jetpack/view.css.txt', 'utf8').replace(/@media[^{]*\{/g, '');
 const geometry = /(?:^|[;{])\s*(left|right|width|max-width|margin)\s*:/;
 const align = [...jetpack.matchAll(/([^{}]+)\{([^}]*)\}/g)].filter(([, , body]) => geometry.test(body)).flatMap(([, selectors]) => selectors.split(',')).filter(selector => /\.align(wide|full|left|right)/.test(selector));
 assert.ok(align.some(selector => selector.includes('.alignwide')), 'fixture carries Jetpack alignwide geometry');
 const css = execFileSync('php', ['tests/fixtures/consent-banner/markup.php', 'card'], { encoding: 'utf8' }).split('\n---\n')[1];
 const ssi = [...css.matchAll(/([^{}]+)\{[^}]*width:400px!important/g)].map(([, selector]) => selector.replace(/^@media[^{]*\{/, '').trim());
 assert.ok(ssi.length > 0, css);
 for (const ours of ssi) {
  for (const theirs of align) {
   assert.ok(compare(specificity(ours), specificity(theirs)) > 0, `${ours} ${specificity(ours)} must outrank ${theirs.trim()} ${specificity(theirs)}`);
  }
 }
});
