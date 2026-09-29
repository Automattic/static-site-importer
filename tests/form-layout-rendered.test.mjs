import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';

const { chromium } = ( await import( 'playwright' ).catch( () => null ) ) ?? {};

const transformer = process.env.STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH
	? `${process.env.STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH.replace(/\/$/, '')}/php-transformer.php`
	: 'vendor/automattic/blocks-engine-php-transformer/php-transformer.php';
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
			${seeded.css}
		</style><div class="page"><div class="wp-block-jetpack-contact-form ${seeded.className}"><form class="jetpack-contact-form__form has-no-jetpack-form-layout">${fields}${seeded.runtimeHtml.replace( '<div class="wp-block-button', '<div data-field="Send" class="wp-block-button' )}</form></div></div>` );
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
			assert.ok( Math.abs( send.parentRight - send.parentPaddingRight - send.right ) <= 1, `right-aligned submit at ${width}: ${JSON.stringify( send )}` );
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
	assert.equal( seeded.submitParentProducerLayout.layout.min_height, undefined, 'producer output omits the source-owned wrapper min-height fact' );
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
			assert.deepEqual( wrappers, [ sourceWrapperHeight, '0px' ], `current graph v2 omission is distinct from provider wrapper default at ${width}: ${JSON.stringify( wrappers )}` );
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
