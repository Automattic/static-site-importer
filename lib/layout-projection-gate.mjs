/**
 * Render gate for a candidate layout projection.
 *
 * Every function here is pure: it takes already-measured boxes and content
 * sizes and returns a verdict. Nothing here opens a browser or reads a page;
 * that measurement happens once, in `tools/project-imported-layout.mjs`, and
 * is handed in as plain data. Keeping the gate pure and Node-side (rather
 * than, say, evaluating it inside the page) makes it independently testable
 * without a browser, deterministic across runs, and reusable for both the
 * live pipeline step and its regression tests.
 */

export const DEFAULT_GATE_WIDTHS = [ 320, 390, 600, 782, 800, 1000, 1200, 1440, 1920 ];
export const DEFAULT_REFERENCE_WIDTH = 1440;
const OVERLAP_EPSILON = 1;
const OVERFLOW_EPSILON = 1;

/**
 * Canonical, order-independent key for one pair of item paths.
 *
 * @param {string} a First item path.
 * @param {string} b Second item path.
 * @returns {string}
 */
function pairKey( a, b ) {
	return a < b ? `${ a }|${ b }` : `${ b }|${ a }`;
}

/**
 * Pairs of items that overlap by more than 1px on both axes.
 *
 * @param {Array<{path:string,x:number,y:number,width:number,height:number}>} itemBoxes Item boxes at one width.
 * @returns {Array<string>} Sorted, deduplicated pair keys (`"pathA|pathB"`, lexicographically ordered).
 */
export function sectionOverlaps( itemBoxes ) {
	const pairs = new Set();
	for ( let i = 0; i < itemBoxes.length; i++ ) {
		for ( let j = i + 1; j < itemBoxes.length; j++ ) {
			const a = itemBoxes[ i ];
			const b = itemBoxes[ j ];
			const xOverlap = Math.min( a.x + a.width, b.x + b.width ) - Math.max( a.x, b.x );
			const yOverlap = Math.min( a.y + a.height, b.y + b.height ) - Math.max( a.y, b.y );
			if ( xOverlap > OVERLAP_EPSILON && yOverlap > OVERLAP_EPSILON ) {
				pairs.add( pairKey( a.path, b.path ) );
			}
		}
	}
	return [ ...pairs ].sort();
}

/**
 * Overlap pairs present in a candidate width's layout but not in the reference.
 *
 * @param {Array<string>} reference Pair keys from `sectionOverlaps` of the reference layout.
 * @param {Array<string>} candidate Pair keys from `sectionOverlaps` of the candidate width.
 * @returns {Array<string>} Pair keys new to the candidate.
 */
export function newOverlaps( reference, candidate ) {
	const known = new Set( reference );
	return candidate.filter( pair => ! known.has( pair ) );
}

/**
 * Items whose rendered content extends past their own box by more than 1px.
 *
 * @param {Array<{path:string,clientWidth:number,clientHeight:number,scrollWidth:number,scrollHeight:number}>} items
 *   Per-item content measurements at one width (`clientWidth`/`clientHeight` and
 *   `scrollWidth`/`scrollHeight`, as read from the element in the browser).
 * @returns {Array<{path:string,axis:'width'|'height'|'both'}>} Items that overflow, and on which axis.
 */
export function contentOverflow( items ) {
	const overflowing = [];
	for ( const item of items ) {
		const widthOverflow = ( item.scrollWidth ?? 0 ) - ( item.clientWidth ?? 0 ) > OVERFLOW_EPSILON;
		const heightOverflow = ( item.scrollHeight ?? 0 ) - ( item.clientHeight ?? 0 ) > OVERFLOW_EPSILON;
		if ( ! widthOverflow && ! heightOverflow ) {
			continue;
		}
		overflowing.push( {
			path: item.path,
			axis: widthOverflow && heightOverflow ? 'both' : widthOverflow ? 'width' : 'height',
		} );
	}
	return overflowing;
}

/**
 * Items whose box, relative to the section's own top-left, moved or resized
 * beyond tolerance compared with the unprojected original at the same width.
 * A layout adapter must reproduce the imported layout, not just avoid
 * collisions, so this is a fidelity check. Tolerances allow grid snapping:
 * horizontally the larger of 16px and 4% of the section width, vertically
 * the larger of 32px and 6% of the section height.
 *
 * @param {Array<{path:string,x:number,y:number,width:number,height:number}>} baseline
 * @param {Array<{path:string,x:number,y:number,width:number,height:number}>} candidate
 * @returns {Array<{path:string,dx:number,dy:number,dw:number,dh:number}>}
 */
export function layoutDeviations( baseline, candidate ) {
	if ( ! baseline.length || ! candidate.length ) {
		return [];
	}
	const frame = items => {
		const left = Math.min( ...items.map( item => item.x ) );
		const top = Math.min( ...items.map( item => item.y ) );
		const right = Math.max( ...items.map( item => item.x + item.width ) );
		const bottom = Math.max( ...items.map( item => item.y + item.height ) );
		return { left, top, width: right - left, height: bottom - top };
	};
	const base = frame( baseline );
	const cand = frame( candidate );
	const xTolerance = Math.max( 16, 0.04 * base.width );
	const yTolerance = Math.max( 32, 0.06 * base.height );
	const byPath = new Map( baseline.map( item => [ item.path, item ] ) );
	const deviations = [];
	for ( const item of candidate ) {
		const original = byPath.get( item.path );
		if ( ! original ) {
			continue;
		}
		const dx = Math.round( ( item.x - cand.left ) - ( original.x - base.left ) );
		const dy = Math.round( ( item.y - cand.top ) - ( original.y - base.top ) );
		const dw = Math.round( item.width - original.width );
		const dh = Math.round( item.height - original.height );
		if ( Math.abs( dx ) > xTolerance || Math.abs( dw ) > xTolerance || Math.abs( dy ) > yTolerance || Math.abs( dh ) > yTolerance ) {
			deviations.push( { path: item.path, dx, dy, dw, dh } );
		}
	}
	return deviations;
}

/**
 * Evaluate one section's candidate projection across a set of widths.
 *
 * The reference layout is the candidate's own layout at `referenceWidth`
 * (1440 by default). Overlaps already present there are authored, not a
 * projection defect, so they are allowed everywhere; only overlaps new to a
 * gate width, and any content overflow at any gate width (including the
 * reference width itself), fail the section.
 *
 * @param {Record<number,Array<{path:string,x:number,y:number,width:number,height:number,clientWidth:number,clientHeight:number,scrollWidth:number,scrollHeight:number}>>} measurementsByWidth
 *   Per-item measurements, keyed by viewport width, at every width to gate (the reference width's
 *   entry doubles as the reference layout).
 * @param {number} [referenceWidth] Width whose own layout defines authored overlaps. Defaults to 1440.
 * @param {Record<number,Array<object>>} [baselineByWidth] The unprojected section measured at the same
 *   widths (same item paths). Overlaps and overflow the original already has at a width are
 *   authored behavior at that width, not a projection defect.
 * @returns {{pass:boolean,failures:Array<{width:number,kind:'overlap'|'overflow'|'layout'|'page_overflow',detail:*}>}}
 */
export function evaluateSection( measurementsByWidth, referenceWidth = DEFAULT_REFERENCE_WIDTH, baselineByWidth = {} ) {
	const referenceItems = measurementsByWidth[ referenceWidth ] ?? measurementsByWidth[ String( referenceWidth ) ];
	if ( ! referenceItems ) {
		throw new Error( `evaluateSection: no measurement at reference width ${ referenceWidth }.` );
	}
	const referenceOverlapPairs = sectionOverlaps( referenceItems );

	const failures = [];
	const widths = Object.keys( measurementsByWidth )
		.map( Number )
		.sort( ( a, b ) => a - b );

	for ( const width of widths ) {
		const items = measurementsByWidth[ width ] ?? measurementsByWidth[ String( width ) ];
		const baseline = baselineByWidth[ width ] ?? baselineByWidth[ String( width ) ] ?? [];
		const allowedOverlaps = [ ...referenceOverlapPairs, ...sectionOverlaps( baseline ) ];
		const candidateOverlaps = sectionOverlaps( items );
		const newOnes = newOverlaps( allowedOverlaps, candidateOverlaps );
		if ( newOnes.length > 0 ) {
			failures.push( { width, kind: 'overlap', detail: newOnes } );
		}

		const deviations = layoutDeviations( baseline, items );
		if ( deviations.length > 0 ) {
			failures.push( { width, kind: 'layout', detail: deviations } );
		}

		// Horizontal page overflow the original does not have.
		if ( items.some( item => true === item.pageOverflow ) && ! baseline.some( item => true === item.pageOverflow ) ) {
			failures.push( { width, kind: 'page_overflow', detail: [] } );
		}

		const baselineOverflow = new Set( contentOverflow( baseline ).map( entry => entry.path ) );
		const overflow = contentOverflow( items ).filter( entry => ! baselineOverflow.has( entry.path ) );
		if ( overflow.length > 0 ) {
			failures.push( { width, kind: 'overflow', detail: overflow } );
		}
	}

	return { pass: 0 === failures.length, failures };
}
