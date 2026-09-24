import assert from 'node:assert/strict';
import test from 'node:test';
import {
	DEFAULT_GATE_WIDTHS,
	DEFAULT_REFERENCE_WIDTH,
	contentOverflow,
	evaluateSection,
	layoutDeviations,
	newOverlaps,
	sectionOverlaps,
} from '../lib/layout-projection-gate.mjs';

const box = ( path, x, y, width, height ) => ( { path, x, y, width, height } );

test( 'default gate widths and reference width', () => {
	assert.deepEqual( DEFAULT_GATE_WIDTHS, [ 320, 390, 600, 782, 800, 1000, 1200, 1440, 1920 ] );
	assert.equal( DEFAULT_REFERENCE_WIDTH, 1440 );
} );

test( 'sectionOverlaps finds pairs overlapping by more than 1px on both axes', () => {
	const items = [
		box( 'a', 0, 0, 100, 100 ),
		box( 'b', 50, 50, 100, 100 ), // overlaps a by 50x50
		box( 'c', 300, 300, 100, 100 ), // no overlap with anything
	];
	assert.deepEqual( sectionOverlaps( items ), [ 'a|b' ] );
} );

test( 'sectionOverlaps ignores touching or 1px overlaps', () => {
	const touching = [ box( 'a', 0, 0, 100, 100 ), box( 'b', 100, 0, 100, 100 ) ];
	assert.deepEqual( sectionOverlaps( touching ), [] );

	const onePixel = [ box( 'a', 0, 0, 100, 100 ), box( 'b', 99.5, 0, 100, 100 ) ];
	assert.deepEqual( sectionOverlaps( onePixel ), [] );
} );

test( 'sectionOverlaps requires overlap on both axes, not just one', () => {
	// b overlaps a on the x-axis only (they don't share any y range).
	const items = [ box( 'a', 0, 0, 100, 100 ), box( 'b', 50, 200, 100, 100 ) ];
	assert.deepEqual( sectionOverlaps( items ), [] );
} );

test( 'sectionOverlaps pair keys are order-independent', () => {
	const forward = sectionOverlaps( [ box( 'x', 0, 0, 10, 10 ), box( 'y', 5, 5, 10, 10 ) ] );
	const backward = sectionOverlaps( [ box( 'y', 5, 5, 10, 10 ), box( 'x', 0, 0, 10, 10 ) ] );
	assert.deepEqual( forward, [ 'x|y' ] );
	assert.deepEqual( forward, backward );
} );

test( 'newOverlaps returns only pairs absent from the reference (authored overlaps allowed)', () => {
	const reference = [ 'a|b' ];
	const candidateSame = [ 'a|b' ];
	const candidateWithNew = [ 'a|b', 'b|c' ];
	assert.deepEqual( newOverlaps( reference, candidateSame ), [] );
	assert.deepEqual( newOverlaps( reference, candidateWithNew ), [ 'b|c' ] );
	assert.deepEqual( newOverlaps( [], [ 'a|b' ] ), [ 'a|b' ] );
} );

test( 'contentOverflow flags items whose scroll size exceeds their client size by more than 1px', () => {
	const items = [
		{ path: 'clean', clientWidth: 100, clientHeight: 100, scrollWidth: 100, scrollHeight: 100 },
		{ path: 'tall', clientWidth: 100, clientHeight: 40, scrollWidth: 100, scrollHeight: 80 },
		{ path: 'wide', clientWidth: 50, clientHeight: 50, scrollWidth: 90, scrollHeight: 50 },
		{ path: 'both', clientWidth: 50, clientHeight: 50, scrollWidth: 90, scrollHeight: 90 },
		{ path: 'within-tolerance', clientWidth: 100, clientHeight: 100, scrollWidth: 100.5, scrollHeight: 100 },
	];
	assert.deepEqual( contentOverflow( items ), [
		{ path: 'tall', axis: 'height' },
		{ path: 'wide', axis: 'width' },
		{ path: 'both', axis: 'both' },
	] );
} );

test( 'evaluateSection passes a section with no new overlaps and no overflow', () => {
	const clean = ( path, x, y ) => ( { path, x, y, width: 100, height: 40, clientWidth: 100, clientHeight: 40, scrollWidth: 100, scrollHeight: 40 } );
	const measurementsByWidth = {
		1440: [ clean( 'a', 0, 0 ), clean( 'b', 120, 0 ) ],
		390: [ clean( 'a', 0, 0 ), clean( 'b', 0, 50 ) ],
	};
	const result = evaluateSection( measurementsByWidth );
	assert.deepEqual( result, { pass: true, failures: [] } );
} );

test( 'evaluateSection allows an overlap authored at the reference width, everywhere', () => {
	const overlapping = ( path, x, y ) => ( { path, x, y, width: 100, height: 100, clientWidth: 100, clientHeight: 100, scrollWidth: 100, scrollHeight: 100 } );
	// a and b overlap at the reference width itself; that's how the section
	// was authored, so the same overlap at every other gate width is fine too.
	const measurementsByWidth = {
		1440: [ overlapping( 'a', 0, 0 ), overlapping( 'b', 50, 50 ) ],
		390: [ overlapping( 'a', 0, 0 ), overlapping( 'b', 50, 50 ) ],
	};
	const result = evaluateSection( measurementsByWidth );
	assert.deepEqual( result, { pass: true, failures: [] } );
} );

test( 'evaluateSection fails a new overlap introduced at a narrower gate width', () => {
	const clean = ( path, x, y ) => ( { path, x, y, width: 100, height: 40, clientWidth: 100, clientHeight: 40, scrollWidth: 100, scrollHeight: 40 } );
	const overlapping = ( path, x, y ) => ( { path, x, y, width: 100, height: 40, clientWidth: 100, clientHeight: 40, scrollWidth: 100, scrollHeight: 40 } );
	const measurementsByWidth = {
		1440: [ clean( 'a', 0, 0 ), clean( 'b', 120, 0 ) ],
		// Squeezed at 390px, a and b now collide - a defect the reference layout didn't have.
		390: [ overlapping( 'a', 0, 0 ), overlapping( 'b', 50, 10 ) ],
	};
	const result = evaluateSection( measurementsByWidth );
	assert.equal( result.pass, false );
	assert.deepEqual( result.failures, [ { width: 390, kind: 'overlap', detail: [ 'a|b' ] } ] );
} );

test( 'evaluateSection fails overflow at any gate width, including the reference width', () => {
	const item = ( path, clientHeight, scrollHeight ) => ( { path, x: 0, y: 0, width: 100, height: clientHeight, clientWidth: 100, clientHeight, scrollWidth: 100, scrollHeight } );
	const measurementsByWidth = {
		1440: [ item( 'a', 40, 80 ) ],
	};
	const result = evaluateSection( measurementsByWidth );
	assert.equal( result.pass, false );
	assert.deepEqual( result.failures, [ { width: 1440, kind: 'overflow', detail: [ { path: 'a', axis: 'height' } ] } ] );
} );

test( 'evaluateSection can report both overlap and overflow failures at the same width', () => {
	const measurementsByWidth = {
		1440: [
			{ path: 'a', x: 0, y: 0, width: 100, height: 40, clientWidth: 100, clientHeight: 40, scrollWidth: 100, scrollHeight: 40 },
			{ path: 'b', x: 120, y: 0, width: 100, height: 40, clientWidth: 100, clientHeight: 40, scrollWidth: 100, scrollHeight: 40 },
		],
		390: [
			{ path: 'a', x: 0, y: 0, width: 100, height: 40, clientWidth: 100, clientHeight: 40, scrollWidth: 100, scrollHeight: 80 },
			{ path: 'b', x: 50, y: 10, width: 100, height: 40, clientWidth: 100, clientHeight: 40, scrollWidth: 100, scrollHeight: 40 },
		],
	};
	const result = evaluateSection( measurementsByWidth );
	assert.equal( result.pass, false );
	assert.equal( result.failures.length, 2 );
	assert.deepEqual( result.failures.find( failure => 'overlap' === failure.kind ), { width: 390, kind: 'overlap', detail: [ 'a|b' ] } );
	assert.deepEqual( result.failures.find( failure => 'overflow' === failure.kind ), { width: 390, kind: 'overflow', detail: [ { path: 'a', axis: 'height' } ] } );
} );

test( 'evaluateSection accepts an explicit non-default reference width', () => {
	const clean = ( path, x, y ) => ( { path, x, y, width: 100, height: 40, clientWidth: 100, clientHeight: 40, scrollWidth: 100, scrollHeight: 40 } );
	const measurementsByWidth = {
		1200: [ clean( 'a', 0, 0 ), clean( 'b', 50, 10 ) ], // overlaps, but this is the reference width now
	};
	const result = evaluateSection( measurementsByWidth, 1200 );
	assert.deepEqual( result, { pass: true, failures: [] } );
} );

test( 'evaluateSection throws when the reference width has no measurement', () => {
	assert.throws( () => evaluateSection( { 390: [] }, 1440 ), /reference width 1440/ );
} );

test( 'layoutDeviations allows grid snapping but flags moved or resized items relative to the section frame', () => {
	const baseline = [
		{ path: 'a', x: 100, y: 100, width: 400, height: 200 },
		{ path: 'b', x: 520, y: 100, width: 400, height: 200 },
	];
	const snapped = [
		{ path: 'a', x: 108, y: 110, width: 396, height: 210 },
		{ path: 'b', x: 524, y: 110, width: 404, height: 205 },
	];
	assert.deepEqual( layoutDeviations( baseline, snapped ), [] );
	const stacked = [
		{ path: 'a', x: 100, y: 100, width: 820, height: 200 },
		{ path: 'b', x: 100, y: 320, width: 820, height: 200 },
	];
	assert.deepEqual( layoutDeviations( baseline, stacked ).map( entry => entry.path ), [ 'a', 'b' ] );
} );

test( 'evaluateSection subtracts what the unprojected original already does and flags layout and page overflow', () => {
	const item = ( path, x, extra = {} ) => ( { path, x, y: 0, width: 100, height: 50, clientWidth: 100, clientHeight: 50, scrollWidth: 100, scrollHeight: 50, ...extra } );
	const original = { 1440: [ item( 'a', 0 ), item( 'b', 60 ) ], 390: [ item( 'a', 0, { scrollHeight: 90 } ), item( 'b', 110 ) ] };
	// Same layout as the original, including its authored overlap and overflow: passes.
	assert.equal( evaluateSection( original, 1440, original ).pass, true );
	// Page overflow the original does not have fails.
	const wide = { 1440: original[ 1440 ], 390: original[ 390 ].map( entry => ( { ...entry, pageOverflow: true } ) ) };
	assert.deepEqual( evaluateSection( wide, 1440, original ).failures.map( failure => failure.kind ), [ 'page_overflow' ] );
	// Moving an item far from its original place fails as a layout deviation.
	const moved = { 1440: [ item( 'a', 0 ), item( 'b', 600 ) ], 390: original[ 390 ] };
	assert.ok( evaluateSection( moved, 1440, original ).failures.some( failure => 'layout' === failure.kind && 1440 === failure.width ) );
} );
