import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';

const { chromium } = ( await import( 'playwright' ).catch( () => null ) ) ?? {};

const transformer = process.env.STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH
	? `${process.env.STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH.replace(/\/$/, '')}/php-transformer.php`
	: 'vendor/automattic/blocks-engine-php-transformer/php-transformer.php';

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

test( 'simulated Jetpack field markup preserves source field pitch and bounded submit layout across viewports', async () => {
	requireBrowserPrerequisites();
	const raw = execFileSync( 'php', [ 'tests/fixtures/form-layout-rendered-fixture.php' ], {
		cwd: process.cwd(),
		encoding: 'utf8',
		env: process.env,
	} );
	const seeded = JSON.parse( raw );
	assert.equal( seeded.status, 'mapped', raw );
	assert.equal( seeded.fields.length, 3 );
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage( { viewport: { width: 1440, height: 900 } } );
		const fields = seeded.fields.map( ( field ) => `<div class="wp-block-jetpack-field-text grunion-field-text-wrap ${field.classes}" data-field="${field.label}"><label class="${field.labelClasses}">${field.label}</label><input></div>` ).join( '' );
		await page.setContent( `<!doctype html><style>
			body{margin:0}
			.page{width:min(883px,calc(100vw - 24px));margin:12px}
			.stack{display:flex;flex-direction:column;gap:24px;width:100%;background:#123456;padding:13px 17px}
			.jetpack-contact-form__form{display:flex;flex-direction:row;flex-wrap:wrap;gap:1.5rem}
			:where(.has-no-jetpack-form-layout) .jetpack-contact-form__form>:not(.wp-block-button){box-sizing:border-box;flex:0 0 100%}
			.wp-block-button{display:block;width:100%}
			.wp-block-jetpack-field-text label{display:block}
			.source-oracle{visibility:hidden}
			${seeded.css}
		</style><div class="page"><div class="source-oracle">${seeded.sourceHtml}</div><div class="wp-block-jetpack-contact-form ${seeded.className}"><form class="jetpack-contact-form__form has-no-jetpack-form-layout">${fields}${seeded.runtimeHtml.replace( '<div class="wp-block-button', '<div data-field="Send" class="wp-block-button' )}</form></div></div>` );
		for ( const width of [ 390, 768, 1440, 1600 ] ) {
			await page.setViewportSize( { width, height: 900 } );
			const boxes = await page.locator( '[data-field]:has(input)' ).evaluateAll( ( nodes ) => nodes.map( ( node ) => {
				const rect = node.getBoundingClientRect();
				const label = node.querySelector( 'label' ).getBoundingClientRect();
				const input = node.querySelector( 'input' ).getBoundingClientRect();
				return { label: node.getAttribute( 'data-field' ), top: rect.top, left: rect.left, width: rect.width, parent: node.parentElement.getBoundingClientRect().width, pitch: input.top - label.bottom };
			} ) );
			const first = boxes.find( ( box ) => box.label === 'First name' );
			const last = boxes.find( ( box ) => box.label === 'Last name' );
			const send = await page.locator( '[data-field="Send"]' ).evaluate( ( node ) => {
				const rect = node.getBoundingClientRect();
				const parent = node.parentElement.getBoundingClientRect();
				return { width: rect.width, left: rect.left, right: rect.right, parentWidth: parent.width, parentRight: parent.right, parentPaddingRight: parseFloat( getComputedStyle( node.parentElement ).paddingRight ) };
			} );
			assert.ok( first && last, JSON.stringify( boxes ) );
			assert.equal( first.top, last.top, `viewport ${width}: ${JSON.stringify( boxes )}` );
			assert.notEqual( first.left, last.left, `viewport ${width}: ${JSON.stringify( boxes )}` );
			assert.ok( Math.abs( last.left - first.left - first.width - 24 ) <= 1, `field grid/flex column gap at ${width}: ${JSON.stringify( boxes )}` );
			assert.ok( first.pitch >= 8, `source label margin-bottom at ${width}: ${JSON.stringify( boxes )}` );
			assert.ok( send.width <= send.parentWidth * 0.25 + 24, `bounded submit at ${width}: ${JSON.stringify( send )}` );
			// The source parent owns grid-column:1 / span 3. Its nested
			// button's margin-left:auto cannot move that parent to the last column.
			const sourceSubmit = await page.locator( '.source-oracle [style*="grid-column:1 / span 3"]' ).evaluate( node => {
				const box = node.getBoundingClientRect();
				return { width:box.width, left:box.left, right:box.right };
			} );
			assert.equal( send.left, sourceSubmit.left, `source-owned first-column submit parent placement at ${width}` );
			const container = await page.locator( '.wp-block-jetpack-contact-form' ).evaluate( ( node ) => {
				const style = getComputedStyle( node );
				return { background: style.backgroundColor, paddingTop: style.paddingTop, paddingRight: style.paddingRight, paddingBottom: style.paddingBottom, paddingLeft: style.paddingLeft, gap: style.gap, display: style.display, flexDirection: style.flexDirection };
			} );
			assert.equal( container.background, 'rgb(18, 52, 86)', JSON.stringify( container ) );
			assert.equal( container.paddingTop, '13px', JSON.stringify( container ) );
			assert.equal( container.paddingRight, '17px', JSON.stringify( container ) );
			assert.equal( container.paddingBottom, '13px', JSON.stringify( container ) );
			assert.equal( container.paddingLeft, '17px', JSON.stringify( container ) );
			assert.equal( container.display, 'flex', JSON.stringify( container ) );
			assert.equal( container.flexDirection, 'column', JSON.stringify( container ) );
			assert.equal( container.gap, '24px', JSON.stringify( container ) );
		}
	} finally {
		await browser.close();
	}
} );

test( 'source submit minimum height is not replaced by a provider reset', async () => {
	requireBrowserPrerequisites();
	const raw = execFileSync( 'php', [ 'tests/fixtures/form-layout-rendered-fixture.php' ], {
		cwd: process.cwd(),
		encoding: 'utf8',
		env: process.env,
	} );
	const seeded = JSON.parse( raw );
	assert.match( seeded.css, /min-height:56px/ );
	assert.doesNotMatch( seeded.css, /min-height:0/ );
	assert.ok( seeded.producerContext.context_before[ 0 ].class.includes( 'responsive-intro' ), JSON.stringify( seeded.producerContext ) );
	assert.ok( seeded.producerContext.context_after[ 0 ].class.includes( 'responsive-disclaimer' ), JSON.stringify( seeded.producerContext ) );
	assert.equal( seeded.submitParentSourceMinHeight, '64px' );
	assert.equal( seeded.submitParentProducerLayout.id, 'wrapper-4' );
	// Layout graph v3 (blocks-engine#2356) owns the wrapper's own box; v2 omitted it.
	const producerOwnsWrapperBox = 'generic/computed-layout-graph/v3' === seeded.producerLayoutGraph.schema;
	assert.equal( seeded.submitParentProducerLayout.layout.min_height, producerOwnsWrapperBox ? '64px' : undefined, 'producer wrapper min-height ownership follows its graph version' );
	assert.equal( seeded.normalizedContext.context_before[ 0 ].class, seeded.producerContext.context_before[ 0 ].class );
	assert.equal( seeded.normalizedContext.context_after[ 0 ].class, seeded.producerContext.context_after[ 0 ].class );
	assert.equal( seeded.normalizedContext.unrepresented_context_count, seeded.producerContext.unrepresented_context.length );
	assert.ok( seeded.normalizedContext.unrepresented_context_count >= 1, JSON.stringify( seeded.producerContext ) );
	for ( const item of [ ...seeded.normalizedContext.context_before, ...seeded.normalizedContext.context_after ] ) {
		assert.equal( item.styles.font_family, 'Georgia', JSON.stringify( item ) );
		assert.equal( item.styles.line_height, '1.75', JSON.stringify( item ) );
	}
	assert.match( seeded.contextHtml, /wp:core\/heading/ );
	assert.match( seeded.contextHtml, /wp:core\/paragraph/ );
	const browser = await chromium.launch();
	try {
		for ( const width of [ 390, 768, 1440, 1600 ] ) {
			const page = await browser.newPage( { viewport: { width, height: 900 } } );
			const sourceContext = '<h2 class="intro-note first second third fourth fifth sixth seventh eighth responsive-intro ninth tenth">Contact us</h2><p class="disclaimer-note first second third fourth fifth sixth seventh eighth responsive-disclaimer ninth tenth">We will reply soon.</p>';
			const providerDefaults = '<style>.wp-block-heading,.wp-block-paragraph{font-family:serif;font-size:22px;line-height:normal}.wp-block-button{min-height:0}.wp-block-button__link{min-height:0}.source-wrapper{min-height:64px}@media(min-width:1536px){.source-wrapper{min-height:72px}}</style>';
			assert.ok( seeded.materializedStyleCss.indexOf( seeded.contextCss ) < seeded.materializedStyleCss.indexOf( seeded.sourceCss ), 'frontend context fallback must precede authored CSS' );
			assert.ok( seeded.materializedEditorCss.indexOf( seeded.editorContextCss ) < seeded.materializedEditorCss.indexOf( seeded.sourceCss ), 'editor context fallback must precede authored CSS' );
			const sourceMarkup = `<form class="source stack"><div class="wp-block-button source-wrapper"><button type="submit" class="send-button">Send</button></div>${sourceContext}</form>`;
			const frontendMarkup = `<div class="projected"><div class="${seeded.className}">${seeded.runtimeHtml}${seeded.contextHtml}</div></div>`;
			const editorMarkup = `<div class="editor-styles-wrapper"><div class="projected"><div class="${seeded.className}">${seeded.contextHtml}</div></div></div>`;
			await page.setContent( `${providerDefaults}<style>${seeded.materializedStyleCss}</style>${sourceMarkup}${frontendMarkup}${editorMarkup}<div><button type="submit" class="wp-block-button__link">Unowned</button></div><style>${seeded.materializedEditorCss}</style>` );
			const measured = await page.locator( 'button' ).evaluateAll( ( nodes ) => nodes.map( ( node ) => ( {
				minHeight: getComputedStyle( node ).minHeight,
				height: node.getBoundingClientRect().height,
				type: node.type,
			} ) ) );
			const expectedMinHeight = width >= 1536 ? '68px' : '56px';
			assert.equal( measured[ 0 ].minHeight, expectedMinHeight, `source viewport ${width}: ${JSON.stringify( measured )}` );
			assert.equal( measured[ 1 ].minHeight, expectedMinHeight, `projected viewport ${width}: ${JSON.stringify( measured )}` );
			assert.ok( measured[ 1 ].height >= measured[ 0 ].height, `projected viewport ${width}: ${JSON.stringify( measured )}` );
			assert.equal( measured[ 2 ].minHeight, '0px', `unowned provider default at ${width}: ${JSON.stringify( measured )}` );
			assert.deepEqual( measured.map( ( item ) => item.type ), [ 'submit', 'submit', 'submit' ] );
			const wrappers = await page.locator( '.wp-block-button' ).evaluateAll( ( nodes ) => nodes.map( ( node ) => getComputedStyle( node ).minHeight ) );
			const sourceWrapperHeight = width >= 1536 ? '72px' : '64px';
			assert.deepEqual( wrappers, [ sourceWrapperHeight, producerOwnsWrapperBox ? '64px' : '0px' ], `projected wrapper min-height follows producer ownership at ${width}: ${JSON.stringify( wrappers )}` );
			const contextBoxes = await page.locator( '.intro-note,.disclaimer-note' ).evaluateAll( ( nodes ) => nodes.filter( ( node ) => ! node.closest( '.editor-styles-wrapper' ) ).map( ( node ) => {
				const rect = node.getBoundingClientRect();
				const style = getComputedStyle( node );
				return { top: rect.top, height: rect.height, fontFamily: style.fontFamily, fontSize: style.fontSize, lineHeight: style.lineHeight, marginBottom: style.marginBottom, paddingTop: style.paddingTop, paddingBottom: style.paddingBottom };
			} ) );
			assert.equal( contextBoxes.length, 4, `producer/consumer context at ${width}: ${JSON.stringify( contextBoxes )}` );
			assert.ok( Math.abs( contextBoxes[ 0 ].height - contextBoxes[ 2 ].height ) <= 6, `intro height at ${width}: ${JSON.stringify( contextBoxes )}` );
			assert.ok( Math.abs( contextBoxes[ 1 ].height - contextBoxes[ 3 ].height ) <= 6, `disclaimer height at ${width}: ${JSON.stringify( contextBoxes )}` );
			assert.equal( contextBoxes[ 0 ].fontSize, width >= 1536 ? '24px' : '16px', `intro responsive font at ${width}: ${JSON.stringify( contextBoxes )}` );
			assert.equal( contextBoxes[ 2 ].fontSize, contextBoxes[ 0 ].fontSize, `projected intro responsive font at ${width}: ${JSON.stringify( contextBoxes )}` );
			assert.equal( contextBoxes[ 3 ].marginBottom, '24px', `disclaimer margin at ${width}: ${JSON.stringify( contextBoxes )}` );
			assert.equal( contextBoxes[ 3 ].paddingBottom, '16px', `disclaimer padding at ${width}: ${JSON.stringify( contextBoxes )}` );
			assert.equal( contextBoxes[ 2 ].fontFamily, 'Georgia', `ancestor family fallback at ${width}: ${JSON.stringify( contextBoxes )}` );
			assert.equal( contextBoxes[ 2 ].lineHeight, width >= 1536 ? '42px' : '28px', `ancestor line-height fallback remains while responsive author changes size at ${width}: ${JSON.stringify( contextBoxes )}` );
			const editorIntro = await page.locator( '.editor-styles-wrapper .intro-note' ).evaluate( ( node ) => {
				const style = getComputedStyle( node );
				return { fontFamily: style.fontFamily, fontSize: style.fontSize, lineHeight: style.lineHeight };
			} );
			assert.equal( editorIntro.fontFamily, 'Georgia', `editor ancestor fallback at ${width}: ${JSON.stringify( editorIntro )}` );
			assert.equal( editorIntro.fontSize, width >= 1536 ? '24px' : '16px', `editor author font-size at ${width}: ${JSON.stringify( editorIntro )}` );
			assert.equal( editorIntro.lineHeight, width >= 1536 ? '42px' : '28px', `editor ancestor line-height fallback remains at ${width}: ${JSON.stringify( editorIntro )}` );
			await page.close();
		}
	} finally {
		await browser.close();
	}
} );

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

test( 'editor canvas lays provider fields out on the layered source form grid, not Jetpack inner-blocks flex', async () => {
	requireBrowserPrerequisites();
	const raw = execFileSync( 'php', [ 'tests/fixtures/editor-form-grid-fixture.php' ], { encoding: 'utf8', env: process.env } );
	const seeded = JSON.parse( raw );
	assert.equal( seeded.status, 'mapped', raw );
	assert.equal( seeded.fields.length, 3, raw );
	assert.ok( seeded.headingClassName.includes( 'sm-span-2' ), raw );
	// Jetpack's editor DOM: the block wrapper carries the source form classes and
	// className; an inner-blocks flex container parents the field blocks, which
	// carry their className on the block wrapper itself (no `-wrap` copy).
	const fields = seeded.fields.map( ( field, index ) => `<div class="wp-block jetpack-field wp-block-jetpack-field-${ field.type } ${ field.className }" data-field="${ index }"><label>Field ${ index }</label>${ 'textarea' === field.type ? '<textarea></textarea>' : '<input>' }</div>` ).join( '' );
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage( { viewport: { width: 1440, height: 900 } } );
		await page.setContent( `<!doctype html><style>${ seeded.sourceCss }</style><style>
			body{margin:0}
			.editor-styles-wrapper{width:min(700px,100vw)}
			.jetpack-contact-form.is-layout-flex{display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start}
			.jetpack-contact-form>.wp-block{flex:1 1 100%;box-sizing:border-box;margin:0}
			input,textarea{display:block;width:100%;box-sizing:border-box}
		</style><style>${ seeded.editorCss }</style><div class="editor-styles-wrapper"><div class="wp-block wp-block-jetpack-contact-form ${ seeded.className }"><div class="jetpack-contact-form block-editor-block-list__layout is-layout-flex"><h3 class="wp-block ${ seeded.headingClassName }">Request</h3>${ fields }</div></div></div>` );
		for ( const width of [ 390, 1440 ] ) {
			await page.setViewportSize( { width, height: 900 } );
			const layout = await page.evaluate( () => {
				const form = document.querySelector( '.wp-block-jetpack-contact-form' );
				const style = getComputedStyle( form );
				const content = form.getBoundingClientRect().width - parseFloat( style.paddingLeft ) - parseFloat( style.paddingRight ) - parseFloat( style.borderLeftWidth ) - parseFloat( style.borderRightWidth );
				return { content, fields: [ ...document.querySelectorAll( '[data-field]' ) ].map( ( node ) => {
					const rect = node.getBoundingClientRect();
					return { top: rect.top, left: rect.left, width: rect.width };
				} ) };
			} );
			const [ name, phone, message ] = layout.fields;
			if ( width >= 640 ) {
				assert.equal( name.top, phone.top, `two-column source grid at ${ width }: ${ JSON.stringify( layout ) }` );
				assert.ok( Math.abs( phone.left - name.left - name.width - 20 ) <= 1, `source column gap at ${ width }: ${ JSON.stringify( layout ) }` );
				assert.ok( Math.abs( message.width - layout.content ) <= 1, `source span-2 field fills the form at ${ width }: ${ JSON.stringify( layout ) }` );
			} else {
				assert.equal( name.left, phone.left, `single-column source grid at ${ width }: ${ JSON.stringify( layout ) }` );
				assert.ok( Math.abs( phone.top - name.top - ( message.top - phone.top ) ) <= 1, `uniform source row gap at ${ width }: ${ JSON.stringify( layout ) }` );
			}
		}
	} finally {
		await browser.close();
	}
} );

test( 'editor canvas phone, dropdown and rows-sized textarea controls take the front-end control box', async () => {
	requireBrowserPrerequisites();
	const raw = execFileSync( 'php', [ 'tests/fixtures/editor-form-controls-fixture.php' ], { encoding: 'utf8', env: process.env } );
	const seeded = JSON.parse( raw );
	assert.equal( seeded.status, 'mapped', raw );
	assert.deepEqual( seeded.fields.map( ( field ) => field.type ), [ 'telephone', 'select', 'textarea' ], raw );
	// Jetpack's own front-end (grunion.css) and editor (blocks/editor.css) rules
	// that compete with the projection for these control boxes.
	const providerFrontendCss = `
		:where(.contact-form input[type=tel],.contact-form textarea){border:1px solid #8c8f94;border-radius:0;box-sizing:border-box;padding:16px;font:inherit;width:100%;background:#fff}
		:where(.contact-form textarea){display:block;height:200px}
		.contact-form .grunion-field-wrap input,.contact-form .grunion-field-wrap textarea{margin:0}
		.contact-form .contact-form__select-wrapper{padding:0;position:relative}
		.contact-form :where(.contact-form__select-wrapper){background-color:#fff;border:1px solid #8c8f94}
		.contact-form .contact-form__select-element-wrapper{display:flex}
		.contact-form :where(.contact-form__select-element-wrapper),.contact-form :where(.contact-form__select-wrapper select){background:inherit!important;border:0!important;border-radius:inherit!important;border-width:0!important}
		.contact-form .contact-form__select-wrapper select{appearance:none;box-sizing:border-box;padding:16px;width:100%}`;
	const providerEditorCss = `
		.jetpack-field.jetpack-field-telephone .jetpack-field__input{background-color:#fff;border-color:#8c8f94;border-width:1px;box-sizing:border-box;display:flex;gap:8px}
		.jetpack-field.jetpack-field-telephone .jetpack-field__input .jetpack-field__input-element:not(.jetpack-field__input-prefix .jetpack-field__input-element){background-color:#0000;border:0;box-sizing:border-box;color:inherit;flex:1;font:inherit;line-height:inherit;min-height:unset;outline:none;padding:0;resize:none}
		.jetpack-field .jetpack-field__textarea{display:block;min-height:200px}
		.jetpack-field .jetpack-field__input,.jetpack-field .jetpack-field__textarea{box-shadow:unset;box-sizing:border-box;margin:0;padding:16px}
		:where(.jetpack-field .jetpack-field__input,.jetpack-field .jetpack-field__textarea,.jetpack-field .jetpack-field-dropdown__toggle){border:1px solid #8c8f94;border-radius:0;font:inherit}
		:where(.jetpack-field .jetpack-field-dropdown__toggle){background-color:#fff}
		.jetpack-field-dropdown__toggle{align-items:center;border-color:#8c8f94;display:flex;font-size:16px;justify-content:space-between;padding:16px}
		.jetpack-field-dropdown__toggle .rich-text{flex-grow:1}
		.jetpack-field-dropdown__icon{align-items:center;border:2px solid #0000;box-sizing:border-box;display:flex;height:.8em;width:.8em}`;
	// Jetpack's editor DOM: each input block's className lands on the control
	// element itself (the phone wrapper, the dropdown toggle, the textarea).
	const editorControl = ( field ) => {
		if ( 'telephone' === field.type ) {
			return `<div class="wp-block jetpack-field__input jetpack-field__input-phone ${ field.inputClassName }" data-control="telephone"><input type="text" class="jetpack-field__input-element"></div>`;
		}
		if ( 'select' === field.type ) {
			return `<div class="wp-block jetpack-field-dropdown__toggle ${ field.inputClassName }" data-control="select"><div class="rich-text">One</div><span class="jetpack-field-dropdown__icon"></span></div>`;
		}
		// Jetpack's textarea field renders its input block inside a block-flow
		// inner-blocks layout after the label block.
		return `<div class="jetpack-field__control block-editor-block-list__layout"><textarea class="wp-block jetpack-field__textarea ${ field.inputClassName }" data-control="textarea"></textarea></div>`;
	};
	const editorFields = seeded.fields.map( ( field ) => `<div class="wp-block jetpack-field wp-block-jetpack-field-${ field.type } jetpack-field-${ field.type } ${ field.className }" data-field="${ field.type }"><div class="wp-block wp-block-jetpack-label ${ field.labelClassName }"><label>${ field.type }</label></div>${ editorControl( field ) }</div>` ).join( '' );
	const frontendFields = seeded.fields.map( ( field ) => field.frontendHtml ).join( '' );
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage( { viewport: { width: 1024, height: 1200 } } );
		await page.setContent( `<!doctype html><style>${ seeded.sourceCss }</style><style>
			body{margin:0;font-family:sans-serif;font-size:16px}
			.frontend,.editor-styles-wrapper{width:600px}
			${ providerFrontendCss }${ providerEditorCss }
		</style><style>${ seeded.css }${ seeded.editorCss }</style>
		<div class="frontend"><div class="wp-block-jetpack-contact-form ${ seeded.className }"><form class="jetpack-contact-form__form contact-form">${ frontendFields }</form></div></div>
		<div class="editor-styles-wrapper"><div class="wp-block wp-block-jetpack-contact-form ${ seeded.className }"><div class="jetpack-contact-form block-editor-block-list__layout is-layout-flex">${ editorFields }</div></div></div>` );
		const box = ( selector ) => page.locator( selector ).evaluate( ( node ) => {
			const style = getComputedStyle( node );
			return {
				height: node.getBoundingClientRect().height,
				borderTopWidth: parseFloat( style.borderTopWidth ),
				borderBottomWidth: parseFloat( style.borderBottomWidth ),
				paddingTop: parseFloat( style.paddingTop ),
				paddingRight: parseFloat( style.paddingRight ),
				paddingBottom: parseFloat( style.paddingBottom ),
				paddingLeft: parseFloat( style.paddingLeft ),
				background: style.backgroundColor,
			};
		} );
		const pairs = {
			telephone: [ '.frontend input[type=tel]', '.editor-styles-wrapper [data-control="telephone"]' ],
			select: [ '.frontend select', '.editor-styles-wrapper [data-control="select"]' ],
			textarea: [ '.frontend textarea', '.editor-styles-wrapper [data-control="textarea"]' ],
		};
		for ( const [ type, [ frontendSelector, editorSelector ] ] of Object.entries( pairs ) ) {
			const frontend = await box( frontendSelector );
			const editor = await box( editorSelector );
			const detail = `${ type }: ${ JSON.stringify( { frontend, editor } ) }`;
			for ( const key of [ 'height', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft' ] ) {
				assert.ok( Math.abs( frontend[ key ] - editor[ key ] ) <= 1, `${ key } ${ detail }` );
			}
			assert.equal( editor.borderTopWidth, frontend.borderTopWidth, `border-top-width ${ detail }` );
			assert.equal( editor.borderBottomWidth, frontend.borderBottomWidth, `border-bottom-width ${ detail }` );
			assert.equal( editor.background, frontend.background, `background ${ detail }` );
		}
		// The source sizes the control boxes: a 2.75rem phone field and select,
		// and a four-row textarea with 12px block padding and a 1px border.
		assert.ok( Math.abs( ( await box( pairs.telephone[ 0 ] ) ).height - 44 ) <= 1 );
		assert.ok( Math.abs( ( await box( pairs.textarea[ 0 ] ) ).height - ( 4 * 24 + 24 + 2 ) ) <= 1 );
		// The source textarea is a grid item of its `grid gap-2` field row, so its
		// outer display is blockified and the row carries no baseline descent. The
		// editor field block, where the textarea sits in block flow, must match the
		// front-end field shell.
		const frontendRow = await box( '.frontend .grunion-field-textarea-wrap' );
		const editorRow = await box( '.editor-styles-wrapper [data-field="textarea"]' );
		assert.ok( Math.abs( frontendRow.height - editorRow.height ) <= 1, `textarea field row ${ JSON.stringify( { frontendRow, editorRow } ) }` );
	} finally {
		await browser.close();
	}
} );

test( 'flattened equal-width row keeps the source stacking rhythm between field groups (#2159)', async () => {
	requireBrowserPrerequisites();
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage();
		// The issue's reduced fixture, then the same form whose row gap differs
		// from its stacking margin, so a form gap borrowed from the row is caught.
		for ( const rowGap of [ '1.25rem', '1rem' ] ) {
			const raw = execFileSync( 'php', [ 'tests/fixtures/form-stacking-rhythm-fixture.php', rowGap ], { cwd: process.cwd(), encoding: 'utf8', env: process.env } );
			const seeded = JSON.parse( raw );
			assert.equal( seeded.status, 'mapped', raw );
			assert.ok( seeded.strategies.includes( 'provider_equal_width_fields' ), raw );
			assert.equal( seeded.fields.length, 3, raw );
			const tag = { text: 'input', email: 'input', textarea: 'textarea' };
			const fields = seeded.fields.map( ( field ) => `<div class="grunion-field-wrap grunion-field-${ field.type }-wrap ${ field.classes }" data-field="${ field.label }"><label class="grunion-field-label ${ field.labelClasses }">${ field.label }</label><${ tag[ field.type ] } class="grunion-field"></${ tag[ field.type ] }></div>` ).join( '' );
			await page.setContent( `<!doctype html><style>
				body{margin:0}
				.page{width:min(600px,calc(100vw - 24px));margin:12px}
				input,textarea{box-sizing:border-box;font-size:16px;margin:0}
				textarea{height:80px}
				button{font-size:16px;margin:0}
				/* Jetpack's front-end form: a wrapping flex row with no block gap. */
				.jetpack-contact-form__form{display:flex;flex-direction:row;flex-wrap:wrap;align-items:flex-start}
				.jetpack-contact-form__form>*{box-sizing:border-box;flex:0 0 100%}
				.wp-block-button{display:block;width:100%}
				${ seeded.sourceCss }
				${ seeded.css }
			</style><div class="page"><div class="source">${ seeded.sourceHtml }</div><div class="wp-block-jetpack-contact-form ${ seeded.className }"><form class="jetpack-contact-form__form">${ fields }${ seeded.runtimeHtml }</form></div></div>` );
			for ( const width of [ 390, 1440 ] ) {
				await page.setViewportSize( { width, height: 900 } );
				const measure = await page.evaluate( () => {
					const box = ( node ) => node.getBoundingClientRect();
					const groupGaps = ( groups ) => groups.slice( 1 ).map( ( group, index ) => Math.min( ...group.map( ( node ) => box( node ).top ) ) - Math.max( ...groups[ index ].map( ( node ) => box( node ).bottom ) ) );
					const field = ( label ) => document.querySelector( `.wp-block-jetpack-contact-form [data-field="${ label }"]` );
					const source = [ ...document.querySelector( '.source form' ).children ].map( ( node ) => [ node ] );
					const provider = [ [ field( 'Name' ), field( 'Email' ) ], [ field( 'Message' ) ], [ document.querySelector( '.wp-block-jetpack-contact-form .wp-block-button' ) ] ];
					const row = [ ...document.querySelector( '.source .grid' ).children ];
					return {
						source: groupGaps( source ),
						provider: groupGaps( provider ),
						rowGap: getComputedStyle( document.querySelector( '.jetpack-contact-form__form' ) ).rowGap,
						sourceColumnGap: box( row[1] ).left - box( row[0] ).right,
						providerColumnGap: box( field( 'Email' ) ).left - box( field( 'Name' ) ).right,
						twoUp: box( field( 'Email' ) ).top === box( field( 'Name' ) ).top,
					};
				} );
				const detail = `row gap ${ rowGap } at ${ width }: ${ JSON.stringify( measure ) }`;
				assert.equal( measure.rowGap, '20px', detail );
				assert.deepEqual( measure.source, [ 20, 20 ], detail );
				assert.equal( measure.provider.length, 2, detail );
				for ( const gap of measure.provider ) {
					assert.ok( Math.abs( gap - 20 ) <= 0.5, detail );
				}
				if ( width >= 640 ) {
					assert.ok( measure.twoUp, detail );
					assert.ok( Math.abs( measure.providerColumnGap - measure.sourceColumnGap ) <= 0.5, `flattened row column gap: ${ detail }` );
				}
			}
		}
	} finally {
		await browser.close();
	}
} );

test( 'form gap reaches the flex container when Jetpack 16.3 wraps the scoped block in its form (#2159)', async () => {
	requireBrowserPrerequisites();
	const browser = await chromium.launch();
	try {
		const page = await browser.newPage();
		// The issue's plain fixture and Tailwind's real reversible-calc stacking shape.
		for ( const shape of [ 'plain', 'tailwind' ] ) {
			const raw = execFileSync( 'php', [ 'tests/fixtures/form-stacking-rhythm-fixture.php', '1.25rem', shape ], { cwd: process.cwd(), encoding: 'utf8', env: process.env } );
			const seeded = JSON.parse( raw );
			assert.equal( seeded.status, 'mapped', raw );
			assert.ok( seeded.strategies.includes( 'provider_equal_width_fields' ), raw );
			assert.equal( seeded.fields.length, 3, raw );
			const scope = seeded.className.split( /\s+/ ).find( ( token ) => /^ssi-form-[a-f0-9]{12}$/.test( token ) );
			assert.ok( scope, raw );
			const tag = { text: 'input', email: 'input', textarea: 'textarea' };
			const fields = seeded.fields.map( ( field ) => `<div class="grunion-field-wrap grunion-field-${ field.type }-wrap ${ field.classes }" data-field="${ field.label }"><label class="grunion-field-label ${ field.labelClasses }">${ field.label }</label><${ tag[ field.type ] } class="grunion-field"></${ tag[ field.type ] }></div>` ).join( '' );
			// Jetpack 16.3.1 front end: the container carries the scope, its form is
			// a plain block box, and the scoped block wrapper inside the form is the
			// flex container that holds the fields.
			await page.setContent( `<!doctype html><style>
				body{margin:0}
				.page{width:min(600px,calc(100vw - 24px));margin:12px}
				input,textarea{box-sizing:border-box;font-size:16px;margin:0}
				textarea{height:80px}
				button{font-size:16px;margin:0}
				.jetpack-contact-form__form{display:block}
				body .is-layout-flex{display:flex}
				.is-layout-flex{flex-wrap:wrap;align-items:flex-start}
				.wp-block-jetpack-contact-form>*{box-sizing:border-box;flex:0 0 100%}
				.wp-block-button{display:block;width:100%}
				${ seeded.sourceCss }
				${ seeded.css }
			</style><div class="page"><div class="source">${ seeded.sourceHtml }</div><div class="jetpack-contact-form-container ${ scope }"><form class="contact-form commentsblock jetpack-contact-form__form has-no-jetpack-form-layout"><input type="hidden" name="jetpack_contact_form_jwt" value=""><div class="wp-block-jetpack-contact-form ${ seeded.className } is-layout-flex">${ fields }${ seeded.runtimeHtml }</div></form></div></div>` );
			for ( const width of [ 390, 1440 ] ) {
				await page.setViewportSize( { width, height: 900 } );
				const measure = await page.evaluate( () => {
					const box = ( node ) => node.getBoundingClientRect();
					const groupGaps = ( groups ) => groups.slice( 1 ).map( ( group, index ) => Math.min( ...group.map( ( node ) => box( node ).top ) ) - Math.max( ...groups[ index ].map( ( node ) => box( node ).bottom ) ) );
					const container = document.querySelector( '.jetpack-contact-form__form > .wp-block-jetpack-contact-form' );
					const field = ( label ) => container.querySelector( `[data-field="${ label }"]` );
					return {
						rowGap: getComputedStyle( container ).rowGap,
						formRowGap: getComputedStyle( document.querySelector( '.jetpack-contact-form__form' ) ).rowGap,
						provider: groupGaps( [ [ field( 'Name' ), field( 'Email' ) ], [ field( 'Message' ) ], [ container.querySelector( '.wp-block-button' ) ] ] ),
						twoUp: box( field( 'Email' ) ).top === box( field( 'Name' ) ).top,
					};
				} );
				const detail = `${ shape } at ${ width }: ${ JSON.stringify( measure ) }\n${ seeded.css }`;
				assert.equal( measure.rowGap, '20px', detail );
				// The block form box is not a layout container; nothing lands on it twice.
				assert.equal( measure.formRowGap, 'normal', detail );
				assert.equal( measure.provider.length, 2, detail );
				for ( const gap of measure.provider ) {
					assert.ok( Math.abs( gap - 20 ) <= 0.5, detail );
				}
				assert.equal( measure.twoUp, width >= 640, detail );
			}
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
					samples.push( await page.locator( '.wp-block-group' ).evaluateAll( nodes => nodes.map( node => {
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
