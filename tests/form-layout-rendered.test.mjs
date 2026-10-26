import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';

const { chromium } = ( await import( 'playwright' ).catch( () => null ) ) ?? {};

const transformer = process.env.STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH
	? `${process.env.STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH.replace(/\/$/, '')}/php-transformer.php`
	: 'vendor/automattic/blocks-engine-php-transformer/php-transformer.php';

test( 'visually hidden sub-labels stay accessible without increasing the provider field height', async () => {
	requireBrowserPrerequisites();
	const fixture = JSON.parse( execFileSync( 'php', [ 'tests/fixtures/form-hidden-help.php' ], { encoding: 'utf8' } ) );
	assert.equal( fixture.status, 'mapped' );
	assert.equal( fixture.fields.length, 2 );
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage();
		await page.setContent( `<style>${ fixture.css }</style><div class="source">${ fixture.source }</div><form class="name-form imported">${ fixture.fields.join( '' ) }</form>` );
		for ( const [ index, text ] of [ 'First', 'Last' ].entries() ) {
			const help = page.locator( '.imported .contact-form__field-help' ).nth( index );
			assert.equal( await help.textContent(), text );
			assert.equal( await page.locator( '.imported input' ).nth( index ).getAttribute( 'placeholder' ), `${ text } name` );
			assert.match( await help.ariaSnapshot(), new RegExp( text ) );
			assert.equal( await page.locator( '.imported input' ).nth( index ).evaluate( node => node.getAttribute( 'aria-describedby' ) && document.getElementById( node.getAttribute( 'aria-describedby' ) ).textContent ), text );
			const presentation = await help.evaluate( node => {
				const style = getComputedStyle( node );
				const box = node.getBoundingClientRect();
				return { width: box.width, height: box.height, clip: style.clip, display: style.display, visibility: style.visibility, hidden: Boolean( node.closest( '[aria-hidden="true"], [hidden]' ) ) };
			} );
			assert.equal( presentation.hidden, false );
			assert.notEqual( presentation.display, 'none' );
			assert.equal( presentation.visibility, 'visible' );
			assert.ok( ( presentation.width <= 1 && presentation.height <= 1 ) || presentation.clip === 'rect(0px, 0px, 0px, 0px)', JSON.stringify( presentation ) );
			const sourceHeight = await page.locator( '.source input' ).nth( index ).evaluate( node => node.parentElement.getBoundingClientRect().height );
			const importedHeight = await help.evaluate( node => node.parentElement.getBoundingClientRect().height );
			assert.ok( Math.abs( sourceHeight - importedHeight ) <= 1, `source ${ sourceHeight }, imported ${ importedHeight }` );
		}
	} finally {
		await browser.close();
	}
} );

test( 'overlay compaction preserves frontend and editor cascade across responsive conditions', async () => {
	assert.ok( chromium, 'Playwright is required' );
	const fixture = JSON.parse( execFileSync( 'php', [ 'tests/fixtures/provider-overlay-compaction-rendered.php' ], { encoding: 'utf8' } ) );
	assert.ok( fixture.after.length < fixture.before.length );
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage();
		for ( const width of [ 390, 768, 1079, 1080, 1440 ] ) {
			await page.setViewportSize( { width, height: 900 } );
			const samples = [];
			for ( const css of [ fixture.before, fixture.after ] ) {
				await page.setContent( `<style>.form{container-type:inline-size;width:600px}input,textarea{font-size:16px}${ css }</style><div class="form"><input class="first"><input class="last"><textarea class="message"></textarea></div><div class="editor-styles-wrapper"><div class="form"><input class="first"><input class="last"><textarea class="message"></textarea></div></div>` );
				samples.push( await page.locator( 'input,textarea' ).evaluateAll( nodes => nodes.map( node => {
					const style = getComputedStyle( node );
					return { color: style.color, fontSize: style.fontSize, padding: style.padding, border: style.border, height: node.getBoundingClientRect().height };
				} ) ) );
			}
			assert.deepEqual( samples[1], samples[0], `cascade and container/media applicability at ${ width }` );
		}
	} finally {
		await browser.close();
	}
} );
test( 'provider form target lands on exactly one box when Jetpack nests the scoped block inside its form', async () => {
	assert.ok( chromium, 'Playwright is required' );
	const selector = execFileSync( 'php', [ 'tests/fixtures/provider-form-target-selector.php' ], { encoding: 'utf8' } ).trim();
	assert.ok( selector.length > 0 );
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage();
		const scope = 'ssi-form-123456789abc';
		const markup = {
			// Jetpack 16.3 writes the block class on the container and on the block
			// wrapper inside its plain block form; that wrapper is the flex container.
			nested: `<div class="jetpack-contact-form-container ${ scope }"><form class="jetpack-contact-form__form"><div class="wp-block-jetpack-contact-form ${ scope }">fields</div></form></div>`,
			// Older output renders the form inside the scoped block wrapper; the form holds the fields.
			wrapped: `<div class="wp-block-jetpack-contact-form ${ scope }"><form class="jetpack-contact-form__form">fields</form></div>`,
			// A later form on the page is laid out directly in the block wrapper.
			formless: `<div class="wp-block-jetpack-contact-form ${ scope }">fields</div>`,
		};
		const padded = {};
		for ( const [ name, html ] of Object.entries( markup ) ) {
			await page.setContent( `<style>${ selector }{padding:32px}</style>${ html }` );
			padded[ name ] = await page.locator( 'div,form' ).evaluateAll( ( nodes ) => nodes.filter( ( node ) => '32px' === getComputedStyle( node ).paddingTop ).map( ( node ) => `${ node.tagName.toLowerCase() }.${ node.classList[0] }` ) );
		}
		assert.deepEqual( padded, {
			nested: [ 'div.wp-block-jetpack-contact-form' ],
			wrapped: [ 'form.jetpack-contact-form__form' ],
			formless: [ 'div.wp-block-jetpack-contact-form' ],
		}, selector );
	} finally {
		await browser.close();
	}
} );
function requireBrowserPrerequisites() {
	assert.ok( chromium, 'Playwright is required; browser acceptance must not silently skip' );
	assert.ok( existsSync( chromium.executablePath() ), `Playwright Chromium is missing at ${chromium.executablePath()}` );
	assert.ok( existsSync( transformer ), `Blocks Engine transformer is missing at ${transformer}` );
}

test( 'v3 source boxes keep wrapper, control and context ownership against the source across viewports', async () => {
	requireBrowserPrerequisites();
	const raw = execFileSync( 'php', [ 'tests/fixtures/form-source-boxes-v3-rendered.php' ], {
		cwd: process.cwd(),
		encoding: 'utf8',
		env: process.env,
	} );
	const seeded = JSON.parse( raw );
	assert.equal( seeded.status, 'mapped', raw );
	const late = Array.from( { length: 20 }, ( _, index ) => `hook-${ index + 1 }` ).join( ' ' );
	// The neutral source the producer fixture was compiled from.
	const sourceMarkup = `<main class="page"><form class="source"><div class="intro-box"><p class="${ late } wide-copy">A neutral introduction.</p></div><div class="field-box"><label>Email</label><input type="email"></div><div class="submit-box"><button class="send" type="submit">Send</button></div><div class="note-box"><p class="note">Please review your details.</p></div></form></main>`;
	// Provider and theme defaults the projected form must not inherit in place of source facts.
	const providerDefaults = '<style>body{margin:0;font-family:serif;color:#000}.wp-block-paragraph{font-size:22px;line-height:normal;margin:0}.wp-block-group{padding:0}.wp-block-button{min-height:0;padding:0}.wp-block-button__link{min-height:0}</style>';
	const projectedMarkup = `<div class="projected"><div class="wp-block-jetpack-contact-form ${ seeded.className }">${ seeded.beforeHtml }<div class="wp-block-jetpack-field-email"><label>Email</label><input type="email"></div>${ seeded.submitHtml }${ seeded.afterHtml }</div></div>`;
	const editorMarkup = `<div class="editor-styles-wrapper"><div class="wp-block-jetpack-contact-form ${ seeded.className }">${ seeded.beforeHtml }${ seeded.afterHtml }</div></div>`;
	const browser = await chromium.launch();
	try {
		for ( const width of [ 390, 768, 1440, 1600 ] ) {
			const page = await browser.newPage( { viewport: { width, height: 900 } } );
			await page.setContent( `${ providerDefaults }<style>${ seeded.sourceCss }</style><style>${ seeded.css }${ seeded.contextCss }${ seeded.editorContextCss }</style>${ sourceMarkup }${ projectedMarkup }${ editorMarkup }` );
			const measure = ( root ) => page.evaluate( ( scope ) => {
				const pick = ( selector ) => {
					const node = document.querySelector( `${ scope } ${ selector }` );
					const style = getComputedStyle( node );
					return { minHeight: style.minHeight, paddingTop: style.paddingTop, paddingRight: style.paddingRight, paddingBottom: style.paddingBottom, paddingLeft: style.paddingLeft, fontSize: style.fontSize, fontFamily: style.fontFamily, color: style.color, letterSpacing: style.letterSpacing, lineHeight: style.lineHeight, height: node.getBoundingClientRect().height };
				};
				return { intro: pick( '.wide-copy' ), introBox: pick( '.intro-box' ), note: pick( '.note' ), noteBox: pick( '.note-box' ), button: pick( 'button[type=submit]' ) };
			}, root );
			const wrapper = ( root ) => page.evaluate( ( scope ) => {
				const style = getComputedStyle( document.querySelector( `${ scope } button[type=submit]` ).parentElement );
				return { minHeight: style.minHeight, paddingBottom: style.paddingBottom };
			}, root );
			const source = await measure( '.source' );
			const projected = await measure( '.projected' );
			const editor = await page.evaluate( () => {
				const style = getComputedStyle( document.querySelector( '.editor-styles-wrapper .wide-copy' ) );
				return { fontSize: style.fontSize, letterSpacing: style.letterSpacing, fontFamily: style.fontFamily };
			} );
			const wide = width >= 1200;
			assert.deepEqual( await wrapper( '.source' ), { minHeight: '73px', paddingBottom: '19px' }, `source submit wrapper at ${ width }` );
			assert.deepEqual( await wrapper( '.projected' ), { minHeight: '73px', paddingBottom: '19px' }, `projected submit wrapper keeps its own box at ${ width }` );
			assert.equal( source.button.minHeight, '41px', `source button at ${ width }` );
			assert.equal( projected.button.minHeight, '41px', `projected button keeps only its own min-height at ${ width }: ${ JSON.stringify( projected.button ) }` );
			for ( const key of [ 'fontSize', 'fontFamily', 'color', 'letterSpacing', 'lineHeight' ] ) {
				assert.equal( projected.intro[ key ], source.intro[ key ], `late-class responsive intro ${ key } at ${ width }: ${ JSON.stringify( { source: source.intro, projected: projected.intro } ) }` );
			}
			assert.equal( projected.intro.fontSize, wide ? '27px' : '21px', `intro custom-property size at ${ width }` );
			assert.equal( projected.intro.letterSpacing, wide ? '2px' : 'normal', `intro property-only patch at ${ width }` );
			for ( const key of [ 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft' ] ) {
				assert.equal( projected.introBox[ key ], source.introBox[ key ], `intro wrapper ${ key } at ${ width }` );
				assert.equal( projected.noteBox[ key ], source.noteBox[ key ], `disclaimer wrapper ${ key } at ${ width }: ${ JSON.stringify( { source: source.noteBox, projected: projected.noteBox } ) }` );
			}
			assert.equal( projected.noteBox.paddingBottom, wide ? '17px' : '13px', `disclaimer wrapper responsive padding at ${ width }` );
			for ( const key of [ 'fontSize', 'lineHeight', 'fontFamily', 'color' ] ) {
				assert.equal( projected.note[ key ], source.note[ key ], `disclaimer ${ key } at ${ width }` );
			}
			assert.ok( Math.abs( projected.noteBox.height - source.noteBox.height ) <= 1 && Math.abs( projected.introBox.height - source.introBox.height ) <= 1, `context box heights at ${ width }: ${ JSON.stringify( { source, projected } ) }` );
			assert.deepEqual( editor, { fontSize: source.intro.fontSize, letterSpacing: source.intro.letterSpacing, fontFamily: source.intro.fontFamily }, `editor context presentation at ${ width }` );
			await page.close();
		}
	} finally {
		await browser.close();
	}
} );

test( 'long alternating form maps without gate losses and preserves every field before/after compaction', async () => {
	requireBrowserPrerequisites();
	const load = ( ...args ) => JSON.parse( execFileSync( 'php', [ 'tests/fixtures/long-form-overlay.php', ...args ], { encoding: 'utf8' } ) );
	const compacted = load();
	assert.equal( compacted.status, 'mapped', JSON.stringify( compacted.row.mapping_decision ) );
	const original = load( '--uncompacted' );
	assert.equal( compacted.fields.length, 30 );
	assert.equal( compacted.overlayValid, true, 'both surfaces pass persisted overlay validation' );
	assert.equal( compacted.row.mapping_decision.status, 'mapped' );
	assert.deepEqual( compacted.row.mapping_decision.losses, [], 'receipt quality gate has no unaccepted form losses' );
	assert.notEqual( compacted.row.reason, 'form_receipt_loss_unaccepted' );
	assert.ok( compacted.css.length < original.css.length, 'nonadjacent body compaction reduces real form CSS' );
	assert.equal( compacted.html, original.html );
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage();
		for ( const width of [ 1440, 390 ] ) {
			await page.setViewportSize( { width, height: 900 } );
			for ( const surface of [ 'css', 'editorCss' ] ) {
				const samples = [];
				for ( const fixture of [ original, compacted ] ) {
					await page.setContent( `<style>body{margin:0}input,select,textarea{font-size:16px}${ fixture[ surface ] }</style><div class="${ surface === 'editorCss' ? 'editor-styles-wrapper' : 'frontend' }">${ fixture.html }</div>` );
					// Each field box is a child of the readable form group.
					samples.push( await page.locator( '.wp-block-group > :has(label)' ).evaluateAll( nodes => nodes.map( node => {
						const style = getComputedStyle( node );
						const rect = node.getBoundingClientRect();
						return { top: rect.top, left: rect.left, width: rect.width, height: rect.height, display: style.display, gap: style.gap, padding: style.padding, margin: style.margin, border: style.border, fontFamily: style.fontFamily, fontSize: style.fontSize, fontWeight: style.fontWeight, lineHeight: style.lineHeight, letterSpacing: style.letterSpacing, color: style.color };
					} ) ) );
				}
				assert.equal( samples[0].length, 30 );
				assert.deepEqual( samples[1], samples[0], `${ surface } per-field layout and typography at ${ width }` );
			}
		}
	} finally {
		await browser.close();
	}
} );

test( 'mapped full-width submit keeps its winning background and fills the form row (#2223)', async () => {
	const fixture = JSON.parse( execFileSync( 'php', [ 'tests/fixtures/form-submit-cascade.php' ], { encoding: 'utf8' } ) );
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage();
		for ( const width of [ 1440, 390 ] ) {
			await page.setViewportSize( { width, height: 900 } );
			await page.setContent( `<style>form,.imported{width:100%;max-width:580px;box-sizing:border-box}.imported{display:flex;flex-wrap:wrap}.wp-block-button{display:inline-block}.wp-block-button__link{display:inline-block;box-sizing:border-box;width:100%}${fixture.sourceCss}${fixture.css}</style>${fixture.sourceHtml}<div class="imported ${fixture.className}">${fixture.submit}</div>` );
			const measured = await page.locator( '.imported button' ).evaluate( button => ( {
				background: getComputedStyle( button ).backgroundColor,
				width: button.getBoundingClientRect().width,
				formWidth: button.closest( '.imported' ).getBoundingClientRect().width,
				sourceWidth: document.querySelector( '.ctx button' ).getBoundingClientRect().width,
			} ) );
			assert.equal( measured.background, 'rgb(23, 23, 23)', `background at ${width}` );
			assert.equal( measured.width, measured.formWidth, `full form width at ${width}` );
			assert.equal( measured.width, measured.sourceWidth, `source width at ${width}` );
		}
	} finally {
		await browser.close();
	}
} );

