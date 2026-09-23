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
	const widths = [ ...new Set( viewports ) ].sort( ( a, b ) => b - a );
	const measured = {};

	for ( const width of widths ) {
		await page.setViewportSize( { width, height: 900 } );
		await page.waitForLoadState( 'networkidle' );
		measured[ width ] = await measureMarkedElements( page );
	}

	const host = selectHost( measured[ widths[ 0 ] ], options.hostPath );
	if ( ! host ) {
		throw new Error( 'No marked host section found; render the page with the SSI measurement markers active.' );
	}

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
		model.host.viewports[ String( width ) ] = roundBox( boxes.get( host.path ) );
		for ( const item of model.items ) {
			item.viewports[ String( width ) ] = roundBox( boxes.get( item.path ) ?? { x: 0, y: 0, width: 0, height: 0 } );
		}
	}

	return model;
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

		const rows = marked.map( element => {
			const rect = element.getBoundingClientRect();
			return [
				element.getAttribute( attribute ),
				{ x: rect.x, y: rect.y, width: rect.width, height: rect.height },
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
