/**
 * Capture a `static-site-importer/layout-placement/v1` model with Playwright.
 *
 * The page must be rendered with the SSI measurement markers active (see
 * `Static_Site_Importer_Layout_Marker_Filter`), so every block of the main
 * content pass carries `data-ssi-block-path`. The first marked element that
 * owns other marked elements becomes the host; its direct marked descendants
 * become the items, and their boxes are measured at each viewport.
 */

export const CAPTURE_VIEWPORTS = [ 1440, 700, 390 ];
export const PATH_ATTRIBUTE = 'data-ssi-block-path';
export const MODEL_SCHEMA = 'static-site-importer/layout-placement/v1';
export const SECTIONS_SCHEMA = 'static-site-importer/layout-sections/v1';

const roundBox = box => ( {
	x: Math.round( ( box.x + Number.EPSILON ) * 100 ) / 100,
	y: Math.round( ( box.y + Number.EPSILON ) * 100 ) / 100,
	width: Math.round( ( box.width + Number.EPSILON ) * 100 ) / 100,
	height: Math.round( ( box.height + Number.EPSILON ) * 100 ) / 100,
} );

/**
 * Capture the placement model for the marked host section of a page.
 *
 * @param {import('playwright').Page} page        Playwright page on the rendered content.
 * @param {Array<number>}             [viewports] Viewport widths, widest first. Defaults to 1440, 700, 390.
 * @param {object}                    [options]   Capture options.
 * @param {string}                    [options.hostPath] Restrict capture to one marked host path.
 * @returns {Promise<object>} The placement model for the selected host section.
 */
export async function captureLayoutPlacement( page, viewports = CAPTURE_VIEWPORTS, options = {} ) {
	const widths = orderedWidths( viewports );
	const measured = await measureAtViewports( page, widths );

	const host = selectHost( measured[ widths[ 0 ] ], options.hostPath );
	if ( ! host ) {
		throw new Error( 'No marked host section found; render the page with the SSI measurement markers active.' );
	}

	return buildPlacementModel( measured, widths, host );
}

/**
 * Capture one placement model per content section of a page.
 *
 * A section is discovered from the page's content root (the top marked
 * element with no marked ancestor): each of its marked direct children is a
 * section candidate, descended through marked single-child wrappers until an
 * element with two or more marked direct children is reached. That element
 * becomes the section host; its marked direct children become the items.
 * Candidates that never reach two items (the descent bottoms out at a marked
 * leaf) are skipped and reported instead of producing a model.
 *
 * @param {import('playwright').Page} page        Playwright page on the rendered content.
 * @param {Array<number>}             [viewports] Viewport widths, widest first. Defaults to 1440, 700, 390.
 * @param {object}                    [options]   Reserved for future capture options.
 * @returns {Promise<{schema:string,sections:Array<object>,skipped:Array<{path:string,reason:string}>}>}
 */
export async function captureLayoutSections( page, viewports = CAPTURE_VIEWPORTS, options = {} ) { // eslint-disable-line no-unused-vars -- reserved for parity with captureLayoutPlacement's options parameter.
	const widths = orderedWidths( viewports );
	const measured = await measureAtViewports( page, widths );
	const tree = buildSectionTree( measured[ widths[ 0 ] ] );

	const sections = [];
	const skipped = [];

	// One top-level block wrapping the content (a `<main>` group) holds the
	// sections; several top-level blocks are the sections themselves.
	const candidates = 1 === tree.rootPaths.length ? tree.childrenOf( tree.rootPaths[ 0 ] ) : tree.rootPaths;
	for ( const candidatePath of candidates ) {
		let current = candidatePath;
		let children = tree.childrenOf( current );
		while ( 1 === children.length ) {
			current = children[ 0 ];
			children = tree.childrenOf( current );
		}
		if ( children.length >= 2 ) {
			sections.push( buildPlacementModel( measured, widths, { path: current, items: children } ) );
		} else {
			skipped.push( { path: current, reason: 'section_without_items' } );
		}
	}

	return { schema: SECTIONS_SCHEMA, sections, skipped };
}

/**
 * Bring the page to its settled, visitor-visible layout before measuring:
 * scroll through it so scroll-triggered reveals fire, finish running
 * transitions and animations, then return to the top.
 *
 * @param {import('playwright').Page} page
 */
export async function settleLayout( page ) {
	await page.evaluate( async () => {
		const pause = ms => new Promise( resolve => setTimeout( resolve, ms ) );
		const step = Math.max( 200, Math.floor( window.innerHeight * 0.8 ) );
		for ( let y = 0; y < document.documentElement.scrollHeight; y += step ) {
			window.scrollTo( 0, y );
			await pause( 60 );
		}
		window.scrollTo( 0, document.documentElement.scrollHeight );
		await pause( 120 );
		for ( const animation of document.getAnimations() ) {
			try {
				animation.finish();
			} catch {
				// Infinite animations cannot finish; they are left running.
			}
		}
		window.scrollTo( 0, 0 );
		await pause( 60 );
	} );
}

function orderedWidths( viewports ) {
	return [ ...new Set( viewports ) ].sort( ( a, b ) => b - a );
}

async function measureAtViewports( page, widths ) {
	const measured = {};
	for ( const width of widths ) {
		await page.setViewportSize( { width, height: 900 } );
		await page.waitForLoadState( 'networkidle' );
		await settleLayout( page );
		measured[ width ] = await measureMarkedElements( page );
	}
	return measured;
}

function buildPlacementModel( measured, widths, host ) {
	const model = {
		schema: MODEL_SCHEMA,
		host: {
			path: host.path,
			viewports: {},
		},
		items: host.items.map( path => ( { path, viewports: {} } ) ),
	};

	for ( const width of widths ) {
		const boxes = measured[ width ].boxes;
		model.host.viewports[ String( width ) ] = roundBox( boxes.get( host.path ).content );
		for ( const item of model.items ) {
			item.viewports[ String( width ) ] = roundBox( boxes.get( item.path )?.border ?? { x: 0, y: 0, width: 0, height: 0 } );
		}
	}

	return model;
}

/**
 * Build the marked-element adjacency (parent path -> ordered child paths)
 * needed to walk from the content root down to each section host.
 *
 * @param {{boxes:Map<string,object>,hosts:Array<{path:string,items:Array<string>}>}} measurement Widest-viewport measurement.
 * @returns {{rootPath:string,childrenOf:(path:string)=>Array<string>}}
 */
function buildSectionTree( measurement ) {
	const childrenByPath = new Map( measurement.hosts.map( host => [ host.path, host.items ] ) );
	const allPaths = [ ...measurement.boxes.keys() ];
	const childPaths = new Set();
	for ( const host of measurement.hosts ) {
		for ( const item of host.items ) {
			childPaths.add( item );
		}
	}
	const rootPaths = allPaths.filter( path => ! childPaths.has( path ) );
	return {
		rootPaths,
		childrenOf: path => childrenByPath.get( path ) ?? [],
	};
}

async function measureMarkedElements( page ) {
	const measurement = await page.evaluate( attribute => {
		const nearestMarkedAncestor = element => {
			let parent = element.parentElement;
			while ( parent && ! parent.hasAttribute( attribute ) ) {
				parent = parent.parentElement;
			}
			return parent;
		};

		const marked = Array.from( document.querySelectorAll( `[${ attribute }]` ) );
		const itemsByHost = new Map();
		for ( const element of marked ) {
			const host = nearestMarkedAncestor( element );
			if ( ! host ) {
				continue;
			}
			const hostPath = host.getAttribute( attribute );
			if ( ! itemsByHost.has( hostPath ) ) {
				itemsByHost.set( hostPath, [] );
			}
			itemsByHost.get( hostPath ).push( element.getAttribute( attribute ) );
		}

		// Every element records its border box (as an item) and its content box
		// (as a host): placement tracks span the area inside a host's padding
		// and border, while an item occupies its whole box.
		const rows = marked.map( element => {
			const rect = element.getBoundingClientRect();
			const style = getComputedStyle( element );
			const edge = side => parseFloat( style[ `padding${ side }` ] ) + parseFloat( style[ `border${ side }Width` ] );
			return [
				element.getAttribute( attribute ),
				{
					border: { x: rect.x, y: rect.y + window.scrollY, width: rect.width, height: rect.height },
					content: {
						x: rect.x + edge( 'Left' ),
						y: rect.y + window.scrollY + edge( 'Top' ),
						width: Math.max( 0, rect.width - edge( 'Left' ) - edge( 'Right' ) ),
						height: Math.max( 0, rect.height - edge( 'Top' ) - edge( 'Bottom' ) ),
					},
				},
			];
		} );
		return { rows, hosts: [ ...itemsByHost.entries() ].map( ( [ hostPath, items ] ) => ( { hostPath, items } ) ) };
	}, PATH_ATTRIBUTE );

	return {
		boxes: new Map( measurement.rows ),
		hosts: measurement.hosts.map( ( { hostPath, items } ) => ( { path: hostPath, items } ) ),
	};
}

function selectHost( measurement, hostPath ) {
	const hosts = measurement.hosts ?? [];
	if ( hostPath ) {
		return hosts.find( host => host.path === hostPath ) ?? null;
	}
	return hosts[ 0 ] ?? null;
}
